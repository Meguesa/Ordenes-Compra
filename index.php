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
$isApprover = ordenes_user_is_approver($user);

$pending = [];
$approved = [];
$drafts = [];
$inbox = [];
$listError = '';

try {
    $pending = ordenes_list_user_records($user, 'PENDIENTE_AUTORIZACION');
    $approved = array_merge(
        ordenes_list_user_records($user, 'APROBADA'),
        ordenes_list_user_records($user, 'ENVIADA')
    );
    $drafts = array_merge(
        ordenes_list_user_records($user, 'RECHAZADA'),
        ordenes_list_user_records($user, 'BORRADOR')
    );
    if ($isApprover) $inbox = ordenes_list_inbox_records();
} catch (Throwable $error) {
    $listError = $error->getMessage();
}

$view = strtolower(trim((string)($_GET['view'] ?? 'pendientes')));
$allowedViews = ['pendientes', 'aprobadas', 'borradores'];
if (!in_array($view, $allowedViews, true)) $view = 'pendientes';

$records = match ($view) {
    'aprobadas' => $approved,
    'borradores' => $drafts,
    default => $pending,
};

usort($records, static fn(array $a, array $b): int => ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0)));

function odc_home_date(mixed $value): string
{
    $raw = substr(trim((string)$value), 0, 10);
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $raw);
    return $date ? $date->format('d/m/Y') : $raw;
}

function odc_home_status(string $state): array
{
    $state = strtoupper(trim($state));
    return match ($state) {
        'APROBADA' => ['Aprobada', 'approved'],
        'ENVIADA' => ['Enviada', 'approved'],
        'RECHAZADA' => ['Rechazada', 'rejected'],
        'BORRADOR' => ['Borrador', 'draft'],
        default => ['Pendiente autorización', 'pending'],
    };
}

$listTitle = match ($view) {
    'aprobadas' => 'Órdenes aprobadas',
    'borradores' => 'Borradores y correcciones',
    default => 'Órdenes pendientes',
};

$listSubtitle = match ($view) {
    'aprobadas' => count($approved) . ' orden(es) con aprobación final.',
    'borradores' => count($drafts) . ' orden(es) por completar o corregir.',
    default => count($pending) . ' orden(es) en seguimiento.',
};
?><!doctype html>
<html lang="es-MX">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#ffffff">
  <title>Órdenes de Compra | Jardines de Juan Pablo</title>
  <link rel="stylesheet" href="styles.css?v=20261009-menu-2">
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

<main class="shell odc-menu-main">
  <section class="odc-intro-card">
    <div>
      <span class="odc-menu-pill">ÓRDENES DE COMPRA</span>
      <h1>Mis órdenes de compra</h1>
      <p>Crea una orden nueva, consulta su seguimiento o revisa autorizaciones cuando tu perfil tenga acceso.</p>
    </div>
    <a class="secondary-button button-link" href="/ordenes-compra/?view=<?= urlencode($view) ?>">Actualizar</a>
  </section>

  <section class="odc-menu-grid" aria-label="Opciones de Órdenes de Compra">
    <a class="odc-menu-card odc-menu-card-new" href="nueva.php?nuevo=1">
      <span class="odc-menu-icon">＋</span>
      <div><strong>Nueva orden</strong><span>Iniciar una nueva captura de orden de compra.</span></div>
    </a>

    <a class="odc-menu-card <?= $view === 'pendientes' ? 'active' : '' ?>" href="?view=pendientes">
      <span class="odc-menu-icon">◷</span>
      <div><strong>Órdenes pendientes</strong><span><b><?= count($pending) ?></b> en seguimiento.</span></div>
    </a>

    <a class="odc-menu-card <?= $view === 'aprobadas' ? 'active' : '' ?>" href="?view=aprobadas">
      <span class="odc-menu-icon">✓</span>
      <div><strong>Órdenes aprobadas</strong><span><b><?= count($approved) ?></b> con aprobación final.</span></div>
    </a>

    <?php if ($isApprover): ?>
    <a class="odc-menu-card odc-menu-card-vobo" href="buzon.php">
      <span class="odc-menu-icon">✓</span>
      <div><strong>Buzón de autorizaciones</strong><span><b><?= count($inbox) ?></b> pendiente(s) de revisión.</span></div>
    </a>
    <?php endif; ?>

    <a class="odc-menu-card odc-menu-card-draft <?= $view === 'borradores' ? 'active' : '' ?>" href="?view=borradores">
      <span class="odc-menu-icon">✎</span>
      <div><strong>Borradores</strong><span><b><?= count($drafts) ?></b> por completar o corregir.</span></div>
    </a>
  </section>

  <?php if ($listError !== ''): ?>
    <div class="records-message error"><?= htmlspecialchars($listError, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <section class="odc-list-panel">
    <div class="odc-list-heading">
      <div>
        <h2><?= htmlspecialchars($listTitle, ENT_QUOTES, 'UTF-8') ?></h2>
        <p><?= htmlspecialchars($listSubtitle, ENT_QUOTES, 'UTF-8') ?></p>
      </div>
    </div>

    <?php if (!$records): ?>
      <div class="odc-empty-state">No hay órdenes en esta sección.</div>
    <?php else: ?>
      <div class="odc-home-list">
      <?php foreach ($records as $record):
        $state = strtoupper((string)($record['Estado'] ?? ($view === 'aprobadas' ? 'APROBADA' : ($view === 'borradores' ? 'BORRADOR' : 'PENDIENTE_AUTORIZACION'))));
        [$statusLabel, $statusClass] = odc_home_status($state);
        $id = (int)($record['id'] ?? 0);
      ?>
        <article class="odc-home-row">
          <div class="odc-home-field"><span>Folio</span><strong><?= htmlspecialchars((string)($record['Folio'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong></div>
          <div class="odc-home-field odc-home-provider"><span>Proveedor</span><strong><?= htmlspecialchars((string)($record['Proveedor'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong></div>
          <div class="odc-home-field"><span>Fecha</span><strong><?= htmlspecialchars(odc_home_date($record['Fecha'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong></div>
          <div class="odc-home-field"><span>Total</span><strong>$<?= number_format((float)($record['Total'] ?? 0), 2, '.', ',') ?></strong></div>
          <div class="odc-home-field"><span>Estado</span><strong class="odc-status-badge <?= $statusClass ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></strong></div>
          <div class="odc-home-action">
            <?php if (in_array($state, ['BORRADOR', 'RECHAZADA'], true)): ?>
              <a class="odc-row-button" href="nueva.php?draft=<?= $id ?>"><?= $state === 'RECHAZADA' ? 'Corregir' : 'Continuar' ?></a>
            <?php else: ?>
              <a class="odc-row-button" target="_blank" href="pdf.php?id=<?= $id ?>">Ver PDF</a>
            <?php endif; ?>
          </div>
          <?php if ($state === 'RECHAZADA' && trim((string)($record['UltimoComentario'] ?? '')) !== ''): ?>
            <div class="odc-row-comment"><strong>Comentario:</strong> <?= htmlspecialchars((string)$record['UltimoComentario'], ENT_QUOTES, 'UTF-8') ?></div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
