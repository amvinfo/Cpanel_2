<?php
/**
 * api/panel.php — Endpoints del Dashboard Interno (requiere sesión).
 * Uso:  api/panel.php?action=nombre_accion
 */
require_once __DIR__ . '/../config.php';
app_session_start();

$action = $_GET['action'] ?? '';

/* ---------- helpers ---------- */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}
function require_role(array $roles): void
{
    $u = current_user();
    if (!$u) json_out(['ok' => false, 'error' => 'No autenticado. Inicie sesión.'], 401);
    if (!in_array($u['rol'], $roles, true)) json_out(['ok' => false, 'error' => 'Sin permisos para esta acción.'], 403);
}
function fmt(?string $f): string { return $f ? date('d/m/Y H:i', strtotime($f)) : ''; }

switch ($action) {

    /* ==================== LOGIN ==================== */
    case 'login':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Método no permitido'], 405);
        $u = clean($_POST['usuario'] ?? '', 60);
        $p = (string)($_POST['password'] ?? '');
        $st = db()->prepare("SELECT * FROM usuarios WHERE usuario = ? AND activo = 1 LIMIT 1");
        $st->execute([$u]);
        $row = $st->fetch();
        if (!$row || !password_verify($p, $row['password_hash'])) {
            json_out(['ok' => false, 'error' => 'Usuario o contraseña incorrectos.'], 401);
        }
        session_regenerate_id(true);
        $_SESSION['user'] = ['id'=>(int)$row['id'], 'nombre'=>$row['nombre'], 'usuario'=>$row['usuario'], 'rol'=>$row['rol']];
        json_out(['ok' => true, 'usuario' => $_SESSION['user']]);
        break;

    /* ==================== LOGOUT ==================== */
    case 'logout':
        $_SESSION = [];
        session_destroy();
        json_out(['ok' => true]);
        break;

    /* ==================== SESIÓN ACTUAL ==================== */
    case 'session':
        $u = current_user();
        json_out(['ok' => (bool)$u, 'usuario' => $u], $u ? 200 : 401);
        break;

    /* ==================== ESTADÍSTICAS REALES ==================== */
    case 'stats':
        require_role(['admin','supervisor','tecnico']);
        $pdo = db();
        // Total por estado (una sola consulta agrupada → sin "Cargando estadísticas...")
        $byState = ['abierto'=>0,'en_proceso'=>0,'resuelto'=>0,'cerrado'=>0];
        foreach ($pdo->query("SELECT estado, COUNT(*) n FROM tickets GROUP BY estado") as $r) {
            $byState[$r['estado']] = (int)$r['n'];
        }
        $total      = array_sum($byState);
        $criticos   = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE prioridad='critica' AND estado IN ('abierto','en_proceso')")->fetchColumn();
        $sinAsignar = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE asignado_a IS NULL AND estado IN ('abierto','en_proceso')")->fetchColumn();
        $hoy        = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE DATE(creado_en) = CURDATE()")->fetchColumn();
        $promHoras  = $pdo->query(
            "SELECT ROUND(AVG(TIMESTAMPDIFF(HOUR, creado_en, actualizado_en)),1)
             FROM tickets WHERE estado IN ('resuelto','cerrado')"
        )->fetchColumn();

        // Serie últimos 7 días para el gráfico
        $trend = $pdo->query(
            "SELECT DATE(creado_en) d, COUNT(*) n FROM tickets
             WHERE creado_en >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
             GROUP BY DATE(creado_en) ORDER BY d"
        )->fetchAll();

        json_out(['ok' => true, 'stats' => [
            'total'        => $total,
            'abiertos'     => $byState['abierto'],
            'en_proceso'   => $byState['en_proceso'],
            'resueltos'    => $byState['resuelto'],
            'cerrados'     => $byState['cerrado'],
            'criticos'     => $criticos,
            'sin_asignar'  => $sinAsignar,
            'creados_hoy'  => $hoy,
            'prom_horas'   => $promHoras !== null ? (float)$promHoras : null,
            'por_estado'   => $byState,
            'trend'        => array_map(fn($r) => ['fecha'=>$r['d'],'n'=>(int)$r['n']], $trend),
        ]]);
        break;

    /* ==================== LISTADO CON FILTROS ==================== */
    case 'tickets':
        require_role(['admin','supervisor','tecnico']);
        $estado    = clean($_GET['estado'] ?? '', 20);
        $prioridad = clean($_GET['prioridad'] ?? '', 20);
        $asig      = (int)($_GET['asignado'] ?? 0);
        $q         = trim(clean($_GET['q'] ?? '', 100));

        $where = []; $params = [];
        if (in_array($estado, ['abierto','en_proceso','resuelto','cerrado'], true))   { $where[] = 't.estado = ?';     $params[] = $estado; }
        if (in_array($prioridad, ['baja','media','alta','critica'], true))             { $where[] = 't.prioridad = ?';  $params[] = $prioridad; }
        if ($asig > 0)                                                                  { $where[] = 't.asignado_a = ?'; $params[] = $asig; }
        if ($q !== '') {
            $where[] = '(t.codigo LIKE ? OR t.solicitante LIKE ? OR t.asunto LIKE ? OR t.email LIKE ?)';
            $like = "%$q%"; array_push($params, $like, $like, $like, $like);
        }
        $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $pdo = db();
        $rows = $pdo->prepare(
            "SELECT t.id, t.codigo, t.solicitante, t.email, t.asunto, t.estado, t.prioridad,
                    c.nombre AS categoria, u.nombre AS tecnico,
                    DATE_FORMAT(t.creado_en,'%d/%m/%Y %H:%i') AS creado,
                    DATE_FORMAT(t.actualizado_en,'%d/%m/%Y %H:%i') AS actualizado
             FROM tickets t
             LEFT JOIN categorias c ON c.id = t.categoria_id
             LEFT JOIN usuarios u   ON u.id = t.asignado_a
             $sqlWhere
             ORDER BY FIELD(t.estado,'abierto','en_proceso','resuelto','cerrado'),
                      FIELD(t.prioridad,'critica','alta','media','baja'),
                      t.creado_en DESC
             LIMIT 500"
        );
        $rows->execute($params);
        $data = $rows->fetchAll();

        json_out(['ok' => true, 'data' => $data, 'total' => count($data)]);
        break;

    /* ==================== DETALLE ==================== */
    case 'detalle':
        require_role(['admin','supervisor','tecnico']);
        $id = (int)($_GET['id'] ?? 0);
        $pdo = db();
        $st = $pdo->prepare(
            "SELECT t.*, c.nombre AS categoria, u.nombre AS tecnico
             FROM tickets t
             LEFT JOIN categorias c ON c.id = t.categoria_id
             LEFT JOIN usuarios u   ON u.id = t.asignado_a
             WHERE t.id = ? LIMIT 1"
        );
        $st->execute([$id]);
        $t = $st->fetch();
        if (!$t) json_out(['ok' => false, 'error' => 'Ticket no encontrado'], 404);

        $h = $pdo->prepare("SELECT autor,tipo,mensaje,creado_en FROM ticket_historial WHERE ticket_id=? ORDER BY creado_en ASC, id ASC");
        $h->execute([$id]);
        $t['creado'] = fmt($t['creado_en']); $t['actualizado'] = fmt($t['actualizado_en']);
        unset($t['creado_en'], $t['actualizado_en']);
        $t['historial'] = array_map(fn($r) => ['autor'=>$r['autor'],'tipo'=>$r['tipo'],'mensaje'=>$r['mensaje'],'fecha'=>fmt($r['creado_en'])], $h->fetchAll());

        json_out(['ok' => true, 'ticket' => $t]);
        break;

    /* ==================== TÉCNICOS (para asignar) ==================== */
    case 'tecnicos':
        require_role(['admin','supervisor','tecnico']);
        $rows = db()->query("SELECT id, nombre, rol FROM usuarios WHERE rol IN ('tecnico','supervisor') AND activo = 1 ORDER BY nombre")->fetchAll();
        json_out(['ok' => true, 'data' => $rows]);
        break;

    /* ==================== ACTUALIZAR TICKET ==================== */
    case 'actualizar':
        require_role(['admin','supervisor','tecnico']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Método no permitido'], 405);
        $u = current_user();
        $id = (int)($_POST['id'] ?? 0);
        $pdo = db();

        $st = $pdo->prepare("SELECT * FROM tickets WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $t = $st->fetch();
        if (!$t) json_out(['ok' => false, 'error' => 'Ticket no encontrado'], 404);

        $nuevoEstado = $_POST['estado'] ?? null;
        $nuevaPrio   = $_POST['prioridad'] ?? null;
        $nuevoAsig   = isset($_POST['asignado_a']) ? (int)$_POST['asignado_a'] : null;

        if ($nuevoEstado && in_array($nuevoEstado, ['abierto','en_proceso','resuelto','cerrado'], true) && $nuevoEstado !== $t['estado']) {
            $pdo->prepare("UPDATE tickets SET estado = ? WHERE id = ?")->execute([$nuevoEstado, $id]);
            $pdo->prepare("INSERT INTO ticket_historial (ticket_id, autor, tipo, mensaje) VALUES (?,?, 'estado', ?)")
                ->execute([$id, $u['nombre'], "Estado cambiado de {$t['estado']} a {$nuevoEstado}."]);
        }
        if ($nuevaPrio && in_array($nuevaPrio, ['baja','media','alta','critica'], true) && $nuevaPrio !== $t['prioridad']) {
            $pdo->prepare("UPDATE tickets SET prioridad = ? WHERE id = ?")->execute([$nuevaPrio, $id]);
            $pdo->prepare("INSERT INTO ticket_historial (ticket_id, autor, tipo, mensaje) VALUES (?,?, 'estado', ?)")
                ->execute([$id, $u['nombre'], "Prioridad cambiada a {$nuevaPrio}."]);
        }
        if ($nuevoAsig !== null && $nuevoAsig !== (int)$t['asignado_a']) {
            $a = $pdo->prepare("SELECT nombre FROM usuarios WHERE id = ?");
            $a->execute([$nuevoAsig]);
            $nom = $a->fetchColumn();
            if ($nom !== false) {
                $pdo->prepare("UPDATE tickets SET asignado_a = ? WHERE id = ?")->execute([$nuevoAsig ?: null, $id]);
                $pdo->prepare("INSERT INTO ticket_historial (ticket_id, autor, tipo, mensaje) VALUES (?,?, 'asignacion', ?)")
                    ->execute([$id, $u['nombre'], $nuevoAsig ? "Ticket asignado a {$nom}." : 'Asignación eliminada.']);
            }
        }
        json_out(['ok' => true, 'mensaje' => 'Ticket actualizado.']);
        break;

    /* ==================== COMENTARIO INTERNO ==================== */
    case 'comentar':
        require_role(['admin','supervisor','tecnico']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Método no permitido'], 405);
        $u = current_user();
        $id = (int)($_POST['id'] ?? 0);
        $msg = clean($_POST['mensaje'] ?? '', 3000);
        if (str_len_safe($msg) < 3) json_out(['ok' => false, 'error' => 'Mensaje demasiado corto.'], 422);
        $pdo = db();
        $chk = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE id = ?"); $chk->execute([$id]);
        if (!$chk->fetchColumn()) json_out(['ok' => false, 'error' => 'Ticket no encontrado'], 404);
        $pdo->prepare("INSERT INTO ticket_historial (ticket_id, autor, tipo, mensaje) VALUES (?,?, 'comentario', ?)")->execute([$id, $u['nombre'], $msg]);
        $pdo->prepare("UPDATE tickets SET actualizado_en = NOW() WHERE id = ?")->execute([$id]);
        json_out(['ok' => true, 'mensaje' => 'Comentario agregado.']);
        break;

    default:
        json_out(['ok' => false, 'error' => 'Acción no reconocida'], 400);
}
