// CP-SOL-05: Módulo de Radicación Dual Hospitalaria (Estándar / Soporte Vital)
import { createSolicitud, createSolicitudExpedita } from '../api/solicitudes-api.js';

const form = document.getElementById('radicar-form');
const btnModoEstandar = document.getElementById('btn-modo-estandar');
const btnModoExpedito = document.getElementById('btn-modo-expedito');
const modoIndicator = document.getElementById('modo-indicator');
const errorP = document.getElementById('radicar-error');
const okP = document.getElementById('radicar-ok');
const submitBtn = document.getElementById('radicar-submit');

const selectPrioridad = document.getElementById('prioridad-asistencial');
const selectSede = document.getElementById('sede-id');
const selectSrvSol = document.getElementById('servicio-solicitante-id');
const selectSrvRes = document.getElementById('servicio-resolutor-id');
const selectTipo = document.getElementById('tipo-solicitud-id');
const inputUbicacion = document.getElementById('ubicacion-especifica');
const textareaDescripcion = document.getElementById('descripcion-falla');

let modoActual = 'estandar'; // 'estandar' | 'expedito'

function generarUuid() {
  if (crypto && crypto.randomUUID) {
    return crypto.randomUUID();
  }
  return 'uuid-' + Math.random().toString(36).substring(2, 15) + '-' + Date.now();
}

function setModo(modo) {
  modoActual = modo;
  errorP.hidden = true;
  okP.hidden = true;

  if (modo === 'expedito') {
    btnModoEstandar.classList.remove('active');
    btnModoExpedito.classList.add('active', 'expedita-active');
    modoIndicator.innerHTML = `
      <div class="banner-zero-phi" style="background:#ffe4e6; border-left-color:#e11d48; color:#9f1239;">
        <strong>⚡ MODO EXPEDITO DE SOPORTE VITAL ACTIVADO (UCI / QUIRÓFANO)</strong>
        Prioridad forzada automáticamente a <strong>VITAL</strong>. Se emitirá alerta prioritaria e inmediata de guardia biomédica.
      </div>
    `;
    if (selectPrioridad) {
      selectPrioridad.value = 'VITAL';
      selectPrioridad.disabled = true;
    }
    submitBtn.textContent = '⚡ Radicar Orden de Soporte Vital';
    submitBtn.style.background = '#e11d48';
  } else {
    btnModoExpedito.classList.remove('active', 'expedita-active');
    btnModoEstandar.classList.add('active');
    modoIndicator.innerHTML = '';
    if (selectPrioridad) {
      selectPrioridad.disabled = false;
      if (selectPrioridad.value === 'VITAL') {
        selectPrioridad.value = 'MEDIA';
      }
    }
    submitBtn.textContent = 'Radicar Solicitud Ordinaria';
    submitBtn.style.background = '';
  }
}

btnModoEstandar?.addEventListener('click', () => setModo('estandar'));
btnModoExpedito?.addEventListener('click', () => setModo('expedito'));

// Validación preventiva Zero-PHI en cliente (RNF-035)
function checkClientZeroPhi(text) {
  const phiRegex = /\b(?:ci|dni|c[eé]dula|carnet|pasaporte)[\s:#._-]*\d{4,10}\b|\b(?:hc|h\.c\.|historia\s*cl[ií]nica|expediente)[\s:#._-]*\d+\b|\b(?:paciente)[\s:]+[a-záéíóúñ]{2,}\b/iu;
  return phiRegex.test(text);
}

form?.addEventListener('submit', async (e) => {
  e.preventDefault();
  errorP.hidden = true;
  okP.hidden = true;

  const ubicacion = inputUbicacion?.value.trim() || '';
  const descripcion = textareaDescripcion?.value.trim() || '';

  if (ubicacion.length < 3) {
    errorP.textContent = 'La ubicación específica debe tener al menos 3 caracteres.';
    errorP.hidden = false;
    return;
  }

  if (descripcion.length < 5) {
    errorP.textContent = 'La descripción técnica de la falla debe tener al menos 5 caracteres.';
    errorP.hidden = false;
    return;
  }

  // Intercepción preventiva de datos personales (Zero-PHI)
  if (checkClientZeroPhi(ubicacion) || checkClientZeroPhi(descripcion)) {
    errorP.textContent = 'Violación de política Zero-PHI: Queda prohibido ingresar cédulas, historias clínicas o nombres de pacientes en campos técnicos.';
    errorP.hidden = false;
    return;
  }

  const payload = {
    sede_id: parseInt(selectSede?.value || '1', 10),
    servicio_solicitante_id: parseInt(selectSrvSol?.value || '1', 10),
    servicio_resolutor_id: parseInt(selectSrvRes?.value || '4', 10),
    tipo_solicitud_id: parseInt(selectTipo?.value || '1', 10),
    ubicacion_especifica: ubicacion,
    descripcion_falla: descripcion,
    prioridad_asistencial: modoActual === 'expedito' ? 'VITAL' : (selectPrioridad?.value || 'MEDIA'),
  };

  const idempotencyKey = generarUuid();

  submitBtn.disabled = true;
  const originalText = submitBtn.textContent;
  submitBtn.textContent = 'Radicando en servidor…';

  try {
    let result;
    if (modoActual === 'expedito') {
      result = await createSolicitudExpedita(payload, idempotencyKey);
    } else {
      result = await createSolicitud(payload, idempotencyKey);
    }

    okP.innerHTML = `
      <strong>¡Orden Radicada Exitosamente!</strong><br>
      Código Correlativo Inmutable: <strong>${result.codigo_correlativo}</strong><br>
      Prioridad: <strong>${result.prioridad_asistencial}</strong> | Estado: <strong>${result.estado}</strong><br>
      <div style="margin-top: 8px;">
        <a href="/solicitudes" class="btn btn-ghost btn-sm">Ir a la Bandeja</a>
        <button type="button" class="btn btn-ghost btn-sm" id="btn-nueva-orden">Radicar Otra</button>
      </div>
    `;
    okP.hidden = false;
    form.reset();
    setModo('estandar');

    document.getElementById('btn-nueva-orden')?.addEventListener('click', () => {
      okP.hidden = true;
    });
  } catch (err) {
    errorP.textContent = err.message || 'Error al radicar la solicitud hospitalaria';
    errorP.hidden = false;
  } finally {
    submitBtn.disabled = false;
    submitBtn.textContent = originalText;
  }
});
