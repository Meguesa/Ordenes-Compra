<?php
declare(strict_types=1);

function odc_pdf_escape(string $text): string
{
    $clean = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $clean);
        if (is_string($converted)) $clean = $converted;
    }
    $clean = preg_replace('/[^\x20-\xFF]/', '?', $clean) ?? $clean;
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $clean);
}

function odc_pdf_text(string &$s,float $x,float $y,string $text,float $size=8,bool $bold=false): void
{
    $font=$bold?'F2':'F1';
    $s.="BT /{$font} {$size} Tf {$x} {$y} Td (".odc_pdf_escape($text).") Tj ET\n";
}

function odc_pdf_line(string &$s,float $x1,float $y1,float $x2,float $y2): void
{
    $s.="0 G 0.55 w {$x1} {$y1} m {$x2} {$y2} l S\n";
}

function odc_pdf_box(string &$s,float $x,float $y,float $w,float $h,bool $fill=false): void
{
    if($fill) $s.="0.933 0.925 0.882 rg {$x} {$y} {$w} {$h} re f 0 g\n";
    $s.="0 G 0.55 w {$x} {$y} {$w} {$h} re S\n";
}

function odc_pdf_money(float $v,string $currency): string
{
    return ($currency==='USD'?'US$':'$').number_format($v,2,'.',',');
}

function odc_pdf_clip(string $text,int $max): string
{
    $text=trim(preg_replace('/\s+/u',' ',$text)??$text);
    if(mb_strlen($text,'UTF-8')<=$max) return $text;
    return rtrim(mb_substr($text,0,max(1,$max-3),'UTF-8')).'...';
}

function odc_pdf_date(string $date): string
{
    if(preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$date,$m)) return $m[3].'/'.$m[2].'/'.$m[1];
    return $date;
}

function odc_pdf_generate(array $d,array $user,string $documentRoot): string
{
    $s=''; $left=42.0;
    $currency=strtoupper(trim((string)($d['moneda']??'MXN')));
    if(!in_array($currency,['MXN','USD'],true)) $currency='MXN';
    $folio=trim((string)($d['folio']??'PENDIENTE'));
    $fecha=odc_pdf_date(trim((string)($d['fecha']??'')));

    $logoBytes=''; $logoW=0; $logoH=0;
    $logoPath=rtrim($documentRoot,'/').'/mapa/assets/logo.jpg';
    if(is_file($logoPath)){
        $info=@getimagesize($logoPath); $bytes=@file_get_contents($logoPath);
        if(is_array($info)&&is_string($bytes)&&$bytes!==''&&(($info[2]??0)===IMAGETYPE_JPEG)){
            $logoBytes=$bytes; $logoW=(int)$info[0]; $logoH=(int)$info[1];
        }
    }

    if($logoBytes!=='' && $logoW>0 && $logoH>0) {
        $maxW=78.0; $maxH=70.0;
        $scale=min($maxW/$logoW,$maxH/$logoH);
        $drawW=$logoW*$scale;
        $drawH=$logoH*$scale;
        $drawX=50.0+(($maxW-$drawW)/2);
        $drawY=699.0+(($maxH-$drawH)/2);
        $s.="q {$drawW} 0 0 {$drawH} {$drawX} {$drawY} cm /Im1 Do Q\n";
    }
    odc_pdf_text($s,220,756,'ORDEN DE COMPRA',16,false);
    odc_pdf_text($s,228,733,'Jardines de Juan Pablo',12,true);
    odc_pdf_text($s,194,718,'RAZON SOCIAL: MEGUESA   RFC: MEG-060608-LQ6',8,true);
    odc_pdf_text($s,174,706,'CALLE: CHURUBUSCO NORTE No 217   COLONIA: CHURUBUSCO',8,true);
    odc_pdf_text($s,238,694,'MONTERREY, NL CP 64590',8,true);

    odc_pdf_box($s,466,735,104,28,true);
    odc_pdf_text($s,474,745,'No  '.odc_pdf_clip($folio,18),9,true);
    odc_pdf_box($s,466,701,104,20,true);
    odc_pdf_text($s,501,708,'FECHA',8,true);
    odc_pdf_box($s,466,673,104,20,true);
    odc_pdf_text($s,495,680,$fecha,8,true);

    $top=651.0; $rh=19.0; $cols=[88.0,240.0,92.0,108.0];
    $rows=[
        ['EMPRESA:',(string)($d['proveedor']??''),'',''],
        ['DOMICILIO:',(string)($d['domicilio']??''),'TELEFONO:',(string)($d['telefono']??'')],
        ['CIUDAD Y ESTADO:',(string)($d['ciudadEstado']??''),'T/ENTREGA:',(string)($d['tiempoEntrega']??'')],
        ['COND. DE PAGO:',(string)($d['condicionPago']??''),'T. CAMBIO:',(string)($d['tipoCambio']??1)],
        ['MONEDA:',$currency,'RFC:',(string)($d['rfc']??'')],
    ];
    foreach($rows as $i=>$row){
        $y=$top-$rh*($i+1); $x=$left;
        foreach($cols as $w){ odc_pdf_box($s,$x,$y,$w,$rh,false); $x+=$w; }
        odc_pdf_text($s,$left+4,$y+6,$row[0],7,true);
        odc_pdf_text($s,$left+$cols[0]+4,$y+6,odc_pdf_clip($row[1],44),7,false);
        if($row[2]!=='') odc_pdf_text($s,$left+$cols[0]+$cols[1]+4,$y+6,$row[2],7,true);
        if($row[3]!=='') odc_pdf_text($s,$left+$cols[0]+$cols[1]+$cols[2]+4,$y+6,odc_pdf_clip($row[3],24),7,false);
    }

    $tableTop=535.0; $hh=24.0; $ih=22.0; $ic=[90.0,268.0,82.0,88.0];
    $x=$left; foreach($ic as $w){ odc_pdf_box($s,$x,$tableTop-$hh,$w,$hh,true); $x+=$w; }
    odc_pdf_text($s,59,$tableTop-16,'CANTIDAD',7,true);
    odc_pdf_text($s,201,$tableTop-16,'DESCRIPCION',7,true);
    odc_pdf_text($s,405,$tableTop-11,'PRECIO',6,true);
    odc_pdf_text($s,402,$tableTop-19,'UNITARIO',6,true);
    odc_pdf_text($s,505,$tableTop-16,'IMPORTE',7,true);

    $items=is_array($d['items']??null)?$d['items']:[]; $rowsCount=10;
    for($i=0;$i<$rowsCount;$i++){
        $y=$tableTop-$hh-$ih*($i+1); $x=$left;
        foreach($ic as $w){ odc_pdf_box($s,$x,$y,$w,$ih,false); $x+=$w; }
        if(!isset($items[$i])||!is_array($items[$i])) continue;
        $it=$items[$i]; $qty=(float)($it['qty']??0); $price=(float)($it['price']??0);
        $amount=(float)($it['amount']??($qty*$price));
        odc_pdf_text($s,92,$y+8,$qty==(int)$qty?(string)(int)$qty:number_format($qty,2,'.',''),7,false);
        odc_pdf_text($s,137,$y+8,odc_pdf_clip((string)($it['description']??''),54),6.6,false);
        odc_pdf_text($s,410,$y+8,odc_pdf_money($price,$currency),7,false);
        odc_pdf_text($s,505,$y+8,odc_pdf_money($amount,$currency),7,false);
    }

    $bottom=$tableTop-$hh-$ih*$rowsCount;
    odc_pdf_box($s,$left,$bottom-24,300,24,true);
    odc_pdf_text($s,70,$bottom-15,'FAVOR DE CONFIRMAR RECEPCION DE OC',9,true);
    odc_pdf_text($s,$left,$bottom-46,'CONFIRMACION DE REQUISICION',8,false);
    odc_pdf_text($s,$left,$bottom-68,'NOMBRE:',7,false);
    odc_pdf_text($s,95,$bottom-68,odc_pdf_clip(trim((string)($user['name']??'')),36),8,true);
    odc_pdf_line($s,94,$bottom-71,340,$bottom-71);
    odc_pdf_text($s,$left,$bottom-89,'PUESTO:',7,false); odc_pdf_line($s,94,$bottom-92,340,$bottom-92);
    odc_pdf_text($s,$left,$bottom-110,'FIRMA:',7,false); odc_pdf_line($s,94,$bottom-113,340,$bottom-113);

    $subtotal=(float)($d['subtotal']??0); $iva=(float)($d['iva']??0); $retIsr=(float)($d['retIsr']??0); $retIva=(float)($d['retIva']??0);
    $total=(float)($d['total']??($subtotal+$iva-$retIsr-$retIva));
    $totals=[['SUBTOTAL:',$subtotal],['I.V.A.',$iva],['retencion ISR',-$retIsr],['Retencion IVA',-$retIva],['TOTAL',$total]];
    foreach($totals as $i=>$row){
        $y=$bottom-20*($i+1);
        if($i===4) odc_pdf_box($s,350,$y,220,20,true); else odc_pdf_line($s,350,$y,570,$y);
        odc_pdf_text($s,420,$y+6,$row[0],7,$i===4);
        odc_pdf_text($s,505,$y+6,odc_pdf_money((float)$row[1],$currency),7,true);
    }
    odc_pdf_text($s,350,$bottom-122,'No. Cuenta: '.odc_pdf_clip(trim((string)($d['cuenta']??'')),28),7,false);
    odc_pdf_text($s,350,$bottom-137,'No. Clabe: '.odc_pdf_clip(trim((string)($d['clabe']??'')),28),7,false);
    odc_pdf_text($s,350,$bottom-152,'Banco: '.odc_pdf_clip(trim((string)($d['banco']??'')),28),7,false);

    $objects=[];
    $objects[1]='<< /Type /Catalog /Pages 2 0 R >>';
    $objects[2]='<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
    $resources='<< /Font << /F1 5 0 R /F2 6 0 R >>';
    if($logoBytes!=='') $resources.=' /XObject << /Im1 7 0 R >>';
    $resources.=' >>';
    $objects[3]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources '.$resources.' /Contents 4 0 R >>';
    $objects[4]="<< /Length ".strlen($s)." >>\nstream\n".$s."endstream";
    $objects[5]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    $objects[6]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
    if($logoBytes!=='') $objects[7]="<< /Type /XObject /Subtype /Image /Width {$logoW} /Height {$logoH} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($logoBytes)." >>\nstream\n".$logoBytes."\nendstream";

    ksort($objects); $pdf="%PDF-1.4\n"; $offsets=[0];
    foreach($objects as $n=>$obj){ $offsets[$n]=strlen($pdf); $pdf.="{$n} 0 obj\n{$obj}\nendobj\n"; }
    $max=max(array_keys($objects)); $xref=strlen($pdf);
    $pdf.="xref\n0 ".($max+1)."\n0000000000 65535 f \n";
    for($i=1;$i<=$max;$i++) $pdf.=sprintf("%010d 00000 n \n",$offsets[$i]??0);
    $pdf.="trailer\n<< /Size ".($max+1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    return $pdf;
}
