<?php
/**
 * api/helpers.php — funciones transversales v3:
 *  - auditoría (log de sistema)
 *  - lectura/escritura de la tabla `configuracion` (General + SMTP)
 *  - vocabularios dinámicos con fallback al hardcodeo de config.php
 *  - sanitización de HTML para artículos de la Base de Conocimiento
 */

require_once __DIR__ . '/../config.php';

/** Escribe un evento en la tabla `auditoria` (nunca rompe la operación). */
function auditar(string $accion, string $detalle = ''): void
{
    try {
        $u = $_SESSION['user'] ?? null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        db()->prepare("INSERT INTO auditoria (usuario_id, usuario, accion, detalle, ip) VALUES (?,?,?,?,?)")
            ->execute([$u['id'] ?? null, ($u['nombre'] ?? 'Anónimo') . ' (' . ($u['rol'] ?? 'público') . ')',
                       $accion, clean($detalle, 500), substr($ip, 0, 45)]);
    } catch (Throwable $e) { /* silencioso por diseño */ }
}

/** ¿Existe una tabla? (tolerancia a BD aún sin migrar) */
function tabla_existe(string $t): bool
{
    static $cache = [];
    if (!isset($cache[$t])) {
        try {
            $r = db()->query("SHOW TABLES LIKE " . db()->quote($t))->fetch();
            $cache[$t] = (bool)$r;
        } catch (Throwable $e) { $cache[$t] = false; }
    }
    return $cache[$t];
}

/** Lee toda la configuración general+SMTP como array clave=>valor. */
function config_db(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $cfg = [];
    if (tabla_existe('configuracion')) {
        foreach (db()->query("SELECT clave, valor FROM configuracion")->fetchAll() as $r) {
            $cfg[$r['clave']] = $r['valor'];
        }
    }
    // valores por defecto
    $def = [
        'nombre_sistema' => APP_NAME, 'nombre_institucion' => 'Gobierno de Chile',
        'logo' => '🖥️', 'color_primario' => '#0039A6',
        'correo_contacto' => 'soporte@helpdesk.gob.cl', 'telefono_contacto' => '',
        'zona_horaria' => 'America/Santiago', 'max_upload_mb' => (string)MAX_UPLOAD_MB,
        'smtp_activo' => '0', 'smtp_host' => '', 'smtp_puerto' => '587',
        'smtp_usuario' => '', 'smtp_password' => '', 'smtp_remitente' => '',
    ];
    return $cfg = array_merge($def, $cfg);
}

/** Guarda claves de configuración (solo las permitidas). */
function config_guardar(array $pares): void
{
    $permitidas = ['nombre_sistema','nombre_institucion','logo','color_primario','correo_contacto',
                   'telefono_contacto','zona_horaria','max_upload_mb','smtp_activo','smtp_host',
                   'smtp_puerto','smtp_usuario','smtp_password','smtp_remitente'];
    $st = db()->prepare("INSERT INTO configuracion (clave, valor) VALUES (?, ?)
                         ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
    foreach ($pares as $k => $v) {
        if (in_array($k, $permitidas, true)) $st->execute([$k, (string)$v]);
    }
}

/** Departamentos desde BD (fallback a constante DEPARTAMENTOS). */
function departamentos_lista(): array
{
    if (tabla_existe('departamentos')) {
        $r = db()->query("SELECT nombre FROM departamentos WHERE activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_COLUMN);
        if ($r) return $r;
    }
    return DEPARTAMENTOS;
}

/** Ubicaciones desde BD (fallback a constante UBICACIONES). */
function ubicaciones_lista(): array
{
    if (tabla_existe('ubicaciones')) {
        $r = db()->query("SELECT nombre FROM ubicaciones WHERE activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_COLUMN);
        if ($r) return $r;
    }
    return UBICACIONES;
}

/** Tipos de ticket desde BD (fallback a constante TIPOS_TICKET). */
function tipos_ticket_lista(): array
{
    if (tabla_existe('tipos_ticket')) {
        $rows = db()->query("SELECT nombre FROM tipos_ticket WHERE activo=1 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        if ($rows) return array_combine($rows, $rows);
    }
    return TIPOS_TICKET;
}

/** Categorías con visibilidad (agrega columna default si la BD es v2). */
function categorias_lista(?string $visibilidad = null): array
{
    $sql = "SELECT id, nombre, descripcion, icono, articulos FROM categorias";
    if (tabla_existe('categorias') && columna_existe('categorias', 'visibilidad')) {
        if ($visibilidad === 'publica') {
            $sql .= " WHERE visibilidad IN ('both','external')";
        } elseif ($visibilidad === 'interna') {
            $sql .= " WHERE visibilidad IN ('both','internal')";
        }
    }
    return db()->query($sql . " ORDER BY orden, nombre")->fetchAll();
}

/** ¿Existe una columna en una tabla? */
function columna_existe(string $tabla, string $col): bool
{
    static $cache = [];
    $k = "$tabla.$col";
    if (!isset($cache[$k])) {
        try {
            $st = db()->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $st->execute([$tabla, $col]);
            $cache[$k] = (int)$st->fetchColumn() > 0;
        } catch (Throwable $e) { $cache[$k] = false; }
    }
    return $cache[$k];
}

/**
 * Sanitiza HTML de artículos KB: mantiene etiquetas de formato seguras,
 * elimina eventos on*, scripts e iframe. Devuelve HTML listo para mostrar.
 */
function limpiar_html(string $html): string
{
    $permitidas = '<p><br><strong><b><em><i><u><s><ul><ol><li><h2><h3><h4><code><pre><blockquote>'
                . '<table><thead><tbody><tr><th><td><a><span><div>';
    $html = strip_tags($html, $permitidas);
    $html = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);           // onclick=...
    $html = preg_replace('/\sstyle\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $html);                    // estilos inline
    $html = preg_replace_callback('/<a\b[^>]*>/i', function ($m) {                               // links seguros
        $tag = $m[0];
        if (!preg_match('/href\s*=\s*["\']([^"\']+)["\']/i', $tag, $h)) return '<span>';
        $url = $h[1];
        if (!preg_match('#^(https?:)?//#i', $url) && strpos($url, 'mailto:') !== 0) return '<span>';
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer nofollow">';
    }, $html);
    $html = str_replace('</a>', '</a>', $html);
    return trim($html);
}

/** Envío SMTP muy simple (sin dependencias). Solo se usa si smtp_activo=1. */
function enviar_correo_smtp(string $para, string $asunto, string $htmlBody): bool
{
    $c = config_db();
    if (($c['smtp_activo'] ?? '0') !== '1' || empty($c['smtp_host'])) return false;
    $ssl = ((int)($c['smtp_puerto'] ?? 587) === 465) ? 'ssl://' : '';
    $sock = @fsockopen($ssl . $c['smtp_host'], (int)$c['smtp_puerto'], $errno, $errstr, 8);
    if (!$sock) return false;
    $read = function () use ($sock) { $o = ''; while ($l = fgets($sock, 515)) { $o .= $l; if (isset($l[3]) && $l[3] === ' ') break; } return $o; };
    $cmd = function ($c2) use ($sock, $read) { fwrite($sock, $c2 . "\r\n"); return $read(); };
    try {
        $read();
        $cmd('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        if (!empty($c['smtp_usuario'])) {
            $cmd('AUTH LOGIN');
            $cmd(base64_encode($c['smtp_usuario']));
            $cmd(base64_encode($c['smtp_password'] ?? ''));
        }
        $cmd('MAIL FROM:<' . ($c['smtp_remitente'] ?: 'no-reply@localhost') . '>');
        $cmd('RCPT TO:<' . $para . '>');
        $cmd('DATA');
        $hdr = "From: {$c['smtp_remitente']} ({$c['nombre_sistema']})\r\nTo: $para\r\n"
             . "Subject: $asunto\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n";
        fwrite($sock, $hdr . $htmlBody . "\r\n.\r\n"); $read();
        $cmd('QUIT');
        return true;
    } catch (Throwable $e) { return false; }
    finally { fclose($sock); }
}
