<?php
declare(strict_types=1);

$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
require_once $root . '/includes/bootstrap.php';
portal_require_authentication();
require_once __DIR__ . '/includes/ordenes-access.php';
ordenes_require_preview_access();

$user = portal_user();
$name = htmlspecialchars(trim((string)($user['name'] ?? 'Usuario')), ENT_QUOTES, 'UTF-8');
$email = htmlspecialchars(strtolower(trim((string)($user['email'] ?? ''))), ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="es-MX">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#ffffff">
  <title>Órdenes de Compra | Jardines de Juan Pablo</title>
  <link rel="stylesheet" href="styles.css?v=20261009-auth-1">
</head>
<body>
<header class="tool-header">
  <div class="shell tool-header-inner">
    <div class="tool-brand">
      <img class="tool-logo" src="/mapa/assets/logo.jpg" alt="Jardines de Juan Pablo">
      <div class="tool-identity">
        <strong>Órdenes de Compra</strong>
        <span>Portal Interno JdJP · Jardines de Juan Pablo</span>
      </div>
    </div>
    <div class="tool-header-context">Captura y seguimiento de órdenes de compra</div>
    <div class="tool-header-actions">
      <a class="header-action" href="/">Regresar al portal</a>
      <details class="account-menu">
        <summary class="account-trigger" aria-label="Abrir menú de usuario" title="<?= $name ?>">
          <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4" fill="currentColor"/><path d="M4 20c0-4.1 3.6-6 8-6s8 1.9 8 6v1H4z" fill="currentColor"/></svg>
        </summary>
        <div class="account-menu-panel">
          <div class="account-menu-info"><strong><?= $name ?></strong><span><?= $email ?></span></div>
          <a class="account-menu-logout" href="/logout.php">Cerrar sesión</a>
        </div>
      </details>
    </div>
  </div>
</header>

<main class="shell main-content odc-home">
  <section class="form-banner selector-banner">
    <div>
      <p class="eyebrow">Órdenes de Compra</p>
      <h1>Selecciona una opción</h1>
      <p>Crea una nueva orden, consulta las órdenes enviadas o continúa un borrador.</p>
    </div>
    <div class="selector-banner-meta"><span class="production-pill">Producción · SharePoint</span></div>
  </section>

  <section class="selector-grid" aria-label="Opciones de Órdenes de Compra">
    <article class="selector-card">
      <div class="selector-card-top"><span class="selector-card-kicker">CAPTURA</span><span class="selector-card-status available">Disponible</span></div>
      <div class="selector-card-icon">＋</div>
      <h2>Nueva Orden</h2>
      <p>Captura proveedor, partidas, impuestos, datos bancarios, observaciones y adjuntos para generar una nueva ODC.</p>
      <a class="primary-button selector-action" href="nueva.php?nuevo=1">Crear nueva orden</a>
    </article>

    <article class="selector-card">
      <div class="selector-card-top"><span class="selector-card-kicker">HISTORIAL</span><span class="selector-card-status available">Disponible</span></div>
      <div class="selector-card-icon">✓</div>
      <h2>Órdenes</h2>
      <p>Consulta las órdenes de compra que ya fueron enviadas, con folio, proveedor, fecha y total.</p>
      <a class="primary-button selector-action" href="ordenes.php">Ver órdenes</a>
    </article>

    <article class="selector-card">
      <div class="selector-card-top"><span class="selector-card-kicker">PENDIENTES</span><span class="selector-card-status available">Disponible</span></div>
      <div class="selector-card-icon">✎</div>
      <h2>Borradores</h2>
      <p>Continúa órdenes guardadas previamente antes de enviarlas a Finanzas.</p>
      <a class="primary-button selector-action" href="borradores.php">Ver borradores</a>
    </article>

    <?php if (ordenes_user_is_approver($user)): ?>
    <article class="selector-card">
      <div class="selector-card-top"><span class="selector-card-kicker">AUTORIZACIONES</span><span class="selector-card-status available">Disponible</span></div>
      <div class="selector-card-icon">✓</div>
      <h2>Buzón</h2>
      <p>Revisa órdenes pendientes de autorización, agrega comentarios y aprueba o rechaza solicitudes.</p>
      <a class="primary-button selector-action" href="buzon.php">Abrir buzón</a>
    </article>
    <?php endif; ?>
  </section>

  <p class="selector-account-note">Sesión activa: <?= $email !== '' ? $email : 'Usuario autenticado' ?>.</p>
</main>
</body>
</html>
