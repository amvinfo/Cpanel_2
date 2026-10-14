/* =====================================================================
   assets/js/dashboard.js — Panel Interno de Funcionarios
   Estadísticas REALES (fin del "Cargando estadísticas..."), filtros,
   tabla de gestión, modal de detalle con acciones y comentarios.
   ===================================================================== */
(function () {
  'use strict';

  const API = 'api/panel.php';
  const $  = (s, c) => (c || document).querySelector(s);
  const $$ = (s, c) => Array.from((c || document).querySelectorAll(s));

  const ESTADOS_ES  = { abierto: 'Abierto', en_proceso: 'En Proceso', resuelto: 'Resuelto', cerrado: 'Cerrado' };
  const PRIORIDADES = ['baja', 'media', 'alta', 'critica'];

  function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  async function get(action, params) {
    const url = new URL(API, location.href);
    url.searchParams.set('action', action);
    Object.entries(params || {}).forEach(([k, v]) => { if (v !== '' && v != null) url.searchParams.set(k, v); });
    const res = await fetch(url.toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
    if (res.status === 401) { location.href = 'login.php'; throw new Error('Sesión expirada'); }
    return res.json();
  }
  async function post(action, obj) {
    const url = new URL(API, location.href);
    url.searchParams.set('action', action);
    const fd = new FormData();
    Object.entries(obj).forEach(([k, v]) => fd.set(k, v));
    const res = await fetch(url.toString(), { method: 'POST', body: fd, credentials: 'same-origin' });
    return res.json();
  }

  /* ---------------- Toast ---------------- */
  function toast(msg, esError) {
    const cont = $('#toast');
    const el = document.createElement('div');
    el.className = 'toast-item' + (esError ? ' error' : '');
    el.textContent = msg;
    cont.appendChild(el);
    setTimeout(() => el.remove(), 4200);
  }

  /* =====================================================================
     1) ESTADÍSTICAS EN TIEMPO REAL — consulta directa a la BD
     ===================================================================== */
  async function cargarStats() {
    const zonaErr = $('#stats-error');
    zonaErr.style.display = 'none';
    try {
      const json = await get('stats');
      if (!json.ok) throw new Error(json.error || 'Sin respuesta');
      const s = json.stats;
      const mapa = {
        total: s.total, abiertos: s.abiertos, en_proceso: s.en_proceso, resueltos: s.resueltos,
        criticos: s.criticos, sin_asignar: s.sin_asignar, creados_hoy: s.creados_hoy,
        prom_horas: s.prom_horas == null ? '—' : s.prom_horas
      };
      Object.entries(mapa).forEach(([k, v]) => {
        const el = $(`[data-stat="${k}"]`);
        if (el) { el.classList.remove('skeleton'); el.textContent = v; }
      });
    } catch (e) {
      $$('.stat-card__valor').forEach(el => { el.classList.remove('skeleton'); el.textContent = '—'; });
      zonaErr.textContent = 'No se pudieron cargar las estadísticas: ' + e.message + '. Verifique conexión a la base de datos.';
      zonaErr.style.display = 'block';
    }
  }

  /* =====================================================================
     2) LISTADO + FILTROS
     ===================================================================== */
  async function cargarTickets() {
    const tbody = $('#tbody-tickets');
    tbody.innerHTML = '<tr><td colspan="9" class="vacio-msg"><span class="spinner spinner-azul"></span> Cargando tickets…</td></tr>';
    try {
      const json = await get('tickets', {
        q: $('#filtro-q').value.trim(),
        estado: $('#filtro-estado').value,
        prioridad: $('#filtro-prioridad').value,
        asignado: $('#filtro-asignado').value
      });
      if (!json.ok) throw new Error(json.error || 'Error');
      const data = json.data || [];
      if (!data.length) {
        tbody.innerHTML = '<tr><td colspan="9" class="vacio-msg">No hay solicitudes que coincidan con los filtros.</td></tr>';
        return;
      }
      tbody.innerHTML = data.map(t => `
        <tr>
          <td class="codigo">#${esc(t.codigo)}</td>
          <td>${esc(t.solicitante)}<br><small style="color:var(--gris-texto)">${esc(t.email)}</small></td>
          <td style="max-width:260px">${esc(t.asunto)}</td>
          <td>${esc(t.categoria || '—')}</td>
          <td><span class="pri-${esc(t.prioridad)}">${esc(t.prioridad)}</span></td>
          <td><span class="badge badge-${esc(t.estado)}">${ESTADOS_ES[t.estado] || esc(t.estado)}</span></td>
          <td>${esc(t.tecnico || 'Por asignar')}</td>
          <td style="white-space:nowrap">${esc(t.creado)}</td>
          <td><button class="btn btn-primario btn-chico" data-ver="${t.id}">Ver</button></td>
        </tr>`).join('');

      $$('[data-ver]', tbody).forEach(b => b.addEventListener('click', () => abrirDetalle(+b.dataset.ver)));
    } catch (e) {
      tbody.innerHTML = `<tr><td colspan="9" class="vacio-msg" style="color:var(--rojo-acento)">⚠️ ${esc(e.message)}</td></tr>`;
    }
  }

  $('#btn-filtrar').addEventListener('click', cargarTickets);
  $('#filtro-q').addEventListener('keyup', (ev) => { if (ev.key === 'Enter') cargarTickets(); });
  ['#filtro-estado', '#filtro-prioridad', '#filtro-asignado'].forEach(sel =>
    $(sel).addEventListener('change', cargarTickets));
  $('#btn-limpiar').addEventListener('click', () => {
    ['#filtro-q', '#filtro-estado', '#filtro-prioridad', '#filtro-asignado'].forEach(sel => $(sel).value = '');
    cargarTickets();
  });

  /* ---------------- Técnicos para filtro/asignación ---------------- */
  async function cargarTecnicos() {
    try {
      const json = await get('tecnicos');
      if (json.ok) {
        $('#filtro-asignado').innerHTML = '<option value="">Todo el equipo</option>' +
          json.data.map(u => `<option value="${u.id}">${esc(u.nombre)} (${esc(u.rol)})</option>`).join('');
      }
    } catch (e) { /* silencioso */ }
  }

  /* =====================================================================
     3) MODAL DETALLE + ACCIONES (estado / prioridad / asignación / comentario)
     ===================================================================== */
  let tecnicosCache = [];

  async function abrirDetalle(id) {
    const overlay = $('#modal-detalle');
    const cuerpo = $('#modal-cuerpo');
    overlay.classList.add('visible');
    document.body.style.overflow = 'hidden';
    cuerpo.innerHTML = '<p><span class="spinner spinner-azul"></span> Cargando detalle…</p>';

    try {
      const [dj, tj] = await Promise.all([get('detalle', { id }), get('tecnicos')]);
      if (!dj.ok) throw new Error(dj.error || 'No encontrado');
      const t = dj.ticket;
      tecnicosCache = tj.ok ? tj.data : [];

      const hist = (t.historial || []).map(h => `
        <li class="tipo-${esc(h.tipo)}">
          <span class="h-autor">${esc(h.autor)}</span><span class="h-fecha">${esc(h.fecha)}</span>
          <div class="h-mensaje">${esc(h.mensaje)}</div>
        </li>`).join('');

      const optsEstado = Object.keys(ESTADOS_ES).map(k =>
        `<option value="${k}" ${k === t.estado ? 'selected' : ''}>${ESTADOS_ES[k]}</option>`).join('');
      const optsPrio = PRIORIDADES.map(p =>
        `<option value="${p}" ${p === t.prioridad ? 'selected' : ''}>${p}</option>`).join('');
      const optsAsig = '<option value="0">— Sin asignar —</option>' +
        tecnicosCache.map(u => `<option value="${u.id}" ${u.id == t.asignado_a ? 'selected' : ''}>${esc(u.nombre)}</option>`).join('');

      $('#modal-titulo').textContent = '#' + t.codigo + ' — ' + t.asunto;
      cuerpo.innerHTML = `
        <div class="meta-grid">
          <div class="meta-item"><span>Solicitante</span><strong>${esc(t.solicitante)}</strong></div>
          <div class="meta-item"><span>Correo</span><strong>${esc(t.email)}</strong></div>
          <div class="meta-item"><span>Categoría</span><strong>${esc(t.categoria || '—')}</strong></div>
          <div class="meta-item"><span>Folio público</span><strong>${esc(t.token_consulta)}</strong></div>
          <div class="meta-item"><span>Ingreso</span><strong>${esc(t.creado)}</strong></div>
          <div class="meta-item"><span>Actualizado</span><strong>${esc(t.actualizado)}</strong></div>
        </div>
        <div>
          <h4 style="margin:0 0 .4rem;color:var(--azul-obscuro)">Descripción</h4>
          <p style="margin:0;background:var(--gris-fondo);padding:.8rem 1rem;border-radius:8px">${esc(t.descripcion)}</p>
        </div>

        <div class="acciones-ticket" aria-label="Acciones sobre el ticket">
          <div class="campo"><label for="sel-estado">Estado</label><select id="sel-estado">${optsEstado}</select></div>
          <div class="campo"><label for="sel-prioridad">Prioridad</label><select id="sel-prioridad">${optsPrio}</select></div>
          <div class="campo"><label for="sel-asignado">Asignar a</label><select id="sel-asignado">${optsAsig}</select></div>
          <div class="campo" style="justify-content:flex-end">
            <label aria-hidden="true">&nbsp;</label>
            <button class="btn btn-rojo btn-chico" id="btn-guardar-cambios">Guardar cambios</button>
          </div>
        </div>

        <div>
          <h4 style="margin:0 0 .8rem;color:var(--azul-obscuro)">Historial</h4>
          <ul class="timeline" id="lista-hist">${hist || '<li>Sin movimientos.</li>'}</ul>
        </div>

        <form id="form-comentario-interno" class="campo completo">
          <label for="txt-comentario">Agregar respuesta interna / al ciudadano</label>
          <textarea id="txt-comentario" rows="3" required placeholder="Escriba la solución, solicitud de antecedentes u observación…"></textarea>
          <div style="margin-top:.5rem"><button class="btn btn-primario btn-chico" type="submit">Comentar</button></div>
        </form>`;

      // Guardar cambios de estado/prioridad/asignación
      $('#btn-guardar-cambios').addEventListener('click', async () => {
        const json = await post('actualizar', {
          id: t.id,
          estado: $('#sel-estado').value,
          prioridad: $('#sel-prioridad').value,
          asignado_a: $('#sel-asignado').value
        });
        toast(json.ok ? 'Ticket actualizado correctamente.' : ('Error: ' + json.error), !json.ok);
        if (json.ok) { recargarTrasCambio(t.id); }
      });

      // Comentario interno
      $('#form-comentario-interno').addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const msg = $('#txt-comentario').value.trim();
        const json = await post('comentar', { id: t.id, mensaje: msg });
        toast(json.ok ? 'Comentario agregado.' : ('Error: ' + json.error), !json.ok);
        if (json.ok) { $('#txt-comentario').value = ''; recargarTrasCambio(t.id); }
      });
    } catch (e) {
      cuerpo.innerHTML = `<div class="aviso-error">⚠️ ${esc(e.message)}</div>`;
    }
  }

  async function recargarTrasCambio(id) {
    // re-render modal + stats + tabla (todo desde la BD → tiempo real)
    await abrirDetalle(id);
    cargarStats();
    cargarTickets();
  }

  function cerrarModal() {
    $('#modal-detalle').classList.remove('visible');
    document.body.style.overflow = '';
  }
  $('#modal-cerrar').addEventListener('click', cerrarModal);
  $('#modal-cerrar-2').addEventListener('click', cerrarModal);
  $('#modal-detalle').addEventListener('click', (ev) => { if (ev.target === ev.currentTarget) cerrarModal(); });
  document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') cerrarModal(); });

  /* =====================================================================
     4) NAV / REFRESH / LOGOUT
     ===================================================================== */
  $('#btn-refrescar').addEventListener('click', () => { cargarStats(); cargarTickets(); toast('Datos actualizados.'); });

  $('#btn-logout').addEventListener('click', async () => {
    await post('logout', {});
    location.href = 'login.php';
  });

  $$('.dash-nav a[data-seccion]').forEach(a =>
    a.addEventListener('click', () => {
      $$('.dash-nav a').forEach(x => x.classList.remove('activo'));
      a.classList.add('activo');
    }));

  /* ---------------- Init ---------------- */
  document.addEventListener('DOMContentLoaded', () => {
    cargarStats();      // ← ya no queda en "Cargando estadísticas..."
    cargarTecnicos();
    cargarTickets();
  });
})();
