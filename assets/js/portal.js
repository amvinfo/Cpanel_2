/* =====================================================================
   assets/js/portal.js — Comportamiento SPA del Portal Ciudadano
   Fetch API + Vanilla JS. Sin recargas ni redirecciones.
   ===================================================================== */
(function () {
  'use strict';

  const API = 'api/public.php';

  /* ---------------- Iconos SVG inline (sin dependencias) ---------------- */
  const ICONS = {
    wifi: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 12.5a10 10 0 0 1 14 0"/><path d="M8.5 16a5.5 5.5 0 0 1 7 0"/><circle cx="12" cy="19.5" r="1" fill="currentColor"/></svg>',
    lock: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>',
    soporte: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 4h16v12H8l-4 4z"/><path d="M9 9h6M9 12h4"/></svg>',
    hardware: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="5" width="18" height="10" rx="2"/><path d="M8 19h8M12 15v4"/></svg>',
    shield: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z"/><path d="M9 12l2 2 4-4"/></svg>',
    correo: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>',
    buscar: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>'
  };
  window.PORTAL_ICONS = ICONS;

  const $ = (sel, ctx) => (ctx || document).querySelector(sel);
  const $$ = (sel, ctx) => Array.from((ctx || document).querySelectorAll(sel));

  /* ---------------- Utilidades fetch ---------------- */
  async function apiGet(action, params) {
    const url = new URL(API, location.href);
    url.searchParams.set('action', action);
    Object.entries(params || {}).forEach(([k, v]) => url.searchParams.set(k, v));
    const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
    return res.json();
  }
  async function apiPost(action, formData) {
    const url = new URL(API, location.href);
    url.searchParams.set('action', action);
    const res = await fetch(url.toString(), { method: 'POST', body: formData });
    return res.json();
  }
  function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  const ESTADOS_ES = {
    nuevo: 'Nuevo', abierto: 'Abierto', asignado: 'Asignado', en_atencion: 'En atención',
    esperando_solicitante: 'Esperando solicitante', esperando_tercero: 'Esperando tercero',
    escalado: 'Escalado', resuelto: 'Resuelto', cerrado: 'Cerrado', reabierto: 'Reabierto',
    cancelado: 'Cancelado', en_proceso: 'En atención',
  };

  /* =====================================================================
     1) ACORDEÓN INLINE — tarjetas de acción rápida
     ===================================================================== */
  $$('.tarjeta-accion').forEach(btn => {
    btn.addEventListener('click', () => {
      const targetId = btn.getAttribute('aria-controls');
      const panel = document.getElementById(targetId);
      if (!panel) return;
      const abierto = panel.classList.contains('abierto');

      // cerrar otros paneles (comportamiento acordeón)
      $$('.panel-inline.abierto').forEach(p => {
        p.classList.remove('abierto');
        const t = $(`[aria-controls="${p.id}"]`);
        if (t) t.setAttribute('aria-expanded', 'false');
      });

      if (!abierto) {
        panel.classList.add('abierto');
        btn.setAttribute('aria-expanded', 'true');
        // enfocar el primer campo al desplegar (accesibilidad)
        setTimeout(() => {
          const first = $('input, select, textarea', panel);
          if (first) first.focus();
        }, 350);
      }
    });
  });

  /* =====================================================================
     2) CATEGORÍAS — carga dinámica (select del formulario + grid KB)
     ===================================================================== */
  let CATEGORIAS_CACHE = [];

  async function cargarCategorias() {
    const contenedorGrid = $('#grid-categorias');
    try {
      const json = await apiGet('categorias');
      if (!json.ok) throw new Error('Sin datos');
      CATEGORIAS_CACHE = json.data;

      // 2a. Select del formulario "Crear Nueva Solicitud"
      const select = $('#f-categoria');
      if (select) {
        select.innerHTML = '<option value="">— Seleccione una categoría —</option>' +
          json.data.map(c => `<option value="${c.id}">${escapeHtml(c.nombre)}</option>`).join('');
      }

      // 2b. Grid de categorías (Base de Conocimiento)
      if (contenedorGrid) {
        contenedorGrid.innerHTML = json.data.map(c => `
          <a class="cat-tarjeta" href="#kb-${c.id}" onclick="Portal.abrirKB(event, ${c.id})">
            <div class="cat-tarjeta__icono">${ICONS[c.icono] || ICONS.soporte}</div>
            <h3>${escapeHtml(c.nombre)}</h3>
            <p>${escapeHtml(c.descripcion || '')}</p>
            <div class="cat-conteo">
              <span title="Artículos de ayuda">📄 ${c.articulos} artículos</span>
              <span title="Solicitudes registradas">🎫 ${c.solicitudes} solicitudes</span>
            </div>
          </a>`).join('');
      }
    } catch (e) {
      if (contenedorGrid) {
        contenedorGrid.innerHTML = '<div class="aviso-error" style="grid-column:1/-1">No fue posible cargar las categorías. Verifique la instalación (config.php / schema.sql).</div>';
      }
    }
  }

  /* =====================================================================
     3) FORMULARIO — Crear Nueva Solicitud (inline)
     ===================================================================== */
  const formTicket = $('#form-solicitud');
  if (formTicket) {
    formTicket.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const btn = $('#btn-enviar-solicitud');
      limpiarErrores(formTicket);

      btn.disabled = true;
      btn.innerHTML = '<span class="spinner"></span> Enviando…';

      const fd = new FormData(formTicket);
      try {
        const json = await apiPost('crear_ticket', fd);

        if (!json.ok && json.errors) {
          Object.entries(json.errors).forEach(([campo, msg]) => mostrarError(campo, msg));
          throw new Error('validacion');
        }
        if (!json.ok) throw new Error(json.error || 'Error al enviar');

        const t = json.ticket;
        $('#resultado-solicitud').innerHTML = `
          <div class="aviso-exito" role="status">
            ✅ <strong>${escapeHtml(json.mensaje)}</strong><br>
            Guarde sus credenciales de consulta:<br><br>
            Nº de Ticket: <code>${escapeHtml(t.codigo)}</code> &nbsp;·&nbsp;
            Código de seguimiento: <code>${escapeHtml(t.token)}</code><br><br>
            Podrá consultar el estado y agregar comentarios desde la tarjeta
            <em>“Consultar Estado de Ticket”</em>.
            <div style="margin-top:.8rem">
              <button type="button" class="btn btn-primario btn-chico" onclick="Portal.consultar('${escapeHtml(t.codigo)}')">Ver mi ticket ahora</button>
            </div>
          </div>`;
        formTicket.reset();
      } catch (e) {
        if (e.message !== 'validacion') {
          $('#resultado-solicitud').innerHTML = `<div class="aviso-error" role="alert">⚠️ ${escapeHtml(e.message || 'Ocurrió un error inesperado. Intente nuevamente.')}</div>`;
        }
      } finally {
        btn.disabled = false;
        btn.innerHTML = 'Enviar Solicitud';
      }
    });
  }

  function limpiarErrores(form) {
    $$('.error-campo', form).forEach(el => (el.textContent = ''));
    $$('.invalido', form).forEach(el => el.classList.remove('invalido'));
    const r = $('#resultado-solicitud');
    if (r) r.innerHTML = '';
  }
  function mostrarError(campo, msg) {
    const input = formTicket.elements[campo] || formTicket.querySelector(`[name="${campo}"]`);
    if (input) {
      input.classList.add('invalido');
      const err = formTicket.querySelector(`[data-error="${campo}"]`);
      if (err) err.textContent = msg;
    }
  }

  /* =====================================================================
     4) CONSULTA DE TICKET (inline) + ficha + comentarios
     ===================================================================== */
  const formConsulta = $('#form-consulta');
  if (formConsulta) {
    formConsulta.addEventListener('submit', (ev) => {
      ev.preventDefault();
      Portal.consultar($('#f-consulta-ref').value.trim());
    });
  }

  let ticketActual = null;

  async function consultar(ref) {
    const zona = $('#zona-consulta');
    if (!ref) {
      zona.innerHTML = '<div class="aviso-error" role="alert">Ingrese su número de ticket (ej. TKT-2026-00013) o su código de seguimiento.</div>';
      return;
    }
    zona.innerHTML = '<p><span class="spinner spinner-azul"></span> Consultando…</p>';
    try {
      const json = await apiGet('consultar_ticket', { q: ref });
      if (!json.ok) {
        zona.innerHTML = `<div class="aviso-error" role="alert">⚠️ ${escapeHtml(json.error)}</div>`;
        return;
      }
      ticketActual = json.ticket;
      renderFicha(json.ticket);
    } catch (e) {
      zona.innerHTML = '<div class="aviso-error" role="alert">⚠️ Error de conexión con el servidor.</div>';
    }
  }

  function renderFicha(t) {
    const historial = (t.historial || []).map(h => `
      <li class="tipo-${escapeHtml(h.tipo)}">
        <span class="h-autor">${escapeHtml(h.autor)}</span>
        <span class="h-fecha">${escapeHtml(h.fecha)}</span>
        <div class="h-mensaje">${escapeHtml(h.mensaje)}</div>
      </li>`).join('');

    $('#zona-consulta').innerHTML = `
      <article class="ficha-ticket" aria-live="polite">
        <header class="ficha-ticket__cabecera">
          <span class="ficha-ticket__numero">#${escapeHtml(t.codigo)}</span>
          <span class="badge badge-${escapeHtml(t.estado)}">${ESTADOS_ES[t.estado] || escapeHtml(t.estado)}</span>
        </header>
        <div class="ficha-ticket__cuerpo">
          <h3 style="margin:0;color:var(--azul-obscuro)">${escapeHtml(t.asunto)}</h3>
          <div class="meta-grid">
            <div class="meta-item"><span>Solicitante</span><strong>${escapeHtml(t.solicitante)}</strong></div>
            <div class="meta-item"><span>Categoría</span><strong>${escapeHtml(t.categoria)}</strong></div>
            <div class="meta-item"><span>Prioridad</span><strong class="pri-${escapeHtml(t.prioridad)}">${escapeHtml(t.prioridad)}</strong></div>
            <div class="meta-item"><span>Folio de seguimiento</span><strong>${escapeHtml(t.token)}</strong></div>
            <div class="meta-item"><span>Ingreso</span><strong>${escapeHtml(t.creado)}</strong></div>
            <div class="meta-item"><span>Última actualización</span><strong>${escapeHtml(t.actualizado)}</strong></div>
            <div class="meta-item"><span>Técnico asignado</span><strong>${escapeHtml(t.tecnico || 'Por asignar')}</strong></div>
          </div>
          <div>
            <h4 style="margin:0 0 .4rem;color:var(--azul-obscuro)">Descripción de la solicitud</h4>
            <p style="margin:0;background:var(--gris-fondo);padding:.8rem 1rem;border-radius:8px">${escapeHtml(t.descripcion)}</p>
          </div>
          <div>
            <h4 style="margin:0 0 .8rem;color:var(--azul-obscuro)">Historial de respuestas</h4>
            <ul class="timeline">${historial || '<li>Sin novedades todavía.</li>'}</ul>
          </div>
          <form id="form-comentario" class="campo completo" autocomplete="off">
            <label for="f-comentario">Agregar un comentario a esta solicitud</label>
            <textarea id="f-comentario" name="mensaje" rows="3" placeholder="Escriba aquí su respuesta o antecedente adicional…" required></textarea>
            <div style="margin-top:.5rem">
              <button class="btn btn-rojo btn-chico" type="submit">Comentar</button>
            </div>
            <div id="resultado-comentario" aria-live="polite"></div>
          </form>
        </div>
      </article>`;

    $('#form-comentario').addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const msg = $('#f-comentario').value.trim();
      const fd = new FormData();
      fd.set('referencia', ticketActual.codigo);
      fd.set('mensaje', msg);
      const json = await apiPost('comentar_ticket', fd);
      $('#resultado-comentario').innerHTML = json.ok
        ? '<div class="aviso-exito" role="status">✅ Comentario enviado.</div>'
        : `<div class="aviso-error" role="alert">⚠️ ${escapeHtml(json.error || 'Error')}</div>`;
      if (json.ok) { consultar(ticketActual.codigo); }
    });
  }

  /* =====================================================================
     5) BUSCADOR DEL HERO — filtrado de categorías sin recargar
     ===================================================================== */
  const buscador = $('#buscador-hero');
  if (buscador) {
    buscador.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const q = $('#q-buscador').value.trim();
      const zona = $('#resultado-busqueda');
      if (!q) { zona.innerHTML = ''; return; }
      const json = await apiGet('buscar', { q });
      if (json.ok && json.categorias.length) {
        zona.innerHTML = `<div class="panel-card" style="border-left-color:var(--rojo-acento)">
          <h3>${ICONS.buscar} Resultados para “${escapeHtml(q)}”</h3>
          <ul style="margin:0;padding-left:1.2rem">
            ${json.categorias.map(c => `<li style="margin-bottom:.4rem"><a href="#kb-${c.id}" style="color:var(--azul-corporativo);font-weight:700">${escapeHtml(c.nombre)}</a> — ${escapeHtml(c.descripcion || '')}</li>`).join('')}
          </ul>
          <p style="margin:.9rem 0 0;font-size:.85rem;color:var(--gris-texto)">¿No encuentra lo que busca? Use la tarjeta <strong>Solicitar Soporte Técnico</strong>.</p>
        </div>`;
      } else {
        zona.innerHTML = `<div class="panel-card"><h3>Sin coincidencias para “${escapeHtml(q)}”</h3>
          <p style="margin:0">Le recomendamos <strong>solicitar soporte técnico</strong>: un analista le responderá a la brevedad.</p></div>`;
      }
    });
  }

  /* =====================================================================
     6) Base de conocimiento (enlace simple por categoría)
     ===================================================================== */
  function abrirKB(ev, id) {
    // Demostración: despliega el panel de consulta como acceso a KB
    // En producción puede apuntar a /kb/categoria-id
    ev.preventDefault();
    const cat = CATEGORIAS_CACHE.find(c => Number(c.id) === id);
    if (cat) {
      const zona = $('#zona-consulta');
      const panel = $('#panel-consulta');
      $$('.panel-inline.abierto').forEach(p => p.classList.remove('abierto'));
      panel.classList.add('abierto');
      $('[aria-controls="panel-consulta"]').setAttribute('aria-expanded', 'true');
      zona.innerHTML = `<div class="panel-card" id="kb-${id}">
        <h3>📚 Base de Conocimiento — ${escapeHtml(cat.nombre)}</h3>
        <p>${escapeHtml(cat.descripcion || '')}</p>
        <p style="font-size:.88rem;color:var(--gris-texto)">
          Esta categoría tiene <strong>${cat.articulos} artículos de ayuda</strong> y
          <strong>${cat.solicitudes} solicitudes</strong> relacionadas.<br>
          Si su problema no está resuelto aquí, ingrese una solicitud indicando esta categoría.
        </p>
      </div>`;
      panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }

  /* ---------------- API pública del portal ---------------- */
  window.Portal = { consultar, abrirKB, cargarCategorias };

  document.addEventListener('DOMContentLoaded', cargarCategorias);
})();
