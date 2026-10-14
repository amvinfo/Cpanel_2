<?php
/**
 * api/public.php — Endpoints del Portal Ciudadano (sin sesión).
 * Uso:  api/public.php?action=nombre_accion   (GET o POST)
 */
require_once __DIR__ . '/../config.php';

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
function nuevo_token(): string
{
    do {
        $t = strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));
        $t = implode('-', str_split($t, 4)); // ej: A7X3-K9P2-M1Q4
        $ok = db()->prepare("SELECT COUNT(*) FROM tickets WHERE REPLACE(token_consulta,'-','')=?");
        $ok->execute([str_replace('-', '', $t)]);
    } while ($ok->fetchColumn() > 0);
    return $t;
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

        $st = $pdo->prepare(
            "INSERT INTO tickets (codigo, token_consulta, solicitante, email, asunto, descripcion, categoria_id, adjunto, estado, prioridad)
             VALUES (?,?,?,?,?,?,?,?,'abierto','media')"
        );
        $st->execute([$codigo, $token, $nombre, $email, $asunto, $desc, $catId ?: null, $adjunto]);
        $tid = (int)$pdo->lastInsertId();

        $pdo->prepare("INSERT INTO ticket_historial (ticket_id, autor, tipo, mensaje) VALUES (?,?, 'creacion', ?)")
            ->execute([$tid, $nombre, 'Ticket creado automáticamente desde el Portal Ciudadano.']);

        json_out([
            'ok'     => true,
            'mensaje' => 'Su solicitud fue registrada correctamente.',
            'ticket' => [
                'codigo' => $codigo,
                'token'  => $token,
                'estado' => ESTADOS_TICKET['abierto'],
            ],
        ], 201);
        break;

    /* ==================== CONSULTAR TICKET (público) ==================== */
    case 'consultar_ticket':
        $q = strtoupper(clean($_GET['q'] ?? $_POST['q'] ?? '', 40));
        $q = str_replace([' ', '-'], ['', '-'], $q);
        if ($q === '') json_out(['ok' => false, 'error' => 'Ingrese un número de ticket o código de consulta.'], 422);

        $pdo = db();
        $st = $pdo->prepare(
            "SELECT t.*, c.nombre AS categoria, u.nombre AS tecnico
             FROM tickets t
             LEFT JOIN categorias c ON c.id = t.categoria_id
             LEFT JOIN usuarios u   ON u.id = t.asignado_a
             WHERE t.codigo = ? OR REPLACE(t.token_consulta,'-','') = REPLACE(?, '-', '')
             LIMIT 1"
        );
        $st->execute([$q, str_replace('-', '', $q)]);
        $t = $st->fetch();

        if (!$t) json_out(['ok' => false, 'error' => 'No se encontró la solicitud. Verifique el número de ticket o el código de consulta.'], 404);

        $h = $pdo->prepare("SELECT autor, tipo, mensaje, creado_en FROM ticket_historial WHERE ticket_id = ? ORDER BY creado_en ASC, id ASC");
        $h->execute([$t['id']]);

        /* Las notas internas NO se exponen al ciudadano; solo respuestas públicas. */
        $visibles = array_values(array_filter($h->fetchAll(), fn($r) => !in_array($r['tipo'], ['nota_interna','creacion','estado','asignacion'], true)));

        json_out(['ok' => true, 'ticket' => [
            'id'         => (int)$t['id'],
            'codigo'     => $t['codigo'],
            'token'      => $t['token_consulta'],
            'solicitante'=> $t['solicitante'],
            'email'      => $t['email'],
            'asunto'     => $t['asunto'],
            'descripcion'=> $t['descripcion'],
            'categoria'  => $t['categoria'] ?? 'Sin categoría',
            'estado'     => ESTADOS_TICKET[$t['estado']] ?? ucfirst($t['estado']),
            'prioridad'  => $t['prioridad'],
            'tecnico'    => $t['tecnico'],
            'creado'     => fmt_fecha($t['creado_en']),
            'actualizado'=> fmt_fecha($t['actualizado_en']),
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
