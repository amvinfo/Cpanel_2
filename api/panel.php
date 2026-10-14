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

/** Código correlativo para tickets (compatibilidad con portal: TKT-AAAA-#####). */
function nuevo_codigo_panel(PDO $pdo): string
{
    $anio = date('Y');
    $max = $pdo->query("SELECT MAX(CAST(SUBSTRING(codigo, -5) AS UNSIGNED)) FROM tickets WHERE codigo LIKE 'TKT-$anio-%'")->fetchColumn();
    return sprintf('TKT-%s-%05d', $anio, (int)$max + 1);
}
/** Token de seguimiento único XXXX-XXXX-XXXX (14 chars, compatible con esquema v1/v2). */
function nuevo_token_panel(PDO $pdo): string
{
    $chk = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE REPLACE(token_consulta,'-','') = ?");
    do {
        $raw = strtoupper(bin2hex(random_bytes(6)));
        $chk->execute([$raw]);
    } while ($chk->fetchColumn() > 0);
    return implode('-', str_split($raw, 4));
}

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
        // Vocabulario unificado: 11 estados + compatibilidad con 'en_proceso' legacy.
        $byState = [];
        foreach (array_keys(ESTADOS_TICKET) as $k) $byState[$k] = 0;
        $byState['en_proceso'] = 0;
        foreach ($pdo->query("SELECT estado, COUNT(*) n FROM tickets GROUP BY estado") as $r) {
            $byState[estado_normalizado($r['estado'])] += (int)$r['n'];
        }
        $abiertos    = $byState['nuevo'] + $byState['abierto'] + $byState['reabierto'];
        $enAtencion  = $byState['asignado'] + $byState['en_atencion'] + $byState['en_proceso']
                     + $byState['esperando_solicitante'] + $byState['esperando_tercero'] + $byState['escalado'];
        $total       = array_sum($byState);
        $criticos    = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE prioridad='urgente' AND estado NOT IN ('resuelto','cerrado','cancelado')")->fetchColumn();
        $sinAsignar  = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE asignado_a IS NULL AND estado NOT IN ('resuelto','cerrado','cancelado')")->fetchColumn();
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
            'abiertos'     => $abiertos,
            'en_proceso'   => $enAtencion,
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
        $estado    = clean($_GET['estado'] ?? '', 30);
        $prioridad = clean($_GET['prioridad'] ?? '', 20);
        $asig      = (int)($_GET['asignado'] ?? 0);
        $q         = trim(clean($_GET['q'] ?? '', 100));

        $where = []; $params = [];
        if (es_estado_ticket_valido($estado) || $estado === 'en_proceso') { $where[] = 't.estado = ?';     $params[] = $estado; }
        if (isset(PRIORIDADES_TICKET[$prioridad]))                          { $where[] = 't.prioridad = ?';  $params[] = $prioridad; }
        if ($asig > 0)                                                      { $where[] = 't.asignado_a = ?'; $params[] = $asig; }
        if ($q !== '') {
            $where[] = '(t.codigo LIKE ? OR t.solicitante LIKE ? OR t.asunto LIKE ? OR t.email LIKE ?)';
            $like = "%$q%"; array_push($params, $like, $like, $like, $like);
        }
        $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $pdo = db();
        $rows = $pdo->prepare(
            "SELECT t.id, t.codigo, t.token_consulta, t.solicitante, t.email, t.asunto, t.descripcion,
                    t.estado, t.prioridad, t.tipo, t.origen, t.departamento, t.ubicacion, t.asignado_a,
                    c.nombre AS categoria, u.nombre AS tecnico,
                    (SELECT COUNT(*) FROM ticket_historial h WHERE h.ticket_id = t.id) AS mensajes,
                    DATE_FORMAT(t.creado_en,'%d/%m/%Y %H:%i') AS creado,
                    DATE_FORMAT(t.actualizado_en,'%d/%m/%Y %H:%i') AS actualizado
             FROM tickets t
             LEFT JOIN categorias c ON c.id = t.categoria_id
             LEFT JOIN usuarios u   ON u.id = t.asignado_a
             $sqlWhere
             ORDER BY FIELD(t.estado,'nuevo','abierto','asignado','en_atencion','esperando_solicitante','esperando_tercero','escalado','reabierto','resuelto','cerrado','cancelado'),
                      FIELD(t.prioridad,'urgente','alta','media','baja'),
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
        // etiquetas legibles del vocabulario unificado
        $t['estado_label']   = ESTADOS_TICKET[estado_normalizado($t['estado'])] ?? ucfirst($t['estado']);
        $t['prioridad_label']= PRIORIDADES_TICKET[$t['prioridad']] ?? ucfirst($t['prioridad']);

        json_out(['ok' => true, 'ticket' => $t]);
        break;

    /* ==================== TÉCNICOS (para asignar) ==================== */
    case 'tecnicos':
        require_role(['admin','supervisor','tecnico']);
        $rows = db()->query("SELECT id, nombre, rol FROM usuarios WHERE rol IN ('tecnico','supervisor') AND activo = 1 ORDER BY nombre")->fetchAll();
        json_out(['ok' => true, 'data' => $rows]);
        break;

    /* ==================== CATEGORÍAS + VOCABULARIO (modal Nuevo Ticket) ==================== */
    case 'metadata':
        require_role(['admin','supervisor','tecnico']);
        json_out([
            'ok'           => true,
            'categorias'   => db()->query("SELECT id, nombre FROM categorias ORDER BY orden, nombre")->fetchAll(),
            'estados'      => ESTADOS_TICKET,
            'prioridades'  => PRIORIDADES_TICKET,
            'tipos'        => TIPOS_TICKET,
            'departamentos'=> DEPARTAMENTOS,
            'ubicaciones'  => UBICACIONES,
        ]);
        break;

    /* ==================== NUEVO TICKET INTERNO (Admin/Supervisor) ==================== */
    case 'crear_ticket_interno':
        require_role(['admin','supervisor']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Método no permitido'], 405);
        $u = current_user();

        $solicitante  = clean($_POST['solicitante'] ?? '', 120);
        $email        = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $catId        = (int)($_POST['categoria_id'] ?? 0);
        $tipo         = clean($_POST['tipo'] ?? 'Incidencia', 40);
        $prioridad    = isset(PRIORIDADES_TICKET[$_POST['prioridad'] ?? '']) ? $_POST['prioridad'] : 'media';
        $origen       = in_array($_POST['origen'] ?? 'interno', ['publico','interno'], true) ? $_POST['origen'] : 'interno';
        $departamento = clean($_POST['departamento'] ?? '', 120) ?: null;
        $ubicacion    = clean($_POST['ubicacion'] ?? '', 120) ?: null;
        $asignado     = (int)($_POST['asignado_a'] ?? 0);
        $asunto       = clean($_POST['asunto'] ?? '', 200);
        $descripcion  = clean($_POST['descripcion'] ?? '', 5000);

        $errores = [];
        if (str_len_safe($solicitante) < 3) $errores['solicitante'] = 'Indique el solicitante.';
        if (!$email)                        $errores['email'] = 'Correo inválido.';
        if (!$catId)                        $errores['categoria_id'] = 'Seleccione una categoría.';
        if (!isset(TIPOS_TICKET[$tipo]))    $errores['tipo'] = 'Tipo inválido.';
        if (str_len_safe($asunto) < 5)      $errores['asunto'] = 'El asunto debe tener al menos 5 caracteres.';
        if (str_len_safe($descripcion) < 10)$errores['descripcion'] = 'Describa el requerimiento (mín. 10 caracteres).';
        if ($errores) json_out(['ok' => false, 'errors' => $errores], 422);

        $pdo = db();
        try {
            $codigo = nuevo_codigo_panel($pdo);
            $token  = nuevo_token_panel($pdo);
            $st = $pdo->prepare(
                "INSERT INTO tickets (codigo, token_consulta, solicitante, email, asunto, descripcion,
                                      categoria_id, estado, prioridad, tipo, origen, departamento, ubicacion,
                                      asignado_a, creado_por)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $st->execute([$codigo, $token, $solicitante, $email, $asunto, $descripcion, $catId, 'nuevo', $prioridad, $tipo, $origen, $departamento, $ubicacion, $asignado ?: null, $u['id']]);
            $tid = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO ticket_historial (ticket_id, autor, tipo, mensaje) VALUES (?,?, 'creacion', ?)")
                ->execute([$tid, $u['nombre'], 'Ticket interno creado desde el Panel de Administración.']);
            if ($asignado) {
                $a = $pdo->prepare("SELECT nombre FROM usuarios WHERE id = ?"); $a->execute([$asignado]);
                if ($nom = $a->fetchColumn()) {
                    $pdo->prepare("INSERT INTO ticket_historial (ticket_id, autor, tipo, mensaje) VALUES (?,?, 'asignacion', ?)")
                        ->execute([$tid, $u['nombre'], "Ticket asignado a {$nom}."]);
                }
            }
        } catch (PDOException $e) {
            error_log('crear_ticket_interno: ' . $e->getMessage());
            json_out(['ok' => false, 'error' => 'No fue posible crear el ticket: ' . $e->getMessage()], 500);
        }
        json_out(['ok' => true, 'mensaje' => 'Ticket interno creado.', 'ticket' => ['id' => $tid, 'codigo' => $codigo, 'token' => $token]], 201);
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
        $nuevoAsig   = isset($_POST['asignado_a']) && $_POST['asignado_a'] !== '' ? (int)$_POST['asignado_a'] : null;

        // Vocabulario unificado: acepta los 11 estados + 'en_proceso' legacy
        if ($nuevoEstado && (es_estado_ticket_valido($nuevoEstado) || $nuevoEstado === 'en_proceso') && $nuevoEstado !== $t['estado']) {
            $etiqueta = ESTADOS_TICKET[$nuevoEstado] ?? $nuevoEstado;
            $anterior = ESTADOS_TICKET[estado_normalizado($t['estado'])] ?? $t['estado'];
            $pdo->prepare("UPDATE tickets SET estado = ? WHERE id = ?")->execute([$nuevoEstado, $id]);
            $pdo->prepare("INSERT INTO ticket_historial (ticket_id, autor, tipo, mensaje) VALUES (?,?, 'estado', ?)")
                ->execute([$id, $u['nombre'], "Estado cambiado de {$anterior} a {$etiqueta}."]);
        }
        if ($nuevaPrio && isset(PRIORIDADES_TICKET[$nuevaPrio]) && $nuevaPrio !== $t['prioridad']) {
            $pdo->prepare("UPDATE tickets SET prioridad = ? WHERE id = ?")->execute([$nuevaPrio, $id]);
            $pdo->prepare("INSERT INTO ticket_historial (ticket_id, autor, tipo, mensaje) VALUES (?,?, 'estado', ?)")
                ->execute([$id, $u['nombre'], "Prioridad cambiada a " . PRIORIDADES_TICKET[$nuevaPrio] . "."]);
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

    /* ==================== COMENTARIO / RESPUESTA / NOTA INTERNA ==================== */
    case 'comentar':
        require_role(['admin','supervisor','tecnico']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Método no permitido'], 405);
        $u = current_user();
        $id = (int)($_POST['id'] ?? 0);
        $msg = clean($_POST['mensaje'] ?? '', 3000);
        $modo = in_array($_POST['modo'] ?? '', ['respuesta','nota_interna'], true) ? $_POST['modo'] : 'respuesta';
        if (str_len_safe($msg) < 3) json_out(['ok' => false, 'error' => 'Mensaje demasiado corto.'], 422);
        $pdo = db();
        $chk = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE id = ?"); $chk->execute([$id]);
        if (!$chk->fetchColumn()) json_out(['ok' => false, 'error' => 'Ticket no encontrado'], 404);
        $pdo->prepare("INSERT INTO ticket_historial (ticket_id, autor, tipo, mensaje) VALUES (?,?,?,?)")->execute([$id, $u['nombre'], $modo, $msg]);
        $pdo->prepare("UPDATE tickets SET actualizado_en = NOW() WHERE id = ?")->execute([$id]);
        json_out(['ok' => true, 'mensaje' => $modo === 'nota_interna' ? 'Nota interna agregada.' : 'Respuesta pública enviada.']);
        break;

    default:
        json_out(['ok' => false, 'error' => 'Acción no reconocida'], 400);
}
