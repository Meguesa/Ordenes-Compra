<?php

declare(strict_types=1);

/** @return string[] */
function ordenes_preview_allowed_emails(): array
{
    return [
        'gabriel.guerra@juanpablo.com.mx',
        'sistemas@juanpablo.com.mx',
    ];
}

function ordenes_user_has_preview_access(?array $user = null): bool
{
    $user = $user ?? portal_user();
    $email = strtolower(trim((string)($user['email'] ?? '')));
    return $email !== '' && in_array($email, ordenes_preview_allowed_emails(), true);
}

function ordenes_require_preview_access(): void
{
    if (ordenes_user_has_preview_access()) return;

    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="es-MX"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Acceso restringido</title></head><body style="font-family:Segoe UI,Arial,sans-serif;background:#f7f5f0;color:#241d19;margin:0;padding:40px"><main style="max-width:720px;margin:60px auto;background:#fff;border:1px solid #e8e1d8;border-top:5px solid #fdbb2d;border-radius:14px;padding:28px"><h1 style="color:#3a1109">Órdenes de Compra</h1><p>Esta herramienta continúa en desarrollo y tu cuenta todavía no tiene acceso.</p><p><a href="/" style="color:#225b8a;font-weight:700">Regresar al Portal Interno</a></p></main></body></html>';
    exit;
}

/* SharePoint backend for Orders */
const ORDENES_LIST_TITLE = 'BI_Ordenes_Compra';

function ordenes_config(): array {
    $raw = null;
    foreach (['/home/juanpab1/reportes-config/config.php','/home/juanpab1/portal-config/config.php'] as $configPath) {
        if (!is_file($configPath)) continue;
        $loaded = require $configPath;
        if (is_array($loaded)) {
            $raw = $loaded;
            break;
        }
    }
    if (!is_array($raw)) throw new RuntimeException('Configuracion privada no disponible.');
    $cfg = [
        'tenantId'=>trim((string)($raw['reportes_tenant_id'] ?? $raw['portal_access_tenant_id'] ?? $raw['solicitud_backend_tenant_id'] ?? '')),
        'clientId'=>trim((string)($raw['reportes_client_id'] ?? $raw['portal_access_client_id'] ?? $raw['solicitud_backend_client_id'] ?? '')),
        'clientSecret'=>trim((string)($raw['reportes_client_secret'] ?? $raw['portal_access_client_secret'] ?? $raw['solicitud_backend_client_secret'] ?? '')),
        'siteId'=>trim((string)($raw['reportes_sharepoint_site_id'] ?? $raw['portal_access_sharepoint_site_id'] ?? $raw['solicitud_sharepoint_site_id'] ?? '')),
        'pfxPath'=>trim((string)($raw['reportes_sharepoint_pfx_path'] ?? $raw['portal_access_sharepoint_pfx_path'] ?? $raw['solicitud_sharepoint_pfx_path'] ?? '')),
        'pfxPassword'=>(string)($raw['reportes_sharepoint_pfx_password'] ?? $raw['portal_access_sharepoint_pfx_password'] ?? $raw['solicitud_sharepoint_pfx_password'] ?? ''),
    ];
    foreach (['tenantId','clientId','clientSecret','siteId','pfxPath'] as $k) if ($cfg[$k]==='') throw new RuntimeException('Falta configurar '.$k.'.');
    if (!is_file($cfg['pfxPath'])) throw new RuntimeException('Certificado PFX no disponible.');
    return $cfg;
}

function ordenes_http_json(string $url,string $method,array $headers,?string $body=null): array {
    $c=curl_init($url);
    if ($c===false) throw new RuntimeException('No fue posible iniciar cURL.');
    $o=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2];
    if ($body!==null) $o[CURLOPT_POSTFIELDS]=$body;
    curl_setopt_array($c,$o);
    $raw=curl_exec($c); $status=(int)curl_getinfo($c,CURLINFO_HTTP_CODE); $err=curl_error($c); curl_close($c);
    if ($raw===false) throw new RuntimeException('Solicitud remota fallida: '.$err);
    $data=json_decode((string)$raw,true);
    if ($status<200 || $status>=300) {
        $detail=is_array($data)?(string)($data['error']['message'] ?? $data['error_description'] ?? ''):'';
        throw new RuntimeException('SharePoint respondio HTTP '.$status.($detail!==''?': '.$detail:''));
    }
    return is_array($data)?$data:[];
}

function ordenes_b64url(string $v): string { return rtrim(strtr(base64_encode($v),'+/','-_'),'='); }

function ordenes_graph_token(array $cfg): string {
    $url='https://login.microsoftonline.com/'.rawurlencode($cfg['tenantId']).'/oauth2/v2.0/token';
    $body=http_build_query(['client_id'=>$cfg['clientId'],'client_secret'=>$cfg['clientSecret'],'scope'=>'https://graph.microsoft.com/.default','grant_type'=>'client_credentials'],'','&',PHP_QUERY_RFC3986);
    $d=ordenes_http_json($url,'POST',['Content-Type: application/x-www-form-urlencoded','Accept: application/json'],$body);
    $t=trim((string)($d['access_token']??'')); if($t==='') throw new RuntimeException('Entra no devolvio token Graph.'); return $t;
}

function ordenes_sp_token(array $cfg,string $host): string {
    $bytes=file_get_contents($cfg['pfxPath']); $certs=[];
    if($bytes===false || !openssl_pkcs12_read($bytes,$certs,$cfg['pfxPassword'])) throw new RuntimeException('No fue posible abrir PFX.');
    $cert=(string)($certs['cert']??''); $key=$certs['pkey']??null;
    $der=preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/','',$cert);
    $derBytes=is_string($der)?base64_decode($der,true):false;
    if($key===null || $derBytes===false) throw new RuntimeException('PFX invalido.');
    $tokenUrl='https://login.microsoftonline.com/'.rawurlencode($cfg['tenantId']).'/oauth2/v2.0/token'; $now=time();
    $head=ordenes_b64url((string)json_encode(['alg'=>'RS256','typ'=>'JWT','x5t'=>ordenes_b64url(hash('sha1',$derBytes,true))]));
    $claims=ordenes_b64url((string)json_encode(['aud'=>$tokenUrl,'iss'=>$cfg['clientId'],'sub'=>$cfg['clientId'],'jti'=>bin2hex(random_bytes(16)),'nbf'=>$now-30,'iat'=>$now,'exp'=>$now+300]));
    $unsigned=$head.'.'.$claims; $sig='';
    if(!openssl_sign($unsigned,$sig,$key,OPENSSL_ALGO_SHA256)) throw new RuntimeException('No fue posible firmar token.');
    $body=http_build_query(['client_id'=>$cfg['clientId'],'scope'=>'https://'.strtolower($host).'/.default','grant_type'=>'client_credentials','client_assertion_type'=>'urn:ietf:params:oauth:client-assertion-type:jwt-bearer','client_assertion'=>$unsigned.'.'.ordenes_b64url($sig)],'','&',PHP_QUERY_RFC3986);
    $d=ordenes_http_json($tokenUrl,'POST',['Content-Type: application/x-www-form-urlencoded','Accept: application/json'],$body);
    $t=trim((string)($d['access_token']??'')); if($t==='') throw new RuntimeException('Entra no devolvio token SharePoint.'); return $t;
}

function ordenes_session(): array {
    static $s=null; if(is_array($s)) return $s;
    $cfg=ordenes_config(); $gt=ordenes_graph_token($cfg);
    $site=ordenes_http_json('https://graph.microsoft.com/v1.0/sites/'.rawurlencode($cfg['siteId']).'?$select=webUrl','GET',['Authorization: Bearer '.$gt,'Accept: application/json']);
    $url=rtrim((string)($site['webUrl']??''),'/'); $host=(string)parse_url($url,PHP_URL_HOST);
    if($url==='' || $host==='') throw new RuntimeException('No fue posible resolver el sitio SharePoint.');
    return $s=['siteUrl'=>$url,'token'=>ordenes_sp_token($cfg,$host)];
}

function ordenes_list_base(): string {
    $s=ordenes_session();
    return rtrim($s['siteUrl'],'/')."/_api/web/lists/getbytitle('".rawurlencode(ORDENES_LIST_TITLE)."')";
}

function ordenes_aliases(): array {
    return [
      'Title'=>['Title','Titulo'],'Folio'=>['Folio'],'Fecha'=>['Fecha'],'EmpresaCompradora'=>['EmpresaCompradora','Empresa Compradora'],
      'Proveedor'=>['Proveedor'],'Domicilio'=>['Domicilio'],'RFC'=>['RFC'],'Telefono'=>['Telefono','Teléfono'],'CiudadEstado'=>['CiudadEstado','Ciudad Estado'],
      'CondicionPago'=>['CondicionPago','Condicion de Pago'],'TiempoEntrega'=>['TiempoEntrega','Tiempo de Entrega'],'Moneda'=>['Moneda'],
      'TipoCambio'=>['TipoCambio','Tipo de Cambio'],'PartidasJson'=>['PartidasJson','Partidas'],'Subtotal'=>['Subtotal'],'IvaPct'=>['IvaPct'],
      'IVA'=>['IVA'],'RetIsrPct'=>['RetIsrPct'],'RetencionISR'=>['RetencionISR','Retencion ISR'],'RetIvaPct'=>['RetIvaPct'],
      'RetencionIVA'=>['RetencionIVA','Retencion IVA'],'Total'=>['Total'],'Banco'=>['Banco'],'Cuenta'=>['Cuenta'],'CLABE'=>['CLABE'],
      'SolicitanteNombre'=>['SolicitanteNombre','Solicitante Nombre'],'SolicitanteCorreo'=>['SolicitanteCorreo','Solicitante Correo'],
      'Estado'=>['Estado','Estatus'],'Ambiente'=>['Ambiente']
    ];
}

function ordenes_schema(): array {
    $text=['Folio','EmpresaCompradora','Proveedor','Domicilio','RFC','Telefono','CiudadEstado','CondicionPago','TiempoEntrega','Moneda','Banco','Cuenta','CLABE','SolicitanteNombre','SolicitanteCorreo','Estado','Ambiente'];
    $num=['TipoCambio','Subtotal','IvaPct','IVA','RetIsrPct','RetencionISR','RetIvaPct','RetencionIVA','Total'];
    $out=['Fecha'=>'DateTime','PartidasJson'=>'Note']; foreach($text as $x)$out[$x]='Text'; foreach($num as $x)$out[$x]='Number'; return $out;
}

function ordenes_fields(bool $refresh=false): array {
    static $cache=null; if(!$refresh && is_array($cache)) return $cache;
    $s=ordenes_session(); $d=ordenes_http_json(ordenes_list_base()."/fields?\$select=Title,InternalName,TypeAsString,ReadOnlyField,Hidden",'GET',['Authorization: Bearer '.$s['token'],'Accept: application/json;odata=nometadata']);
    $cache=[]; foreach(($d['value']??[]) as $r) if(is_array($r)) $cache[]=$r; return $cache;
}

function ordenes_norm(string $v): string {
    if(function_exists('iconv')){$x=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$v);if(is_string($x))$v=$x;}
    return strtolower((string)preg_replace('/[^a-z0-9]+/i','',$v));
}

function ordenes_field(string $canonical): ?string {
    $cand=ordenes_aliases()[$canonical]??[$canonical];
    foreach(ordenes_fields() as $f){
        if(!empty($f['ReadOnlyField'])||!empty($f['Hidden']))continue;
        foreach($cand as $candidate){
            if(strcasecmp((string)$f['InternalName'],$candidate)===0) return (string)$f['InternalName'];
        }
    }
    foreach(ordenes_fields() as $f){
        if(!empty($f['ReadOnlyField'])||!empty($f['Hidden']))continue;
        $titleNorm=ordenes_norm((string)$f['Title']);
        $internalNorm=ordenes_norm((string)$f['InternalName']);
        foreach($cand as $candidate){
            $needle=ordenes_norm((string)$candidate);
            if($needle!=='' && ($needle===$titleNorm || $needle===$internalNorm)) return (string)$f['InternalName'];
        }
    }
    return null;
}

function ordenes_missing_schema_fields(): array {
    $m=[]; foreach(array_keys(ordenes_schema()) as $k) if(ordenes_field($k)===null)$m[]=$k; return $m;
}

function ordenes_expected_schema(): array { return ordenes_schema(); }

function ordenes_try_prepare_schema(): array {
    $s=ordenes_session(); $created=[];$errors=[];
    foreach(ordenes_missing_schema_fields() as $name){
        $type=ordenes_schema()[$name];
        if($type==='DateTime')$xml='<Field Type="DateTime" Name="'.$name.'" DisplayName="'.$name.'" Format="DateOnly" />';
        elseif($type==='Number')$xml='<Field Type="Number" Name="'.$name.'" DisplayName="'.$name.'" Decimals="Automatic" />';
        elseif($type==='Note')$xml='<Field Type="Note" Name="'.$name.'" DisplayName="'.$name.'" NumLines="20" RichText="FALSE" />';
        else $xml='<Field Type="Text" Name="'.$name.'" DisplayName="'.$name.'" MaxLength="255" />';
        $json=json_encode(['parameters'=>['__metadata'=>['type'=>'SP.XmlSchemaFieldCreationInformation'],'SchemaXml'=>$xml,'Options'=>0]],JSON_UNESCAPED_SLASHES);
        try{ordenes_http_json(ordenes_list_base().'/fields/CreateFieldAsXml','POST',['Authorization: Bearer '.$s['token'],'Accept: application/json;odata=verbose','Content-Type: application/json;odata=verbose'],(string)$json);$created[]=$name;ordenes_fields(true);}
        catch(Throwable $e){$errors[$name]=$e->getMessage();}
    }
    ordenes_fields(true); return ['created'=>$created,'errors'=>$errors,'missing'=>ordenes_missing_schema_fields()];
}

function ordenes_required_schema_fields(): array {
    return ['Folio','Fecha','Proveedor','PartidasJson','Subtotal','IVA','RetencionISR','RetencionIVA','Total','SolicitanteCorreo','Estado','Ambiente'];
}

function ordenes_map(array $values,array $required=[]): array {
    $out=[]; $missing=[];
    foreach($values as $k=>$v){
        $field=ordenes_field((string)$k);
        if($field===null){
            if(in_array((string)$k,$required,true)) $missing[]=(string)$k;
            continue;
        }
        $out[$field]=$v;
    }
    if($missing) throw new RuntimeException('Faltan columnas requeridas en '.ORDENES_LIST_TITLE.': '.implode(', ',$missing).'.');
    return $out;
}

function ordenes_create_item(array $values): array {
    $s=ordenes_session(); $payload=ordenes_map($values,ordenes_required_schema_fields());
    $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if(!is_string($json)) throw new RuntimeException('No fue posible preparar el registro.');
    return ordenes_http_json(ordenes_list_base().'/items','POST',[
        'Authorization: Bearer '.$s['token'],'Accept: application/json;odata=nometadata','Content-Type: application/json;odata=nometadata'
    ],$json);
}

function ordenes_get_item(int $id): array {
    if($id<=0) throw new InvalidArgumentException('ID invalido.');
    $s=ordenes_session();
    return ordenes_http_json(ordenes_list_base().'/items('.$id.')','GET',[
        'Authorization: Bearer '.$s['token'],'Accept: application/json;odata=nometadata'
    ]);
}

function ordenes_update_item(int $id,array $values): void {
    if($id<=0) throw new InvalidArgumentException('ID invalido.');
    $s=ordenes_session(); $payload=ordenes_map($values,[]);
    $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if(!is_string($json)) throw new RuntimeException('No fue posible preparar la actualizacion.');
    ordenes_http_json(ordenes_list_base().'/items('.$id.')','POST',[
        'Authorization: Bearer '.$s['token'],'Accept: application/json;odata=nometadata','Content-Type: application/json;odata=nometadata',
        'IF-MATCH: *','X-HTTP-Method: MERGE'
    ],$json);
}


function ordenes_num_value(mixed $value): float
{
    return is_numeric($value) ? (float)$value : 0.0;
}

function ordenes_save_draft_payload(array $input, array $user): array
{
    $missingSchema = ordenes_missing_schema_fields();
    $missingRequired = array_values(array_intersect($missingSchema, ordenes_required_schema_fields()));
    if (count($missingRequired) > 0) {
        throw new RuntimeException('Faltan columnas requeridas en ' . ORDENES_LIST_TITLE . ': ' . implode(', ', $missingRequired) . '.');
    }

    $userName = trim((string)($user['name'] ?? 'Usuario'));
    $userEmail = strtolower(trim((string)($user['email'] ?? '')));
    if ($userEmail === '') throw new RuntimeException('La sesion no contiene correo electronico.');

    $proveedor = trim((string)($input['proveedor'] ?? ''));
    $rfc = strtoupper(trim((string)($input['rfc'] ?? '')));
    $condicionPago = trim((string)($input['condicionPago'] ?? ''));
    $fecha = trim((string)($input['fecha'] ?? ''));
    if ($proveedor === '' || $rfc === '' || $condicionPago === '' || $fecha === '') {
        throw new RuntimeException('Proveedor, RFC, condicion de pago y fecha son obligatorios para guardar el borrador.');
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $fecha);
    if (!$date || $date->format('Y-m-d') !== $fecha) throw new RuntimeException('La fecha no es valida.');

    $itemsRaw = is_array($input['items'] ?? null) ? $input['items'] : [];
    $items = [];
    $subtotal = 0.0;
    foreach ($itemsRaw as $row) {
        if (!is_array($row)) continue;
        $qty = max(0.0, ordenes_num_value($row['qty'] ?? 0));
        $description = trim((string)($row['description'] ?? ''));
        $price = max(0.0, ordenes_num_value($row['price'] ?? 0));
        if ($description === '' && $qty <= 0 && $price <= 0) continue;
        if ($description === '' || $qty <= 0) throw new RuntimeException('Cada partida debe tener cantidad mayor a cero y descripcion.');
        $amount = round($qty * $price, 2);
        $items[] = ['qty' => $qty, 'description' => $description, 'price' => round($price, 2), 'amount' => $amount];
        $subtotal += $amount;
    }
    if (count($items) === 0) throw new RuntimeException('Agrega al menos una partida antes de guardar.');
    $subtotal = round($subtotal, 2);

    $ivaPct = max(0.0, min(100.0, ordenes_num_value($input['ivaPct'] ?? 0)));
    $retIsrPct = max(0.0, min(100.0, ordenes_num_value($input['retIsrPct'] ?? 0)));
    $retIvaPct = max(0.0, min(100.0, ordenes_num_value($input['retIvaPct'] ?? 0)));
    $iva = round($subtotal * $ivaPct / 100, 2);
    $retIsr = round($subtotal * $retIsrPct / 100, 2);
    $retIva = round($subtotal * $retIvaPct / 100, 2);
    $total = round($subtotal + $iva - $retIsr - $retIva, 2);

    $itemId = (int)($input['itemId'] ?? 0);
    $folio = trim((string)($input['folio'] ?? ''));
    $partidasJson = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($partidasJson)) throw new RuntimeException('No fue posible serializar las partidas.');

    $values = [
        'Title' => $folio !== '' ? $folio : 'ODC-PREVIEW-PENDIENTE',
        'Folio' => $folio,
        'Fecha' => $fecha,
        'EmpresaCompradora' => trim((string)($input['empresaCompradora'] ?? 'MEGUESA')),
        'Proveedor' => $proveedor,
        'Domicilio' => trim((string)($input['domicilio'] ?? '')),
        'RFC' => $rfc,
        'Telefono' => trim((string)($input['telefono'] ?? '')),
        'CiudadEstado' => trim((string)($input['ciudadEstado'] ?? '')),
        'CondicionPago' => $condicionPago,
        'TiempoEntrega' => trim((string)($input['tiempoEntrega'] ?? '')),
        'Moneda' => strtoupper(trim((string)($input['moneda'] ?? 'MXN'))),
        'TipoCambio' => max(0.0, ordenes_num_value($input['tipoCambio'] ?? 1)),
        'PartidasJson' => $partidasJson,
        'Subtotal' => $subtotal,
        'IvaPct' => $ivaPct,
        'IVA' => $iva,
        'RetIsrPct' => $retIsrPct,
        'RetencionISR' => $retIsr,
        'RetIvaPct' => $retIvaPct,
        'RetencionIVA' => $retIva,
        'Total' => $total,
        'Banco' => trim((string)($input['banco'] ?? '')),
        'Cuenta' => trim((string)($input['cuenta'] ?? '')),
        'CLABE' => trim((string)($input['clabe'] ?? '')),
        'SolicitanteNombre' => $userName,
        'SolicitanteCorreo' => $userEmail,
        'Estado' => 'BORRADOR',
        'Ambiente' => 'PREVIEW',
    ];

    if ($itemId > 0) {
        $existing = ordenes_get_item($itemId);
        $emailField = ordenes_field('SolicitanteCorreo');
        $existingOwner = $emailField !== null ? strtolower(trim((string)($existing[$emailField] ?? ''))) : '';
        if ($existingOwner !== '' && $existingOwner !== $userEmail) throw new RuntimeException('No tienes permiso para modificar este borrador.');
        if ($folio === '') $folio = 'ODC-PREVIEW-' . str_pad((string)$itemId, 6, '0', STR_PAD_LEFT);
        $values['Title'] = $folio;
        $values['Folio'] = $folio;
        ordenes_update_item($itemId, $values);
    } else {
        $created = ordenes_create_item($values);
        $itemId = (int)($created['Id'] ?? $created['ID'] ?? 0);
        if ($itemId <= 0) throw new RuntimeException('SharePoint creo el registro, pero no devolvio su identificador.');
        $folio = 'ODC-PREVIEW-' . str_pad((string)$itemId, 6, '0', STR_PAD_LEFT);
        ordenes_update_item($itemId, ['Title' => $folio, 'Folio' => $folio]);
    }

    return [
        'ok' => true,
        'itemId' => $itemId,
        'folio' => $folio,
        'estado' => 'BORRADOR',
        'totals' => [
            'subtotal' => $subtotal,
            'iva' => $iva,
            'retIsr' => $retIsr,
            'retIva' => $retIva,
            'total' => $total,
        ],
    ];
}


function ordenes_mail_graph_token(): string
{
    $path='/home/juanpab1/portal-config/config.php';
    if(!is_file($path)) throw new RuntimeException('No se encontro la configuracion privada del Portal.');
    $raw=require $path;
    if(!is_array($raw)) throw new RuntimeException('La configuracion privada del Portal no es valida.');

    $tenant=trim((string)($raw['registro_servicios_tenant_id']??''));
    $client=trim((string)($raw['registro_servicios_client_id']??''));
    $secret=trim((string)($raw['registro_servicios_client_secret']??''));
    if($tenant===''||$client===''||$secret==='') {
        throw new RuntimeException('Faltan credenciales de correo de Registro de Servicios.');
    }

    $url='https://login.microsoftonline.com/'.rawurlencode($tenant).'/oauth2/v2.0/token';
    $body=http_build_query([
        'client_id'=>$client,
        'client_secret'=>$secret,
        'scope'=>'https://graph.microsoft.com/.default',
        'grant_type'=>'client_credentials',
    ],'','&',PHP_QUERY_RFC3986);

    $curl=curl_init($url);
    if($curl===false) throw new RuntimeException('No fue posible iniciar autenticacion de correo.');
    curl_setopt_array($curl,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>10,
        CURLOPT_TIMEOUT=>30,
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>$body,
        CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded','Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
    ]);
    $response=curl_exec($curl);
    $status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
    $error=curl_error($curl);
    curl_close($curl);

    if($response===false) throw new RuntimeException('La autenticacion de correo fallo: '.$error);
    $data=json_decode((string)$response,true);
    if($status<200||$status>=300) {
        $detail=is_array($data)?trim((string)($data['error_description']??$data['error']??'')):'';
        throw new RuntimeException('Microsoft Entra respondio HTTP '.$status.($detail!==''?': '.$detail:'.'));
    }

    $token=trim((string)($data['access_token']??''));
    if($token==='') throw new RuntimeException('Microsoft Entra no devolvio token de correo.');
    return $token;
}

function ordenes_pdf_escape(string $text): string
{
    $encoded=function_exists('iconv')?@iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',$text):false;
    if(!is_string($encoded)) $encoded=preg_replace('/[^\\x20-\\x7E]/','?',$text)??'';
    return str_replace(['\\\\','(',')'],['\\\\\\\\','\\(','\\)'],$encoded);
}

function ordenes_pdf_text(string &$stream,float $x,float $y,string $text,float $size=8,bool $bold=false): void
{
    $font=$bold?'F2':'F1';
    $stream.="BT /{$font} {$size} Tf {$x} {$y} Td (".ordenes_pdf_escape($text).") Tj ET\n";
}

function ordenes_pdf_line(string &$stream,float $x1,float $y1,float $x2,float $y2): void
{
    $stream.="0 G 0.55 w {$x1} {$y1} m {$x2} {$y2} l S\n";
}

function ordenes_pdf_rect(string &$stream,float $x,float $y,float $w,float $h,bool $fill=false): void
{
    if($fill) $stream.="0.88 g {$x} {$y} {$w} {$h} re f 0 g\n";
    $stream.="0 G 0.55 w {$x} {$y} {$w} {$h} re S\n";
}

function ordenes_pdf_money(float $value,string $currency): string
{
    return ($currency==='USD'?'US
{
    $folio=trim((string)($input['folio']??''));
    $itemId=(int)($input['itemId']??0);

    if($itemId<=0 || !preg_match('/^ODC-PREVIEW-\d{6,}$/',$folio)) {
        throw new RuntimeException('Guarda primero la ODC como borrador antes de enviar el correo de prueba.');
    }

    if(!ordenes_user_has_preview_access($user)) {
        throw new RuntimeException('Tu cuenta no puede enviar correos de prueba.');
    }

    $recipient='gabriel.guerra@juanpablo.com.mx';
    $sender='sistemas@juanpablo.com.mx';
    $proveedor=trim((string)($input['proveedor']??''));
    $observaciones=trim((string)($input['observaciones']??''));
    $moneda=strtoupper(trim((string)($input['moneda']??'MXN')));
    $total=(float)($input['total']??0);

    $h=static fn(string $value):string=>htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $totalText=($moneda==='USD'?'US$':'$').number_format($total,2,'.',',');
    $obsHtml=$observaciones!==''?nl2br($h($observaciones)):'<em>Sin observaciones.</em>';

    $html='<!doctype html><html><body style="margin:0;background:#f5f1ec;font-family:Arial,sans-serif;color:#2b1b15">'
      .'<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:24px 10px;background:#f5f1ec"><tr><td align="center">'
      .'<table role="presentation" width="640" cellspacing="0" cellpadding="0" style="max-width:640px;background:#fff;border:1px solid #e4d9cf;border-radius:12px;overflow:hidden">'
      .'<tr><td style="padding:22px 26px;border-top:5px solid #e28a16">'
      .'<div style="font-size:11px;letter-spacing:1.4px;font-weight:700;color:#225b8a">JARDINES DE JUAN PABLO · PRUEBA</div>'
      .'<h1 style="font-size:22px;margin:8px 0 4px">Orden de Compra '.$h($folio).'</h1>'
      .'<p style="margin:0;color:#6b625d">Correo de prueba del nuevo flujo de Órdenes de Compra.</p>'
      .'</td></tr><tr><td style="padding:0 26px 24px">'
      .'<table width="100%" cellspacing="0" cellpadding="8" style="border-collapse:collapse;font-size:14px">'
      .'<tr><td style="font-weight:700;border-bottom:1px solid #eee7e1">Proveedor</td><td style="border-bottom:1px solid #eee7e1">'.$h($proveedor).'</td></tr>'
      .'<tr><td style="font-weight:700;border-bottom:1px solid #eee7e1">Total</td><td style="border-bottom:1px solid #eee7e1">'.$h($totalText).'</td></tr>'
      .'<tr><td style="font-weight:700;border-bottom:1px solid #eee7e1">Solicitante</td><td style="border-bottom:1px solid #eee7e1">'.$h((string)($user['name']??'')).'</td></tr>'
      .'</table>'
      .'<div style="margin-top:20px;padding:14px 16px;background:#fff8e6;border:1px solid #efd48a;border-radius:8px">'
      .'<strong>Observaciones</strong><div style="margin-top:8px;line-height:1.5">'.$obsHtml.'</div></div>'
      .'<p style="margin:20px 0 0;color:#756a64;font-size:12px">Durante esta etapa de pruebas, el único destinatario es '.$h($recipient).'.</p>'
      .'</td></tr></table></td></tr></table></body></html>';

    $input['folio']=$folio;
    $input['itemId']=$itemId;
    $pdf=ordenes_pdf_build($input,$user,(string)($_SERVER['DOCUMENT_ROOT']??''));
    if(!str_starts_with($pdf,'%PDF-')) throw new RuntimeException('No fue posible generar un PDF valido.');

    $attachmentName='ODC_'.$folio.'.pdf';
    $request=[
        'message'=>[
            'subject'=>'[PRUEBA] Orden de Compra '.$folio.' | '.$proveedor,
            'body'=>['contentType'=>'HTML','content'=>$html],
            'toRecipients'=>[['emailAddress'=>['address'=>$recipient]]],
            'attachments'=>[[
                '@odata.type'=>'#microsoft.graph.fileAttachment',
                'name'=>$attachmentName,
                'contentType'=>'application/pdf',
                'contentBytes'=>base64_encode($pdf),
            ]],
        ],
        'saveToSentItems'=>true,
    ];

    $json=json_encode($request,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if(!is_string($json)) throw new RuntimeException('No fue posible preparar el correo.');

    $token=ordenes_mail_graph_token();
    $curl=curl_init('https://graph.microsoft.com/v1.0/users/'.rawurlencode($sender).'/sendMail');
    if($curl===false) throw new RuntimeException('No fue posible iniciar el envio del correo.');

    curl_setopt_array($curl,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>10,
        CURLOPT_TIMEOUT=>40,
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>$json,
        CURLOPT_HTTPHEADER=>[
            'Authorization: Bearer '.$token,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
    ]);

    $response=curl_exec($curl);
    $status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
    $error=curl_error($curl);
    curl_close($curl);

    if($response===false) throw new RuntimeException('El envio de correo fallo: '.$error);
    if(!in_array($status,[200,202,204],true)) {
        $decoded=json_decode((string)$response,true);
        $detail=is_array($decoded)?trim((string)($decoded['error']['message']??'')):'';
        throw new RuntimeException('Microsoft Graph respondio HTTP '.$status.($detail!==''?': '.$detail:'.'));
    }

    return [
        'ok'=>true,
        'recipient'=>$recipient,
        'sender'=>$sender,
        'folio'=>$folio,
        'attachment'=>$attachmentName,
    ];
}
:'
{
    $folio=trim((string)($input['folio']??''));
    $itemId=(int)($input['itemId']??0);

    if($itemId<=0 || !preg_match('/^ODC-PREVIEW-\d{6,}$/',$folio)) {
        throw new RuntimeException('Guarda primero la ODC como borrador antes de enviar el correo de prueba.');
    }

    if(!ordenes_user_has_preview_access($user)) {
        throw new RuntimeException('Tu cuenta no puede enviar correos de prueba.');
    }

    $recipient='gabriel.guerra@juanpablo.com.mx';
    $sender='sistemas@juanpablo.com.mx';
    $proveedor=trim((string)($input['proveedor']??''));
    $observaciones=trim((string)($input['observaciones']??''));
    $moneda=strtoupper(trim((string)($input['moneda']??'MXN')));
    $total=(float)($input['total']??0);

    $h=static fn(string $value):string=>htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $totalText=($moneda==='USD'?'US$':'$').number_format($total,2,'.',',');
    $obsHtml=$observaciones!==''?nl2br($h($observaciones)):'<em>Sin observaciones.</em>';

    $html='<!doctype html><html><body style="margin:0;background:#f5f1ec;font-family:Arial,sans-serif;color:#2b1b15">'
      .'<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:24px 10px;background:#f5f1ec"><tr><td align="center">'
      .'<table role="presentation" width="640" cellspacing="0" cellpadding="0" style="max-width:640px;background:#fff;border:1px solid #e4d9cf;border-radius:12px;overflow:hidden">'
      .'<tr><td style="padding:22px 26px;border-top:5px solid #e28a16">'
      .'<div style="font-size:11px;letter-spacing:1.4px;font-weight:700;color:#225b8a">JARDINES DE JUAN PABLO · PRUEBA</div>'
      .'<h1 style="font-size:22px;margin:8px 0 4px">Orden de Compra '.$h($folio).'</h1>'
      .'<p style="margin:0;color:#6b625d">Correo de prueba del nuevo flujo de Órdenes de Compra.</p>'
      .'</td></tr><tr><td style="padding:0 26px 24px">'
      .'<table width="100%" cellspacing="0" cellpadding="8" style="border-collapse:collapse;font-size:14px">'
      .'<tr><td style="font-weight:700;border-bottom:1px solid #eee7e1">Proveedor</td><td style="border-bottom:1px solid #eee7e1">'.$h($proveedor).'</td></tr>'
      .'<tr><td style="font-weight:700;border-bottom:1px solid #eee7e1">Total</td><td style="border-bottom:1px solid #eee7e1">'.$h($totalText).'</td></tr>'
      .'<tr><td style="font-weight:700;border-bottom:1px solid #eee7e1">Solicitante</td><td style="border-bottom:1px solid #eee7e1">'.$h((string)($user['name']??'')).'</td></tr>'
      .'</table>'
      .'<div style="margin-top:20px;padding:14px 16px;background:#fff8e6;border:1px solid #efd48a;border-radius:8px">'
      .'<strong>Observaciones</strong><div style="margin-top:8px;line-height:1.5">'.$obsHtml.'</div></div>'
      .'<p style="margin:20px 0 0;color:#756a64;font-size:12px">Durante esta etapa de pruebas, el único destinatario es '.$h($recipient).'.</p>'
      .'</td></tr></table></td></tr></table></body></html>';

    $request=[
        'message'=>[
            'subject'=>'[PRUEBA] Orden de Compra '.$folio.' | '.$proveedor,
            'body'=>['contentType'=>'HTML','content'=>$html],
            'toRecipients'=>[['emailAddress'=>['address'=>$recipient]]],
        ],
        'saveToSentItems'=>true,
    ];

    $json=json_encode($request,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if(!is_string($json)) throw new RuntimeException('No fue posible preparar el correo.');

    $token=ordenes_mail_graph_token();
    $curl=curl_init('https://graph.microsoft.com/v1.0/users/'.rawurlencode($sender).'/sendMail');
    if($curl===false) throw new RuntimeException('No fue posible iniciar el envio del correo.');

    curl_setopt_array($curl,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>10,
        CURLOPT_TIMEOUT=>40,
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>$json,
        CURLOPT_HTTPHEADER=>[
            'Authorization: Bearer '.$token,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
    ]);

    $response=curl_exec($curl);
    $status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
    $error=curl_error($curl);
    curl_close($curl);

    if($response===false) throw new RuntimeException('El envio de correo fallo: '.$error);
    if(!in_array($status,[200,202,204],true)) {
        $decoded=json_decode((string)$response,true);
        $detail=is_array($decoded)?trim((string)($decoded['error']['message']??'')):'';
        throw new RuntimeException('Microsoft Graph respondio HTTP '.$status.($detail!==''?': '.$detail:'.'));
    }

    return [
        'ok'=>true,
        'recipient'=>$recipient,
        'sender'=>$sender,
        'folio'=>$folio,
    ];
}
).number_format($value,2,'.',',');
}

function ordenes_pdf_wrap(string $text,int $maxChars=48,int $maxLines=2): array
{
    $text=trim(preg_replace('/\\s+/u',' ',$text)??$text);
    if($text==='') return [''];
    $words=preg_split('/\\s+/u',$text)?:[$text];
    $lines=[];$line='';
    foreach($words as $word){
        $candidate=$line===''?$word:$line.' '.$word;
        if(mb_strlen($candidate,'UTF-8')<=$maxChars){
            $line=$candidate;
            continue;
        }
        if($line!=='') $lines[]=$line;
        $line=$word;
        if(count($lines)>=$maxLines-1) break;
    }
    if(count($lines)<$maxLines && $line!=='') $lines[]=$line;
    if(count($lines)===$maxLines && mb_strlen(implode(' ',$lines),'UTF-8')<mb_strlen($text,'UTF-8')){
        $last=array_pop($lines);
        $last=mb_substr($last,0,max(1,$maxChars-3),'UTF-8').'...';
        $lines[]=$last;
    }
    return $lines;
}

function ordenes_pdf_build(array $d,array $user,string $documentRoot): string
{
    $s=''; $left=42.0; $right=570.0;
    $currency=strtoupper(trim((string)($d['moneda']??'MXN')));
    if(!in_array($currency,['MXN','USD'],true)) $currency='MXN';

    $folio=trim((string)($d['folio']??'PENDIENTE'));
    $fecha=trim((string)($d['fecha']??''));
    if(preg_match('/^(\\d{4})-(\\d{2})-(\\d{2})$/',$fecha,$m)) $fecha=$m[3].'/'.$m[2].'/'.$m[1];

    $logoBytes=''; $logoW=0; $logoH=0;
    $logoPath=rtrim($documentRoot,'/').'/mapa/assets/logo.jpg';
    if(is_file($logoPath)){
        $info=@getimagesize($logoPath);
        $bytes=@file_get_contents($logoPath);
        if(is_array($info)&&is_string($bytes)&&$bytes!==''&&($info[2]??0)===IMAGETYPE_JPEG){
            $logoBytes=$bytes; $logoW=(int)$info[0]; $logoH=(int)$info[1];
        }
    }

    if($logoBytes!=='') $s.="q 52 0 0 52 48 704 cm /Im1 Do Q\n";
    ordenes_pdf_text($s,220,754,'ORDEN DE COMPRA',16,false);
    ordenes_pdf_text($s,228,731,'Jardines de Juan Pablo',12,true);
    ordenes_pdf_text($s,194,716,'RAZON SOCIAL: MEGUESA   RFC: MEG-060608-LQ6',8,true);
    ordenes_pdf_text($s,174,704,'CALLE: CHURUBUSCO NORTE No 217   COLONIA: CHURUBUSCO',8,true);
    ordenes_pdf_text($s,238,692,'MONTERREY, NL CP 64590',8,true);
    ordenes_pdf_text($s,102,733,'20',20,true);
    ordenes_pdf_text($s,105,723,'ANOS',6,true);

    ordenes_pdf_rect($s,466,733,104,30,true);
    ordenes_pdf_text($s,473,744,'No  '.$folio,9,true);
    ordenes_pdf_rect($s,466,697,104,22,true);
    ordenes_pdf_text($s,500,705,'FECHA',8,true);
    ordenes_pdf_rect($s,466,669,104,22,true);
    ordenes_pdf_text($s,494,677,$fecha,8,true);

    $py=650.0; $rh=19.0; $cols=[88.0,240.0,92.0,108.0];
    $rows=[
        ['EMPRESA:',(string)($d['proveedor']??''),'',''],
        ['DOMICILIO:',(string)($d['domicilio']??''),'TELEFONO:',(string)($d['telefono']??'')],
        ['CIUDAD Y ESTADO:',(string)($d['ciudadEstado']??''),'T/ENTREGA:',(string)($d['tiempoEntrega']??'')],
        ['COND. DE PAGO:',(string)($d['condicionPago']??''),'T. CAMBIO:',(string)($d['tipoCambio']??1)],
        ['MONEDA:',$currency,'RFC:',(string)($d['rfc']??'')],
    ];
    foreach($rows as $ri=>$row){
        $y=$py-$rh*($ri+1); $x=$left;
        foreach($cols as $cw){ordenes_pdf_rect($s,$x,$y,$cw,$rh,false);$x+=$cw;}
        ordenes_pdf_text($s,$left+4,$y+6,$row[0],7,true);
        ordenes_pdf_text($s,$left+$cols[0]+4,$y+6,mb_substr($row[1],0,45,'UTF-8'),7,false);
        if($row[2]!=='') ordenes_pdf_text($s,$left+$cols[0]+$cols[1]+4,$y+6,$row[2],7,true);
        if($row[3]!=='') ordenes_pdf_text($s,$left+$cols[0]+$cols[1]+$cols[2]+4,$y+6,mb_substr($row[3],0,24,'UTF-8'),7,false);
    }

    $tableTop=535.0; $headerH=24.0; $itemH=22.0; $ic=[90.0,268.0,82.0,88.0];
    $x=$left; foreach($ic as $cw){ordenes_pdf_rect($s,$x,$tableTop-$headerH,$cw,$headerH,true);$x+=$cw;}
    ordenes_pdf_text($s,59,$tableTop-16,'CANTIDAD',7,true);
    ordenes_pdf_text($s,201,$tableTop-16,'DESCRIPCION',7,true);
    ordenes_pdf_text($s,405,$tableTop-11,'PRECIO',6,true);
    ordenes_pdf_text($s,402,$tableTop-19,'UNITARIO',6,true);
    ordenes_pdf_text($s,505,$tableTop-16,'IMPORTE',7,true);

    $items=is_array($d['items']??null)?$d['items']:[];
    $rowsCount=max(10,min(12,count($items)>10?count($items):10));
    for($i=0;$i<$rowsCount;$i++){
        $y=$tableTop-$headerH-$itemH*($i+1); $x=$left;
        foreach($ic as $cw){ordenes_pdf_rect($s,$x,$y,$cw,$itemH,false);$x+=$cw;}
        $it=$items[$i]??null;
        if(!is_array($it)) continue;
        $qty=(float)($it['qty']??0);
        $price=(float)($it['price']??0);
        $amount=(float)($it['amount']??($qty*$price));
        $descLines=ordenes_pdf_wrap((string)($it['description']??''),54,2);
        ordenes_pdf_text($s,92,$y+8,$qty==(int)$qty?(string)(int)$qty:number_format($qty,2,'.',''),7,false);
        ordenes_pdf_text($s,137,$y+12,$descLines[0]??'',6.6,false);
        if(isset($descLines[1])) ordenes_pdf_text($s,137,$y+4,$descLines[1],6.6,false);
        ordenes_pdf_text($s,410,$y+8,ordenes_pdf_money($price,$currency),7,false);
        ordenes_pdf_text($s,505,$y+8,ordenes_pdf_money($amount,$currency),7,false);
    }

    $bottom=$tableTop-$headerH-$itemH*$rowsCount;
    ordenes_pdf_rect($s,$left,$bottom-24,300,24,true);
    ordenes_pdf_text($s,70,$bottom-15,'FAVOR DE CONFIRMAR RECEPCION DE OC',9,true);

    ordenes_pdf_text($s,$left,$bottom-45,'CONFIRMACION DE REQUISICION',8,false);
    ordenes_pdf_text($s,$left,$bottom-68,'NOMBRE:',7,false);
    ordenes_pdf_text($s,95,$bottom-68,trim((string)($user['name']??'')),8,true);
    ordenes_pdf_line($s,94,$bottom-71,340,$bottom-71);
    ordenes_pdf_text($s,$left,$bottom-89,'PUESTO:',7,false);
    ordenes_pdf_line($s,94,$bottom-92,340,$bottom-92);
    ordenes_pdf_text($s,$left,$bottom-110,'FIRMA:',7,false);
    ordenes_pdf_line($s,94,$bottom-113,340,$bottom-113);

    $subtotal=(float)($d['subtotal']??0);
    $iva=(float)($d['iva']??0);
    $retIsr=(float)($d['retIsr']??0);
    $retIva=(float)($d['retIva']??0);
    $total=(float)($d['total']??($subtotal+$iva-$retIsr-$retIva));
    $totals=[
        ['SUBTOTAL:',$subtotal],
        ['I.V.A.',$iva],
        ['retencion ISR',-$retIsr],
        ['Retencion IVA',-$retIva],
        ['TOTAL',$total],
    ];
    $tx=350.0; $ty=$bottom; $tr=20.0;
    foreach($totals as $idx=>$row){
        $y=$ty-$tr*($idx+1);
        if($idx===4) ordenes_pdf_rect($s,$tx,$y,220,$tr,true);
        else ordenes_pdf_line($s,$tx,$y,$right,$y);
        ordenes_pdf_text($s,$tx+70,$y+6,$row[0],7,$idx===4);
        ordenes_pdf_text($s,$tx+155,$y+6,ordenes_pdf_money((float)$row[1],$currency),7,true);
    }

    $bankY=$bottom-122;
    ordenes_pdf_text($s,$tx,$bankY,'No. Cuenta: '.trim((string)($d['cuenta']??'')),7,false);
    ordenes_pdf_text($s,$tx,$bankY-15,'No. Clabe: '.trim((string)($d['clabe']??'')),7,false);
    ordenes_pdf_text($s,$tx,$bankY-30,'Banco: '.trim((string)($d['banco']??'')),7,false);

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
    if($logoBytes!==''){
        $objects[7]="<< /Type /XObject /Subtype /Image /Width {$logoW} /Height {$logoH} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($logoBytes)." >>\nstream\n".$logoBytes."\nendstream";
    }

    ksort($objects);
    $pdf="%PDF-1.4\n"; $offsets=[0];
    foreach($objects as $n=>$obj){
        $offsets[$n]=strlen($pdf);
        $pdf.="{$n} 0 obj\n{$obj}\nendobj\n";
    }
    $max=max(array_keys($objects));
    $xref=strlen($pdf);
    $pdf.="xref\n0 ".($max+1)."\n0000000000 65535 f \n";
    for($i=1;$i<=$max;$i++) $pdf.=sprintf("%010d 00000 n \n",$offsets[$i]??0);
    $pdf.="trailer\n<< /Size ".($max+1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    return $pdf;
}

function ordenes_send_test_email(array $input,array $user): array
{
    $folio=trim((string)($input['folio']??''));
    $itemId=(int)($input['itemId']??0);

    if($itemId<=0 || !preg_match('/^ODC-PREVIEW-\d{6,}$/',$folio)) {
        throw new RuntimeException('Guarda primero la ODC como borrador antes de enviar el correo de prueba.');
    }

    if(!ordenes_user_has_preview_access($user)) {
        throw new RuntimeException('Tu cuenta no puede enviar correos de prueba.');
    }

    $recipient='gabriel.guerra@juanpablo.com.mx';
    $sender='sistemas@juanpablo.com.mx';
    $proveedor=trim((string)($input['proveedor']??''));
    $observaciones=trim((string)($input['observaciones']??''));
    $moneda=strtoupper(trim((string)($input['moneda']??'MXN')));
    $total=(float)($input['total']??0);

    $h=static fn(string $value):string=>htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $totalText=($moneda==='USD'?'US$':'$').number_format($total,2,'.',',');
    $obsHtml=$observaciones!==''?nl2br($h($observaciones)):'<em>Sin observaciones.</em>';

    $html='<!doctype html><html><body style="margin:0;background:#f5f1ec;font-family:Arial,sans-serif;color:#2b1b15">'
      .'<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:24px 10px;background:#f5f1ec"><tr><td align="center">'
      .'<table role="presentation" width="640" cellspacing="0" cellpadding="0" style="max-width:640px;background:#fff;border:1px solid #e4d9cf;border-radius:12px;overflow:hidden">'
      .'<tr><td style="padding:22px 26px;border-top:5px solid #e28a16">'
      .'<div style="font-size:11px;letter-spacing:1.4px;font-weight:700;color:#225b8a">JARDINES DE JUAN PABLO · PRUEBA</div>'
      .'<h1 style="font-size:22px;margin:8px 0 4px">Orden de Compra '.$h($folio).'</h1>'
      .'<p style="margin:0;color:#6b625d">Correo de prueba del nuevo flujo de Órdenes de Compra.</p>'
      .'</td></tr><tr><td style="padding:0 26px 24px">'
      .'<table width="100%" cellspacing="0" cellpadding="8" style="border-collapse:collapse;font-size:14px">'
      .'<tr><td style="font-weight:700;border-bottom:1px solid #eee7e1">Proveedor</td><td style="border-bottom:1px solid #eee7e1">'.$h($proveedor).'</td></tr>'
      .'<tr><td style="font-weight:700;border-bottom:1px solid #eee7e1">Total</td><td style="border-bottom:1px solid #eee7e1">'.$h($totalText).'</td></tr>'
      .'<tr><td style="font-weight:700;border-bottom:1px solid #eee7e1">Solicitante</td><td style="border-bottom:1px solid #eee7e1">'.$h((string)($user['name']??'')).'</td></tr>'
      .'</table>'
      .'<div style="margin-top:20px;padding:14px 16px;background:#fff8e6;border:1px solid #efd48a;border-radius:8px">'
      .'<strong>Observaciones</strong><div style="margin-top:8px;line-height:1.5">'.$obsHtml.'</div></div>'
      .'<p style="margin:20px 0 0;color:#756a64;font-size:12px">Durante esta etapa de pruebas, el único destinatario es '.$h($recipient).'.</p>'
      .'</td></tr></table></td></tr></table></body></html>';

    $request=[
        'message'=>[
            'subject'=>'[PRUEBA] Orden de Compra '.$folio.' | '.$proveedor,
            'body'=>['contentType'=>'HTML','content'=>$html],
            'toRecipients'=>[['emailAddress'=>['address'=>$recipient]]],
        ],
        'saveToSentItems'=>true,
    ];

    $json=json_encode($request,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if(!is_string($json)) throw new RuntimeException('No fue posible preparar el correo.');

    $token=ordenes_mail_graph_token();
    $curl=curl_init('https://graph.microsoft.com/v1.0/users/'.rawurlencode($sender).'/sendMail');
    if($curl===false) throw new RuntimeException('No fue posible iniciar el envio del correo.');

    curl_setopt_array($curl,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>10,
        CURLOPT_TIMEOUT=>40,
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>$json,
        CURLOPT_HTTPHEADER=>[
            'Authorization: Bearer '.$token,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
    ]);

    $response=curl_exec($curl);
    $status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
    $error=curl_error($curl);
    curl_close($curl);

    if($response===false) throw new RuntimeException('El envio de correo fallo: '.$error);
    if(!in_array($status,[200,202,204],true)) {
        $decoded=json_decode((string)$response,true);
        $detail=is_array($decoded)?trim((string)($decoded['error']['message']??'')):'';
        throw new RuntimeException('Microsoft Graph respondio HTTP '.$status.($detail!==''?': '.$detail:'.'));
    }

    return [
        'ok'=>true,
        'recipient'=>$recipient,
        'sender'=>$sender,
        'folio'=>$folio,
    ];
}
