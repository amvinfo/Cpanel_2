<?php require_once __DIR__ . '/config.php'; ?>
<!DOCTYPE html>
<html lang="es-CL">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Acceso Funcionarios · <?= APP_NAME ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/gobcl.css">
</head>
<body class="login-page">

<main class="login-card" aria-label="Formulario de acceso para funcionarios">
  <span class="marca__logo" aria-hidden="true">GdC</span>
  <h1>Acceso Funcionarios</h1>
  <p class="desc">Panel interno de la Mesa de Ayuda — Gobierno de Chile</p>

  <form id="form-login" autocomplete="off">
    <div class="campo">
      <label for="usuario">Usuario</label>
      <input type="text" id="usuario" name="usuario" required autocomplete="username" placeholder="admin">
    </div>
    <div class="campo">
      <label for="password">Contraseña</label>
      <input type="password" id="password" name="password" required autocomplete="current-password" placeholder="••••••••">
    </div>
    <div id="login-error" role="alert" aria-live="assertive"></div>
    <button type="submit" class="btn btn-primario" id="btn-login" style="width:100%">Iniciar Sesión</button>
    <a href="index.php" class="btn btn-secundario" style="width:100%;text-decoration:none">← Volver al Portal Ciudadano</a>
  </form>

  <div class="login-hint" style="margin-top:1.3rem">
    <strong>Credenciales de prueba (seed):</strong><br>
    Admin: <code>admin / admin123</code> · Supervisor: <code>supervisor / admin123</code> · Técnico: <code>tecnico / admin123</code>
  </div>
</main>

<script>
(function () {
  'use strict';
  const form = document.getElementById('form-login');
  const btn  = document.getElementById('btn-login');
  const err  = document.getElementById('login-error');

  // Si ya hay sesión activa, ir directo al dashboard
  fetch('api/panel.php?action=session', { credentials: 'same-origin' })
    .then(r => r.json())
    .then(j => { if (j.ok) location.href = 'dashboard.php'; })
    .catch(() => {});

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    err.innerHTML = '';
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Verificando…';
    try {
      const fd = new FormData(form);
      const res = await fetch('api/panel.php?action=login', { method: 'POST', body: fd, credentials: 'same-origin' });
      const json = await res.json();
      if (json.ok) {
        location.href = 'dashboard.php';
      } else {
        err.innerHTML = `<div class="aviso-error" role="alert">⚠️ ${json.error || 'Credenciales inválidas.'}</div>`;
        btn.disabled = false;
        btn.textContent = 'Iniciar Sesión';
      }
    } catch (e) {
      err.innerHTML = '<div class="aviso-error" role="alert">⚠️ No fue posible conectar con el servidor. Revise la instalación de la base de datos.</div>';
      btn.disabled = false;
      btn.textContent = 'Iniciar Sesión';
    }
  });
})();
</script>
</body>
</html>
