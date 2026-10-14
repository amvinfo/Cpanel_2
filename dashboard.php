<?php
/**
 * dashboard.php — Panel Interno de Funcionarios (Mesa de Ayuda).
 * Requiere sesión; el guard lo hace panel.php (API) + este redirect.
 */
require_once __DIR__ . '/config.php';
app_session_start();

if (empty($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}
$u = $_SESSION['user'];
?>
<!DOCTYPE html>
<html lang="es-CL">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Panel Interno · <?= APP_NAME ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/gobcl.css">
</head>
<body>

<div class="dash">

  <!-- ===== SIDEBAR ===== -->
  <nav class="dash-nav" aria-label="Navegación del panel">
    <a class="marca" href="index.php">
      <span class="marca__logo" aria-hidden="true">GdC</span>
      <span class="marca__texto">HELPDESK<small>Mesa de Ayuda TI</small></span>
    </a>
    <a href="#resumen" class="activo" data-seccion="resumen">📊 Resumen</a>
    <a href="#tickets" data-seccion="tickets">🎫 Gestión de Tickets</a>
    <button type="button" class="navlink" id="btn-refrescar">🔄 Actualizar datos</button>
    <span class="sep" aria-hidden="true"></span>
    <a href="index.php">🌐 Portal Ciudadano</a>
    <button type="button" class="navlink" id="btn-logout">🚪 Cerrar Sesión</button>
  </nav>

  <!-- ===== CONTENIDO ===== -->
  <div class="dash-main">
    <header class="dash-topbar">
      <h1>Panel de Control — Mesa de Ayuda</h1>
      <div class="usuario-chip">
        <span class="avatar" aria-hidden="true"><?= strtoupper(function_exists('mb_substr') ? mb_substr($u['nombre'], 0, 1) : substr($u['nombre'], 0, 1)) ?></span>
        <span><strong><?= htmlspecialchars($u['nombre']) ?></strong><br>
          <small style="text-transform:capitalize"><?= htmlspecialchars($u['rol']) ?></small></span>
      </div>
    </header>

    <main class="dash-contenido">

      <!-- ===== TARJETAS DE ESTADÍSTICAS (carga real desde BD) ===== -->
      <section id="resumen" aria-label="Estadísticas en tiempo real">
        <div class="stat-grid" id="stat-grid">
          <div class="stat-card sc-total">
            <div><div class="stat-card__valor skeleton" data-stat="total">0000</div>
                 <div class="stat-card__etiqueta">Total Tickets</div></div>
            <div class="stat-card__icono">🎫</div>
          </div>
          <div class="stat-card sc-abiertos">
            <div><div class="stat-card__valor skeleton" data-stat="abiertos">0000</div>
                 <div class="stat-card__etiqueta">Abiertos</div></div>
            <div class="stat-card__icono">🔥</div>
          </div>
          <div class="stat-card sc-proceso">
            <div><div class="stat-card__valor skeleton" data-stat="en_proceso">0000</div>
                 <div class="stat-card__etiqueta">En Proceso</div></div>
            <div class="stat-card__icono">⚙️</div>
          </div>
          <div class="stat-card sc-resueltos">
            <div><div class="stat-card__valor skeleton" data-stat="resueltos">0000</div>
                 <div class="stat-card__etiqueta">Resueltos</div></div>
            <div class="stat-card__icono">✅</div>
          </div>
        </div>

        <div class="stat-grid">
          <div class="stat-card">
            <div><div class="stat-card__valor skeleton" data-stat="criticos" style="color:var(--rojo-acento)">00</div>
                 <div class="stat-card__etiqueta">Críticos activos</div></div>
          </div>
          <div class="stat-card">
            <div><div class="stat-card__valor skeleton" data-stat="sin_asignar">00</div>
                 <div class="stat-card__etiqueta">Sin asignar</div></div>
          </div>
          <div class="stat-card">
            <div><div class="stat-card__valor skeleton" data-stat="creados_hoy">00</div>
                 <div class="stat-card__etiqueta">Creados hoy</div></div>
          </div>
          <div class="stat-card">
            <div><div class="stat-card__valor skeleton" data-stat="prom_horas">00</div>
                 <div class="stat-card__etiqueta">Tiempo prom. resolución (h)</div></div>
          </div>
        </div>
        <p id="stats-error" role="alert" style="display:none" class="aviso-error"></p>
      </section>

      <!-- ===== GESTIÓN DE TICKETS ===== -->
      <section id="tickets" aria-label="Gestión de tickets">
        <h2 class="seccion-titulo" style="margin-top:1.5rem">Gestión de Tickets</h2>

        <div class="tabla-card">
          <div class="tabla-toolbar" role="search">
            <input type="search" id="filtro-q" placeholder="Buscar por Nº, solicitante, asunto o correo…" aria-label="Buscar tickets">
            <select id="filtro-estado" aria-label="Filtrar por estado">
              <option value="">Todos los estados</option>
              <option value="abierto">Abierto</option>
              <option value="en_proceso">En Proceso</option>
              <option value="resuelto">Resuelto</option>
              <option value="cerrado">Cerrado</option>
            </select>
            <select id="filtro-prioridad" aria-label="Filtrar por prioridad">
              <option value="">Todas las prioridades</option>
              <option value="critica">Crítica</option>
              <option value="alta">Alta</option>
              <option value="media">Media</option>
              <option value="baja">Baja</option>
            </select>
            <select id="filtro-asignado" aria-label="Filtrar por técnico asignado">
              <option value="">Todo el equipo</option>
              <!-- se carga vía API -->
            </select>
            <button class="btn btn-primario btn-chico" id="btn-filtrar">Aplicar</button>
            <button class="btn btn-secundario btn-chico" id="btn-limpiar">Limpiar</button>
          </div>

          <div class="tabla-scroll">
            <table class="dTabla" aria-label="Listado de tickets">
              <thead>
                <tr>
                  <th scope="col">Ticket</th>
                  <th scope="col">Solicitante</th>
                  <th scope="col">Asunto</th>
                  <th scope="col">Categoría</th>
                  <th scope="col">Prioridad</th>
                  <th scope="col">Estado</th>
                  <th scope="col">Técnico</th>
                  <th scope="col">Creado</th>
                  <th scope="col">Acción</th>
                </tr>
              </thead>
              <tbody id="tbody-tickets">
                <tr><td colspan="9" class="vacio-msg"><span class="spinner spinner-azul"></span> Cargando tickets…</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>
    </main>
  </div>
</div>

<!-- ===== MODAL DETALLE ===== -->
<div class="modal-overlay" id="modal-detalle" role="dialog" aria-modal="true" aria-labelledby="modal-titulo">
  <div class="modal">
    <header class="modal__header">
      <h2 id="modal-titulo">Detalle del Ticket</h2>
      <button class="modal__cerrar" id="modal-cerrar" aria-label="Cerrar detalle">×</button>
    </header>
    <div class="modal__cuerpo" id="modal-cuerpo"><!-- dinámico --></div>
    <footer class="modal__pie">
      <button class="btn btn-secundario" id="modal-cerrar-2">Cerrar</button>
    </footer>
  </div>
</div>

<div id="toast" aria-live="polite"></div>

<script src="assets/js/dashboard.js"></script>
</body>
</html>
