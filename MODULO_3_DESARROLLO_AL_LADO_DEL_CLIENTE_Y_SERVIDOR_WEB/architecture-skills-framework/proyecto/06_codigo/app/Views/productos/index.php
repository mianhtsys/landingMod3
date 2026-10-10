<?php /** CP-FRONT-06: listado de productos */ ?>
<h1>Catálogo de Productos y Equipos</h1>
<p class="page-sub">Catálogo de medicamentos, equipamiento biomédico e insumos de la red hospitalaria S3IH.</p>
<p id="prod-error" class="form-error" role="alert" hidden></p>
<p id="prod-loading" class="page-muted">Cargando productos…</p>
<p id="prod-empty" class="page-muted" hidden>No hay productos registrados.</p>
<div class="table-wrap">
  <table id="prod-table" hidden>
    <caption class="visually-hidden">Listado de productos</caption>
    <thead>
      <tr>
        <th scope="col">ID</th>
        <th scope="col">SKU</th>
        <th scope="col">Nombre</th>
        <th scope="col">Categoría</th>
        <th scope="col">Unidad</th>
        <th scope="col">Estado</th>
        <th scope="col">Acciones</th>
      </tr>
    </thead>
    <tbody id="prod-tbody"></tbody>
  </table>
</div>

<section class="form-section" aria-labelledby="prod-new-title">
  <h2 id="prod-new-title">Nuevo producto</h2>
  <form id="prod-new-form" novalidate>
    <div class="form-field">
      <label for="prod-sku">SKU / Código institucional</label>
      <input type="text" id="prod-sku" name="sku" required maxlength="64" placeholder="Ej. BIO-DEF-001">
    </div>
    <div class="form-field">
      <label for="prod-nombre">Nombre del producto o equipo</label>
      <input type="text" id="prod-nombre" name="nombre" required maxlength="255" placeholder="Ej. Desfibrilador Hospitalario con Palas UCI">
    </div>
    <div class="form-field">
      <label for="prod-cat">Categoría asignada</label>
      <select id="prod-cat" name="categoria_id" required>
        <option value="">— Selecciona categoría —</option>
      </select>
    </div>
    <div class="form-field">
      <label for="prod-unidad">Unidad de medida / presentación</label>
      <input type="text" id="prod-unidad" name="unidad" required maxlength="32" placeholder="EQUIPO, UNIDAD, FRASCO, CAJA…">
    </div>
    <p id="prod-new-error" class="form-error" role="alert" hidden></p>
    <p id="prod-new-ok" class="form-ok" role="status" hidden></p>
    <button type="submit" class="btn btn-primary btn-inline" id="prod-new-submit">Crear producto</button>
    <button type="button" class="btn btn-ghost btn-inline" id="prod-cancel-edit" hidden>Cancelar edición</button>
  </form>
</section>
<script type="module" src="/assets/js/modules/productos.js"></script>
