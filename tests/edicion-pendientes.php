<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/ordenes-access.php';
function check(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
$h=[['revision'=>1,'accion'=>'ENVIADA_AUTORIZACION','comentario'=>'Original'],['revision'=>2,'accion'=>'MODIFICADA','correoEstado'=>'PENDIENTE','comentario'=>'Corregida']];
check(!ordenes_modification_mail_pending($h,1),'R1 no debe bloquearse');
check(ordenes_modification_mail_pending($h,2),'R2 debe bloquearse hasta confirmar correo');
check(ordenes_last_sent_observations($h,2)==='Corregida','Debe recuperar observaciones R2');
$h[1]['correoEstado']='ENVIADO';
check(!ordenes_modification_mail_pending($h,2),'Correo confirmado desbloquea R2');
$h[]=['revision'=>2,'accion'=>'COMENTARIO','comentario'=>'Finanzas'];
check(ordenes_last_sent_observations($h,2)==='Corregida','Un comentario de Finanzas no sustituye las observaciones');
check(!ordenes_modification_mail_pending($h,2),'Comentario no vuelve a bloquear R2');
check(ordenes_with_item_lock(987654321,static fn()=>42)===42,'Bloqueo devuelve resultado');
echo "OK edicion-pendientes\n";
