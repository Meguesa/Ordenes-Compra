<?php
declare(strict_types=1);
$root=rtrim((string)($_SERVER['DOCUMENT_ROOT']??''),'/');
require_once $root.'/includes/bootstrap.php';
portal_require_authentication();
require_once __DIR__.'/includes/ordenes-access.php';

$user=portal_user();
if(!ordenes_user_is_approver($user)){ http_response_code(403); exit('Acceso restringido.'); }
$name=htmlspecialchars(trim((string)($user['name']??'Usuario')),ENT_QUOTES,'UTF-8');
$email=htmlspecialchars(strtolower(trim((string)($user['email']??''))),ENT_QUOTES,'UTF-8');
$records=[];$error='';
try{ ordenes_ensure_approval_schema(); $records=ordenes_list_inbox_records(); }
catch(Throwable $e){ $error=$e->getMessage(); }
function odc_inbox_date(mixed $v):string{
  $s=substr(trim((string)$v),0,10);
  $d=DateTimeImmutable::createFromFormat('Y-m-d',$s);
  return $d?$d->format('d/m/Y'):$s;
}
?><!doctype html>
<html lang="es-MX">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Buzón de Órdenes | Jardines de Juan Pablo</title>
<link rel="stylesheet" href="styles.css?v=20261009-auth-1">
</head>
<body>
<header class="tool-header"><div class="shell tool-header-inner">
  <div class="tool-brand"><img class="tool-logo" src="/mapa/assets/logo.jpg" alt="Jardines de Juan Pablo"><div class="tool-identity"><strong>Órdenes de Compra</strong><span>Portal Interno JdJP · Jardines de Juan Pablo</span></div></div>
  <div class="tool-header-context">Buzón de autorizaciones</div>
  <div class="tool-header-actions"><a class="header-action" href="/ordenes-compra/">Regresar</a><details class="account-menu"><summary class="account-trigger" title="<?= $name ?>"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4" fill="currentColor"/><path d="M4 20c0-4.1 3.6-6 8-6s8 1.9 8 6v1H4z" fill="currentColor"/></svg></summary><div class="account-menu-panel"><div class="account-menu-info"><strong><?= $name ?></strong><span><?= $email ?></span></div><a class="account-menu-logout" href="/logout.php">Cerrar sesión</a></div></details></div>
</div></header>
<main class="shell main-content records-home">
  <section class="form-banner"><div><p class="eyebrow">Autorizaciones</p><h1>Buzón de Órdenes</h1><p>Revisa las ODC pendientes de aprobación o rechazo.</p></div><a class="secondary-button button-link" href="buzon.php">Actualizar</a></section>
  <?php if($error!==''): ?><div class="records-message error"><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>
  <section class="records-panel">
    <div class="records-panel-heading"><div><h2>Pendientes de autorización</h2><p><?= count($records) ?> solicitud(es) pendiente(s).</p></div></div>
    <?php if(!$records): ?>
      <div class="records-empty">No hay órdenes pendientes de autorización.</div>
    <?php else: ?>
      <div class="records-list">
      <?php foreach($records as $r): ?>
        <article class="record-card has-action">
          <div class="record-field"><span>Folio</span><strong><?= htmlspecialchars((string)($r['Folio']??''),ENT_QUOTES,'UTF-8') ?></strong></div>
          <div class="record-field record-provider"><span>Proveedor</span><strong><?= htmlspecialchars((string)($r['Proveedor']??''),ENT_QUOTES,'UTF-8') ?></strong></div>
          <div class="record-field"><span>Solicitante</span><strong><?= htmlspecialchars((string)($r['SolicitanteNombre']??$r['SolicitanteCorreo']??''),ENT_QUOTES,'UTF-8') ?></strong></div>
          <div class="record-field"><span>Total</span><strong>$<?= number_format((float)($r['Total']??0),2,'.',',') ?></strong></div>
          <div class="record-field"><span>Revisión</span><strong>R<?= (int)($r['Revision']??1) ?></strong></div>
          <div class="record-actions"><a class="primary-button button-link" href="revisar.php?id=<?= (int)($r['id']??0) ?>">Revisar</a></div>
        </article>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</main>
</body></html>