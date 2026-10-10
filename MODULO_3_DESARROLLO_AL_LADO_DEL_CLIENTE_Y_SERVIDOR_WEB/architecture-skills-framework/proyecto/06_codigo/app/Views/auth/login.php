<?php /** CP-FRONT-01: pantalla de login */ ?>
<main class="auth-card">
  <h1>Iniciar sesión</h1>
  <p class="auth-sub">S3IH — Red Hospitalaria Interconectada</p>
  <form id="login-form" novalidate>
    <div class="form-field">
      <label for="email">Correo electrónico corporativo</label>
      <input type="email" id="email" name="email" autocomplete="username" placeholder="usuario@redhospitalaria.local" required>
    </div>
    <div class="form-field">
      <label for="password">Contraseña</label>
      <input type="password" id="password" name="password" autocomplete="current-password" required>
    </div>
    <p id="login-error" class="form-error" role="alert" hidden></p>
    <button type="submit" class="btn btn-primary" id="login-submit">Entrar al Sistema</button>
  </form>
  <p class="auth-notice" style="font-size: 0.75rem; color: var(--color-muted); margin-top: 1rem; text-align: center; border-top: 1px solid var(--color-border); padding-top: 0.75rem;">
    Uso exclusivo para personal institucional autorizado. Prohibido registrar información de salud protegida (Zero-PHI).
  </p>
</main>
<script type="module" src="/assets/js/modules/login.js"></script>
