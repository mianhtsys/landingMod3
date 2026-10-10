<?php /** CP-SOL-05: Bandeja de Trabajo de Solicitudes Hospitalarias (Cero SQL en capa de vista) */ ?>
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-3); flex-wrap: wrap; gap: var(--space-2);">
  <div>
    <h1>Bandeja de Solicitudes Hospitalarias</h1>
    <p class="page-sub">Seguimiento en tiempo real de requerimientos técnicos y soporte vital en la red hospitalaria S3IH.</p>
  </div>
  <div>
    <a href="/solicitudes/radicar" class="btn btn-primary btn-inline" style="display: inline-flex; align-items: center; gap: 6px;">
      <span>➕</span> Radicar Solicitud
    </a>
  </div>
</div>

<div class="filter-bar" role="search" aria-label="Filtros de solicitudes hospitalarias">
  <label for="filter-sede" class="visually-hidden">Sede</label>
  <select id="filter-sede">
    <option value="">— Todas las Sedes —</option>
    <option value="1">H1 - Hospital Central Universitario</option>
    <option value="2">H2 - Hospital del Norte</option>
    <option value="3">H3 - Hospital Materno Infantil</option>
    <option value="4">H4 - Hospital del Sur</option>
  </select>

  <label for="filter-estado" class="visually-hidden">Estado</label>
  <select id="filter-estado">
    <option value="">— Todos los Estados —</option>
    <option value="Registrada">Registrada</option>
    <option value="Asignada">Asignada</option>
    <option value="En Proceso">En Proceso</option>
    <option value="En Espera">En Espera (SLA Pausado)</option>
    <option value="Atendida">Atendida</option>
    <option value="Cerrada">Cerrada</option>
  </select>

  <label for="filter-prioridad" class="visually-hidden">Prioridad Asistencial</label>
  <select id="filter-prioridad">
    <option value="">— Prioridad —</option>
    <option value="VITAL">VITAL</option>
    <option value="ALTA">ALTA</option>
    <option value="MEDIA">MEDIA</option>
    <option value="BAJA">BAJA</option>
  </select>

  <label style="display: inline-flex; align-items: center; gap: 4px; font-size: .9rem; cursor: pointer; padding: 4px 8px; background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius);">
    <input type="checkbox" id="filter-expedita" value="1">
    <strong>⚡ Solo Soporte Vital</strong>
  </label>

  <button type="button" class="btn btn-ghost btn-sm" id="filter-apply-btn">Filtrar</button>
</div>

<p id="sol-error" class="form-error" role="alert" hidden></p>
<p id="sol-loading" class="page-muted">Consultando órdenes hospitalarias en tiempo real…</p>
<p id="sol-empty" class="page-muted" hidden>No hay solicitudes registradas con los criterios seleccionados.</p>

<div class="table-wrap">
  <table id="sol-table" hidden>
    <caption class="visually-hidden">Bandeja de trabajo de solicitudes hospitalarias</caption>
    <thead>
      <tr>
        <th scope="col">Correlativo</th>
        <th scope="col">Sede</th>
        <th scope="col">Servicio Solicitante</th>
        <th scope="col">Ubicación Específica</th>
        <th scope="col">Prioridad SLA</th>
        <th scope="col">Estado FSM</th>
        <th scope="col">Versión</th>
        <th scope="col">Acciones</th>
      </tr>
    </thead>
    <tbody id="sol-tbody"></tbody>
  </table>
</div>

<script type="module" src="/assets/js/modules/solicitudes.js"></script>
