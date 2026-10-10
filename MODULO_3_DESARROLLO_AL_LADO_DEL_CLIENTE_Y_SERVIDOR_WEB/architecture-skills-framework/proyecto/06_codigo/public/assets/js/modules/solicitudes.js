// CP-SOL-05: Módulo de Bandeja Asistencial de Solicitudes Hospitalarias
import { listSolicitudes } from '../api/solicitudes-api.js';

const tbody = document.getElementById('sol-tbody');
const table = document.getElementById('sol-table');
const loading = document.getElementById('sol-loading');
const empty = document.getElementById('sol-empty');
const errorP = document.getElementById('sol-error');

const filterSede = document.getElementById('filter-sede');
const filterEstado = document.getElementById('filter-estado');
const filterPrioridad = document.getElementById('filter-prioridad');
const filterExpedita = document.getElementById('filter-expedita');
const filterBtn = document.getElementById('filter-apply-btn');

function getPrioridadBadge(prioridad, esExpedita) {
  let badgeClass = 'badge-media';
  if (prioridad === 'VITAL') badgeClass = 'badge-vital';
  else if (prioridad === 'ALTA') badgeClass = 'badge-alta';
  else if (prioridad === 'BAJA') badgeClass = 'badge-baja';

  let html = `<span class="badge ${badgeClass}">${prioridad}</span>`;
  if (esExpedita) {
    html += ` <span class="badge badge-expedita" title="Orden Expedita de Soporte Vital">⚡ VITAL</span>`;
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

async function renderTable() {
  if (!tbody) return;
  loading.hidden = false;
  errorP.hidden = true;
  empty.hidden = true;
  table.hidden = true;
  tbody.innerHTML = '';

  const filters = {};
  if (filterSede?.value) filters.sede_id = filterSede.value;
  if (filterEstado?.value) filters.estado = filterEstado.value;
  if (filterPrioridad?.value) filters.prioridad_asistencial = filterPrioridad.value;
  if (filterExpedita?.checked) filters.es_expedita = '1';

  try {
    const list = await listSolicitudes(filters);
    loading.hidden = true;

    if (!list || list.length === 0) {
      empty.hidden = false;
      return;
    }

    list.forEach((sol) => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td><strong>${sol.codigo_correlativo}</strong></td>
        <td>${sol.sede_codigo || 'H' + sol.sede_id}</td>
        <td>${sol.servicio_solicitante_nombre || 'Servicio ' + sol.servicio_solicitante_id}</td>
        <td>${sol.ubicacion_especifica}</td>
        <td>${getPrioridadBadge(sol.prioridad_asistencial, !!sol.es_expedita)}</td>
        <td>${getEstadoBadge(sol.estado)}</td>
        <td>v${sol.version}</td>
        <td>
          <a href="/solicitudes/${sol.id}" class="btn btn-ghost btn-sm" title="Ver detalle y transiciones">
            Detalle
          </a>
        </td>
      `;
      tbody.appendChild(tr);
    });

    table.hidden = false;
  } catch (err) {
    loading.hidden = true;
    errorP.textContent = err.message || 'Error al cargar la bandeja de solicitudes';
    errorP.hidden = false;
  }
}

filterBtn?.addEventListener('click', (e) => {
  e.preventDefault();
  renderTable();
});

filterSede?.addEventListener('change', renderTable);
filterEstado?.addEventListener('change', renderTable);
filterPrioridad?.addEventListener('change', renderTable);
filterExpedita?.addEventListener('change', renderTable);

renderTable();
