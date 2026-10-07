<?php
declare(strict_types=1);
$root=rtrim((string)($_SERVER['DOCUMENT_ROOT']??''),'/');
require_once $root.'/includes/bootstrap.php';
portal_require_authentication();
require_once __DIR__.'/includes/ordenes-access.php';
ordenes_require_preview_access();

$user=portal_user();
$name=htmlspecialchars(trim((string)($user['name']??'Usuario')),ENT_QUOTES,'UTF-8');
$email=htmlspecialchars(strtolower(trim((string)($user['email']??''))),ENT_QUOTES,'UTF-8');
$records=[];$error='';
try{$records=ordenes_list_user_records($user,'ENVIADA');}catch(Throwable $e){$error=$e->getMessage();}
function odc_list_date(mixed $v): string {
    $s=substr(trim((string)$v),0,10);
    $d=DateTimeImmutable::createFromFormat('Y-m-d',$s);
    return $d?$d->format('d/m/Y'):$s;
}
?><!doctype html>
<html lang="es-MX">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Órdenes | Jardines de Juan Pablo</title>
<link rel="stylesheet" href="styles.css?v=20261007-home-1">
</head>
<body>
<header class="tool-header">
  <div class="shell tool-header-inner">
    <div class="tool-brand"><img class="tool-logo" src="/mapa/assets/logo.jpg" alt="Jardines de Juan Pablo"><div class="tool-identity"><strong>Órdenes de Compra</strong><span>Portal Interno JdJP · Jardines de Juan Pablo</span></div></div>
    <div class="tool-header-context">Consulta de órdenes enviadas</div>
    <div class="tool-header-actions"><a class="header-action" href="/ordenes-compra/">Regresar</a><details class="account-menu"><summary class="account-trigger" title="<?= $name ?>"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4" fill="currentColor"/><path d="M4 20c0-4.1 3.6-6 8-6s8 1.9 8 6v1H4z" fill="currentColor"/></svg></summary><div class="account-menu-panel"><div class="account-menu-info"><strong><?= $name ?></strong><span><?= $email ?></span></div><a class="account-menu-logout" href="/logout.php">Cerrar sesión</a></div></details></div>
  </div>
</header>
<main class="shell main-content records-home">
  <section class="form-banner">
    <div><p class="eyebrow">Historial</p><h1>Órdenes</h1><p>Consulta tus órdenes de compra enviadas a Finanzas.</p></div>
    <a class="primary-button button-link" href="nueva.php?nuevo=1">＋ Nueva Orden</a>
  </section>
  <section class="record-menu-grid">
    <a class="record-menu-card" href="borradores.php"><span>✎</span><div><strong>Borradores</strong><small>Continuar órdenes pendientes</small></div></a>
    <div class="record-menu-card active"><span>✓</span><div><strong>Órdenes</strong><small><?= count($records) ?> enviada(s)</small></div></div>
  </section>
  <?php if($error!==''): ?><div class="records-message error"><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>
  <section class="records-panel">
    <div class="records-panel-heading"><div><h2>Órdenes enviadas</h2><p>Registros asociados a tu cuenta.</p></div><a class="secondary-button button-link" href="ordenes.php">Actualizar</a></div>
    <?php if(!$records): ?>
      <div class="records-empty">No tienes órdenes enviadas todavía.</div>
    <?php else: ?>
      <div class="records-list">
      <?php foreach($records as $r): ?>
        <article class="record-card">
          <div class="record-field"><span>Folio</span><strong><?= htmlspecialchars((string)($r['Folio']??''),ENT_QUOTES,'UTF-8') ?></strong></div>
          <div class="record-field record-provider"><span>Proveedor</span><strong><?= htmlspecialchars((string)($r['Proveedor']??''),ENT_QUOTES,'UTF-8') ?></strong></div>
          <div class="record-field"><span>Fecha</span><strong><?= htmlspecialchars(odc_list_date($r['Fecha']??''),ENT_QUOTES,'UTF-8') ?></strong></div>
          <div class="record-field"><span>Total</span><strong>$<?= number_format((float)($r['Total']??0),2,'.',',') ?></strong></div>
          <div class="record-field"><span>Estado</span><strong class="record-badge sent">Enviada</strong></div>
        </article>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</main>
</body></html>