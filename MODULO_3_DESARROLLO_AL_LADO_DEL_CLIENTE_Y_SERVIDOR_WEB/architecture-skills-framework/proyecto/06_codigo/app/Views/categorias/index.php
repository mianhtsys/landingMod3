<?php /** CP-FRONT-03: listado de categorías */ ?>
<h1>Categorías de Suministros</h1>
<p class="page-sub">Catálogo tipificado de insumos, materiales y equipamiento de la red hospitalaria S3IH.</p>
<p id="cat-error" class="form-error" role="alert" hidden></p>
<p id="cat-loading" class="page-muted">Cargando categorías…</p>
<p id="cat-empty" class="page-muted" hidden>No hay categorías registradas.</p>
<div class="table-wrap">
  <table id="cat-table" hidden>
    <caption class="visually-hidden">Listado de categorías</caption>
    <thead>
      <tr>
        <th scope="col">ID</th>
        <th scope="col">Nombre</th>
        <th scope="col">Categoría padre</th>
        <th scope="col">Estado</th>
        <th scope="col">Acciones</th>
      </tr>
    </thead>
    <tbody id="cat-tbody"></tbody>
  </table>
</div>

<section class="form-section" aria-labelledby="cat-new-title">
  <h2 id="cat-new-title">Nueva categoría</h2>
  <form id="cat-new-form" novalidate>
    <div class="form-field">
      <label for="cat-nombre">Nombre de la categoría</label>
      <input type="text" id="cat-nombre" name="nombre" maxlength="100" placeholder="Ej. Instrumental Quirúrgico" required>
    </div>
    <div class="form-field">
      <label for="cat-padre">Categoría padre (opcional)</label>
      <select id="cat-padre" name="categoria_padre_id">
        <option value="">— Ninguna —</option>
      </select>
    </div>
    <p id="cat-new-error" class="form-error" role="alert" hidden></p>
    <p id="cat-new-ok" class="form-ok" role="status" hidden>Categoría creada.</p>
    <button type="submit" class="btn btn-primary btn-inline" id="cat-new-submit">Crear categoría</button>
    <button type="button" class="btn btn-ghost btn-inline" id="cat-cancel-edit" hidden>Cancelar edición</button>
  </form>
</section>
<script type="module" src="/assets/js/modules/categorias.js"></script>
