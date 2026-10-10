<?php /** CP-SOL-06: Detalle de Orden Hospitalaria, Línea de Tiempo de Auditoría y Transiciones FSM (Cero SQL en vista) */ ?>
<div style="margin-bottom: var(--space-3);">
  <a href="/solicitudes" class="btn btn-ghost btn-inline" style="margin-bottom: var(--space-2); display: inline-flex; align-items: center; gap: 6px;">
    ← Volver a Bandeja Asistencial
  </a>
  <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-start; gap: var(--space-3);">
    <div>
      <h1 id="sol-correlativo-title" style="margin: 0;">Cargando orden hospitalaria…</h1>
      <p class="page-sub" style="margin-top: 4px;">Ficha técnica institucional de mantenimiento y soporte de equipamiento médico.</p>
    </div>
    <div id="sol-badges-header" style="display: flex; gap: var(--space-2); align-items: center; flex-wrap: wrap;"></div>
  </div>
</div>

<p id="detail-loading" class="page-muted">Consultando ficha técnica y bitácora de auditoría en tiempo real…</p>
<p id="detail-error" class="form-error" role="alert" hidden></p>

<div id="solicitud-detail-container">
  <!-- Ficha Técnica Asistencial y de Infraestructura -->
  <div id="ficha-tecnica"></div>

  <!-- Panel Contextual de Transiciones de Guardia FSM (RN-005, D-01) -->
  <section class="transition-panel" aria-labelledby="transition-title" style="margin-bottom: var(--space-4);">
    <h2 id="transition-title" style="font-size: 1.15rem; margin-top: 0; margin-bottom: 6px;">
      ⚡ Transición Operativa y Control de Guardia
    </h2>
    <p class="page-sub" style="margin-bottom: var(--space-3);">
      Acciones permitidas conforme al ciclo de vida formal y Máquina de Estados Finita (RN-005). La concurrencia optimista previene colisiones entre operadores técnicos (D-01).
    </p>
    <div id="transition-panel-content"></div>
  </section>

  <!-- Bitácora Inmutable de Auditoría Append-Only (Audit Trail) -->
  <section class="card" aria-labelledby="audit-title" style="margin-bottom: var(--space-4);">
    <h2 id="audit-title" style="font-size: 1.15rem; margin-top: 0; margin-bottom: 6px;">
      📜 Bitácora de Auditoría Inmutable (Audit Trail)
    </h2>
    <p class="page-sub" style="margin-bottom: var(--space-3);">
      Trazabilidad inmutable append-only con registro forense de operador, IP, marca temporal UTC y causales justificadas (RN-005, RN-012).
    </p>
    <div id="audit-timeline"></div>
  </section>
</div>

<script type="module" src="/assets/js/modules/solicitud-detalle.js"></script>
