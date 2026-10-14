<?php
/**
 * ============================================================
 * MESA DE AYUDA / HELPDESK — Gobierno de Chile
 * config.php  →  Configuración única de conexión a la BD
 * ============================================================
 * Generado por install.php el 14/10/2026 18:43
 * Si necesita cambiar credenciales, edite este archivo
 * o vuelva a usar el instalador (con INSTALL_FORCE=1).
 */

// -------- Base de datos --------
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'mesa_ayuda');
define('DB_USER', 'mesa_ayuda_user');
define('DB_PASS', 'test_pass_2026');
define('DB_CHARSET', 'utf8mb4');

// -------- Aplicación --------
define('APP_NAME', 'Helpdesk | Gobierno de Chile');
define('APP_URL', 'http://localhost:8099'); // opcional: https://midominio.cl (sin "/" final)
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
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => APP_DEBUG ? $e->getMessage() : 'Error de conexión a la base de datos. Revise config.php']);
            exit;
        }
    }
    return $pdo;
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
function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Sanitize + truncate */
function clean(?string $s, int $max = 2000): string
{
    $s = trim(strip_tags((string) $s));
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, $max);
    }
    return substr($s, 0, $max); // fallback si el hosting no tiene ext-mbstring
}

/** Longitud segura de un string (multi-byte aware con fallback) */
function str_len_safe(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
}
