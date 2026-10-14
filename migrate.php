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

/* ---------- 2. Ampliar ENUMs ---------- */
run($pdo, "ALTER TABLE tickets MODIFY `estado` ENUM('" . implode("','", array_keys(ESTADOS_TICKET)) . "') NOT NULL DEFAULT 'nuevo'",
    'tickets.estado ampliado a 11 estados', $log, $err);
run($pdo, "ALTER TABLE tickets MODIFY `prioridad` ENUM('" . implode("','", array_keys(PRIORIDADES_TICKET)) . "') NOT NULL DEFAULT 'media'",
    'tickets.prioridad ampliado (baja/media/alta/urgente)', $log, $err);
run($pdo, "ALTER TABLE ticket_historial MODIFY `tipo` ENUM('respuesta','nota_interna','ciudadano','estado','asignacion','creacion') NOT NULL DEFAULT 'respuesta'",
    'ticket_historial.tipo ampliado (nota_interna)', $log, $err);

/* ---------- 3. Normalizar datos legacy ---------- */
$n1 = $pdo->exec("UPDATE tickets SET estado = 'en_atencion' WHERE estado = 'en_proceso'");
$n2 = $pdo->exec("UPDATE tickets SET estado = 'abierto'    WHERE estado = 'asignado' AND asignado_a IS NULL");
$n3 = $pdo->exec("UPDATE tickets SET prioridad = 'urgente' WHERE prioridad = 'critica'");
$n4 = $pdo->exec("UPDATE ticket_historial SET tipo = 'respuesta' WHERE tipo = 'comentario'");
$log[] = "· Datos normalizados: $n1 ticket(s) 'en_proceso'→'en_atencion', $n3 'critica'→'urgente', $n4 comentario(s)→respuesta.";

/* ---------- 4. Índices auxiliares ---------- */
try { $pdo->exec("ALTER TABLE tickets ADD KEY `idx_prioridad` (`prioridad`)"); $log[] = '✔ índice idx_prioridad creado'; }
catch (PDOException $e) { $log[] = '· idx_prioridad ya existe o no aplicable'; }

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
