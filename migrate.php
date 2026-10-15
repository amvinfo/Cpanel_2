<?php
/**
 * ============================================================
 * MESA DE AYUDA / HELPDESK — Gobierno de Chile
 * migrate.php  →  Migración segura del esquema a la versión 2
 *                 (Módulo de Gestión de Tickets Admin/Supervisor)
 * ============================================================
 * - NO destruye datos: agrega columnas nuevas y amplía los ENUM
 *   de estado/prioridad/tipo de historial con el vocabulario completo.
 * - Normaliza valores antiguos (en_proceso→en_atencion, critica→urgente).
 * - Ejecutar UNA VEZ desde el navegador y luego ELIMINAR el archivo.
 *   Protegido con clave de administrador.
 *
 * Uso:  https://tudominio.cl/migrate.php?key=CLAVE_ADMIN_DE_BD
 */
require_once __DIR__ . '/config.php';

header('Content-Type: text/html; charset=utf-8');

if (!isset($_GET['key']) || $_GET['key'] !== DB_PASS) {
    http_response_code(403);
    die('<h2>⛔ Acceso denegado</h2><p>Debe ejecutar: <code>migrate.php?key=<b>clave_bd</b></code> (la misma clave definida en config.php). Una vez migrado, elimine este archivo del servidor.</p>');
}

$pdo = db();
$log = [];
$err = 0;


function col_exists(PDO $pdo, string $tabla, string $col): bool
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute([$tabla, $col]);
    return (bool)$st->fetchColumn();
}

function run(PDO $pdo, string $sql, string $desc, array &$log, int &$err): void
{
    try { $pdo->exec($sql); $log[] = "✔ $desc"; }
    catch (PDOException $e) { $log[] = "✖ $desc → " . $e->getMessage(); $err++; }
}

/* ---------- 1. Columnas nuevas en tickets ---------- */
if (!col_exists($pdo, 'tickets', 'tipo')) {
    run($pdo, "ALTER TABLE tickets ADD COLUMN `tipo` VARCHAR(40) DEFAULT NULL AFTER `prioridad`",
        'tickets.tipo agregada', $log, $err);
} else { $log[] = '· tickets.tipo ya existe'; }

if (!col_exists($pdo, 'tickets', 'origen')) {
    run($pdo, "ALTER TABLE tickets ADD COLUMN `origen` ENUM('publico','interno') NOT NULL DEFAULT 'publico' AFTER `tipo`",
        'tickets.origen agregada', $log, $err);
} else { $log[] = '· tickets.origen ya existe'; }

if (!col_exists($pdo, 'tickets', 'departamento')) {
    run($pdo, "ALTER TABLE tickets ADD COLUMN `departamento` VARCHAR(120) DEFAULT NULL AFTER `origen`",
        'tickets.departamento agregada', $log, $err);
} else { $log[] = '· tickets.departamento ya existe'; }

if (!col_exists($pdo, 'tickets', 'ubicacion')) {
    run($pdo, "ALTER TABLE tickets ADD COLUMN `ubicacion` VARCHAR(120) DEFAULT NULL AFTER `departamento`",
        'tickets.ubicacion agregada', $log, $err);
} else { $log[] = '· tickets.ubicacion ya existe'; }

if (!col_exists($pdo, 'tickets', 'creado_por')) {
    run($pdo, "ALTER TABLE tickets ADD COLUMN `creado_por` INT UNSIGNED DEFAULT NULL AFTER `asignado_a`",
        'tickets.creado_por agregada', $log, $err);
    run($pdo, "ALTER TABLE tickets ADD CONSTRAINT `fk_ticket_creador` FOREIGN KEY (`creado_por`) REFERENCES `usuarios`(`id`) ON DELETE SET NULL",
        'FK creado_por creada', $log, $err);
} else { $log[] = '· tickets.creado_por ya existe'; }

/* ---------- 1b. Enschar token_consulta CHAR(12) -> VARCHAR(14) (esquema v1) ---------- */
try {
    $r = $pdo->query("SELECT CHARACTER_MAXIMUM_LENGTH l, DATA_TYPE t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'token_consulta'")->fetch(PDO::FETCH_ASSOC);
    if ($r && strtolower($r['t']) === 'char' && (int)$r['l'] < 14) {
        run($pdo, "ALTER TABLE tickets MODIFY `token_consulta` VARCHAR(14) NOT NULL",
            'tickets.token_consulta ampliada a VARCHAR(14) (tokens con guiones)', $log, $err);
    } else { $log[] = '· tickets.token_consulta ya admite tokens de seguimiento (VARCHAR)'; }
} catch (PDOException $e) { $log[] = '· token_consulta: no verificable (' . $e->getMessage() . ')'; }

/* ---------- 2. Ampliar ticket_historial.tipo (incl. 'comentario' temporal) ---------- */
/* Al ser superconjunto del vocabulario antiguo, nunca produce truncamiento. */
run($pdo, "ALTER TABLE ticket_historial MODIFY `tipo` ENUM('respuesta','nota_interna','ciudadano','estado','asignacion','creacion','comentario') NOT NULL DEFAULT 'respuesta'",
    'ticket_historial.tipo ampliado (incluye nota_interna)', $log, $err);

/* ---------- 3. Ampliar tickets.estado/prioridad con técnica de valor temporal ---------- */
$estados = array_keys(ESTADOS_TICKET);                       // 11 estados v2
$prios   = array_keys(PRIORIDADES_TICKET);                   // baja/media/alta/urgente
$extra   = ['en_proceso', 'critica'];                        // valores legacy aún presentes

$allEstados = array_unique(array_merge($estados, $extra)); // v2 + legacy posibles

try {
    /* 3.1 ENUM universal: vocabulario v2 + valores legacy + marcador temporal.
          Al ser superconjunto del estado anterior, NUNCA produce truncamiento. */
    $pdo->exec("ALTER TABLE tickets MODIFY `estado` ENUM('" . implode("','", $allEstados) . "','__tmp__') NOT NULL DEFAULT 'abierto'");
    /* 3.2 mapear valores ajenos (si existieran) al marcador */
    $pdo->exec("UPDATE tickets SET estado = '__tmp__' WHERE estado NOT IN ('" . implode("','", $allEstados) . "')");
    /* 3.3 normalizar legacy -> v2 */
    $pdo->exec("UPDATE tickets SET estado = 'en_atencion' WHERE estado = '__tmp__' OR estado = 'en_proceso'");
    $pdo->exec("UPDATE tickets SET estado = 'abierto'     WHERE estado = 'asignado' AND asignado_a IS NULL");
    /* 3.4 aplicar el ENUM definitivo v2 (ya sin datos legacy) */
    $pdo->exec("ALTER TABLE tickets MODIFY `estado` ENUM('" . implode("','", $estados) . "') NOT NULL DEFAULT 'nuevo'");
    $log[] = '✔ tickets.estado migrado a 11 estados (legacy en_proceso → en_atencion) sin pérdida de filas';
} catch (PDOException $e) {
    $log[] = '✖ tickets.estado → ' . $e->getMessage(); $err++;
}

try {
    $pdo->exec("ALTER TABLE tickets MODIFY `prioridad` ENUM('" . implode("','", array_unique(array_merge($prios, $extra))) . "','__tmp__') NOT NULL DEFAULT 'media'");
    $pdo->exec("UPDATE tickets SET prioridad = '__tmp__' WHERE prioridad NOT IN ('" . implode("','", array_unique(array_merge($prios, $extra))) . "')");
    $pdo->exec("UPDATE tickets SET prioridad = 'urgente' WHERE prioridad = '__tmp__' OR prioridad = 'critica'");
    $pdo->exec("ALTER TABLE tickets MODIFY `prioridad` ENUM('" . implode("','", $prios) . "') NOT NULL DEFAULT 'media'");
    $log[] = '✔ tickets.prioridad migrado (critica → urgente) sin pérdida de filas';
} catch (PDOException $e) {
    $log[] = '✖ tickets.prioridad → ' . $e->getMessage(); $err++;
}

try {
    $pdo->exec("UPDATE ticket_historial SET tipo = 'respuesta' WHERE tipo = 'comentario'");
    $pdo->exec("ALTER TABLE ticket_historial MODIFY `tipo` ENUM('respuesta','nota_interna','ciudadano','estado','asignacion','creacion') NOT NULL DEFAULT 'respuesta'");
    $log[] = '✔ ticket_historial.tipo normalizado (comentario → respuesta)';
} catch (PDOException $e) {
    $log[] = '· historial.tipo: ' . $e->getMessage();
}

/* ---------- 4. Índices auxiliares ---------- */
try { $pdo->exec("ALTER TABLE tickets ADD KEY `idx_prioridad` (`prioridad`)"); $log[] = '✔ índice idx_prioridad creado'; }
catch (PDOException $e) { $log[] = '· idx_prioridad ya existe o no aplicable'; }

/* =====================================================================
   v3 — Base de Conocimiento, Administración del Sistema, Perfil, Auditoría
   (migración idempotente: puede volver a ejecutarse sin errores)
   ===================================================================== */
$V3_FILE = __DIR__ . '/schema_v3.sql';
if (is_file($V3_FILE)) {
    /* Divisor seguro: solo "." en una línea sola (formato phpMyAdmin).
       Así nunca se corta una sentencia multi-línea ni se confunde con
       un ";" dentro de literales SQL. */
    $sqlv3 = str_replace("\r\n", "\n", file_get_contents($V3_FILE));
    if (strpos($sqlv3, "\nDELIMITER $$\n") !== false) {
        $sqlv3 = preg_replace('/^\s*DELIMITER\s+\$\$\s*$/mi', ';', $sqlv3);
        $sqlv3 = preg_replace('/^\s*END\s*\$/mi', 'END;', $sqlv3);
    }
    foreach (explode("\n.\n", $sqlv3) as $stmtv3) {
        $stmtv3 = trim(preg_replace('/^--.*$/m', '', $stmtv3));
        if ($stmtv3 === '') continue;
        try {
            $pdo->exec($stmtv3);
            $log[] = '✔ schema_v3: ' . substr(preg_replace('/\s+/', ' ', $stmtv3), 0, 60) . '...';
        } catch (PDOException $e) {
            $code = (string) ($e->errorInfo[1] ?? '');
            // 1050 tabla existe · 1060 columna existe · 1061 índice existe
            // 1091 no existe · 1826 FK existe · 1054 columna desconocida (AFTER)
            if (in_array($code, ['1050', '1060', '1061', '1091', '1826', '1054'], true)) {
                $log[] = '· schema_v3 omitida (ya aplicada): ' . substr(preg_replace('/\s+/', ' ', $stmtv3), 0, 60) . '...';
            } else {
                $log[] = '✖ schema_v3 → ' . $e->getMessage();
                $err++;
            }
        }
    }
} else {
    $log[] = 'X No se encontro schema_v3.sql junto a migrate.php (modulos v3 no instalados).';
    $err++;
}

?>
<!DOCTYPE html>
<html lang="es-CL">
<head>
<meta charset="UTF-8"><title>Migración · Helpdesk Gob.cl</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
<style>
 body{font-family:Inter,system-ui,sans-serif;background:#F5F7FA;color:#1E2A3A;margin:0;padding:2rem}
 .card{max-width:760px;margin:0 auto;background:#fff;border-radius:14px;box-shadow:0 10px 30px rgba(0,43,73,.12);padding:2rem}
 h1{color:#0B3775;font-size:1.4rem;margin-top:0}
 li{margin:.3rem 0;font-size:.92rem}
 .ok{background:#E6F4EE;border-left:5px solid #1B7F4D;padding:1rem 1.2rem;border-radius:8px;font-weight:700;color:#0B4A2C}
 .fail{background:#FCEBE9;border-left:5px solid #C62828;padding:1rem 1.2rem;border-radius:8px;font-weight:700;color:#7A1911}
 code{background:#EEF2F7;padding:.1rem .4rem;border-radius:4px}
</style>
</head>
<body>
<div class="card">
  <h1>🛠️ Migración — Módulo de Gestión de Tickets v2</h1>
  <ul><?php foreach ($log as $l) echo '<li>' . htmlspecialchars($l) . '</li>'; ?></ul>
  <?php if ($err === 0): ?>
    <p class="ok">✅ Migración completada sin errores. El módulo de tickets (Admin/Supervisor) ya puede usarse.</p>
  <?php else: ?>
    <p class="fail">⚠️ Se detectaron <?= $err ?> error(es). Revise el detalle y vuelva a ejecutar (es idempotente).</p>
  <?php endif; ?>
  <p><strong>Importante:</strong> por seguridad, <code>elimine el archivo migrate.php</code> del servidor después de migrar.</p>
</div>
</body>
</html>
