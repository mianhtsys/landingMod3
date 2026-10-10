// CP-SOL-06: Módulo de Detalle de Solicitud, Botonera de Transiciones FSM y Timeline
import { getSolicitud, getHistorialSolicitud, transicionarSolicitud } from '../api/solicitudes-api.js';

const container = document.getElementById('solicitud-detail-container');
const loadingP = document.getElementById('detail-loading');
const errorP = document.getElementById('detail-error');

// Extraer ID de la URL (/solicitudes/12)
const pathParts = window.location.pathname.split('/').filter(Boolean);
const solicitudId = parseInt(pathParts[pathParts.length - 1], 10);

let currentSolicitud = null;

function getPrioridadBadge(prioridad, esExpedita) {
  let badgeClass = 'badge-media';
  if (prioridad === 'VITAL') badgeClass = 'badge-vital';
  else if (prioridad === 'ALTA') badgeClass = 'badge-alta';
  else if (prioridad === 'BAJA') badgeClass = 'badge-baja';

  let html = `<span class="badge ${badgeClass}">${prioridad}</span>`;
  if (esExpedita) {
    html += ` <span class="badge badge-expedita" title="Orden de Soporte Vital">⚡ VITAL</span>`;
  }
  return html;
}

function getEstadoBadge(estado) {
  let badgeClass = 'badge-off';
  if (estado === 'Registrada') badgeClass = 'badge-off';
  else if (estado === 'Asignada') badgeClass = 'badge-media';
  else if (estado === 'En Proceso') badgeClass = 'badge-alta';
  else if (estado === 'En Espera') badgeClass = 'badge-vital';
  else if (estado === 'Atendida' || estado === 'Cerrada') badgeClass = 'badge-ok';
  return `<span class="badge ${badgeClass}">${estado}</span>`;
}

async function loadDetail() {
  if (!solicitudId || isNaN(solicitudId)) {
    if (errorP) {
      errorP.textContent = 'ID de solicitud inválido en la URL';
      errorP.hidden = false;
    }
    return;
  }

  if (loadingP) loadingP.hidden = false;
  if (errorP) errorP.hidden = true;

  try {
    const [sol, historial] = await Promise.all([
      getSolicitud(solicitudId),
      getHistorialSolicitud(solicitudId),
    ]);

    currentSolicitud = sol;
    if (loadingP) loadingP.hidden = true;

    renderFicha(sol);
    renderTransitionPanel(sol);
    renderTimeline(historial);
  } catch (err) {
    if (loadingP) loadingP.hidden = true;
    if (errorP) {
      errorP.textContent = err.message || 'Error al cargar detalle de la solicitud hospitalaria';
      errorP.hidden = false;
    }
  }
}

function renderFicha(sol) {
  const fichaEl = document.getElementById('ficha-tecnica');
  if (!fichaEl) return;

  document.getElementById('sol-correlativo-title').textContent = sol.codigo_correlativo;
  document.getElementById('sol-badges-header').innerHTML = `
    ${getEstadoBadge(sol.estado)}
    ${getPrioridadBadge(sol.prioridad_asistencial, !!sol.es_expedita)}
    <span class="badge badge-off" title="Versión de Concurrencia Optimista (D-01)">v${sol.version}</span>
  `;

  fichaEl.innerHTML = `
    <div class="detail-grid">
      <div class="detail-card">
        <h3>🏥 Localización Asistencial</h3>
        <div class="detail-row"><span class="detail-label">Sede:</span><span class="detail-value">${sol.sede_codigo} — ${sol.sede_nombre}</span></div>
        <div class="detail-row"><span class="detail-label">Servicio Solicitante:</span><span class="detail-value">${sol.servicio_solicitante_nombre}</span></div>
        <div class="detail-row"><span class="detail-label">Ubicación Específica:</span><span class="detail-value">${sol.ubicacion_especifica}</span></div>
        <div class="detail-row"><span class="detail-label">Usuario Solicitante:</span><span class="detail-value">${sol.usuario_solicitante_nombre}</span></div>
      </div>

      <div class="detail-card">
        <h3>🔧 Requerimiento Técnico</h3>
        <div class="detail-row"><span class="detail-label">Área Resolutora:</span><span class="detail-value">${sol.servicio_resolutor_nombre}</span></div>
        <div class="detail-row"><span class="detail-label">Tipo de Solicitud:</span><span class="detail-value">${sol.tipo_solicitud_nombre}</span></div>
        <div class="detail-row"><span class="detail-label">Técnico Asignado:</span><span class="detail-value">${sol.tecnico_asignado_nombre || '<em class="page-muted">No asignado</em>'}</span></div>
        <div class="detail-row"><span class="detail-label">Prioridad Sugerida:</span><span class="detail-value">${sol.prioridad_sugerida || 'N/A'}</span></div>
      </div>

      <div class="detail-card">
        <h3>⏱️ Ciclo de Vida y Tiempos UTC</h3>
        <div class="detail-row"><span class="detail-label">Radicación:</span><span class="detail-value">${sol.fecha_radicacion_utc || '—'}</span></div>
        <div class="detail-row"><span class="detail-label">Asignación:</span><span class="detail-value">${sol.fecha_asignacion_utc || '—'}</span></div>
        <div class="detail-row"><span class="detail-label">Inicio Proceso:</span><span class="detail-value">${sol.fecha_inicio_proceso_utc || '—'}</span></div>
        <div class="detail-row"><span class="detail-label">Atención Concluida:</span><span class="detail-value">${sol.fecha_atencion_utc || '—'}</span></div>
        <div class="detail-row"><span class="detail-label">Cierre Final:</span><span class="detail-value">${sol.fecha_cierre_utc || '—'}</span></div>
      </div>
    </div>

    <div class="detail-card" style="margin-bottom: var(--space-4);">
      <h3>📝 Descripción Técnica de la Falla (Zero-PHI)</h3>
      <p style="margin: 0; font-size: .95rem; line-height: 1.5; color: var(--color-text);">${sol.descripcion_falla}</p>
    </div>
  `;
}

function renderTransitionPanel(sol) {
  const panel = document.getElementById('transition-panel-content');
  if (!panel) return;

  const isTerminal = ['Cerrada', 'Rechazada', 'Cancelada'].includes(sol.estado);

  if (isTerminal) {
    panel.innerHTML = `
      <div class="banner-zero-phi" style="background:#f3f4f6; border-left-color:#6b7280; color:#374151;">
        <strong>🔒 ESTADO FINAL TERMINAL INMUTABLE (RN-005, RN-012):</strong>
        Esta orden se encuentra en estado <strong>${sol.estado}</strong>. De acuerdo a la normativa hospitalaria, no se permiten modificaciones ni reaperturas posteriores.
      </div>
    `;
    return;
  }

  let buttonsHtml = '<div class="transition-buttons">';

  if (sol.estado === 'Registrada') {
    buttonsHtml += `
      <button type="button" class="btn btn-primary btn-inline" onclick="window.showTransitionForm('Asignada')">👤 Asignar a Técnico</button>
      <button type="button" class="btn btn-ghost btn-inline" onclick="window.showTransitionForm('Rechazada')">⛔ Rechazar Solicitud</button>
      <button type="button" class="btn btn-ghost btn-inline" onclick="window.showTransitionForm('Cancelada')">✕ Cancelar</button>
    `;
  } else if (sol.estado === 'Asignada') {
    buttonsHtml += `
      <button type="button" class="btn btn-primary btn-inline" onclick="window.showTransitionForm('En Proceso')">▶ Iniciar Labores en Sitio</button>
      <button type="button" class="btn btn-ghost btn-inline" onclick="window.showTransitionForm('Asignada')">🔄 Reasignar Técnico</button>
      <button type="button" class="btn btn-ghost btn-inline" onclick="window.showTransitionForm('Cancelada')">✕ Cancelar</button>
    `;
  } else if (sol.estado === 'En Proceso') {
    buttonsHtml += `
      <button type="button" class="btn btn-primary btn-inline" onclick="window.showTransitionForm('Atendida')">✓ Concluir Atención (Atendida)</button>
      <button type="button" class="btn btn-ghost btn-inline" style="border-color:#f59e0b; color:#d97706;" onclick="window.showTransitionForm('En Espera')">⏸ Pausar (En Espera - SLA Pausado)</button>
      <button type="button" class="btn btn-ghost btn-inline" onclick="window.showTransitionForm('Asignada')">🔄 Reasignar Técnico</button>
    `;
  } else if (sol.estado === 'En Espera') {
    buttonsHtml += `
      <button type="button" class="btn btn-primary btn-inline" onclick="window.showTransitionForm('En Proceso')">▶ Reanudar Labores (En Proceso)</button>
    `;
  } else if (sol.estado === 'Atendida') {
    buttonsHtml += `
      <button type="button" class="btn btn-primary btn-inline" style="background:#15803d;" onclick="window.showTransitionForm('Cerrada')">🔒 Cerrar Orden Conforme</button>
    `;
  }

  buttonsHtml += '</div><div id="transition-action-box"></div>';
  panel.innerHTML = buttonsHtml;
}

window.showTransitionForm = function(targetState) {
  const box = document.getElementById('transition-action-box');
  if (!box || !currentSolicitud) return;

  let formHtml = `
    <div class="transition-box">
      <h4 style="margin: 0 0 10px;">Transicionar a: <strong>${targetState}</strong> (Versión actual: v${currentSolicitud.version})</h4>
      <form id="execute-transition-form" novalidate>
  `;

  if (targetState === 'Asignada') {
    formHtml += `
      <div class="form-field" style="margin-bottom: 10px;">
        <label for="trans-tecnico">Seleccionar Técnico Asignado *</label>
        <select id="trans-tecnico" required>
          <option value="1">1 — Oficial de Seguridad TI H1</option>
        </select>
      </div>
    `;
  }

  if (targetState === 'En Espera') {
    formHtml += `
      <div class="form-field" style="margin-bottom: 10px;">
        <label for="trans-causal">Causal Externa Justificada (RN-006) *</label>
        <select id="trans-causal" required>
          <option value="ESPERA_REPUESTO">ESPERA_REPUESTO: Insumo o repuesto pendiente en bodega</option>
          <option value="EQUIPO_EN_GARANTIA">EQUIPO_EN_GARANTIA: En trámite con proveedor externo</option>
          <option value="ESPERA_LIBERACION_AREA">ESPERA_LIBERACION_AREA: Quirófano o sala en procedimiento</option>
          <option value="AUTORIZACION_BIOMEDICA">AUTORIZACION_BIOMEDICA: Pendiente visado técnico</option>
        </select>
      </div>
    `;
  }

  const motivoRequerido = ['Rechazada', 'Cancelada', 'En Espera', 'Atendida', 'Cerrada'].includes(targetState);
  formHtml += `
    <div class="form-field" style="margin-bottom: 10px;">
      <label for="trans-motivo">${motivoRequerido ? 'Justificación / Informe Técnico Obligatorio *' : 'Comentario de Guardia (opcional)'}</label>
      <textarea id="trans-motivo" rows="3" style="width: 100%; padding: 8px; border: 1px solid var(--color-border); border-radius: var(--radius); font: inherit;" placeholder="Ingrese justificación técnica conforme a la política Zero-PHI..." ${motivoRequerido ? 'required' : ''}></textarea>
    </div>

    <p id="trans-error" class="form-error" role="alert" hidden></p>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary btn-inline" id="trans-submit-btn">Confirmar Transición</button>
      <button type="button" class="btn btn-ghost btn-inline" onclick="document.getElementById('transition-action-box').innerHTML = ''">Cancelar</button>
    </div>
    </form>
    </div>
  `;

  box.innerHTML = formHtml;

  const form = document.getElementById('execute-transition-form');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const errEl = document.getElementById('trans-error');
    const submitBtn = document.getElementById('trans-submit-btn');
    errEl.hidden = true;

    const motivoVal = document.getElementById('trans-motivo')?.value.trim() || '';
    if (motivoRequerido && motivoVal.length < 5) {
      errEl.textContent = 'La justificación o informe técnico debe tener al menos 5 caracteres.';
      errEl.hidden = false;
      return;
    }

    const payload = {
      nuevo_estado: targetState,
      version: currentSolicitud.version, // D-01: Control de concurrencia optimista
      motivo: motivoVal || null,
      causal: document.getElementById('trans-causal')?.value || null,
      tecnico_asignado_id: targetState === 'Asignada' ? parseInt(document.getElementById('trans-tecnico')?.value || '1', 10) : null,
    };

    submitBtn.disabled = true;
    submitBtn.textContent = 'Procesando transición…';

    try {
      await transicionarSolicitud(currentSolicitud.id, payload);
      // Recargar datos actualizados
      await loadDetail();
    } catch (err) {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Confirmar Transición';

      if (err.status === 409) {
        // Conflicto de Concurrencia Optimista (D-01)
        errEl.innerHTML = `
          <strong>⚠️ CONFLICTO DE CONCURRENCIA OPTIMISTA (D-01):</strong><br>
          La orden fue modificada concurrentemente por otro miembro del equipo o guardia.
          Se recargarán automáticamente los datos actualizados.
        `;
        errEl.hidden = false;
        setTimeout(() => loadDetail(), 2500);
      } else {
        errEl.textContent = err.message || 'Error al ejecutar la transición de estado';
        errEl.hidden = false;
      }
    }
  });
};

function renderTimeline(historial) {
  const timelineEl = document.getElementById('audit-timeline');
  if (!timelineEl) return;

  if (!historial || historial.length === 0) {
    timelineEl.innerHTML = '<p class="page-muted">No hay registros en la bitácora de auditoría.</p>';
    return;
  }

  let html = '<div class="timeline">';

  historial.forEach((h) => {
    html += `
      <div class="timeline-item">
        <div class="timeline-dot"></div>
        <div class="timeline-content">
          <div class="timeline-header">
            <span class="timeline-title">${h.estado_anterior} ➔ ${h.estado_nuevo}</span>
            <span class="timeline-date">${h.created_at_utc} UTC</span>
          </div>
          <div class="timeline-meta">
            👤 <strong>${h.usuario_nombre}</strong> (${h.usuario_email}) | IP: <code>${h.ip_origen}</code>
            ${h.causal_codigo ? `| Causal: <span class="badge badge-media">${h.causal_codigo}</span>` : ''}
          </div>
          ${h.motivo_texto ? `<div class="timeline-reason">"${h.motivo_texto}"</div>` : ''}
        </div>
      </div>
    `;
  });

  html += '</div>';
  timelineEl.innerHTML = html;
}

loadDetail();
