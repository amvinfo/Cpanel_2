<?php require_once __DIR__ . '/config.php'; ?>
<!DOCTYPE html>
<html lang="es-CL">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= APP_NAME ?> — Portal del Ciudadano</title>
  <meta name="description" content="Mesa de Ayuda institucional del Gobierno de Chile. Solicite soporte técnico y consulte el estado de sus tickets.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/gobcl.css">
</head>
<body>

<a class="skip-link" href="#contenido">Saltar al contenido principal</a>

<!-- ============ CABECERA INSTITUCIONAL ============ -->
<header class="barra-gobierno">
  <div class="barra-gobierno__inner">
    <a class="marca" href="index.php" aria-label="Gobierno de Chile — Helpdesk, inicio">
      <span class="marca__logo" aria-hidden="true">GdC</span>
      <span class="marca__texto">GOBIERNO DE CHILE<small>Ministerio Secretaría General · Mesa de Ayuda TI</small></span>
    </a>
    <a class="btn-funcionarios" href="login.php" aria-label="Acceso para funcionarios: iniciar sesión">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6.5 8-6.5s8 2.5 8 6.5"/></svg>
      Acceso Funcionarios
    </a>
  </div>
</header>

<!-- ============ HERO + BUSCADOR ============ -->
<section class="hero" role="search">
  <div class="hero__inner">
    <h1>¿En qué podemos ayudarte hoy?</h1>
    <p class="subtitulo">Busque en nuestra base de conocimiento o registre una solicitud de soporte técnico. Respondemos a la brevedad.</p>
    <form class="buscador-hero" id="buscador-hero" autocomplete="off">
      <label class="sr-only" for="q-buscador">Buscar en la base de conocimiento</label>
      <input type="search" id="q-buscador" name="q" placeholder="Ej.: VPN, contraseña, impresora…">
      <button type="submit">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
        Buscar
      </button>
    </form>
    <div id="resultado-busqueda" class="resultado-busqueda" aria-live="polite"></div>
  </div>
</section>

<main id="contenido" class="contenedor">

  <!-- ============ TARJETAS DE ACCIÓN RÁPIDA ============ -->
  <section class="acciones-rapidas" aria-label="Acciones rápidas">

    <button type="button" class="tarjeta-accion" id="btn-soporte"
            aria-expanded="false" aria-controls="panel-solicitud">
      <span class="tarjeta-accion__icono icono-soporte" aria-hidden="true">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14.7 6.3a4.5 4.5 0 0 0-6 6L3 18l3 3 5.7-5.7a4.5 4.5 0 0 0 6-6L14 13l-3-3z"/></svg>
      </span>
      <span>
        <h2>Solicitar Soporte Técnico</h2>
        <p>Registre una nueva solicitud y un analista le ayudará. Complete el formulario sin salir de esta página.</p>
      </span>
      <span class="tarjeta-accion__chevron" aria-hidden="true">▼</span>
    </button>

    <button type="button" class="tarjeta-accion" id="btn-consulta"
            aria-expanded="false" aria-controls="panel-consulta">
      <span class="tarjeta-accion__icono icono-consulta" aria-hidden="true">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
      </span>
      <span>
        <h2>Consultar Estado de Ticket</h2>
        <p>Ingrese su número de ticket o código de seguimiento y vea el avance, historial y respuestas.</p>
      </span>
      <span class="tarjeta-accion__chevron" aria-hidden="true">▼</span>
    </button>
  </section>

  <!-- ============ PANEL INLINE: CREAR NUEVA SOLICITUD ============ -->
  <section class="panel-inline" id="panel-solicitud" aria-labelledby="btn-soporte">
    <div class="panel-card">
      <h3>📝 Crear Nueva Solicitud</h3>
      <form id="form-solicitud" enctype="multipart/form-data" novalidate>
        <div class="form-grid">
          <div class="campo">
            <label for="f-nombre">Nombre Completo <span class="obligatorio" aria-hidden="true">*</span></label>
            <input type="text" id="f-nombre" name="nombre" required autocomplete="name" placeholder="Ej.: María Pérez González">
            <span class="error-campo" data-error="nombre" role="alert"></span>
          </div>
          <div class="campo">
            <label for="f-email">Correo Electrónico <span class="obligatorio" aria-hidden="true">*</span></label>
            <input type="email" id="f-email" name="email" required autocomplete="email" placeholder="nombre@correo.gob.cl">
            <span class="error-campo" data-error="email" role="alert"></span>
          </div>
          <div class="campo">
            <label for="f-asunto">Asunto <span class="obligatorio" aria-hidden="true">*</span></label>
            <input type="text" id="f-asunto" name="asunto" required maxlength="200" placeholder="Resumen breve del problema">
            <span class="error-campo" data-error="asunto" role="alert"></span>
          </div>
          <div class="campo">
            <label for="f-categoria">Categoría <span class="obligatorio" aria-hidden="true">*</span></label>
            <select id="f-categoria" name="categoria_id" required>
              <option value="">— Cargando categorías… —</option>
            </select>
            <span class="error-campo" data-error="categoria" role="alert"></span>
          </div>
          <div class="campo completo">
            <label for="f-descripcion">Descripción Detallada <span class="obligatorio" aria-hidden="true">*</span></label>
            <textarea id="f-descripcion" name="descripcion" required rows="5" placeholder="Cuéntenos qué ocurre, desde cuándo y qué ha intentado (mínimo 15 caracteres)…"></textarea>
            <span class="error-campo" data-error="descripcion" role="alert"></span>
          </div>
          <div class="campo completo">
            <label for="f-archivo">Adjuntar Archivo (opcional)</label>
            <input type="file" id="f-archivo" name="archivo" accept=".pdf,.png,.jpg,.jpeg,.gif,.doc,.docx,.txt,.zip">
            <span class="ayuda">Formatos: PDF, imagen, Word, TXT o ZIP · Máximo <?= MAX_UPLOAD_MB ?> MB.</span>
          </div>
        </div>
        <div style="margin-top:1.3rem">
          <button type="submit" class="btn btn-primario" id="btn-enviar-solicitud">Enviar Solicitud</button>
        </div>
        <div id="resultado-solicitud" aria-live="polite"></div>
      </form>
    </div>
  </section>

  <!-- ============ PANEL INLINE: BUSCAR MI TICKET ============ -->
  <section class="panel-inline" id="panel-consulta" aria-labelledby="btn-consulta">
    <div class="panel-card" style="border-left-color:var(--rojo-acento)">
      <h3>🔎 Buscar mi Ticket</h3>
      <form id="form-consulta" autocomplete="off">
        <div class="form-grid">
          <div class="campo" style="grid-column:1/-1;display:flex;flex-direction:row;gap:.8rem;align-items:flex-end;flex-wrap:wrap">
            <div style="flex:1 1 260px">
              <label for="f-consulta-ref">Número de Ticket o Código de Seguimiento</label>
              <input type="text" id="f-consulta-ref" name="referencia" placeholder="Ej.: TKT-2026-00013 o C9F4-H2J6-K8L1" required>
            </div>
            <button type="submit" class="btn btn-rojo">Consultar</button>
          </div>
        </div>
        <p class="ayuda" style="font-size:.8rem;color:var(--gris-texto);margin:.6rem 0 0">Recibirá ambos códigos al crear una solicitud. Puede consultar sin tener cuenta.</p>
      </form>
      <div id="zona-consulta" aria-live="polite"></div>
    </div>
  </section>

  <!-- ============ EXPLORACIÓN POR CATEGORÍAS ============ -->
  <h2 class="seccion-titulo">Explorar por categoría</h2>
  <section class="grid-categorias" id="grid-categorias" aria-label="Categorías de ayuda">
    <!-- Se carga dinámicamente desde api/public.php?action=categorias -->
    <div class="vacio-msg"><span class="spinner spinner-azul"></span> Cargando categorías…</div>
  </section>
</main>

<!-- ============ FOOTER ============ -->
<footer class="footer">
  <div class="footer__inner">
    <div>Gobierno de Chile · Helpdesk Institucional — Mesa de Ayuda TI</div>
    <div><a href="mailto:soporte@helpdesk.gob.cl">soporte@helpdesk.gob.cl</a> · <a href="login.php">Acceso Funcionarios</a></div>
  </div>
</footer>

<script src="assets/js/portal.js"></script>
</body>
</html>
