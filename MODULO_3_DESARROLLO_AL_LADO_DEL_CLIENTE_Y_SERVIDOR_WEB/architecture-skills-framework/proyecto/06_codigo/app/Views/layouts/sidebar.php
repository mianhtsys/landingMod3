<?php /** CP-FRONT-03: navegación lateral. aria-current por path actual. */
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$navItems = [
    '/dashboard' => 'Dashboard',
    '/solicitudes' => 'Solicitudes',
    '/solicitudes/radicar' => 'Radicar Orden',
    '/categorias' => 'Categorías',
    '/productos' => 'Productos',
];
?>
<aside class="sidebar">
  <div class="brand">S3IH Hospitalario</div>
  <nav aria-label="Navegación principal">
<?php foreach ($navItems as $href => $label): ?>
    <a href="<?= $href ?>"<?= $currentPath === $href ? ' aria-current="page"' : '' ?>><?= $label ?></a>
<?php endforeach; ?>
  </nav>
  <div class="sidebar-user">
    <span class="sidebar-user-name"><?= htmlspecialchars($userName ?? '', ENT_QUOTES, 'UTF-8') ?></span>
    <button type="button" class="btn btn-ghost" id="logout-btn">Salir</button>
  </div>
</aside>
