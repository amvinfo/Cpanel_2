<?php
/**
 * api/panel.php — Endpoints del Dashboard Interno (requiere sesión).
 * Uso:  api/panel.php?action=nombre_accion
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/helpers.php';
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
        if (columna_existe('usuarios', 'ultimo_acceso')) {
            db()->prepare("UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?")->execute([(int)$row['id']]);
        }
        auditar('login', "Inicio de sesión correcto ({$row['rol']}).");
        json_out(['ok' => true, 'usuario' => $_SESSION['user']]);
        break;

    /* ==================== LOGOUT ==================== */
    case 'logout':
        auditar('logout', 'Cierre de sesión.');
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
            'tipos'        => tipos_ticket_lista(),
            'departamentos'=> departamentos_lista(),
            'ubicaciones'  => ubicaciones_lista(),
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
        if (!isset(tipos_ticket_lista()[$tipo])) $errores['tipo'] = 'Tipo inválido.';
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
        auditar('comentario_ticket', "Ticket #$id · " . ($modo === 'nota_interna' ? 'nota interna' : 'respuesta pública'));
        json_out(['ok' => true, 'mensaje' => $modo === 'nota_interna' ? 'Nota interna agregada.' : 'Respuesta pública enviada.']);
        break;

    /* =====================================================================
       v3 — PERFIL DE USUARIO (todos los roles autenticados)
       ===================================================================== */
    case 'perfil':
        require_role(['admin','supervisor','tecnico']);
        $u = current_user();
        $st = db()->prepare("SELECT id, nombre, usuario, email, rol, activo, creado_en, ultimo_acceso FROM usuarios WHERE id = ? LIMIT 1");
        $st->execute([$u['id']]);
        $row = $st->fetch();
        if (!$row) json_out(['ok' => false, 'error' => 'Usuario no encontrado.'], 404);
        json_out(['ok' => true, 'perfil' => [
            'id' => (int)$row['id'], 'nombre' => $row['nombre'], 'usuario' => $row['usuario'],
            'email' => $row['email'], 'rol' => $row['rol'], 'activo' => (int)$row['activo'],
            'creado_en' => fmt($row['creado_en']),
            'ultimo_acceso' => fmt($row['ultimo_acceso'] ?? null),
        ]]);
        break;

    case 'perfil_actualizar':
        require_role(['admin','supervisor','tecnico']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Método no permitido'], 405);
        $u = current_user();
        $nombre = clean($_POST['nombre'] ?? '', 120);
        $email  = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $errores = [];
        if (str_len_safe($nombre) < 3) $errores['nombre'] = 'Indique su nombre completo.';
        if (!$email)                   $errores['email'] = 'Correo inválido.';
        if ($errores) json_out(['ok' => false, 'errors' => $errores], 422);
        db()->prepare("UPDATE usuarios SET nombre = ?, email = ? WHERE id = ?")->execute([$nombre, $email, $u['id']]);
        $_SESSION['user']['nombre'] = $nombre;
        auditar('perfil_actualizacion', 'Datos personales actualizados.');
        json_out(['ok' => true, 'mensaje' => 'Datos personales guardados correctamente.']);
        break;

    case 'cambio_clave':
        require_role(['admin','supervisor','tecnico']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Método no permitido'], 405);
        $u = current_user();
        $actual = (string)($_POST['clave_actual'] ?? '');
        $nueva  = (string)($_POST['clave_nueva'] ?? '');
        $confir = (string)($_POST['clave_confirmar'] ?? '');
        $st = db()->prepare("SELECT password_hash FROM usuarios WHERE id = ? LIMIT 1");
        $st->execute([$u['id']]);
        $hash = $st->fetchColumn();
        $errores = [];
        if (!$hash || !password_verify($actual, $hash)) $errores['clave_actual'] = 'La contraseña actual no es correcta.';
        if (str_len_safe($nueva) < 6)                   $errores['clave_nueva'] = 'La nueva clave debe tener al menos 6 caracteres.';
        if ($nueva !== $confir)                          $errores['clave_confirmar'] = 'Las contraseñas no coinciden.';
        if ($errores) json_out(['ok' => false, 'errors' => $errores], 422);
        db()->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?")
            ->execute([password_hash($nueva, PASSWORD_BCRYPT), $u['id']]);
        auditar('cambio_clave', 'El usuario cambió su contraseña.');
        json_out(['ok' => true, 'mensaje' => 'Contraseña actualizada. Use la nueva clave en su próximo ingreso.']);
        break;

    /* =====================================================================
       v3 — BASE DE CONOCIMIENTO (todos los roles)
       ===================================================================== */
    case 'kb_lista':
        require_role(['admin','supervisor','tecnico']);
        if (!tabla_existe('kb_articulos')) json_out(['ok' => false, 'error' => 'Base de conocimiento no instalada. Ejecute migrate.php o reinstale con install.php.'], 500);
        $vis = in_array($_GET['visibilidad'] ?? '', ['externa','interna'], true) ? $_GET['visibilidad'] : '';
        $cat = (int)($_GET['categoria_id'] ?? 0);
        $q   = trim(clean($_GET['q'] ?? '', 100));
        $where = []; $params = [];
        if ($vis) { $where[] = 'a.visibilidad = ?'; $params[] = $vis; }
        if ($cat) { $where[] = 'a.categoria_id = ?'; $params[] = $cat; }
        if ($q !== '') {
            $where[] = '(a.titulo LIKE ? OR a.resumen LIKE ? OR a.etiquetas LIKE ? OR a.palabras_clave LIKE ?)';
            $like = "%$q%"; array_push($params, $like, $like, $like, $like);
        }
        $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $rows = db()->prepare(
            "SELECT a.id, a.titulo, a.resumen, a.estado, a.visibilidad, a.destacado, a.etiquetas,
                    a.vistas, DATE_FORMAT(a.actualizado_en,'%d/%m/%Y') AS actualizado,
                    c.nombre AS categoria, u.nombre AS autor
             FROM kb_articulos a
             LEFT JOIN categorias c ON c.id = a.categoria_id
             LEFT JOIN usuarios u   ON u.id = a.creado_por
             $sqlWhere
             ORDER BY a.destacado DESC, a.actualizado_en DESC LIMIT 300"
        );
        $rows->execute($params);
        json_out(['ok' => true, 'data' => $rows->fetchAll()]);
        break;

    case 'kb_detalle':
        require_role(['admin','supervisor','tecnico']);
        $id = (int)($_GET['id'] ?? 0);
        $st = db()->prepare(
            "SELECT a.*, c.nombre AS categoria, u.nombre AS autor
             FROM kb_articulos a
             LEFT JOIN categorias c ON c.id = a.categoria_id
             LEFT JOIN usuarios u   ON u.id = a.creado_por WHERE a.id = ? LIMIT 1");
        $st->execute([$id]);
        $a = $st->fetch();
        if (!$a) json_out(['ok' => false, 'error' => 'Artículo no encontrado.'], 404);
        db()->prepare("UPDATE kb_articulos SET vistas = vistas + 1 WHERE id = ?")->execute([$id]);
        $a['creado'] = fmt($a['creado_en']); $a['actualizado'] = fmt($a['actualizado_en']);
        unset($a['creado_en'], $a['actualizado_en']);
        json_out(['ok' => true, 'articulo' => $a]);
        break;

    case 'kb_crear':
        require_role(['admin','supervisor','tecnico']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Método no permitido'], 405);
        $u = current_user();
        $titulo   = clean($_POST['titulo'] ?? '', 200);
        $resumen  = clean($_POST['resumen'] ?? '', 500);
        $contenido = limpiar_html((string)($_POST['contenido'] ?? ''));
        $catId    = (int)($_POST['categoria_id'] ?? 0);
        $estado   = in_array($_POST['estado'] ?? '', ['borrador','publicado'], true) ? $_POST['estado'] : 'borrador';
        $vis      = in_array($_POST['visibilidad'] ?? 'externa', ['externa','interna'], true) ? $_POST['visibilidad'] : 'externa';
        $dest     = !empty($_POST['destacado']) ? 1 : 0;
        $etiquetas = clean($_POST['etiquetas'] ?? '', 300);
        $palabras  = clean($_POST['palabras_clave'] ?? '', 300);
        $errores = [];
        if (str_len_safe($titulo) < 5) $errores['titulo'] = 'Título muy corto (mín. 5 caracteres).';
        if (str_len_safe(strip_tags($contenido)) < 20) $errores['contenido'] = 'El contenido debe tener al menos 20 caracteres.';
        if ($errores) json_out(['ok' => false, 'errors' => $errores], 422);
        db()->prepare(
            "INSERT INTO kb_articulos (titulo, resumen, contenido, categoria_id, estado, visibilidad, destacado, etiquetas, palabras_clave, creado_por)
             VALUES (?,?,?,?,?,?,?,?,?,?)"
        )->execute([$titulo, $resumen ?: null, $contenido, $catId ?: null, $estado, $vis, $dest, $etiquetas ?: null, $palabras ?: null, $u['id']]);
        $nid = (int)db()->lastInsertId();
        auditar('kb_crear', "Artículo #$nid «{$titulo}» ({$vis}/{$estado}).");
        json_out(['ok' => true, 'mensaje' => 'Artículo creado.', 'id' => $nid], 201);
        break;

    case 'kb_eliminar':
        require_role(['admin','supervisor']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Método no permitido'], 405);
        $id = (int)($_POST['id'] ?? 0);
        $st = db()->prepare("DELETE FROM kb_articulos WHERE id = ?"); $st->execute([$id]);
        if ($st->rowCount() === 0) json_out(['ok' => false, 'error' => 'Artículo no encontrado.'], 404);
        auditar('kb_eliminar', "Artículo #$id eliminado.");
        json_out(['ok' => true, 'mensaje' => 'Artículo eliminado.']);
        break;

    /* =====================================================================
       v3 — ADMINISTRACIÓN DEL SISTEMA (SOLO ADMIN)
       ===================================================================== */

    /* ---- Usuarios ---- */
    case 'usuarios':
        require_role(['admin']);
        $rows = db()->query(
            "SELECT id, nombre, usuario, email, rol, activo,
                    DATE_FORMAT(creado_en,'%d/%m/%Y') AS creado,
                    " . (columna_existe('usuarios','ultimo_acceso') ? "DATE_FORMAT(ultimo_acceso,'%d/%m/%Y %H:%i')" : "NULL") . " AS ultimo_acceso
             FROM usuarios ORDER BY FIELD(rol,'admin','supervisor','tecnico'), nombre")->fetchAll();
        json_out(['ok' => true, 'data' => $rows]);
        break;

    case 'usuario_guardar':
        require_role(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Método no permitido'], 405);
        $id       = (int)($_POST['id'] ?? 0);
        $nombre   = clean($_POST['nombre'] ?? '', 120);
        $usuario  = strtolower(clean($_POST['usuario'] ?? '', 60));
        $email    = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $rol      = in_array($_POST['rol'] ?? '', ['admin','supervisor','tecnico'], true) ? $_POST['rol'] : 'tecnico';
        $activo   = isset($_POST['activo']) && $_POST['activo'] !== '0' ? 1 : 0;
        $clave    = (string)($_POST['password'] ?? '');
        $errores = [];
        if (str_len_safe($nombre) < 3)  $errores['nombre'] = 'Nombre demasiado corto.';
        if (str_len_safe($usuario) < 3) $errores['usuario'] = 'Usuario demasiado corto.';
        if (!$email)                    $errores['email'] = 'Correo inválido.';
        if (!$id && str_len_safe($clave) < 6) $errores['password'] = 'Defina una clave de al menos 6 caracteres.';
        $dup = db()->prepare("SELECT COUNT(*) FROM usuarios WHERE usuario = ? AND id <> ?");
        $dup->execute([$usuario, $id]);
        if ($dup->fetchColumn()) $errores['usuario'] = 'Ese nombre de usuario ya existe.';
        if ($errores) json_out(['ok' => false, 'errors' => $errores], 422);

        $me = current_user();
        if ($id) {
            // un admin no puede desactivarse a sí mismo ni degradarse sin otro admin
            if ($id === $me['id'] && (!$activo || $rol !== 'admin'))
                json_out(['ok' => false, 'error' => 'No puede desactivar o cambiar el rol de su propia cuenta principal.'], 422);
            $sql = "UPDATE usuarios SET nombre=?, usuario=?, email=?, rol=?, activo=?";
            $params = [$nombre, $usuario, $email, $rol, $activo];
            if ($clave !== '') { $sql .= ", password_hash=?"; $params[] = password_hash($clave, PASSWORD_BCRYPT); }
            $sql .= " WHERE id=?"; $params[] = $id;
            db()->prepare($sql)->execute($params);
            auditar('usuario_editado', "Usuario #$id ({$usuario}) actualizado a rol {$rol}.");
            json_out(['ok' => true, 'mensaje' => 'Usuario actualizado.']);
        } else {
            db()->prepare("INSERT INTO usuarios (nombre, usuario, password_hash, email, rol, activo) VALUES (?,?,?,?,?,?)")
                ->execute([$nombre, $usuario, password_hash($clave, PASSWORD_BCRYPT), $email, $rol, $activo]);
            auditar('usuario_creado', "Nuevo usuario «{$usuario}» con rol {$rol}.");
            json_out(['ok' => true, 'mensaje' => 'Usuario creado correctamente.'], 201);
        }
        break;

    case 'usuario_activar':
        require_role(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Método no permitido'], 405);
        $id = (int)($_POST['id'] ?? 0);
        $me = current_user();
        if ($id === $me['id']) json_out(['ok' => false, 'error' => 'No puede desactivar su propia cuenta.'], 422);
        $st = db()->prepare("UPDATE usuarios SET activo = IF(activo=1,0,1) WHERE id = ?");
        $st->execute([$id]);
        if (!$st->rowCount()) json_out(['ok' => false, 'error' => 'Usuario no encontrado.'], 404);
        $s2 = db()->prepare("SELECT nombre, activo FROM usuarios WHERE id=?"); $s2->execute([$id]); $r = $s2->fetch();
        auditar('usuario_estado', ($r && $r['activo'] ? 'Activado' : 'Desactivado') . ": {$r['nombre']} (#$id).");
        json_out(['ok' => true, 'mensaje' => 'Estado del usuario actualizado.', 'activo' => (int)($r['activo'] ?? 0)]);
        break;

    /* ---- Categorías (CRUD + visibilidad both/internal/external) ---- */
    case 'categorias_admin':
        require_role(['admin']);
        $colVis = columna_existe('categorias', 'visibilidad');
        $rows = db()->query(
            "SELECT c.id, c.nombre, c.descripcion, c.icono, c.articulos,
                    " . ($colVis ? "c.visibilidad" : "'both'") . " AS visibilidad,
                    (SELECT COUNT(*) FROM tickets t WHERE t.categoria_id=c.id) AS tickets,
                    (SELECT COUNT(*) FROM kb_articulos k WHERE k.categoria_id=c.id) AS articulos_kb
             FROM categorias c ORDER BY c.orden, c.nombre")->fetchAll();
        json_out(['ok' => true, 'data' => $rows]);
        break;

    case 'categoria_guardar':
        require_role(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Método no permitido'], 405);
        $id = (int)($_POST['id'] ?? 0);
        $nombre = clean($_POST['nombre'] ?? '', 100);
        $desc   = clean($_POST['descripcion'] ?? '', 255);
        $icono  = in_array($_POST['icono'] ?? 'soporte', ['wifi','lock','soporte','hardware','shield','correo'], true) ? $_POST['icono'] : 'soporte';
        $vis    = in_array($_POST['visibilidad'] ?? 'both', ['both','internal','external'], true) ? $_POST['visibilidad'] : 'both';
        if (str_len_safe($nombre) < 3) json_out(['ok' => false, 'errors' => ['nombre' => 'Nombre demasiado corto.']], 422);
        $colVis = columna_existe('categorias', 'visibilidad');
        if ($id) {
            $sql = "UPDATE categorias SET nombre=?, descripcion=?, icono=?";
            $params = [$nombre, $desc, $icono];
            if ($colVis) { $sql .= ", visibilidad=?"; $params[] = $vis; }
            $sql .= " WHERE id=?"; $params[] = $id;
            db()->prepare($sql)->execute($params);
            auditar('categoria_editada', "Categoría #$id «{$nombre}».");
        } else {
            $sql = "INSERT INTO categorias (nombre, descripcion, icono" . ($colVis ? ", visibilidad" : "") . ", orden) VALUES (?,?,?" . ($colVis ? ",?" : "") . ",
                    (SELECT COALESCE(MAX(orden),0)+1 o FROM categorias x))";
            $params = [$nombre, $desc, $icono]; if ($colVis) $params[] = $vis;
            db()->prepare($sql)->execute($params);
            auditar('categoria_creada', "Nueva categoría «{$nombre}» ({$vis}).");
        }
        json_out(['ok' => true, 'mensaje' => 'Categoría guardada.']);
        break;

    case 'categoria_eliminar':
        require_role(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Método no permitido'], 405);
        $id = (int)($_POST['id'] ?? 0);
        $chk = db()->prepare("SELECT COUNT(*) FROM tickets WHERE categoria_id = ?"); $chk->execute([$id]);
        if ($chk->fetchColumn()) json_out(['ok' => false, 'error' => 'No se puede eliminar: tiene tickets asociados. Puede desactivarla cambiándole la visibilidad.'], 422);
        db()->prepare("DELETE FROM categorias WHERE id = ?")->execute([$id]);
        auditar('categoria_eliminada', "Categoría #$id eliminada.");
        json_out(['ok' => true, 'mensaje' => 'Categoría eliminada.']);
        break;

    /* ---- Departamentos / Ubicaciones / Tipos de ticket (CRUD simple genérico) ---- */
    case 'lista_simple':
        require_role(['admin']);
        $map = ['departamentos' => 'departamentos', 'ubicaciones' => 'ubicaciones', 'tipos' => 'tipos_ticket'];
        $t = $map[$_GET['tabla'] ?? ''] ?? null;
        if (!$t || !tabla_existe($t)) json_out(['ok' => false, 'error' => 'Tabla no disponible.'], 400);
        $cols = $t === 'ubicaciones' ? 'id, nombre, direccion, activo' : 'id, nombre, descripcion, activo';
        json_out(['ok' => true, 'data' => db()->query("SELECT $cols FROM `$t` ORDER BY nombre")->fetchAll()]);
        break;

    case 'simple_guardar':
        require_role(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Método no permitido'], 405);
        $map = ['departamentos' => 'departamentos', 'ubicaciones' => 'ubicaciones', 'tipos' => 'tipos_ticket'];
        $t = $map[$_POST['tabla'] ?? ''] ?? null;
        if (!$t || !tabla_existe($t)) json_out(['ok' => false, 'error' => 'Tabla no disponible.'], 400);
        $id = (int)($_POST['id'] ?? 0);
        $nombre = clean($_POST['nombre'] ?? '', 120);
        $extra  = clean($_POST['extra'] ?? '', 255);
        $activo = isset($_POST['activo']) && $_POST['activo'] !== '0' ? 1 : 0;
        if (str_len_safe($nombre) < 2) json_out(['ok' => false, 'errors' => ['nombre' => 'Nombre demasiado corto.']], 422);
        $colExtra = $t === 'ubicaciones' ? 'direccion' : 'descripcion';
        try {
            if ($id) {
                db()->prepare("UPDATE `$t` SET nombre=?, $colExtra=?, activo=? WHERE id=?")->execute([$nombre, $extra ?: null, $activo, $id]);
            } else {
                db()->prepare("INSERT INTO `$t` (nombre, $colExtra, activo) VALUES (?,?,?)")->execute([$nombre, $extra ?: null, $activo]);
            }
        } catch (PDOException $e) {
            json_out(['ok' => false, 'error' => 'Ese nombre ya está registrado.'], 422);
        }
        auditar('parametro_guardado', "$t: «{$nombre}» " . ($id ? "(editado)" : "(nuevo)"));
        json_out(['ok' => true, 'mensaje' => 'Registro guardado.']);
        break;

    case 'simple_eliminar':
        require_role(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Método no permitido'], 405);
        $map = ['departamentos' => 'departamentos', 'ubicaciones' => 'ubicaciones', 'tipos' => 'tipos_ticket'];
        $t = $map[$_POST['tabla'] ?? ''] ?? null;
        if (!$t || !tabla_existe($t)) json_out(['ok' => false, 'error' => 'Tabla no disponible.'], 400);
        db()->prepare("DELETE FROM `$t` WHERE id = ?")->execute([(int)($_POST['id'] ?? 0)]);
        auditar('parametro_eliminado', "$t #%d eliminado.");
        json_out(['ok' => true, 'mensaje' => 'Registro eliminado.']);
        break;

    /* ---- Equipos de soporte ---- */
    case 'equipos':
        require_role(['admin']);
        if (!tabla_existe('equipos_soporte')) json_out(['ok' => true, 'data' => []]);
        $eq = db()->query("SELECT id, nombre, descripcion FROM equipos_soporte ORDER BY nombre")->fetchAll();
        $mi = db()->query("SELECT equipo_id, usuario_id FROM equipo_miembros")->fetchAll();
        $users = db()->query("SELECT id, nombre, rol FROM usuarios WHERE rol IN ('tecnico','supervisor') AND activo=1 ORDER BY nombre")->fetchAll();
        foreach ($eq as &$e) {
            $e['miembros'] = array_values(array_map(function ($us) use ($mi, $e) {
                foreach ($mi as $m) if ((int)$m['equipo_id'] === (int)$e['id'] && (int)$m['usuario_id'] === (int)$us['id']) return $us;
                return null;
            }, $users));
        }
        json_out(['ok' => true, 'data' => $eq, 'usuarios_disponibles' => $users]);
        break;

    case 'equipo_guardar':
        require_role(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Método no permitido'], 405);
        $id = (int)($_POST['id'] ?? 0);
        $nombre = clean($_POST['nombre'] ?? '', 120);
        $desc = clean($_POST['descripcion'] ?? '', 255);
        $mieb = array_filter(array_map('intval', (array)($_POST['miembros'] ?? [])));
        if (str_len_safe($nombre) < 3) json_out(['ok' => false, 'errors' => ['nombre' => 'Nombre demasiado corto.']], 422);
        try {
            if ($id) db()->prepare("UPDATE equipos_soporte SET nombre=?, descripcion=? WHERE id=?")->execute([$nombre, $desc, $id]);
            else { db()->prepare("INSERT INTO equipos_soporte (nombre, descripcion) VALUES (?,?)")->execute([$nombre, $desc]); $id = (int)db()->lastInsertId(); }
        } catch (PDOException $e) { json_out(['ok' => false, 'error' => 'Ese equipo ya existe.'], 422); }
        db()->prepare("DELETE FROM equipo_miembros WHERE equipo_id=?")->execute([$id]);
        $ins = db()->prepare("INSERT IGNORE INTO equipo_miembros (equipo_id, usuario_id) VALUES (?,?)");
        foreach ($mieb as $uid) $ins->execute([$id, $uid]);
        auditar('equipo_guardado', "Equipo «{$nombre}» con " . count($mieb) . " miembro(s).");
        json_out(['ok' => true, 'mensaje' => 'Equipo guardado.']);
        break;

    case 'equipo_eliminar':
        require_role(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Método no permitido'], 405);
        db()->prepare("DELETE FROM equipos_soporte WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
        auditar('equipo_eliminado', 'Equipo eliminado.');
        json_out(['ok' => true, 'mensaje' => 'Equipo eliminado.']);
        break;

    /* ---- Configuración general + SMTP ---- */
    case 'config':
        require_role(['admin']);
        $c = config_db();
        if (!empty($c['smtp_password'])) $c['smtp_password'] = '••••••••'; // no se expone el real
        json_out(['ok' => true, 'config' => $c]);
        break;

    case 'config_guardar':
        require_role(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Método no permitido'], 405);
        $pares = [];
        foreach (['nombre_sistema','nombre_institucion','logo','correo_contacto','telefono_contacto',
                  'zona_horaria','max_upload_mb','smtp_host','smtp_puerto','smtp_usuario','smtp_remitente'] as $k) {
            if (isset($_POST[$k])) $pares[$k] = clean($_POST[$k], 200);
        }
        if (isset($_POST['color_primario']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['color_primario'])) {
            $pares['color_primario'] = strtoupper($_POST['color_primario']);
        }
        $pares['smtp_activo'] = (($_POST['smtp_activo'] ?? '') === '1') ? '1' : '0';
        if (!empty($_POST['smtp_password'])) $pares['smtp_password'] = (string)$_POST['smtp_password'];
        if (!empty($pares['zona_horaria'])) {
            try { new DateTimeZone($pares['zona_horaria']); } catch (Throwable $e) { unset($pares['zona_horaria']); }
        }
        config_guardar($pares);
        auditar('configuracion_guardada', 'Configuración general/SMTP actualizada.');
        json_out(['ok' => true, 'mensaje' => 'Configuración guardada correctamente.']);
        break;

    case 'smtp_prueba':
        require_role(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Método no permitido'], 405);
        $para = filter_var(trim($_POST['para'] ?? ''), FILTER_VALIDATE_EMAIL);
        if (!$para) json_out(['ok' => false, 'error' => 'Indique un correo de destino válido.'], 422);
        $enviado = enviar_correo_smtp($para, 'Prueba SMTP — ' . APP_NAME,
            '<p>Este es un correo de prueba del sistema <strong>' . htmlspecialchars(APP_NAME) . '</strong>.</p>');
        json_out(['ok' => $enviado, 'mensaje' => $enviado ? 'Correo de prueba enviado a ' . $para : 'No se pudo enviar. Revise host/puerto/credenciales y que smtp_activo=1.'], $enviado ? 200 : 502);
        break;

    /* ---- Auditoría ---- */
    case 'auditoria':
        require_role(['admin']);
        $limit = min(500, max(20, (int)($_GET['limit'] ?? 200)));
        $q = trim(clean($_GET['q'] ?? '', 80));
        if ($q !== '') {
            $st = db()->prepare("SELECT id, usuario, accion, detalle, ip, DATE_FORMAT(creado_en,'%d/%m/%Y %H:%i:%s') AS fecha
                                 FROM auditoria WHERE accion LIKE ? OR usuario LIKE ? OR detalle LIKE ?
                                 ORDER BY id DESC LIMIT $limit");
            $like = "%$q%"; $st->execute([$like, $like, $like]);
        } else {
            $st = db()->prepare("SELECT id, usuario, accion, detalle, ip, DATE_FORMAT(creado_en,'%d/%m/%Y %H:%i:%s') AS fecha
                                 FROM auditoria ORDER BY id DESC LIMIT $limit");
            $st->execute();
        }
        json_out(['ok' => true, 'data' => $st->fetchAll()]);
        break;

    default:
        json_out(['ok' => false, 'error' => 'Acción no reconocida'], 400);
}
