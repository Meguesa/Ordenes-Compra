<?php
declare(strict_types=1);
$root=rtrim((string)($_SERVER['DOCUMENT_ROOT']??''),'/');
require_once $root.'/includes/bootstrap.php';
portal_require_authentication();
require_once __DIR__.'/includes/ordenes-access.php';

$user=portal_user();
$id=(int)($_GET['id']??0);
if($id<=0){http_response_code(400);exit('ODC inválida.');}
$d=ordenes_item_payload($id);
$current=strtolower(trim((string)($user['email']??'')));
$owner=strtolower(trim((string)($d['solicitanteCorreo']??'')));
if($current==='' || ($current!==$owner && !ordenes_user_is_approver($user))){http_response_code(403);exit('Acceso restringido.');}

$pdf=odc_pdf_generate($d,['name'=>$d['solicitanteNombre'],'email'=>$owner],(string)($_SERVER['DOCUMENT_ROOT']??''));
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="ODC_'.preg_replace('/[^0-9A-Za-z_-]/','',(string)$d['folio']).'.pdf"');
header('Content-Length: '.strlen($pdf));
header('Cache-Control: private, no-store');
echo $pdf;
