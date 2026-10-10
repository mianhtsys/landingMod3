<?php /** CP-FRONT-02: dashboard — usuario actual, conteos y enlaces */ ?>
<h1>Centro de Control Hospitalario</h1>
<p class="dash-user">Personal de guardia: <strong><?= htmlspecialchars($userName ?? '', ENT_QUOTES, 'UTF-8') ?></strong></p>
<p id="dash-error" class="form-error" role="alert" hidden></p>
<section class="cards" aria-label="Resumen operativo">
  <article class="card">
    <h2 id="count-categorias" aria-live="polite">…</h2>
    <p>Categorías de Suministros</p>
    <a href="/categorias">Ver categorías</a>
  </article>
  <article class="card">
    <h2 id="count-productos" aria-live="polite">…</h2>
    <p>Catálogo de Productos y Equipos</p>
    <a href="/productos">Ver productos</a>
  </article>
</section>
<script type="module" src="/assets/js/modules/dashboard.js"></script>
