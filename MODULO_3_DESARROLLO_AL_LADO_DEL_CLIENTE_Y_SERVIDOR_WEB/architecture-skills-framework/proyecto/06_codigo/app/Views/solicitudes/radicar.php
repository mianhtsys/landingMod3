<?php /** CP-SOL-05: Formulario de Radicación Dual Hospitalaria (Cero SQL en capa de vista) */ ?>
<div style="margin-bottom: var(--space-3);">
  <h1>Radicación de Órdenes de Trabajo Hospitalarias</h1>
  <p class="page-sub">Ingreso de requerimientos técnicos, soporte locativo y fallas en equipamiento biomédico.</p>
</div>

<!-- Banner Legal y Regulatorio Zero-PHI Obligatorio (RNF-035) -->
<div class="banner-zero-phi" role="alert">
  <strong>🛡️ AVISO REGULATORIO OBLIGATORIO — POLÍTICA ZERO-PHI (RNF-035):</strong>
  Este módulo es de uso exclusivo para mantenimiento técnico y gestión de infraestructura.
  <strong>Queda terminantemente prohibido</strong> registrar nombres de pacientes, diagnósticos médicos, números de cama clínica asociados a identidades o números de cédula/historia clínica (HC). Toda orden es auditada legalmente.
</div>

<section class="form-section form-section-wide" aria-labelledby="radicar-form-title">
  <!-- Selector de Modo Dual: Estándar vs Expedita -->
  <div class="mode-switch" role="tablist" aria-label="Selección de modo de radicación">
    <button type="button" class="mode-btn active" id="btn-modo-estandar" role="tab" aria-selected="true">
      Radicación Estándar
    </button>
    <button type="button" class="mode-btn" id="btn-modo-expedito" role="tab" aria-selected="false">
      ⚡ Modo Expedito (Soporte Vital UCI/Quirófano)
    </button>
  </div>

  <div id="modo-indicator"></div>

  <form id="radicar-form" novalidate>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: var(--space-3);">
      <div class="form-field">
        <label for="sede-id">Sede Hospitalaria *</label>
        <select id="sede-id" name="sede_id" required>
          <option value="1">H1 - Hospital Central Universitario</option>
          <option value="2">H2 - Hospital del Norte</option>
          <option value="3">H3 - Hospital Materno Infantil</option>
          <option value="4">H4 - Hospital del Sur</option>
        </select>
      </div>

      <div class="form-field">
        <label for="servicio-solicitante-id">Servicio Asistencial Solicitante *</label>
        <select id="servicio-solicitante-id" name="servicio_solicitante_id" required>
          <option value="1">Unidad de Cuidados Intensivos (UCI Adultos)</option>
          <option value="2">Pabellón Quirúrgico / Quirófano Central</option>
          <option value="3">Servicio de Urgencias y Trauma</option>
          <option value="5">Hemodiálisis y Nefrología</option>
        </select>
      </div>

      <div class="form-field">
        <label for="servicio-resolutor-id">Área Técnica Resolutora *</label>
        <select id="servicio-resolutor-id" name="servicio_resolutor_id" required>
          <option value="4">Mantenimiento e Ingeniería Biomédica</option>
          <option value="6">Infraestructura y Mantenimiento Edilicio</option>
          <option value="7">Sistemas y Soporte TI Hospitalario</option>
        </select>
      </div>

      <div class="form-field">
        <label for="tipo-solicitud-id">Catálogo Tipificado de Falla *</label>
        <select id="tipo-solicitud-id" name="tipo_solicitud_id" required>
          <option value="1">BIO-VENT-FALLA: Fallo Crítico Ventilador Mecánico UCI</option>
          <option value="2">BIO-MON-FALLA: Pérdida Señal Monitor Multiparamétrico</option>
          <option value="3">INF-GAS-FUGA: Fuga de Oxígeno Medicinal o Gases</option>
          <option value="4">INF-ELEC-CORTE: Fallo Eléctrico en Circuito de Soporte</option>
        </select>
      </div>

      <div class="form-field">
        <label for="prioridad-asistencial">Prioridad Asistencial *</label>
        <select id="prioridad-asistencial" name="prioridad_asistencial" required>
          <option value="MEDIA">MEDIA — Atención ordinaria en horario hábil</option>
          <option value="ALTA">ALTA — Atención técnica prioritaria</option>
          <option value="VITAL">VITAL — Emergencia de soporte vital 24/7</option>
          <option value="BAJA">BAJA — Mantenimiento preventivo o menor</option>
        </select>
      </div>

      <div class="form-field">
        <label for="ubicacion-especifica">Ubicación Específica en Pabellón *</label>
        <input type="text" id="ubicacion-especifica" name="ubicacion_especifica" maxlength="150" placeholder="Ej. Pabellón B, Box 04 o Quirófano Central Mesa 1" required>
      </div>
    </div>

    <div class="form-field" style="margin-top: var(--space-3);">
      <label for="descripcion-falla">Descripción Técnica del Problema / Falla (Zero-PHI estricto) *</label>
      <textarea id="descripcion-falla" name="descripcion_falla" rows="4" style="width: 100%; padding: var(--space-2); border: 1px solid var(--color-border); border-radius: var(--radius); font: inherit;" placeholder="Describa el comportamiento anómalo del equipo biomédico o la instalación física. (Prohibido ingresar nombres de pacientes o historias clínicas)." required></textarea>
    </div>

    <p id="radicar-error" class="form-error" role="alert" hidden></p>
    <div id="radicar-ok" class="form-ok" role="status" hidden></div>

    <div class="form-actions" style="margin-top: var(--space-3);">
      <button type="submit" class="btn btn-primary btn-inline" id="radicar-submit">
        Radicar Solicitud Ordinaria
      </button>
      <a href="/solicitudes" class="btn btn-ghost btn-inline">Cancelar / Volver</a>
    </div>
  </form>
</section>

<script type="module" src="/assets/js/modules/solicitudes-radicar.js"></script>
