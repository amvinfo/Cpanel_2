/* =====================================================================
   assets/js/dashboard.js — Panel Interno de Funcionarios
   Estadísticas REALES (fin del "Cargando estadísticas..."), filtros,
   tabla de gestión, modal Nuevo Ticket interno, modal de detalle con
   acciones (estado / prioridad / asignación) y caja dual de respuesta.
   Incluye lectura tolerante de respuestas JSON (evita el error
   "Failed to execute 'json' on 'Response': Unexpected end of JSON input"
   cuando el servidor devuelve una respuesta vacía o HTML de error).
   ===================================================================== */
(function () {
  'use strict';

  const API = 'api/panel.php';
  const $  = (s, c) => (c || document).querySelector(s);
  const $$ = (s, c) => Array.from((c || document).querySelectorAll(s));

  /* Vocabulario unificado del ciclo de vida (igual que config.php) */
  const ESTADOS_ES = {
    nuevo: 'Nuevo', abierto: 'Abierto', asignado: 'Asignado', en_atencion: 'En atención',
    esperando_solicitante: 'Esperando solicitante', esperando_tercero: 'Esperando tercero',
    escalado: 'Escalado', resuelto: 'Resuelto', cerrado: 'Cerrado', reabierto: 'Reabierto',
    cancelado: 'Cancelado', en_proceso: 'En Proceso',
  };
  const PRIORIDADES = ['urgente', 'alta', 'media', 'baja'];
  const TIPOS = ['Incidencia', 'Requerimiento', 'Consulta', 'Solicitud de acceso', 'Mantención'];
  const DEPARTAMENTOS = [
    'Dirección de Planificación', 'Dirección de Finanzas', 'Dirección de RRHH',
    'Dirección de Operaciones', 'Unidad de Personas', 'Secretaría Regional Ministerial',
    'Oficina de Partes', 'Unidad de Auditoría',
  ];
  const UBICACIONES = [
    'Edificio Central — Piso 1', 'Edificio Central — Piso 2', 'Edificio Central — Piso 3',
    'Anexo A', 'Anexo B', 'Remoto / Teletrabajo',
  ];

  function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  /* ---------------- Lectura segura de JSON (fix del error reportado) ----------------
     Si la respuesta llega vacía o con HTML (advertencias PHP, páginas 500 del hosting),
     res.json() lanza "Unexpected end of JSON input". Aquí se lee como texto primero y
     se entrega un error accionable + los últimos mensajes del log de PHP si existen. */
  async function leerJson(res) {
    const txt = await res.text();
    if (!txt.trim()) {
      throw new Error(`El servidor devolvió una respuesta vacía (HTTP ${res.status}). ` +
        'Esto suele deberse a un error de PHP con display_errors apagado o salida extra antes del JSON.');
    }
    try {
      return JSON.parse(txt);
    } catch (_) {
      const fragmento = txt.slice(0, 160).replace(/\s+/g, ' ');
      throw new Error(`Respuesta no válida del servidor (HTTP ${res.status}): "${fragmento}…"`);
    }
  }

  async function get(action, params) {
    const url = new URL(API, location.href);
    url.searchParams.set('action', action);
    Object.entries(params || {}).forEach(([k, v]) => { if (v !== '' && v != null) url.searchParams.set(k, v); });
    const res = await fetch(url.toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
    if (res.status === 401) { location.href = 'login.php'; throw new Error('Sesión expirada'); }
    return leerJson(res);
  }
  async function post(action, obj) {
    const url = new URL(API, location.href);
    url.searchParams.set('action', action);
    const fd = new FormData();
    Object.entries(obj).forEach(([k, v]) => fd.set(k, v == null ? '' : v));
    const res = await fetch(url.toString(), { method: 'POST', body: fd, credentials: 'same-origin' });
    return leerJson(res);
  }

  /* ---------------- Toast ---------------- */
  function toast(msg, esError) {
    const cont = $('#toast');
    if (!cont) return;
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
      zonaErr.textContent = 'No se pudieron cargar las estadísticas: ' + e.message;
      zonaErr.style.display = 'block';
    }
  }

  /* =====================================================================
     2) LISTADO + FILTROS
     ===================================================================== */
  async function cargarTickets() {
    const tbody = $('#tbody-tickets');
    const contador = $('#contador-tickets');
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
      if (contador) contador.textContent = `(${data.length})`;
      if (!data.length) {
        tbody.innerHTML = '<tr><td colspan="9" class="vacio-msg">No hay solicitudes que coincidan con los filtros.</td></tr>';
        return;
      }
      tbody.innerHTML = data.map(t => `
        <tr>
          <td class="codigo">#${esc(t.codigo)}<br><small style="color:var(--gris-texto)">Folio: ${esc(t.token_consulta)}</small></td>
          <td>${esc(t.solicitante)}<br><small style="color:var(--gris-texto)">${esc(t.email)}</small></td>
          <td style="max-width:260px">${esc(t.asunto)}${t.origen === 'interno' ? ' <span class="badge badge-cancelado">Interno</span>' : ''}</td>
          <td>${esc(t.categoria || '—')}</td>
          <td><span class="pri-${esc(t.prioridad)}">${PRIORIDADES_LABEL[t.prioridad] || esc(t.prioridad)}</span></td>
          <td><span class="badge badge-${esc(t.estado)}">${ESTADOS_ES[t.estado] || esc(t.estado)}</span>${+t.mensajes > 0 ? ` <small title="mensajes en el historial">💬${t.mensajes}</small>` : ''}</td>
          <td>${esc(t.tecnico || 'Por asignar')}</td>
          <td style="white-space:nowrap">${esc(t.creado)}</td>
          <td><button class="btn btn-primario btn-chico" data-ver="${t.id}">Ver</button></td>
        </tr>`).join('');

      $$('[data-ver]', tbody).forEach(b => b.addEventListener('click', () => abrirDetalle(+b.dataset.ver)));
    } catch (e) {
      tbody.innerHTML = `<tr><td colspan="9" class="vacio-msg" style="color:var(--rojo-acento)">⚠️ ${esc(e.message)}</td></tr>`;
    }
  }
  const PRIORIDADES_LABEL = { baja: 'Baja', media: 'Media', alta: 'Alta', urgente: 'Urgente', critica: 'Crítica' };

  $('#btn-filtrar').addEventListener('click', cargarTickets);
  $('#filtro-q').addEventListener('keyup', (ev) => { if (ev.key === 'Enter') cargarTickets(); });
  ['#filtro-estado', '#filtro-prioridad', '#filtro-asignado'].forEach(sel =>
    $(sel).addEventListener('change', cargarTickets));
  $('#btn-limpiar').addEventListener('click', () => {
    ['#filtro-q', '#filtro-estado', '#filtro-prioridad', '#filtro-asignado'].forEach(sel => $(sel).value = '');
    cargarTickets();
  });

  /* ---------------- Técnicos para filtro/asignación ---------------- */
  let tecnicosCache = [];
  async function cargarTecnicos() {
    try {
      const json = await get('tecnicos');
      if (json.ok) {
        tecnicosCache = json.data || [];
        $('#filtro-asignado').innerHTML = '<option value="">Todo el equipo</option>' +
          tecnicosCache.map(u => `<option value="${u.id}">${esc(u.nombre)} (${esc(u.rol)})</option>`).join('');
        const selNuevo = $('#nt-asignado');
        if (selNuevo) {
          selNuevo.innerHTML = '<option value="0">— Sin asignar —</option>' +
            tecnicosCache.map(u => `<option value="${u.id}">${esc(u.nombre)} (${esc(u.rol)})</option>`).join('');
        }
      }
    } catch (e) { /* silencioso */ }
  }

  /* Solicitantes frecuentes (autocompletado en modal Nuevo Ticket) */
  async function cargarSolicitantesFrecuentes() {
    const dl = $('#lista-solicitantes');
    if (!dl) return;
    try {
      const json = await get('tickets', {});
      if (json.ok) {
        const nombres = [...new Set((json.data || []).map(t => t.solicitante).filter(Boolean))].slice(0, 40);
        dl.innerHTML = nombres.map(n => `<option value="${esc(n)}">`).join('');
      }
    } catch (e) { /* opcional */ }
  }

  /* =====================================================================
     3) MODAL NUEVO TICKET INTERNO (admin/supervisor)
     ===================================================================== */
  const formNuevo = $('#form-nuevo-ticket');
  if (formNuevo) {
    // poblar selects estáticos del formulario
    const fill = (sel, arr, def) => {
      const el = $(sel);
      if (!el) return;
      el.innerHTML = arr.map(([v, l]) => `<option value="${esc(v)}"${v === def ? ' selected' : ''}>${esc(l)}</option>`).join('');
    };
    fill('#nt-tipo', TIPOS.map(t => [t, t === 'Mantención' ? 'Mantención programada' : t]), 'Incidencia');
    fill('#nt-prioridad', [['baja','Baja'],['media','Media'],['alta','Alta'],['urgente','Urgente']], 'media');
    fill('#nt-departamento', DEPARTAMENTOS.map(d => ['', d]), '');
    $('#nt-departamento').innerHTML = '<option value="">— Sin departamento —</option>' +
      DEPARTAMENTOS.map(d => `<option value="${esc(d)}">${esc(d)}</option>`).join('');
    $('#nt-ubicacion').innerHTML = '<option value="">— Sin ubicación —</option>' +
      UBICACIONES.map(u => `<option value="${esc(u)}">${esc(u)}</option>`).join('');

    // categorías desde la BD (con fallback al vocabulario local si la API falla)
    (async () => {
      try {
        const json = await get('metadata');
        if (json.ok && json.categorias) {
          $('#nt-categoria').innerHTML = '<option value="">Seleccione…</option>' +
            json.categorias.map(c => `<option value="${c.id}">${esc(c.nombre)}</option>`).join('');
        } else throw new Error('sin datos');
      } catch (e) {
        $('#nt-categoria').innerHTML = '<option value="">No fue posible cargar categorías</option>';
      }
    })();

    const abrirNuevo = () => { $('#modal-nuevo').classList.add('visible'); document.body.style.overflow = 'hidden'; };
    const cerrarNuevo = () => { $('#modal-nuevo').classList.remove('visible'); document.body.style.overflow = ''; };
    $('#btn-nuevo-ticket').addEventListener('click', abrirNuevo);
    $('#nuevo-cerrar').addEventListener('click', cerrarNuevo);
    $('#nuevo-cancelar').addEventListener('click', cerrarNuevo);
    $('#modal-nuevo').addEventListener('click', (ev) => { if (ev.target === ev.currentTarget) cerrarNuevo(); });

    function limpiarErroresNuevo() {
      $$('.error-campo', formNuevo).forEach(el => (el.textContent = ''));
    }
    formNuevo.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      limpiarErroresNuevo();
      const btn = $('#btn-crear-ticket');
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner"></span> Creando…';
      try {
        const fd = new FormData(formNuevo);
        const obj = Object.fromEntries(fd.entries());
        const json = await post('crear_ticket_interno', obj);
        if (!json.ok && json.errors) {
          Object.entries(json.errors).forEach(([campo, msg]) => {
            const span = $(`[data-err="${campo}"]`, formNuevo);
            if (span) span.textContent = msg;
          });
          toast('Revise los campos marcados.', true);
          return;
        }
        if (!json.ok) throw new Error(json.error || 'No fue posible crear el ticket.');
        toast(`✅ Ticket ${json.ticket.codigo} creado correctamente.`);
        cerrarNuevo();
        formNuevo.reset();
        await cargarTickets();
        cargarStats();
        abrirDetalle(json.ticket.id);
      } catch (e) {
        toast('⚠️ ' + e.message, true);
      } finally {
        btn.disabled = false;
        btn.textContent = 'Crear Ticket';
      }
    });
  }

  /* =====================================================================
     4) MODAL DETALLE + ACCIONES (estado / prioridad / asignación / respuesta)
     ===================================================================== */
  async function abrirDetalle(id) {
    const overlay = $('#modal-detalle');
    const cuerpo = $('#modal-cuerpo');
    overlay.classList.add('visible');
    document.body.style.overflow = 'hidden';
    cuerpo.innerHTML = '<p><span class="spinner spinner-azul"></span> Cargando detalle…</p>';

    try {
      const dj = await get('detalle', { id });
      if (!dj.ok) throw new Error(dj.error || 'No encontrado');
      const t = dj.ticket;

      const TIPO_HIST = {
        creacion: 'Creación', estado: 'Estado', asignacion: 'Asignación',
        respuesta: 'Respuesta pública', nota_interna: 'Nota interna', ciudadano: 'Ciudadano', comentario: 'Comentario',
      };
      const hist = (t.historial || []).map(h => `
        <li class="tipo-${esc(h.tipo)}">
          <span class="h-autor">${esc(h.autor)}</span>
          <span class="h-fecha">${esc(h.fecha)}</span>
          ${h.tipo === 'nota_interna' ? '<span class="nota-tag">Interna · no visible al ciudadano</span>' : ''}
          ${h.tipo === 'respuesta' ? '<span class="resp-tag">Visible al ciudadano</span>' : ''}
          <div class="h-mensaje">${esc(h.mensaje)}</div>
        </li>`).join('');

      const optsEstado = Object.entries(ESTADOS_ES)
        .filter(([k]) => k !== 'en_proceso')
        .map(([k, l]) => `<option value="${k}" ${k === t.estado ? 'selected' : ''}>${l}</option>`).join('');
      const optsPrio = PRIORIDADES.map(p =>
        `<option value="${p}" ${p === t.prioridad ? 'selected' : ''}>${PRIORIDADES_LABEL[p]}</option>`).join('');
      const optsAsig = '<option value="0">— Sin asignar —</option>' +
        tecnicosCache.map(u => `<option value="${u.id}" ${u.id == t.asignado_a ? 'selected' : ''}>${esc(u.nombre)}</option>`).join('');

      $('#modal-titulo').textContent = '#' + t.codigo + ' — ' + t.asunto;
      cuerpo.innerHTML = `
        <div class="meta-grid">
          <div class="meta-item"><span>Solicitante</span><strong>${esc(t.solicitante)}</strong></div>
          <div class="meta-item"><span>Correo</span><strong>${esc(t.email)}</strong></div>
          <div class="meta-item"><span>Categoría</span><strong>${esc(t.categoria || '—')}</strong></div>
          <div class="meta-item"><span>Tipo</span><strong>${esc(t.tipo || 'Incidencia')}</strong></div>
          <div class="meta-item"><span>Origen</span><strong>${t.origen === 'interno' ? 'Interno' : 'Portal Ciudadano'}</strong></div>
          <div class="meta-item"><span>Departamento</span><strong>${esc(t.departamento || '—')}</strong></div>
          <div class="meta-item"><span>Ubicación</span><strong>${esc(t.ubicacion || '—')}</strong></div>
          <div class="meta-item"><span>Folio público (Nº ticket)</span><strong>${esc(t.codigo)} · ${esc(t.token_consulta)}</strong></div>
          <div class="meta-item"><span>Ingreso</span><strong>${esc(t.creado)}</strong></div>
          <div class="meta-item"><span>Actualizado</span><strong>${esc(t.actualizado)}</strong></div>
        </div>
        <div>
          <h4 style="margin:0 0 .4rem;color:var(--azul-obscuro)">Descripción</h4>
          <p style="margin:0;background:var(--gris-fondo);padding:.8rem 1rem;border-radius:8px;white-space:pre-wrap">${esc(t.descripcion)}</p>
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

        <div class="caja-dual" id="caja-respuesta">
          <div class="tabs-respuesta" role="tablist">
            <button type="button" id="tab-publica" class="activo-publica" role="tab" aria-selected="true">📤 Respuesta pública</button>
            <button type="button" id="tab-nota" role="tab" aria-selected="false">📝 Nota interna</button>
          </div>
          <textarea id="txt-comentario" rows="3" placeholder="Escriba la solución, solicitud de antecedentes u observación…"></textarea>
          <div class="pie-caja">
            <small id="hint-modo" style="color:var(--gris-texto)">Visible para el ciudadano al consultar su ticket.</small>
            <button class="btn-enviar" id="btn-enviar-respuesta" title="Enviar" aria-label="Enviar respuesta">➤</button>
          </div>
        </div>`;

      // Guardar cambios de estado/prioridad/asignación
      $('#btn-guardar-cambios').addEventListener('click', async () => {
        try {
          const json = await post('actualizar', {
            id: t.id,
            estado: $('#sel-estado').value,
            prioridad: $('#sel-prioridad').value,
            asignado_a: $('#sel-asignado').value
          });
          toast(json.ok ? 'Ticket actualizado correctamente.' : ('Error: ' + json.error), !json.ok);
          if (json.ok) recargarTrasCambio(t.id);
        } catch (e) { toast('⚠️ ' + e.message, true); }
      });

      // Caja dual: respuesta pública ↔ nota interna
      let modo = 'respuesta';
      const caja = $('#caja-respuesta'), tabP = $('#tab-publica'), tabN = $('#tab-nota'), hint = $('#hint-modo');
      const setModo = (m) => {
        modo = m;
        tabP.className = m === 'respuesta' ? 'activo-publica' : '';
        tabN.className = m === 'nota_interna' ? 'activo-nota' : '';
        tabP.setAttribute('aria-selected', m === 'respuesta');
        tabN.setAttribute('aria-selected', m === 'nota_interna');
        caja.classList.toggle('modo-nota', m === 'nota_interna');
        hint.textContent = m === 'nota_interna'
          ? 'Solo el equipo puede ver esta nota. No aparecerá en el Portal Ciudadano.'
          : 'Visible para el ciudadano al consultar su ticket.';
      };
      tabP.addEventListener('click', () => setModo('respuesta'));
      tabN.addEventListener('click', () => setModo('nota_interna'));

      const enviarRespuesta = async () => {
        const msg = $('#txt-comentario').value.trim();
        if (msg.length < 3) { toast('Escriba un mensaje primero.', true); return; }
        const btn = $('#btn-enviar-respuesta');
        btn.disabled = true;
        try {
          const json = await post('comentar', { id: t.id, mensaje: msg, modo });
          toast(json.ok ? json.mensaje : ('Error: ' + json.error), !json.ok);
          if (json.ok) recargarTrasCambio(t.id);
        } catch (e) {
          toast('⚠️ ' + e.message, true);
        } finally {
          btn.disabled = false;
        }
      };
      $('#btn-enviar-respuesta').addEventListener('click', enviarRespuesta);
      $('#txt-comentario').addEventListener('keydown', (ev) => {
        if ((ev.ctrlKey || ev.metaKey) && ev.key === 'Enter') { ev.preventDefault(); enviarRespuesta(); }
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
    const nv = $('#modal-nuevo');
    if (nv) nv.classList.remove('visible');
    document.body.style.overflow = '';
  }
  $('#modal-cerrar').addEventListener('click', cerrarModal);
  $('#modal-cerrar-2').addEventListener('click', cerrarModal);
  $('#modal-detalle').addEventListener('click', (ev) => { if (ev.target === ev.currentTarget) cerrarModal(); });
  document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') cerrarModal(); });

  /* =====================================================================
     5) NAV / REFRESH / LOGOUT
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
    cargarSolicitantesFrecuentes();
  });
})();
