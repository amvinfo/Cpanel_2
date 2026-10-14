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
        <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-top:1.5rem">
          <h2 class="seccion-titulo" style="margin:0;flex:1">Gestión de Tickets <small id="contador-tickets" style="font-size:.85rem;font-weight:600;color:var(--gris-texto)"></small></h2>
          <?php if (in_array($u['rol'], ['admin','supervisor'], true)): ?>
          <button class="btn btn-primario" id="btn-nuevo-ticket" type="button">＋ Nuevo Ticket</button>
          <?php endif; ?>
        </div>

        <div class="tabla-card">
          <div class="tabla-toolbar" role="search">
            <input type="search" id="filtro-q" placeholder="Buscar por Nº, solicitante, asunto o correo…" aria-label="Buscar tickets">
            <select id="filtro-estado" aria-label="Filtrar por estado">
              <option value="">Todos los estados</option>
              <?php foreach (ESTADOS_TICKET as $clave => $etiqueta): ?>
              <option value="<?= htmlspecialchars($clave) ?>"><?= htmlspecialchars($etiqueta) ?></option>
              <?php endforeach; ?>
            </select>
            <select id="filtro-prioridad" aria-label="Filtrar por prioridad">
              <option value="">Todas las prioridades</option>
              <?php foreach (array_reverse(PRIORIDADES_TICKET, true) as $clave => $etiqueta): ?>
              <option value="<?= htmlspecialchars($clave) ?>"><?= htmlspecialchars($etiqueta) ?></option>
              <?php endforeach; ?>
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
  <div class="modal modal--ancho">
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

<?php if (in_array($u['rol'], ['admin','supervisor'], true)): ?>
<!-- ===== MODAL NUEVO TICKET INTERNO ===== -->
<div class="modal-overlay" id="modal-nuevo" role="dialog" aria-modal="true" aria-labelledby="modal-nuevo-titulo">
  <div class="modal modal--ancho">
    <header class="modal__header">
      <h2 id="modal-nuevo-titulo">Nuevo Ticket (Interno)</h2>
      <button class="modal__cerrar" id="nuevo-cerrar" aria-label="Cerrar">×</button>
    </header>
    <form class="modal__cuerpo" id="form-nuevo-ticket" novalidate>
      <div class="form-grid">
        <div>
          <label for="nt-solicitante">Solicitante *</label>
          <input id="nt-solicitante" name="solicitante" type="text" required list="lista-solicitantes" placeholder="Nombre del funcionario o área" autocomplete="off">
          <datalist id="lista-solicitantes"></datalist>
          <span class="error-campo" data-err="solicitante"></span>
        </div>
        <div>
          <label for="nt-email">Correo *</label>
          <input id="nt-email" name="email" type="email" required placeholder="usuario@correo.gob.cl" autocomplete="off">
          <span class="error-campo" data-err="email"></span>
        </div>
        <div>
          <label for="nt-categoria">Categoría *</label>
          <select id="nt-categoria" name="categoria_id" required><option value="">Cargando…</option></select>
          <span class="error-campo" data-err="categoria_id"></span>
        </div>
        <div>
          <label for="nt-tipo">Tipo *</label>
          <select id="nt-tipo" name="tipo" required></select>
          <span class="error-campo" data-err="tipo"></span>
        </div>
        <div>
          <label for="nt-prioridad">Prioridad</label>
          <select id="nt-prioridad" name="prioridad"></select>
        </div>
        <div>
          <label for="nt-origen">Origen</label>
          <select id="nt-origen" name="origen">
            <option value="interno" selected>Interno</option>
            <option value="publico">Público</option>
          </select>
        </div>
        <div>
          <label for="nt-departamento">Departamento</label>
          <select id="nt-departamento" name="departamento"><option value="">— Sin departamento —</option></select>
        </div>
        <div>
          <label for="nt-ubicacion">Ubicación</label>
          <select id="nt-ubicacion" name="ubicacion"><option value="">— Sin ubicación —</option></select>
        </div>
        <div>
          <label for="nt-asignado">Asignar a</label>
          <select id="nt-asignado" name="asignado_a"><option value="0">— Sin asignar —</option></select>
        </div>
        <div class="completo">
          <label for="nt-asunto">Asunto *</label>
          <input id="nt-asunto" name="asunto" type="text" required maxlength="200" placeholder="Resumen breve del requerimiento">
          <span class="error-campo" data-err="asunto"></span>
        </div>
        <div class="completo">
          <label for="nt-descripcion">Descripción *</label>
          <textarea id="nt-descripcion" name="descripcion" rows="5" required placeholder="Detalle técnico, antecedentes, pasos para reproducir…"></textarea>
          <span class="error-campo" data-err="descripcion"></span>
        </div>
      </div>
    </form>
    <footer class="modal__pie">
      <button class="btn btn-primario" id="btn-crear-ticket" type="submit" form="form-nuevo-ticket">Crear Ticket</button>
      <button class="btn btn-secundario" id="nuevo-cancelar" type="button">Cancelar</button>
    </footer>
  </div>
</div>
<?php endif; ?>

<div id="toast" aria-live="polite"></div>

<script src="assets/js/dashboard.js"></script>
</body>
</html>
