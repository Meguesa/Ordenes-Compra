<?php
declare(strict_types=1);
$root=rtrim((string)($_SERVER['DOCUMENT_ROOT']??''),'/');
require_once $root.'/includes/bootstrap.php';
portal_require_authentication();
require_once __DIR__.'/includes/ordenes-access.php';

$user=portal_user();
if(!ordenes_user_is_approver($user)){ http_response_code(403); exit('Acceso restringido.'); }
if(empty($_SESSION['ordenes_auth_csrf'])||!is_string($_SESSION['ordenes_auth_csrf'])) $_SESSION['ordenes_auth_csrf']=bin2hex(random_bytes(24));

$id=(int)($_GET['id']??$_POST['id']??0);
if($id<=0){ http_response_code(400); exit('ODC inválida.'); }

$error='';$message='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
  try{
    $token=(string)($_POST['csrf_token']??'');
    if($token===''||!hash_equals((string)$_SESSION['ordenes_auth_csrf'],$token)) throw new RuntimeException('La sesión de seguridad expiró.');
    $action=(string)($_POST['decision']??'');
    $comment=(string)($_POST['comentario']??'');
    $result=ordenes_process_authorization($id,$user,$action,$comment);
    $message='Acción registrada: '.(string)($result['action']??'');
  }catch(Throwable $e){$error=$e->getMessage();}
}

try{
  ordenes_ensure_approval_schema();
  $d=ordenes_item_payload($id);
  $attachments=ordenes_sharepoint_attachments($id);
}catch(Throwable $e){
  http_response_code(500); exit(htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8'));
}
$name=htmlspecialchars(trim((string)($user['name']??'Usuario')),ENT_QUOTES,'UTF-8');
$email=htmlspecialchars(strtolower(trim((string)($user['email']??''))),ENT_QUOTES,'UTF-8');
$pending=strtoupper((string)$d['estado'])==='PENDIENTE_AUTORIZACION';
?><!doctype html>
<html lang="es-MX">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Revisar ODC <?= htmlspecialchars((string)$d['folio'],ENT_QUOTES,'UTF-8') ?></title>
<link rel="stylesheet" href="styles.css?v=20261009-auth-1">
</head>
<body>
<header class="tool-header"><div class="shell tool-header-inner">
  <div class="tool-brand"><img class="tool-logo" src="/mapa/assets/logo.jpg" alt="Jardines de Juan Pablo"><div class="tool-identity"><strong>Órdenes de Compra</strong><span>Portal Interno JdJP · Jardines de Juan Pablo</span></div></div>
  <div class="tool-header-context">Revisión y autorización</div>
  <div class="tool-header-actions"><a class="header-action" href="buzon.php">Regresar al buzón</a><details class="account-menu"><summary class="account-trigger" title="<?= $name ?>"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4" fill="currentColor"/><path d="M4 20c0-4.1 3.6-6 8-6s8 1.9 8 6v1H4z" fill="currentColor"/></svg></summary><div class="account-menu-panel"><div class="account-menu-info"><strong><?= $name ?></strong><span><?= $email ?></span></div><a class="account-menu-logout" href="/logout.php">Cerrar sesión</a></div></details></div>
</div></header>

<main class="shell main-content review-home">
  <section class="form-banner">
    <div><p class="eyebrow">Autorización · R<?= (int)$d['revision'] ?></p><h1>ODC <?= htmlspecialchars((string)$d['folio'],ENT_QUOTES,'UTF-8') ?></h1><p><?= htmlspecialchars((string)$d['proveedor'],ENT_QUOTES,'UTF-8') ?></p></div>
    <div class="review-status <?= strtolower((string)$d['estado']) ?>"><?= htmlspecialchars(str_replace('_',' ',(string)$d['estado']),ENT_QUOTES,'UTF-8') ?></div>
  </section>

  <?php if($message!==''): ?><div class="records-message success"><?= htmlspecialchars($message,ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>
  <?php if($error!==''): ?><div class="records-message error"><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>

  <section class="review-grid">
    <article class="records-panel">
      <div class="records-panel-heading"><div><h2>Información de la orden</h2><p>Datos enviados por el solicitante.</p></div><a class="secondary-button button-link" target="_blank" href="pdf.php?id=<?= $id ?>">Ver PDF</a></div>
      <div class="review-summary">
        <div><span>Solicitante</span><strong><?= htmlspecialchars((string)$d['solicitanteNombre'],ENT_QUOTES,'UTF-8') ?></strong><small><?= htmlspecialchars((string)$d['solicitanteCorreo'],ENT_QUOTES,'UTF-8') ?></small></div>
        <div><span>RFC</span><strong><?= htmlspecialchars((string)$d['rfc'],ENT_QUOTES,'UTF-8') ?></strong></div>
        <div><span>Condición de pago</span><strong><?= htmlspecialchars((string)$d['condicionPago'],ENT_QUOTES,'UTF-8') ?></strong></div>
        <div><span>Total</span><strong>$<?= number_format((float)$d['total'],2,'.',',') ?> <?= htmlspecialchars((string)$d['moneda'],ENT_QUOTES,'UTF-8') ?></strong></div>
      </div>
      <div class="review-items">
        <table><thead><tr><th>Cantidad</th><th>Descripción</th><th>Precio unitario</th><th>Importe</th></tr></thead><tbody>
        <?php foreach($d['items'] as $it): ?>
          <tr><td><?= htmlspecialchars((string)($it['qty']??''),ENT_QUOTES,'UTF-8') ?></td><td><?= htmlspecialchars((string)($it['description']??''),ENT_QUOTES,'UTF-8') ?></td><td>$<?= number_format((float)($it['price']??0),2,'.',',') ?></td><td>$<?= number_format((float)($it['amount']??0),2,'.',',') ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
      </div>
    </article>

    <aside class="records-panel">
      <div class="records-panel-heading"><div><h2>Adjuntos</h2><p>Documentos enviados por el solicitante.</p></div></div>
      <?php if(!$attachments): ?><div class="records-empty compact">Sin archivos adjuntos.</div>
      <?php else: ?><div class="review-attachments"><?php foreach($attachments as $a): ?>
        <a href="adjunto.php?id=<?= $id ?>&file=<?= rawurlencode((string)($a['FileName']??'')) ?>"><?= htmlspecialchars((string)($a['FileName']??'Archivo'),ENT_QUOTES,'UTF-8') ?></a>
      <?php endforeach; ?></div><?php endif; ?>
    </aside>
  </section>

  <section class="records-panel review-history-panel">
    <div class="records-panel-heading"><div><h2>Historial y comentarios</h2><p>Seguimiento de todas las revisiones de la ODC.</p></div></div>
    <?php if(!$d['historial']): ?><div class="records-empty compact">Sin movimientos registrados.</div>
    <?php else: ?><div class="approval-timeline"><?php foreach($d['historial'] as $ev): if(!is_array($ev)) continue; ?>
      <div class="timeline-event"><strong><?= htmlspecialchars((string)($ev['accion']??''),ENT_QUOTES,'UTF-8') ?></strong><span>R<?= (int)($ev['revision']??0) ?> · <?= htmlspecialchars((string)($ev['usuario']??$ev['correo']??''),ENT_QUOTES,'UTF-8') ?></span><?php if(trim((string)($ev['comentario']??''))!==''): ?><p><?= nl2br(htmlspecialchars((string)$ev['comentario'],ENT_QUOTES,'UTF-8')) ?></p><?php endif; ?></div>
    <?php endforeach; ?></div><?php endif; ?>
  </section>

  <section class="records-panel decision-panel">
    <div class="records-panel-heading"><div><h2>Decisión</h2><p>El rechazo requiere un comentario. Puedes guardar un comentario sin cerrar la solicitud.</p></div></div>
    <?php if($pending): ?>
    <form method="post" action="revisar.php?id=<?= $id ?>" class="decision-form">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['ordenes_auth_csrf'],ENT_QUOTES,'UTF-8') ?>">
      <label>Comentario de revisión
        <textarea name="comentario" rows="5" placeholder="Escribe observaciones para el solicitante..."></textarea>
      </label>
      <div class="decision-actions">
        <button class="secondary-button" type="submit" name="decision" value="COMENTARIO">Guardar comentario</button>
        <button class="danger-button" type="submit" name="decision" value="RECHAZAR">Rechazar</button>
        <button class="approve-button" type="submit" name="decision" value="APROBAR">Aprobar</button>
      </div>
    </form>
    <?php else: ?><div class="records-empty compact">Esta revisión ya fue resuelta: <?= htmlspecialchars((string)$d['estado'],ENT_QUOTES,'UTF-8') ?>.</div><?php endif; ?>
  </section>
</main>
</body></html>