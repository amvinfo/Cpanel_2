<?php
/**
 * api/public.php — Endpoints del Portal Ciudadano (sin sesión).
 * Uso:  api/public.php?action=nombre_accion   (GET o POST)
 */
require_once __DIR__ . '/../config.php';

/* =====================================================================
   BLINDAJE ANTI-HTTP 500 ("Unexpected end of JSON input")
   Cualquier warning/notice/fatal (p. ej. columnas que faltan porque la
   BD aún no aplica schema_v3.sql) se traduce SIEMPRE a un JSON válido.
   ===================================================================== */
error_reporting(E_ALL);
ini_set('display_errors', '0');           // nunca ensuciar la salida JSON
ob_start();                               // capturar cualquier impresión accidental

register_shutdown_function(function () {
    $err = error_get_last();
    $fatal = $err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
    $buf = ob_get_contents();
    if ($buf !== false) { ob_end_clean(); }
    if ($fatal) {
        while (ob_get_level()) { ob_end_clean(); }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo '{"ok":false,"error":"Error interno del servidor. Verifique las migraciones (schema_v3.sql / migrate.php). Detalle: '
            . preg_replace('/[^\x20-\x7E]/', '', addslashes(substr($err['message'] ?? '', 0, 160))) . '"}';
        return;
    }
    // Salida no deseada (warnings/espacios): registrarla y descartarla
    if ($buf !== '' && trim((string)$buf) !== '') {
        error_log('public.php salida no-JSON capturada: ' . substr(strip_tags((string)$buf), 0, 300));
    }
});

header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$action = $_GET['action'] ?? '';

/* ---------- helpers ---------- */
function nuevo_codigo(PDO $pdo): string
{
    $anio = date('Y');
    $max = $pdo->query("SELECT MAX(CAST(SUBSTRING(codigo, -5) AS UNSIGNED)) FROM tickets WHERE codigo LIKE 'TKT-$anio-%'")->fetchColumn();
    return sprintf('TKT-%s-%05d', $anio, (int)$max + 1);
}
/**
 * Genera un token de seguimiento legible (ej: "3A45-868A-B724").
 * Se genera SIN guiones para el chequeo de unicidad y se formatea al final.
 */
function nuevo_token(): string
{
    $pdo = db();
    $chk = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE REPLACE(token_consulta,'-','') = ?");
    do {
        $raw = strtoupper(bin2hex(random_bytes(6))); // 12 caracteres hex sin guiones
        $chk->execute([$raw]);
    } while ($chk->fetchColumn() > 0);
    return implode('-', str_split($raw, 4)); // XXXX-XXXX-XXXX (14 chars)
}

/**
 * Insert el ticket con tolerancia a esquemas antiguos:
 * si la BD aún tiene `token_consulta` CHAR(12) (esquema v1), se guarda
 * el token sin guiones en lugar de fallar con "Data too long" (HTTP 500).
 */
function insert_ticket(PDO $pdo, array $d): int
{
    static $w = null;
    if ($w === null) {
        $cols = [];
        foreach ($pdo->query('SHOW COLUMNS FROM `tickets`')->fetchAll() as $r) $cols[] = $r['Field'];
        $tokLen = 12;
        foreach ($pdo->query("SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'token_consulta'")->fetchAll() as $r) {
            if ((int)$r['CHARACTER_MAXIMUM_LENGTH'] > 0) $tokLen = (int)$r['CHARACTER_MAXIMUM_LENGTH'];
        }
        $w = [
            'token14' => in_array('token_consulta', $cols, true) && $tokLen >= 14,
            'tipo'    => in_array('tipo', $cols, true),
            'origen'  => in_array('origen', $cols, true),
            'depto'   => in_array('departamento', $cols, true),
            'ubic'    => in_array('ubicacion', $cols, true),
        ];
    }
    $campos = ['codigo','token_consulta','solicitante','email','asunto','descripcion','categoria_id','adjunto','estado','prioridad'];
    $vals   = [$d['codigo'], $w['token14'] ? $d['token'] : str_replace('-', '', $d['token']),
               $d['nombre'], $d['email'], $d['asunto'], $d['desc'], $d['catId'], $d['adjunto'], 'nuevo', 'media'];
    if ($w['tipo'])   { $campos[] = 'tipo';         $vals[] = 'Incidencia'; }
    if ($w['origen']) { $campos[] = 'origen';       $vals[] = 'publico';    }
    if ($w['depto'])  { $campos[] = 'departamento'; $vals[] = null;         }
    if ($w['ubic'])   { $campos[] = 'ubicacion';    $vals[] = null;         }
    $ph = implode(',', array_fill(0, count($campos), '?'));
    $pdo->prepare('INSERT INTO tickets (' . implode(',', $campos) . ") VALUES ($ph)")->execute($vals);
    return (int)$pdo->lastInsertId();
}
function fmt_fecha(?string $f): string
{
    return $f ? date('d/m/Y H:i', strtotime($f)) : '';
}

switch ($action) {

    /* ==================== LISTAR CATEGORÍAS ==================== */
    case 'categorias':
        $rows = db()->query(
            "SELECT c.id, c.nombre, c.descripcion, c.icono, c.articulos,
                    (SELECT COUNT(*) FROM tickets t WHERE t.categoria_id = c.id) AS solicitudes
             FROM categorias c ORDER BY c.orden, c.nombre"
        )->fetchAll();
        json_out(['ok' => true, 'data' => $rows]);
        break;

    /* ==================== CREAR TICKET ==================== */
    case 'crear_ticket':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Método no permitido'], 405);

        $nombre   = clean($_POST['nombre'] ?? '', 120);
        $email    = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $asunto   = clean($_POST['asunto'] ?? '', 200);
        $catId    = (int)($_POST['categoria_id'] ?? 0);
        $desc     = clean($_POST['descripcion'] ?? '', 5000);

        $errores = [];
        if (str_len_safe($nombre) < 3)  $errores['nombre']      = 'Indique su nombre completo.';
        if (!$email)                  $errores['email']       = 'Ingrese un correo válido.';
        if (str_len_safe($asunto) < 5)   $errores['asunto']      = 'El asunto debe tener al menos 5 caracteres.';
        if (!$catId)                  $errores['categoria']   = 'Seleccione una categoría.';
        if (str_len_safe($desc) < 15)    $errores['descripcion'] = 'Describa su problema con al menos 15 caracteres.';
        if ($errores) json_out(['ok' => false, 'errors' => $errores], 422);

        // Adjunto opcional
        $adjunto = null;
        if (!empty($_FILES['archivo']['name']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['archivo']['name'], PATHINFO_EXTENSION));
            $permitidas = ['pdf','png','jpg','jpeg','gif','doc','docx','txt','zip'];
            if (in_array($ext, $permitidas) && $_FILES['archivo']['size'] <= MAX_UPLOAD_MB * 1024 * 1024) {
                if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0755, true);
                $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', pathinfo($_FILES['archivo']['name'], PATHINFO_FILENAME));
                $adjunto = substr($safe . '-' . time() . '.' . $ext, 0, 200);
                move_uploaded_file($_FILES['archivo']['tmp_name'], UPLOAD_DIR . '/' . $adjunto);
            }
        }

        $pdo = db();
        $codigo = nuevo_codigo($pdo);
        $token  = nuevo_token();

        try {
            $tid = insert_ticket($pdo, [
                'codigo' => $codigo, 'token' => $token, 'nombre' => $nombre, 'email' => $email,
                'asunto' => $asunto, 'desc' => $desc, 'catId' => $catId ?: null, 'adjunto' => $adjunto,
            ]);
        } catch (PDOException $e) {
            error_log('crear_ticket: ' . $e->getMessage());
            json_out(['ok' => false, 'error' => 'No fue posible registrar la solicitud. Intente nuevamente o contacte al administrador.'], 500);
        }

        $pdo->prepare("INSERT INTO ticket_historial (ticket_id, autor, tipo, mensaje) VALUES (?,?, 'creacion', ?)")
            ->execute([$tid, $nombre, 'Ticket creado automáticamente desde el Portal Ciudadano.']);

        json_out([
            'ok'     => true,
            'mensaje' => 'Su solicitud fue registrada correctamente.',
            'ticket' => [
                'codigo' => $codigo,
                'token'  => $token,
                'estado' => ESTADOS_TICKET['nuevo'] ?? 'Nuevo',
            ],
        ], 201);
        break;

    /* ==================== CONSULTAR TICKET (público) ==================== */
    case 'consultar_ticket':
        $q = strtoupper(clean($_GET['q'] ?? $_POST['q'] ?? '', 40));
        if ($q === '') json_out(['ok' => false, 'error' => 'Ingrese un número de ticket o código de consulta.'], 422);

        $pdo = db();

        /* Detecta el esquema real de la tabla tickets (tolerancia v1/v2/v3):
           si la BD viene de un schema.sql antiguo importado directamente en
           phpMyAdmin, puede no tener token_consulta / tipo / origen / etc. */
        static $colsPub = null;
        if ($colsPub === null) {
            $colsPub = [];
            foreach ($pdo->query('SHOW COLUMNS FROM `tickets`')->fetchAll() as $r) $colsPub[] = $r['Field'];
        }
        $tieneToken = in_array('token_consulta', $colsPub, true);
        $selExtras  = [];
        foreach (['tipo','origen','departamento','ubicacion'] as $x) {
            if (in_array($x, $colsPub, true)) $selExtras[] = "t.$x";
        }

        /* Coincidencia flexible: normaliza mayúsculas/espacios/guiones tanto del
           input como del valor en BD (funciona con tokens CHAR(12) sin guiones). */
        $sql = "SELECT t.*" . ($selExtras ? ', ' . implode(', ', $selExtras) : '') . ",
                       c.nombre AS categoria, u.nombre AS tecnico
                FROM tickets t
                LEFT JOIN categorias c ON c.id = t.categoria_id
                LEFT JOIN usuarios u   ON u.id = t.asignado_a
                WHERE UPPER(REPLACE(t.codigo,' ','')) = ?"
             . ($tieneToken ? " OR REPLACE(UPPER(t.token_consulta),'-','') = REPLACE(UPPER(?),'-','')" : '')
             . " LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute(array_values(array_filter([$q, $tieneToken ? $q : null], fn($v) => $v !== null)));
        $t = $st->fetch();

        if (!$t) json_out(['ok' => false, 'error' => 'No se encontró la solicitud. Verifique el número de ticket o el código de consulta.'], 404);

        $h = $pdo->prepare("SELECT autor, tipo, mensaje, creado_en FROM ticket_historial WHERE ticket_id = ? ORDER BY creado_en ASC, id ASC");
        $h->execute([$t['id']]);

        /* Las notas internas NO se exponen al ciudadano; solo respuestas públicas. */
        $visibles = array_values(array_filter($h->fetchAll(), fn($r) => !in_array($r['tipo'], ['nota_interna','creacion','estado','asignacion'], true)));

        json_out(['ok' => true, 'ticket' => [
            'id'         => (int)$t['id'],
            'codigo'     => $t['codigo'],
            'token'      => $t['token_consulta'] ?? '',
            'solicitante'=> $t['solicitante'],
            'email'      => $t['email'],
            'asunto'     => $t['asunto'],
            'descripcion'=> $t['descripcion'],
            'categoria'  => $t['categoria'] ?? 'Sin categoría',
            'estado'     => ESTADOS_TICKET[estado_normalizado($t['estado'])] ?? ucfirst((string)$t['estado']),
            'prioridad'  => $t['prioridad'] ?? 'media',
            'tecnico'    => $t['tecnico'],
            'creado'     => fmt_fecha($t['creado_en']),
            'actualizado'=> fmt_fecha($t['actualizado_en'] ?? $t['creado_en']),
            'historial'  => array_map(fn($r) => [
                'autor' => $r['autor'],
                'tipo'  => $r['tipo'],
                'mensaje' => $r['mensaje'],
                'fecha' => fmt_fecha($r['creado_en']),
            ], $visibles),
        ]]);
        break;

    /* ==================== COMENTARIO DEL CIUDADANO ==================== */
    case 'comentar_ticket':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Método no permitido'], 405);
        $ref  = strtoupper(clean($_POST['referencia'] ?? '', 40));
        $msg  = clean($_POST['mensaje'] ?? '', 3000);
        if (str_len_safe($msg) < 3 || $ref === '') json_out(['ok' => false, 'error' => 'Mensaje vacío o referencia inválida.'], 422);

        $pdo = db();
        $st = $pdo->prepare("SELECT id, solicitante FROM tickets WHERE codigo = ? OR REPLACE(token_consulta,'-','') = ? LIMIT 1");
        $st->execute([$ref, str_replace('-', '', $ref)]);
        $t = $st->fetch();
        if (!$t) json_out(['ok' => false, 'error' => 'Ticket no encontrado.'], 404);

        $pdo->prepare("INSERT INTO ticket_historial (ticket_id, autor, tipo, mensaje) VALUES (?,?,'ciudadano',?)")
            ->execute([$t['id'], $t['solicitante'], $msg]);
        // tocar actualizado_en
        $pdo->prepare("UPDATE tickets SET actualizado_en = NOW() WHERE id = ?")->execute([$t['id']]);

        json_out(['ok' => true, 'mensaje' => 'Su comentario fue agregado a la solicitud.']);
        break;

    /* ==================== BÚSQUEDA KB (hero) ==================== */
    case 'buscar':
        $term = '%' . clean($_GET['q'] ?? '', 100) . '%';
        $cats = db()->prepare("SELECT id, nombre, descripcion, icono, articulos FROM categorias WHERE nombre LIKE ? OR descripcion LIKE ? ORDER BY orden LIMIT 6");
        $cats->execute([$term, $term]);
        json_out(['ok' => true, 'categorias' => $cats->fetchAll()]);
        break;

    default:
        json_out(['ok' => false, 'error' => 'Acción no reconocida'], 400);
}
