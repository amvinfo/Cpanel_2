<?php
/**
 * =====================================================================
 * MESA DE AYUDA / HELPDESK — Gobierno de Chile
 * install.php  →  Instalador completo del sistema (asistente web)
 * =====================================================================
 * USO EN cPanel:
 *   1. Suba TODOS los archivos del sistema a public_html.
 *   2. Cree la base de datos, el usuario y asígnele privilegios
 *      en cPanel → "Bases de datos MySQL".
 *   3. Abra en el navegador:  https://midominio.cl/install.php
 *   4. Complete el formulario y pulse "Instalar / Ejecutar".
 *   5. Al finalizar, ELIMINE este archivo por seguridad (el propio
 *      instalador ofrece un botón para generar un .htaccess que lo
 *      bloquea, o simplemente bórelo desde el Administrador de Archivos).
 *
 * El instalador:
 *   - Verifica requisitos (PHP >= 7.4, PDO MySQL, mbstring, permisos).
 *   - Genera/actualiza config.php con sus credenciales.
 *   - Crea las tablas ejecutando schema.sql (si existe) o mediante
 *     sentencias embebidas (modo autónomo).
 *   - Re-hashea las contraseñas semilla con bcrypt REAL del servidor
 *     (admin/admin123 · supervisor/admin123 · tecnico/admin123).
 *   - Inserta datos de ejemplo (categorías, tickets, historial) si se
 *     solicita "instalación con datos de demostración".
 *   - Crea la carpeta uploads/ con los permisos adecuados.
 *   - Escribe instalado.lock para impedir re-ejecuciones accidentales.
 * =====================================================================
 */

error_reporting(E_ALL);
ini_set('display_errors', '1'); // durante la instalación SÍ mostramos errores

define('BASE_DIR', __DIR__);
define('LOCK_FILE', BASE_DIR . '/instalado.lock');
define('CONFIG_FILE', BASE_DIR . '/config.php');
define('SCHEMA_FILE', BASE_DIR . '/schema.sql');

$seedHash = password_hash('admin123', PASSWORD_BCRYPT, ['cost' => 10]);

/* ------------------------------------------------------------------ */
/*  REQUISITOS                                                         */
/* ------------------------------------------------------------------ */
function check_requirements(): array
{
    $req = [];
    $req[] = ['PHP >= 7.4', version_compare(PHP_VERSION, '7.4.0', '>='), PHP_VERSION];
    $req[] = ['Extensión PDO MySQL', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? 'activa' : 'NO encontrada'];
    $req[] = ['Extensión mbstring (recomendada)', extension_loaded('mbstring'), extension_loaded('mbstring') ? 'activa' : 'opcional (no crítica)'];
    writable_check(BASE_DIR, $req);
    $upDir = BASE_DIR . '/uploads';
    if (!is_dir($upDir)) { @mkdir($upDir, 0755, true); }
    writable_check($upDir, $req);
    return $req;
}
function writable_check(string $path, array &$req): void
{
    $ok = is_dir($path) && is_writable($path);
    $req[] = ["Escribible: " . str_replace(BASE_DIR . '/', '', $path) . '/', $ok, $ok ? 'permisos OK' : 'sin permiso de escritura (chmod 755/775)'];
}

/* ------------------------------------------------------------------ */
/*  GENERADOR DE config.php                                            */
/* ------------------------------------------------------------------ */
function write_config(array $d): bool
{
    $t = static function ($v) { return str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $v); };
    $php = <<<PHPCONF
<?php
/**
 * ============================================================
 * MESA DE AYUDA / HELPDESK — Gobierno de Chile
 * config.php  →  Configuración única de conexión a la BD
 * ============================================================
 * Generado por install.php el {$d['fecha']}
 * Si necesita cambiar credenciales, edite este archivo
 * o vuelva a usar el instalador (con INSTALL_FORCE=1).
 */

// -------- Base de datos --------
define('DB_HOST', '{$t($d['host'])}');
define('DB_NAME', '{$t($d['name'])}');
define('DB_USER', '{$t($d['user'])}');
define('DB_PASS', '{$t($d['pass'])}');
define('DB_CHARSET', 'utf8mb4');

// -------- Aplicación --------
define('APP_NAME', 'Helpdesk | Gobierno de Chile');
define('APP_URL', '{$t($d['url'])}'); // opcional: https://midominio.cl (sin "/" final)
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('MAX_UPLOAD_MB', 10);

// Show errors only while developing (poner false en producción)
define('APP_DEBUG', false);

error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');

/**
 * Conexión PDO singleton.
 */
function db(): PDO
{
    static \$pdo = null;
    if (\$pdo === null) {
        \$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        try {
            \$pdo = new PDO(\$dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException \$e) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => APP_DEBUG ? \$e->getMessage() : 'Error de conexión a la base de datos. Revise config.php']);
            exit;
        }
    }
    return \$pdo;
}

/**
 * Inicia sesión segura (solo para el panel interno).
 */
function app_session_start(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
        ]);
    }
}

/** JSON response helper */
function json_out(array \$data, int \$code = 200): void
{
    http_response_code(\$code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(\$data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Sanitize + truncate */
function clean(?string \$s, int \$max = 2000): string
{
    \$s = trim(strip_tags((string) \$s));
    if (function_exists('mb_substr')) {
        return mb_substr(\$s, 0, \$max);
    }
    return substr(\$s, 0, \$max); // fallback si el hosting no tiene ext-mbstring
}

/** Longitud segura de un string (multi-byte aware con fallback) */
function str_len_safe(string \$s): int
{
    return function_exists('mb_strlen') ? mb_strlen(\$s) : strlen(\$s);
}

PHPCONF;
    return file_put_contents(CONFIG_FILE, $php) !== false;
}

/* ------------------------------------------------------------------ */
/*  CONEXIÓN DE PRUEBA (antes de escribir config)                      */
/* ------------------------------------------------------------------ */
function test_connection(array $d): PDO
{
    $dsn = 'mysql:host=' . $d['host'] . ';charset=utf8mb4';
    if (!empty($d['name'])) {
        $dsn = 'mysql:host=' . $d['host'] . ';dbname=' . $d['name'] . ';charset=utf8mb4';
    }
    $pdo = new PDO($dsn, $d['user'], $d['pass'], [
        PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

/* ------------------------------------------------------------------ */
/*  ESQUEMA AUTÓNOMO (por si schema.sql no está disponible)            */
/* ------------------------------------------------------------------ */
function run_embedded_schema(PDO $pdo, string $hash, bool $demo): array
{
    $log = [];
    $stmts = [
        "CREATE TABLE IF NOT EXISTS `usuarios` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `nombre` VARCHAR(120) NOT NULL,
          `usuario` VARCHAR(60) NOT NULL UNIQUE,
          `password_hash` VARCHAR(255) NOT NULL,
          `email` VARCHAR(150) DEFAULT NULL,
          `rol` ENUM('admin','supervisor','tecnico') NOT NULL DEFAULT 'tecnico',
          `activo` TINYINT(1) NOT NULL DEFAULT 1,
          `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `categorias` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `nombre` VARCHAR(100) NOT NULL UNIQUE,
          `descripcion` VARCHAR(255) DEFAULT NULL,
          `icono` VARCHAR(40) DEFAULT 'soporte',
          `articulos` INT UNSIGNED NOT NULL DEFAULT 0,
          `orden` INT NOT NULL DEFAULT 0,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `tickets` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `codigo` VARCHAR(30) NOT NULL UNIQUE,
          `token_consulta` VARCHAR(14) NOT NULL UNIQUE,
          `solicitante` VARCHAR(120) NOT NULL,
          `email` VARCHAR(150) NOT NULL,
          `asunto` VARCHAR(200) NOT NULL,
          `descripcion` TEXT NOT NULL,
          `categoria_id` INT UNSIGNED DEFAULT NULL,
          `estado` ENUM('nuevo','abierto','asignado','en_atencion','esperando_solicitante','esperando_tercero','escalado','resuelto','cerrado','reabierto','cancelado') NOT NULL DEFAULT 'nuevo',
          `prioridad` ENUM('baja','media','alta','urgente') NOT NULL DEFAULT 'media',
          `tipo` VARCHAR(40) DEFAULT NULL,
          `origen` ENUM('publico','interno') NOT NULL DEFAULT 'publico',
          `departamento` VARCHAR(120) DEFAULT NULL,
          `ubicacion` VARCHAR(120) DEFAULT NULL,
          `asignado_a` INT UNSIGNED DEFAULT NULL,
          `creado_por` INT UNSIGNED DEFAULT NULL,
          `adjunto` VARCHAR(255) DEFAULT NULL,
          `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `actualizado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_estado` (`estado`),
          KEY `idx_prioridad` (`prioridad`),
          KEY `idx_categoria` (`categoria_id`),
          KEY `idx_asignado` (`asignado_a`),
          CONSTRAINT `fk_ticket_categoria` FOREIGN KEY (`categoria_id`) REFERENCES `categorias`(`id`) ON DELETE SET NULL,
          CONSTRAINT `fk_ticket_usuario` FOREIGN KEY (`asignado_a`) REFERENCES `usuarios`(`id`) ON DELETE SET NULL,
          CONSTRAINT `fk_ticket_creador` FOREIGN KEY (`creado_por`) REFERENCES `usuarios`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `ticket_historial` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `ticket_id` INT UNSIGNED NOT NULL,
          `autor` VARCHAR(120) NOT NULL,
          `tipo` ENUM('respuesta','nota_interna','ciudadano','estado','asignacion','creacion') NOT NULL DEFAULT 'respuesta',
          `mensaje` TEXT NOT NULL,
          `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_ticket` (`ticket_id`),
          CONSTRAINT `fk_hist_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($stmts as $s) {
        $tbl = preg_match('/`(\\w+)`/', $s, $m) ? $m[1] : '?';
        $pdo->exec($s);
        $log[] = "Tabla `$tbl` creada/verificada.";
    }

    /* usuarios semilla (bcrypt real del servidor) */
    $u = $pdo->prepare("INSERT INTO usuarios (nombre, usuario, password_hash, email, rol)
                        VALUES (?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), nombre = VALUES(nombre)");
    $semillas = [
        ['María González', 'admin',      $hash, 'admin@helpdesk.gob.cl',      'admin'],
        ['Ana Riquelme',   'supervisor', $hash, 'supervisor@helpdesk.gob.cl', 'supervisor'],
        ['Carlos Muñoz',   'tecnico',    $hash, 'tecnico@helpdesk.gob.cl',    'tecnico'],
    ];
    foreach ($semillas as $row) { $u->execute($row); }
    $log[] = "Usuarios semilla listos → admin / supervisor / tecnico (clave: admin123).";

    if ($demo) {
        $c = $pdo->prepare("INSERT IGNORE INTO categorias (nombre, descripcion, icono, articulos, orden) VALUES (?,?,?,?,?)");
        foreach ([
            ['Conectividad', 'Red, Internet, VPN y caídas de servicio', 'wifi', 14, 1],
            ['Cuentas y Accesos', 'Alturas de sistema, contraseñas, permisos', 'lock', 21, 2],
            ['Soporte Técnico', 'Software, correo, impresoras y aplicaciones', 'soporte', 33, 3],
            ['Hardware', 'Equipos, periféricos e insumos', 'hardware', 12, 4],
            ['Seguridad Informática', 'Phishing, incidentes y buenas prácticas', 'shield', 9, 5],
            ['Correo Institucional', 'Buzón, reenvíos y listas de distribución', 'correo', 18, 6],
        ] as $r) { $c->execute($r); }
        $log[] = "Categorías de demostración insertadas.";

        $t = $pdo->prepare("INSERT IGNORE INTO tickets
            (codigo, token_consulta, solicitante, email, asunto, descripcion, categoria_id, estado, prioridad, tipo, origen, departamento, ubicacion, asignado_a, creado_por, creado_en)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $demoTickets = [
            ['TK-AB12CD34', 'A7X3-K9P2-M1Q4', 'Juan Pérez', 'juan.perez@correo.gob.cl', 'No puedo ingresar al sistema de RRHH', 'Al intentar autenticarme en el portal de RRHH el sistema muestra el mensaje de autenticacion invalida pese a que mi clave es válida en el resto de los sistemas internos.', 2, 'asignado', 'alta', 'Incidencia', 'publico', 'Dirección de Operaciones', 'Edificio Central — Piso 2', 3, null, '2026-10-13 09:12:00'],
            ['TK-EF56GH78', 'B2M8-N5Q1-R7T9', 'Karla Díaz', 'karla.diaz@correo.gob.cl', 'Problemas de acceso y contraseñas', 'Necesito restablecer mi contraseña porque recibí una alerta de actividad sospechosa en mi cuenta.', 2, 'en_atencion', 'media', 'Solicitud de acceso', 'publico', 'Unidad de Personas', 'Remoto / Teletrabajo', 3, null, '2026-10-13 11:40:00'],
            ['TKT-2026-00013', 'C9F4-H2J6-K8L1', 'Ana Soto', 'ana.soto@correo.gob.cl', 'Corte de conectividad VPN', 'La VPN se desconecta cada 10 minutos desde el lunes, impidiendo teletrabajo.', 1, 'escalado', 'urgente', 'Incidencia', 'publico', 'Dirección de Planificación', 'Anexo A', 3, null, '2026-10-13 08:05:00'],
            ['TK-IJ90KL12', 'D3G7-S1A5-P0Q8', 'Luis Fuentes', 'luis.fuentes@correo.gob.cl', 'Solicitud de altura de sistema SIAC', 'Se requiere dar de baja a dos funcionarios retirados.', 2, 'resuelto', 'media', 'Solicitud de acceso', 'publico', 'Dirección de RRHH', 'Edificio Central — Piso 1', 2, null, '2026-10-08 15:22:00'],
            ['TK-MN34OP56', 'E5R2-T8Y4-U6I0', 'Carla Núñez', 'carla.nunez@correo.gob.cl', 'Monitor con parpadeos', 'El monitor del puesto 14 parpadea, podría ser drivers o cable HDMI.', 4, 'cerrado', 'baja', 'Incidencia', 'publico', 'Dirección de Finanzas', 'Edificio Central — Piso 3', 3, null, '2026-10-05 10:00:00'],
            ['TK-QR78ST90', 'F8Q1-W3E5-R7T2', 'Pedro Salas', 'pedro.salas@correo.gob.cl', 'Posible phishing recibido', 'Llegó un correo falso de Tesorería Nacional pidiendo clave. No hice clic, pero aviso.', 5, 'esperando_solicitante', 'alta', 'Consulta', 'publico', 'Unidad de Auditoría', 'Remoto / Teletrabajo', 2, null, '2026-10-13 16:45:00'],
            ['TK-INT01AB', 'I2N4-T6E8-R10A', 'Administrador del Sistema', 'admin@mesadeayuda.cl', 'Renovación de certificados SSL del portal', 'Los certificados del portal institucional vencen el 30-10-2026. Se debe coordinar renovación e instalación antes de esa fecha.', 3, 'nuevo', 'urgente', 'Mantención', 'interno', 'Dirección de Operaciones', 'Edificio Central — Piso 1', 3, 1, '2026-10-14 10:05:00'],
            ['TK-INT02CD', 'Z9Y8-X7W6-V5U4', 'Supervisor de Mesa', 'supervisor@helpdesk.gob.cl', 'Auditoría mensual de accesos privilegiados', 'Corresponde ejecutar la revisión trimestral de cuentas con permisos elevados en los sistemas financieros.', 2, 'nuevo', 'media', 'Requerimiento', 'interno', 'Unidad de Auditoría', 'Edificio Central — Piso 2', null, 2, '2026-10-14 11:30:00'],
        ];
        foreach ($demoTickets as $r) { $t->execute($r); }
        $log[] = "Tickets de demostración insertados (incluye TK-AB12CD34 y TKT-2026-00013).";

        $h = $pdo->prepare("INSERT INTO ticket_historial (ticket_id, autor, tipo, mensaje, creado_en)
                            SELECT id, ?, ?, ?, ? FROM tickets WHERE codigo = ?
                            AND NOT EXISTS (SELECT 1 FROM ticket_historial WHERE ticket_id = tickets.id LIMIT 1)");
        foreach ([
            ["TK-AB12CD34", 'Sistema', 'creacion', 'Ticket creado automáticamente desde el Portal Ciudadano.', '2026-10-13 09:12:00'],
            ["TK-EF56GH78", 'Sistema', 'creacion', 'Ticket creado automáticamente desde el Portal Ciudadano.', '2026-10-13 11:40:00'],
            ["TKT-2026-00013", 'Sistema', 'creacion', 'Ticket creado automáticamente desde el Portal Ciudadano.', '2026-10-13 08:05:00'],
            ["TK-IJ90KL12", 'Sistema', 'estado', 'Estado cambiado a Resuelto.', '2026-10-08 17:05:00'],
            ["TK-MN34OP56", 'Sistema', 'estado', 'Estado cambiado a Cerrado.', '2026-10-07 09:00:00'],
            ["TK-QR78ST90", 'Sistema', 'creacion', 'Ticket creado automáticamente desde el Portal Ciudadano.', '2026-10-13 16:45:00'],
            ["TK-INT01AB", 'María González', 'creacion', 'Ticket interno creado desde el Panel de Administración.', '2026-10-14 10:05:00'],
            ["TK-INT02CD", 'Ana Riquelme', 'creacion', 'Ticket interno creado desde el Panel de Administración.', '2026-10-14 11:30:00'],
        ] as $r) { $h->execute([$r[1], $r[2], $r[3], $r[4], $r[0]]); }

        /* historial enriquecido (notas internas + respuestas) para el ticket de referencia */
        $refId = $pdo->query("SELECT id FROM tickets WHERE codigo = 'TK-AB12CD34'")->fetchColumn();
        if ($refId) {
            $h2 = $pdo->prepare("INSERT INTO ticket_historial (ticket_id, autor, tipo, mensaje, creado_en) VALUES (?,?,?,?,?)");
            $h2->execute([$refId, 'Ana Riquelme', 'asignacion', 'Asignado a Carlos Muñoz', '2026-10-14 11:49:44']);
            $h2->execute([$refId, 'Carlos Muñoz', 'nota_interna', 'El perfil de RRHH quedó desincronizado tras el cambio de dominio. Verificar con el proveedor AD antes de responder.', '2026-10-14 12:05:00']);
            $h2->execute([$refId, 'Carlos Muñoz', 'respuesta', 'Estimado Juan: restablecimos la sincronización de su cuenta. Por favor intente ingresar nuevamente y confirme si el acceso funciona.', '2026-10-14 12:20:00']);
        }
        $log[] = "Historial, respuestas y notas internas de demostración insertadas.";
    }

    return $log;
}

/* ------------------------------------------------------------------ */
/*  EJECUTOR DE schema.sql (si existe el archivo)                      */
/* ------------------------------------------------------------------ */
function run_sql_file(PDO $pdo, string $file): array
{
    $sql = file_get_contents($file);
    if ($sql === false) throw new RuntimeException('No se pudo leer ' . $file);
    $log = [];
    // separador seguro: solo líneas ";" solas (nuestro schema lo usa)
    $parts = preg_split('/^\s*;\s*$/m', $sql);
    $ejecutadas = 0;
    foreach ($parts as $p) {
        $p = trim(preg_replace('/^--.*$/m', '', $p)); // quitar comentarios de línea
        if ($p === '') continue;
        $pdo->exec($p);
        $ejecutadas++;
    }
    $log[] = "schema.sql ejecutado correctamente ($ejecutadas sentencias).";
    return $log;
}

/* ------------------------------------------------------------------ */
/*  POST-INSTALACIÓN: re-hash bcrypt + conteos                         */
/* ------------------------------------------------------------------ */
function post_install(PDO $pdo, string $hash): array
{
    $log = [];
    $pdo->exec("UPDATE usuarios SET password_hash = '$hash' WHERE usuario IN ('admin','supervisor','tecnico')");
    $log[] = "Contraseñas semilla re-hasheadas con bcrypt real de este servidor.";

    $n = (int) $pdo->query("SELECT COUNT(*) FROM tickets")->fetchColumn();
    $log[] = "Verificación final: " . $n . " ticket(s), "
        . (int) $pdo->query("SELECT COUNT(*) FROM categorias")->fetchColumn() . " categoría(s), "
        . (int) $pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn() . " usuario(s) en base de datos.";
    return $log;
}

/* ------------------------------------------------------------------ */
/*  MANEJO DE PETICIONES                                               */
/* ------------------------------------------------------------------ */
$errores  = [];
$resultados = [];
$etapa = '';

$yaInstalado = is_file(LOCK_FILE);
$fuerzaReinstall = isset($_GET['force']) || !empty($_POST['force']);

/* Bloqueo opcional de install.php mediante .htaccess (desde la pantalla de éxito) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['htaccess_block'])) {
    $ht = BASE_DIR . '/.htaccess';
    $regla = "\n# --- Bloqueo del instalador (generado por install.php) ---\n<Files \"install.php\">\n  Require all denied\n</Files>\n# ---------------------------------------------------------------\n";
    $actual = is_file($ht) ? file_get_contents($ht) : '';
    if (strpos($actual, '<Files "install.php">') !== false) {
        $resultados[] = '.htaccess ya contenía el bloqueo de install.php.';
    } elseif (@file_put_contents($ht, $actual . $regla) !== false) {
        $resultados[] = '.htaccess creado: install.php queda bloqueado desde el navegador. Ya puede borrar el archivo sin riesgo.';
    } else {
        $errores[] = 'No se pudo escribir .htaccess (revise permisos). Borre install.php manualmente.';
    }
    $etapa = 'bloqueo';
}

if ($yaInstalado && !$fuerzaReinstall && $etapa !== 'bloqueo') {
    // mostrar advertencia con opción de forzar
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $etapa !== 'bloqueo') {
    $etapa = 'instalacion';

    $d = [
        'host' => trim($_POST['db_host'] ?? 'localhost'),
        'name' => trim($_POST['db_name'] ?? ''),
        'user' => trim($_POST['db_user'] ?? ''),
        'pass' => (string) ($_POST['db_pass'] ?? ''),
        'url'  => rtrim(trim($_POST['app_url'] ?? ''), '/'),
        'fecha' => date('d/m/Y H:i'),
    ];
    $demo   = !empty($_POST['datos_demo']);
    $usarSchema = !empty($_POST['usar_schema']) && is_file(SCHEMA_FILE);
    $adminPass = (string) ($_POST['admin_pass'] ?? '');
    $hash = $seedHash;
    if ($adminPass !== '' && strlen($adminPass) >= 6) { // strlen (no mb_*): el instalador debe funcionar sin mbstring
        $hash = password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 10]);
    }

    if ($d['name'] === '' || $d['user'] === '') {
        $errores[] = 'Complete al menos: host, base de datos y usuario MySQL.';
    } else {
        try {
            $pdo = test_connection($d);
            $resultados[] = 'Conexión MySQL establecida (' . htmlspecialchars($pdo->query('SELECT VERSION()')->fetchColumn()) . ').';

            if (!$usarSchema) {
                // crear BD si el usuario tiene permisos y no existe
                try { $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $d['name']) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); } catch (Throwable $e) { /* cPanel ya la crea */ }
            }
            $pdo->exec('USE `' . str_replace('`', '', $d['name']) . '`');

            if ($usarSchema) {
                $resultados = array_merge($resultados, run_sql_file($pdo, SCHEMA_FILE));
            } else {
                $resultados = array_merge($resultados, run_embedded_schema($pdo, $hash, $demo));
            }
            $resultados = array_merge($resultados, post_install($pdo, $hash));

            if (write_config($d)) {
                $resultados[] = 'config.php generado con sus credenciales.';
            } else {
                $errores[] = 'No se pudo escribir config.php (revise permisos del directorio).';
            }

            if (!is_dir(BASE_DIR . '/uploads')) mkdir(BASE_DIR . '/uploads', 0755, true);
            @chmod(BASE_DIR . '/uploads', 0755);
            $resultados[] = 'Carpeta uploads/ verificada (0755).';

            if (empty($errores)) {
                file_put_contents(LOCK_FILE, "Instalado: " . date('c') . "\nBorre este archivo para reinstalar.\n");
                $resultados[] = 'Lock de instalación creado (instalado.lock).';
                $etapa = 'exito';
            }
        } catch (PDOException $e) {
            $errores[] = 'Error de base de datos: ' . $e->getMessage();
        } catch (Throwable $e) {
            $errores[] = 'Error: ' . $e->getMessage();
        }
    }
}

$reqs = check_requirements();
$todoOk = true;
foreach ($reqs as $r) { if (!$r[1] && strpos($r[0], 'recomendada') === false) $todoOk = false; }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Instalador — Helpdesk Gobierno de Chile</title>
<style>
  :root{--azul:#002B49;--azul2:#0039A6;--rojo:#D52B1E;--gris:#F4F6F8;--verde:#00642E;--blanco:#fff}
  *{box-sizing:border-box;margin:0;padding:0;font-family:"Inter","Segoe UI",system-ui,sans-serif}
  body{background:var(--gris);color:#262626;min-height:100vh}
  .barra{background:var(--azul);color:#fff;padding:10px 24px;display:flex;align-items:center;gap:12px;border-bottom:4px solid var(--rojo)}
  .barra b{letter-spacing:.5px}
  .escudo{width:34px;height:34px;border-radius:50%;background:#fff;color:var(--azul);display:grid;place-items:center;font-weight:800}
  .wrap{max-width:860px;margin:32px auto;padding:0 16px}
  h1{font-size:26px;color:var(--azul);margin-bottom:4px}
  .sub{color:#5b6570;margin-bottom:24px}
  .card{background:#fff;border-radius:12px;box-shadow:0 2px 10px rgba(0,43,73,.08);padding:24px;margin-bottom:20px}
  .card h2{font-size:16px;color:var(--azul2);margin-bottom:14px;text-transform:uppercase;letter-spacing:.6px}
  table.reqs{width:100%;border-collapse:collapse;font-size:14px}
  table.reqs td{padding:8px 6px;border-bottom:1px solid #e8edf2}
  .ok{color:var(--verde);font-weight:700}.no{color:var(--rojo);font-weight:700}.warn{color:#8a6d00;font-weight:600}
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  label{display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:4px}
  input[type=text],input[type=password]{width:100%;padding:10px 12px;border:1px solid #cfd8e1;border-radius:8px;font-size:14px}
  input:focus{outline:2px solid var(--azul2);border-color:transparent}
  .chk{display:flex;align-items:center;gap:8px;font-size:14px;margin-top:6px;font-weight:400;color:#374151}
  .btn{display:inline-block;background:var(--azul2);color:#fff;border:none;border-radius:8px;padding:12px 26px;font-size:15px;font-weight:700;cursor:pointer}
  .btn:hover{background:var(--azul)}
  .btn-rojo{background:var(--rojo)}.btn-rojo:hover{background:#a32117}
  .log{list-style:none;font-size:13.5px}
  .log li{padding:7px 10px;border-left:4px solid var(--verde);background:#f2faf5;border-radius:0 6px 6px 0;margin-bottom:6px}
  .log li.err{border-color:var(--rojo);background:#fdf1f0}
  .aviso{background:#fff8e6;border:1px solid #e6c84c;border-radius:8px;padding:14px;font-size:14px;margin-bottom:20px}
  .cred{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:12px}
  .cred div{background:var(--gris);border:1px dashed #c3ccd6;border-radius:8px;padding:10px;text-align:center;font-size:13px}
  .cred b{color:var(--azul)}
  footer{text-align:center;color:#8a94a0;font-size:12px;margin:30px 0}
  @media(max-width:640px){.grid,.cred{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="barra">
  <div class="escudo">GOB</div>
  <b>GOBIERNO DE CHILE &nbsp;|&nbsp; HELPDESK — Instalador del Sistema</b>
</div>

<div class="wrap">
  <h1>Instalación completa</h1>
  <p class="sub">Asistente automático: verifica requisitos, genera <code>config.php</code>, crea tablas, usuarios y datos semilla.</p>

  <?php if ($etapa === 'exito' || $etapa === 'bloqueo'): ?>
    <div class="card" style="border-top:4px solid var(--verde)">
      <h2 style="color:var(--verde)">✔ Instalación completada con éxito</h2>
      <ul class="log">
        <?php foreach ($resultados as $r) echo '<li>' . htmlspecialchars($r) . '</li>'; ?>
        <?php foreach ($errores as $e) echo '<li class="err">' . htmlspecialchars($e) . '</li>'; ?>
      </ul>
      <div class="cred">
        <div><b>Admin</b><br>admin / <?= !empty($_POST['admin_pass']) && strlen((string)$_POST['admin_pass']) >= 6 ? '(clave personalizada)' : 'admin123' ?></div>
        <div><b>Supervisor</b><br>supervisor / admin123</div>
        <div><b>Técnico</b><br>tecnico / admin123</div>
      </div>
      <p style="margin-top:16px">
        <a class="btn" href="index.php">Ir al Portal Ciudadano →</a>
        &nbsp; <a class="btn" style="background:#5b6570" href="login.php">Ir al Panel de Funcionarios →</a>
      </p>
      <?php if ($etapa === 'exito'): ?>
      <div class="aviso" style="margin-top:18px">
        ⚠ <b>Seguridad:</b> elimine este archivo <code>install.php</code> desde el Administrador de Archivos de cPanel una vez terminada la instalación.
        Como alternativa, bloquéelo ahora generando un <code>.htaccess</code>:
        <form method="post" style="margin-top:10px" onsubmit="return confirm('¿Bloquear install.php mediante .htaccess?')">
          <input type="hidden" name="htaccess_block" value="1">
          <button class="btn btn-rojo" type="submit">🔒 Bloquear install.php (.htaccess)</button>
        </form>
      </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($yaInstalado && !$fuerzaReinstall && $etapa !== 'exito' && $etapa !== 'bloqueo'): ?>
    <div class="aviso">
      El sistema ya fue instalado (<code>instalado.lock</code> presente).
      Si necesita reinstalar o reparar, use la opción de abajo — esto <b>borrará y volverá a crear</b> las tablas.
      <div style="margin-top:10px"><a class="btn btn-rojo" href="install.php?force=1">Reinstalar desde cero</a></div>
    </div>
  <?php endif; ?>

  <?php if (!$yaInstalado || $fuerzaReinstall): ?>

    <!-- PASO 1: requisitos -->
    <div class="card">
      <h2>Paso 1 · Verificación de requisitos</h2>
      <table class="reqs">
        <?php foreach ($reqs as [$nom, $ok, $det]): ?>
          <tr>
            <td><?= htmlspecialchars($nom) ?></td>
            <td style="text-align:right"><?= $ok ? '<span class="ok">OK</span>' : (strpos($nom,'recomendada')!==false ? '<span class="warn">OPCIONAL</span>' : '<span class="no">FALTA</span>') ?></td>
            <td style="text-align:right;color:#5b6570"><?= htmlspecialchars($det) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>

    <?php if ($errores): ?>
      <div class="card" style="border-top:4px solid var(--rojo)">
        <h2 style="color:var(--rojo)">Errores durante la instalación</h2>
        <ul class="log"><?php foreach ($errores as $e) echo '<li class="err">' . htmlspecialchars($e) . '</li>'; ?></ul>
        <?php if ($resultados): ?><p class="sub" style="margin-top:8px">Progreso antes del error:</p><ul class="log"><?php foreach ($resultados as $r) echo '<li>' . htmlspecialchars($r) . '</li>'; ?></ul><?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- PASO 2: formulario -->
    <div class="card">
      <h2>Paso 2 · Datos de acceso a la base de datos (cPanel)</h2>
      <?php if (!$todoOk): ?>
        <div class="aviso">Hay requisitos obligatorios sin cumplir (marcados en rojo arriba). Corríjalos antes de continuar.</div>
      <?php endif; ?>
      <form method="post" id="f-inst">
        <div class="grid">
          <div>
            <label>Host MySQL</label>
            <input type="text" name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>" required>
          </div>
          <div>
            <label>Nombre de la base de datos</label>
            <input type="text" name="db_name" placeholder="cuenta_mesaayuda" value="<?= htmlspecialchars($_POST['db_name'] ?? '') ?>" required>
          </div>
          <div>
            <label>Usuario MySQL</label>
            <input type="text" name="db_user" placeholder="cuenta_usuario" value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>" required>
          </div>
          <div>
            <label>Contraseña MySQL</label>
            <input type="password" name="db_pass" value="<?= htmlspecialchars($_POST['db_pass'] ?? '') ?>">
          </div>
          <div>
            <label>URL de la aplicación (opcional)</label>
            <input type="text" name="app_url" placeholder="https://midominio.cl" value="<?= htmlspecialchars($_POST['app_url'] ?? '') ?>">
          </div>
          <div>
            <label>Nueva clave para los 3 usuarios (opcional, mín. 6)</label>
            <input type="password" name="admin_pass" placeholder="dejar vacío = admin123">
          </div>
        </div>

        <div style="margin-top:14px">
          <label class="chk"><input type="checkbox" name="datos_demo" value="1" checked> Instalar datos de demostración (categorías, tickets de ejemplo e historial)</label>
          <label class="chk"><input type="checkbox" name="usar_schema" value="1" <?= is_file(SCHEMA_FILE) ? 'checked' : 'disabled' ?>> Usar el archivo <code>schema.sql</code> incluido <?= is_file(SCHEMA_FILE) ? '' : '(no encontrado: se usará el esquema interno)' ?></label>
          <label class="chk"><input type="checkbox" name="force" value="1" <?= $fuerzaReinstall ? 'checked' : '' ?>> Confirmar reinstalación (elimina y recrea las tablas)</label>
        </div>

        <div style="margin-top:18px">
          <button class="btn" type="submit" <?= $todoOk ? '' : 'disabled style="opacity:.5;cursor:not-allowed"' ?>>🚀 Instalar / Ejecutar</button>
        </div>
      </form>
    </div>

    <!-- Guía rápida -->
    <div class="card">
      <h2>Guía rápida de 3 pasos</h2>
      <ol style="padding-left:20px;font-size:14px;line-height:1.9">
        <li>En <b>cPanel → Bases de datos MySQL</b>: cree la BD y un usuario, asígnele <i>Todos los privilegios</i> sobre esa BD.</li>
        <li>Suba todos los archivos del sistema a <code>public_html</code> y complete el formulario anterior.</li>
        <li>Al terminar, <b>elimine <code>install.php</code></b> y entre al panel con <code>admin / admin123</code>.</li>
      </ol>
    </div>
  <?php endif; ?>

  <footer>Mesa de Ayuda Institucional · Kit Digital Gobierno de Chile · install.php v1.0</footer>
</div>
<script>
document.getElementById('f-inst')?.addEventListener('submit', function(e){
  if (!confirm('¿Ejecutar la instalación ahora? Las tablas existentes serán recreadas.')) e.preventDefault();
});
</script>
</body>
</html>
