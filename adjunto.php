<?php
declare(strict_types=1);
$root=rtrim((string)($_SERVER['DOCUMENT_ROOT']??''),'/');
require_once $root.'/includes/bootstrap.php';
portal_require_authentication();
require_once __DIR__.'/includes/ordenes-access.php';

$user=portal_user();
$id=(int)($_GET['id']??0);
$file=basename((string)($_GET['file']??''));
if($id<=0 || $file===''){http_response_code(400);exit('Archivo inválido.');}
$d=ordenes_item_payload($id);
$current=strtolower(trim((string)($user['email']??'')));
$owner=strtolower(trim((string)($d['solicitanteCorreo']??'')));
if($current==='' || ($current!==$owner && !ordenes_user_is_approver($user))){http_response_code(403);exit('Acceso restringido.');}

$found=null;
foreach(ordenes_sharepoint_attachments($id) as $a){
  if((string)($a['FileName']??'')===$file){$found=$a;break;}
}
if(!is_array($found)){http_response_code(404);exit('Archivo no encontrado.');}
$url=(string)($found['ServerRelativeUrl']??'');
$bytes=ordenes_sharepoint_attachment_bytes($url);
$safe=preg_replace('/[\r\n"]+/','_',basename($file));
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="'.$safe.'"');
header('Content-Length: '.strlen($bytes));
header('Cache-Control: private, no-store');
echo $bytes;
