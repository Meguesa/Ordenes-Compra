<?php

declare(strict_types=1);

require_once __DIR__ . '/ordenes-pdf.php';

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
    return $email !== '';
}

function ordenes_require_preview_access(): void
{
    if (ordenes_user_has_preview_access()) return;

    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="es-MX"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Acceso no disponible</title></head><body style="font-family:Segoe UI,Arial,sans-serif;background:#f7f5f0;color:#241d19;margin:0;padding:40px"><main style="max-width:720px;margin:60px auto;background:#fff;border:1px solid #e8e1d8;border-top:5px solid #fdbb2d;border-radius:14px;padding:28px"><h1 style="color:#3a1109">Órdenes de Compra</h1><p>No fue posible validar tu correo de sesión.</p><p><a href="/" style="color:#225b8a;font-weight:700">Regresar al Portal Interno</a></p></main></body></html>';
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

function ordenes_http_raw(string $url,string $method,array $headers,string $body=''): string
{
    $ch=curl_init($url);
    if($ch===false) throw new RuntimeException('No fue posible iniciar cURL.');
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_CONNECTTIMEOUT=>8,
        CURLOPT_TIMEOUT=>40,
        CURLOPT_CUSTOMREQUEST=>$method,
        CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_POSTFIELDS=>$body,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
    ]);
    $raw=curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $error=curl_error($ch);
    curl_close($ch);
    if($raw===false) throw new RuntimeException('Solicitud remota fallida: '.$error);
    if($status<200 || $status>=300) throw new RuntimeException('SharePoint respondio HTTP '.$status.'.');
    return (string)$raw;
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
      'Estado'=>['Estado','Estatus'],'Ambiente'=>['Ambiente'],
      'Revision'=>['Revision'],
      'HistorialAutorizacion'=>['HistorialAutorizacion','Historial Autorizacion'],
      'UltimoComentario'=>['UltimoComentario','Ultimo Comentario'],
      'UltimaRevisionPor'=>['UltimaRevisionPor','Ultima Revision Por'],
      'UltimaRevisionCorreo'=>['UltimaRevisionCorreo','Ultima Revision Correo'],
      'FechaEnvioAutorizacion'=>['FechaEnvioAutorizacion','Fecha Envio Autorizacion'],
      'FechaResolucion'=>['FechaResolucion','Fecha Resolucion']
    ];
}

function ordenes_schema(): array {
    $text=['Folio','EmpresaCompradora','Proveedor','Domicilio','RFC','Telefono','CiudadEstado','CondicionPago','TiempoEntrega','Moneda','Banco','Cuenta','CLABE','SolicitanteNombre','SolicitanteCorreo','Estado','Ambiente','UltimaRevisionPor','UltimaRevisionCorreo'];
    $num=['TipoCambio','Subtotal','IvaPct','IVA','RetIsrPct','RetencionISR','RetIvaPct','RetencionIVA','Total','Revision'];
    $out=['Fecha'=>'DateTime','FechaEnvioAutorizacion'=>'DateTime','FechaResolucion'=>'DateTime','PartidasJson'=>'Note','HistorialAutorizacion'=>'Note','UltimoComentario'=>'Note']; foreach($text as $x)$out[$x]='Text'; foreach($num as $x)$out[$x]='Number'; return $out;
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
        if($type==='DateTime')$xml=$name==='Fecha'
            ? '<Field Type="DateTime" Name="'.$name.'" DisplayName="'.$name.'" Format="DateOnly" />'
            : '<Field Type="DateTime" Name="'.$name.'" DisplayName="'.$name.'" Format="DateTime" />';
        elseif($type==='Number')$xml='<Field Type="Number" Name="'.$name.'" DisplayName="'.$name.'" Decimals="Automatic" />';
        elseif($type==='Note')$xml='<Field Type="Note" Name="'.$name.'" DisplayName="'.$name.'" NumLines="20" RichText="FALSE" />';
        else $xml='<Field Type="Text" Name="'.$name.'" DisplayName="'.$name.'" MaxLength="255" />';
        $json=json_encode(['parameters'=>['__metadata'=>['type'=>'SP.XmlSchemaFieldCreationInformation'],'SchemaXml'=>$xml,'Options'=>0]],JSON_UNESCAPED_SLASHES);
        try{ordenes_http_json(ordenes_list_base().'/fields/CreateFieldAsXml','POST',['Authorization: Bearer '.$s['token'],'Accept: application/json;odata=verbose','Content-Type: application/json;odata=verbose'],(string)$json);$created[]=$name;ordenes_fields(true);}
        catch(Throwable $e){$errors[$name]=$e->getMessage();}
    }
    ordenes_fields(true); return ['created'=>$created,'errors'=>$errors,'missing'=>ordenes_missing_schema_fields()];
}

function ordenes_approver_emails(): array
{
    return ['finanzas@juanpablo.com.mx','admin.gerencia@juanpablo.com.mx'];
}

function ordenes_user_is_approver(?array $user=null): bool
{
    $user=$user??portal_user();
    $email=strtolower(trim((string)($user['email']??'')));
    return $email!=='' && in_array($email,ordenes_approver_emails(),true);
}

function ordenes_ensure_approval_schema(): void
{
    $needed=['Revision','HistorialAutorizacion','UltimoComentario','UltimaRevisionPor','UltimaRevisionCorreo','FechaEnvioAutorizacion','FechaResolucion'];
    $missing=array_values(array_intersect(ordenes_missing_schema_fields(),$needed));
    if(!$missing) return;
    $result=ordenes_try_prepare_schema();
    $still=array_values(array_intersect($result['missing']??[],$needed));
    if($still) throw new RuntimeException('Faltan columnas del flujo de autorizacion: '.implode(', ',$still).'.');
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


function ordenes_sharepoint_delete_attachment(int $itemId,string $name): void
{
    if($itemId<=0 || trim($name)==='') return;
    $s=ordenes_session();
    $safe=str_replace("'","''",basename($name));
    $url=ordenes_list_base()."/items(".$itemId.")/AttachmentFiles/getByFileName('".$safe."')";
    try{
        ordenes_http_raw($url,'POST',[
            'Authorization: Bearer '.$s['token'],
            'Accept: application/json;odata=nometadata',
            'IF-MATCH: *',
            'X-HTTP-Method: DELETE',
        ],'');
    }catch(Throwable $e){}
}

function ordenes_sharepoint_add_attachment(int $itemId,string $name,string $bytes,string $mime='application/octet-stream'): void
{
    if($itemId<=0) throw new InvalidArgumentException('ID invalido.');
    $safeName=basename(trim($name));
    if($safeName==='') throw new InvalidArgumentException('Nombre de archivo invalido.');
    $s=ordenes_session();
    $url=ordenes_list_base()."/items(".$itemId.")/AttachmentFiles/add(FileName='".str_replace("'","''",$safeName)."')";
    ordenes_http_raw($url,'POST',[
        'Authorization: Bearer '.$s['token'],
        'Accept: application/json;odata=nometadata',
        'Content-Type: '.$mime,
    ],$bytes);
}

function ordenes_sharepoint_attachments(int $itemId): array
{
    if($itemId<=0) return [];
    $s=ordenes_session();
    $data=ordenes_http_json(
        ordenes_list_base()."/items(".$itemId.")/AttachmentFiles?\$select=FileName,ServerRelativeUrl",
        'GET',
        ['Authorization: Bearer '.$s['token'],'Accept: application/json;odata=nometadata']
    );
    return array_values(array_filter($data['value']??[],static fn($x)=>is_array($x)));
}

function ordenes_sharepoint_attachment_bytes(string $serverRelativeUrl): string
{
    $s=ordenes_session();
    $url=rtrim($s['siteUrl'],'/')."/_api/web/GetFileByServerRelativeUrl('".str_replace("'","''",$serverRelativeUrl)."')/\$value";
    return ordenes_http_raw($url,'GET',[
        'Authorization: Bearer '.$s['token'],
        'Accept: application/octet-stream',
    ]);
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
        'Title' => $folio !== '' ? $folio : 'PENDIENTE',
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
        'Ambiente' => 'PRODUCCION',
    ];

    if ($itemId > 0) {
        $existing = ordenes_get_item($itemId);
        $emailField = ordenes_field('SolicitanteCorreo');
        $existingOwner = $emailField !== null ? strtolower(trim((string)($existing[$emailField] ?? ''))) : '';
        if ($existingOwner !== '' && $existingOwner !== $userEmail) throw new RuntimeException('No tienes permiso para modificar este borrador.');
        if ($folio === '' || preg_match('/^ODC-PREVIEW-\\d+$/', $folio) || preg_match('/^0{0,3}[12]$/', $folio)) $folio = str_pad((string)(38 + $itemId), 4, '0', STR_PAD_LEFT);
        $values['Title'] = $folio;
        $values['Folio'] = $folio;
        ordenes_update_item($itemId, $values);
    } else {
        $created = ordenes_create_item($values);
        $itemId = (int)($created['Id'] ?? $created['ID'] ?? 0);
        if ($itemId <= 0) throw new RuntimeException('SharePoint creo el registro, pero no devolvio su identificador.');
        $folio = str_pad((string)(38 + $itemId), 4, '0', STR_PAD_LEFT);
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

function ordenes_prepare_uploaded_attachments(array $files): array
{
    if (!$files || !isset($files['name'])) return [];

    $names = is_array($files['name']) ? $files['name'] : [$files['name']];
    $types = is_array($files['type'] ?? null) ? $files['type'] : [($files['type'] ?? '')];
    $tmpNames = is_array($files['tmp_name'] ?? null) ? $files['tmp_name'] : [($files['tmp_name'] ?? '')];
    $errors = is_array($files['error'] ?? null) ? $files['error'] : [($files['error'] ?? UPLOAD_ERR_NO_FILE)];
    $sizes = is_array($files['size'] ?? null) ? $files['size'] : [($files['size'] ?? 0)];

    $allowedExt = ['pdf','jpg','jpeg','png','webp','doc','docx','xls','xlsx','ppt','pptx','txt','csv','zip'];
    $result = [];
    $total = 0;

    foreach ($names as $i => $rawName) {
        $error = (int)($errors[$i] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        if ($error !== UPLOAD_ERR_OK) throw new RuntimeException('No fue posible cargar uno de los archivos adjuntos.');

        if (count($result) >= 5) throw new RuntimeException('Solo se permiten hasta 5 archivos adjuntos.');

        $name = basename(trim((string)$rawName));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($name === '' || !in_array($ext, $allowedExt, true)) {
            throw new RuntimeException('Tipo de archivo no permitido: '.($name !== '' ? $name : 'archivo sin nombre').'.');
        }

        $size = (int)($sizes[$i] ?? 0);
        if ($size <= 0) throw new RuntimeException('El archivo '.$name.' esta vacio.');
        if ($size > 2097152) throw new RuntimeException('El archivo '.$name.' supera el limite individual de 2 MB.');
        $total += $size;
        if ($total > 2621440) throw new RuntimeException('Los adjuntos superan el limite combinado de 2.5 MB.');

        $tmp = (string)($tmpNames[$i] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) throw new RuntimeException('No fue posible validar el archivo '.$name.'.');

        $bytes = file_get_contents($tmp);
        if ($bytes === false) throw new RuntimeException('No fue posible leer el archivo '.$name.'.');

        $mime = trim((string)($types[$i] ?? ''));
        if ($mime === '' || $mime === 'application/octet-stream') {
            $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
            if ($finfo) {
                $detected = finfo_file($finfo, $tmp);
                finfo_close($finfo);
                if (is_string($detected) && $detected !== '') $mime = $detected;
            }
        }
        if ($mime === '') $mime = 'application/octet-stream';

        $result[] = [
            '@odata.type' => '#microsoft.graph.fileAttachment',
            'name' => $name,
            'contentType' => $mime,
            'contentBytes' => base64_encode($bytes),
        ];
    }

    return $result;
}

function ordenes_graph_send_mail_with_retry(string $sender,string $token,string $json): void
{
    $url='https://graph.microsoft.com/v1.0/users/'.rawurlencode($sender).'/sendMail';
    $lastStatus=0;
    $lastDetail='';

    for ($attempt=1; $attempt<=3; $attempt++) {
        $curl=curl_init($url);
        if($curl===false) throw new RuntimeException('No fue posible iniciar el envio del correo.');

        curl_setopt_array($curl,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>10,
            CURLOPT_TIMEOUT=>60,
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

        if($response!==false && in_array($status,[200,202,204],true)) return;

        $lastStatus=$status;
        $decoded=is_string($response)?json_decode($response,true):null;
        $lastDetail=is_array($decoded)?trim((string)($decoded['error']['message']??'')):'';
        if($response===false && $error!=='') $lastDetail=$error;

        if(!in_array($status,[429,502,503,504],true) || $attempt===3) break;
        usleep($attempt * 700000);
    }

    throw new RuntimeException('Microsoft Graph respondio HTTP '.$lastStatus.($lastDetail!==''?': '.$lastDetail:'.'));
}

function ordenes_send_message(string $subject,string $html,array $to,array $cc,array $attachments=[]): void
{
    $request=[
        'message'=>[
            'subject'=>$subject,
            'body'=>['contentType'=>'HTML','content'=>$html],
            'toRecipients'=>array_map(static fn(string $address):array=>['emailAddress'=>['address'=>$address]],array_values(array_unique($to))),
            'ccRecipients'=>array_map(static fn(string $address):array=>['emailAddress'=>['address'=>$address]],array_values(array_unique($cc))),
            'attachments'=>$attachments,
        ],
        'saveToSentItems'=>true,
    ];
    $json=json_encode($request,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if(!is_string($json)) throw new RuntimeException('No fue posible preparar el correo.');
    $token=ordenes_mail_graph_token();
    ordenes_graph_send_mail_with_retry('sistemas@juanpablo.com.mx',$token,$json);
}

function ordenes_history_array(array $item): array
{
    $field=ordenes_field('HistorialAutorizacion');
    if($field===null) return [];
    $raw=trim((string)($item[$field]??''));
    if($raw==='') return [];
    $decoded=json_decode($raw,true);
    return is_array($decoded)?$decoded:[];
}

function ordenes_send_email(array $input,array $user,array $files=[]): array
{
    ordenes_ensure_approval_schema();

    $folio=trim((string)($input['folio']??''));
    $itemId=(int)($input['itemId']??0);
    if($itemId<=0 || !preg_match('/^\d{4,}$/',$folio)) {
        throw new RuntimeException('Guarda primero la ODC como borrador antes de enviarla a autorizacion.');
    }

    $requesterEmail=strtolower(trim((string)($user['email']??'')));
    $requesterName=trim((string)($user['name']??'Usuario'));
    $item=ordenes_get_item($itemId);
    $ownerField=ordenes_field('SolicitanteCorreo');
    $owner=$ownerField!==null?strtolower(trim((string)($item[$ownerField]??''))):'';
    if($owner!=='' && $owner!==$requesterEmail) throw new RuntimeException('No tienes permiso para enviar esta ODC.');

    $stateField=ordenes_field('Estado');
    $state=$stateField!==null?strtoupper(trim((string)($item[$stateField]??''))):'';
    if(!in_array($state,['BORRADOR','RECHAZADA'],true)) {
        throw new RuntimeException('Esta ODC no esta disponible para envio a autorizacion.');
    }

    $revField=ordenes_field('Revision');
    $currentRevision=$revField!==null?(int)($item[$revField]??0):0;
    $revision=max(1,$currentRevision+1);

    $uploaded=ordenes_prepare_uploaded_attachments($files);
    foreach($uploaded as $attachment){
        $original=basename((string)($attachment['name']??'archivo'));
        $stored='R'.$revision.'_'.$original;
        ordenes_sharepoint_delete_attachment($itemId,$stored);
        ordenes_sharepoint_add_attachment(
            $itemId,
            $stored,
            base64_decode((string)($attachment['contentBytes']??''),true)?:'',
            (string)($attachment['contentType']??'application/octet-stream')
        );
    }

    $history=ordenes_history_array($item);
    $history[]=[
        'fecha'=>(new DateTimeImmutable('now',new DateTimeZone('America/Monterrey')))->format(DATE_ATOM),
        'revision'=>$revision,
        'accion'=>'ENVIADA_AUTORIZACION',
        'usuario'=>$requesterName,
        'correo'=>$requesterEmail,
        'comentario'=>trim((string)($input['observaciones']??'')),
    ];

    $proveedor=trim((string)($input['proveedor']??''));
    $moneda=strtoupper(trim((string)($input['moneda']??'MXN')));
    $total=(float)($input['total']??0);
    $totalText=($moneda==='USD'?'US$':'$').number_format($total,2,'.',',');
    $reviewUrl='https://portal.juanpablo.com.mx/ordenes-compra/revisar.php?id='.$itemId;
    $h=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');

    $html='<h2>Orden de Compra '.$h($folio).' pendiente de autorizacion</h2>'
        .'<p><strong>Solicitante:</strong> '.$h($requesterName).'</p>'
        .'<p><strong>Proveedor:</strong> '.$h($proveedor).'</p>'
        .'<p><strong>Total:</strong> '.$h($totalText).'</p>'
        .'<p><strong>Revision:</strong> R'.$revision.'</p>'
        .'<p><a href="'.$h($reviewUrl).'">Abrir en el Buzon de Ordenes</a></p>';

    $pdf=odc_pdf_generate($input,$user,(string)($_SERVER['DOCUMENT_ROOT']??''));
    $emailAttachments=[[
        '@odata.type'=>'#microsoft.graph.fileAttachment',
        'name'=>'ODC_'.$folio.'.pdf',
        'contentType'=>'application/pdf',
        'contentBytes'=>base64_encode($pdf),
    ]];
    $emailAttachments=array_merge($emailAttachments,$uploaded);

    $to=ordenes_approver_emails();
    $cc=array_values(array_filter([$requesterEmail,'jose.santana@juanpablo.com.mx','gabriel.guerra@juanpablo.com.mx']));
    ordenes_send_message('AUTORIZACION ODC '.$folio.' | '.$proveedor,$html,$to,$cc,$emailAttachments);

    ordenes_update_item($itemId,[
        'Estado'=>'PENDIENTE_AUTORIZACION',
        'Revision'=>$revision,
        'HistorialAutorizacion'=>json_encode($history,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        'UltimoComentario'=>trim((string)($input['observaciones']??'')),
        'UltimaRevisionPor'=>'',
        'UltimaRevisionCorreo'=>'',
        'FechaEnvioAutorizacion'=>(new DateTimeImmutable('now',new DateTimeZone('America/Monterrey')))->format('Y-m-d\TH:i:s'),
        'FechaResolucion'=>null,
        'Ambiente'=>'PRODUCCION',
    ]);

    return [
        'ok'=>true,
        'recipient'=>implode(', ',$to),
        'sender'=>'sistemas@juanpablo.com.mx',
        'folio'=>$folio,
        'revision'=>$revision,
    ];
}

function ordenes_list_user_records(array $user,string $estado): array
{
    $email=strtolower(trim((string)($user['email']??'')));
    if($email==='') throw new RuntimeException('La sesion no contiene correo electronico.');

    $estado=strtoupper(trim($estado));
    if(!in_array($estado,['BORRADOR','RECHAZADA','PENDIENTE_AUTORIZACION','APROBADA'],true)) throw new InvalidArgumentException('Estado de ODC no valido.');

    $emailField=ordenes_field('SolicitanteCorreo');
    $estadoField=ordenes_field('Estado');
    if($emailField===null || $estadoField===null) throw new RuntimeException('No fue posible resolver los campos de consulta de Ordenes de Compra.');

    $canonical=['Folio','Fecha','Proveedor','Total','Estado','SolicitanteCorreo'];
    $resolved=['Id'];
    $fieldMap=[];
    foreach($canonical as $name){
        $field=ordenes_field($name);
        if($field!==null){
            $resolved[]=$field;
            $fieldMap[$name]=$field;
        }
    }

    $filter=$emailField." eq '".str_replace("'","''",$email)."' and ".$estadoField." eq '".$estado."'";
    $query=http_build_query([
        '$select'=>implode(',',array_values(array_unique($resolved))),
        '$filter'=>$filter,
        '$orderby'=>'Id desc',
        '$top'=>'100',
    ],'','&',PHP_QUERY_RFC3986);

    $s=ordenes_session();
    $data=ordenes_http_json(ordenes_list_base().'/items?'.$query,'GET',[
        'Authorization: Bearer '.$s['token'],
        'Accept: application/json;odata=nometadata',
    ]);

    $out=[];
    foreach(($data['value']??[]) as $row){
        if(!is_array($row)) continue;
        $record=['id'=>(int)($row['Id']??0)];
        foreach($fieldMap as $canonicalName=>$internalName){
            $record[$canonicalName]=$row[$internalName]??null;
        }
        $out[]=$record;
    }
    return $out;
}

function ordenes_sp_graph_attachments(int $itemId): array
{
    $out=[];
    foreach(ordenes_sharepoint_attachments($itemId) as $meta){
        $name=(string)($meta['FileName']??'archivo');
        $url=(string)($meta['ServerRelativeUrl']??'');
        if($url==='') continue;
        $out[]=[
            '@odata.type'=>'#microsoft.graph.fileAttachment',
            'name'=>$name,
            'contentType'=>'application/octet-stream',
            'contentBytes'=>base64_encode(ordenes_sharepoint_attachment_bytes($url)),
        ];
    }
    return $out;
}

function ordenes_list_inbox_records(): array
{
    $stateField=ordenes_field('Estado');
    if($stateField===null) throw new RuntimeException('No fue posible resolver el estado de las ODC.');
    $canonical=['Folio','Fecha','Proveedor','Total','Estado','SolicitanteNombre','SolicitanteCorreo','Revision'];
    $resolved=['Id']; $fieldMap=[];
    foreach($canonical as $name){
        $field=ordenes_field($name);
        if($field!==null){ $resolved[]=$field; $fieldMap[$name]=$field; }
    }
    $query=http_build_query([
        '$select'=>implode(',',array_values(array_unique($resolved))),
        '$filter'=>$stateField." eq 'PENDIENTE_AUTORIZACION'",
        '$orderby'=>'Id desc',
        '$top'=>'100',
    ],'','&',PHP_QUERY_RFC3986);
    $s=ordenes_session();
    $data=ordenes_http_json(ordenes_list_base().'/items?'.$query,'GET',[
        'Authorization: Bearer '.$s['token'],
        'Accept: application/json;odata=nometadata',
    ]);
    $out=[];
    foreach(($data['value']??[]) as $row){
        if(!is_array($row)) continue;
        $record=['id'=>(int)($row['Id']??0)];
        foreach($fieldMap as $canonicalName=>$internalName) $record[$canonicalName]=$row[$internalName]??null;
        $out[]=$record;
    }
    return $out;
}

function ordenes_item_payload(int $itemId): array
{
    $item=ordenes_get_item($itemId);
    $get=static function(string $name) use ($item): mixed {
        $field=ordenes_field($name);
        return $field!==null?($item[$field]??null):null;
    };
    $items=[];
    $raw=(string)($get('PartidasJson')??'');
    if($raw!==''){
        $decoded=json_decode($raw,true);
        if(is_array($decoded)) $items=$decoded;
    }
    return [
        'itemId'=>$itemId,
        'folio'=>(string)($get('Folio')??''),
        'fecha'=>substr((string)($get('Fecha')??''),0,10),
        'empresaCompradora'=>(string)($get('EmpresaCompradora')??'MEGUESA'),
        'proveedor'=>(string)($get('Proveedor')??''),
        'domicilio'=>(string)($get('Domicilio')??''),
        'rfc'=>(string)($get('RFC')??''),
        'telefono'=>(string)($get('Telefono')??''),
        'ciudadEstado'=>(string)($get('CiudadEstado')??''),
        'condicionPago'=>(string)($get('CondicionPago')??''),
        'tiempoEntrega'=>(string)($get('TiempoEntrega')??''),
        'moneda'=>(string)($get('Moneda')??'MXN'),
        'tipoCambio'=>(float)($get('TipoCambio')??1),
        'items'=>$items,
        'subtotal'=>(float)($get('Subtotal')??0),
        'ivaPct'=>(float)($get('IvaPct')??0),
        'iva'=>(float)($get('IVA')??0),
        'retIsrPct'=>(float)($get('RetIsrPct')??0),
        'retIsr'=>(float)($get('RetencionISR')??0),
        'retIvaPct'=>(float)($get('RetIvaPct')??0),
        'retIva'=>(float)($get('RetencionIVA')??0),
        'total'=>(float)($get('Total')??0),
        'banco'=>(string)($get('Banco')??''),
        'cuenta'=>(string)($get('Cuenta')??''),
        'clabe'=>(string)($get('CLABE')??''),
        'solicitanteNombre'=>(string)($get('SolicitanteNombre')??''),
        'solicitanteCorreo'=>(string)($get('SolicitanteCorreo')??''),
        'estado'=>(string)($get('Estado')??''),
        'revision'=>(int)($get('Revision')??0),
        'ultimoComentario'=>(string)($get('UltimoComentario')??''),
        'historial'=>ordenes_history_array($item),
    ];
}

function ordenes_process_authorization(int $itemId,array $user,string $action,string $comment=''): array
{
    ordenes_ensure_approval_schema();
    if(!ordenes_user_is_approver($user)) throw new RuntimeException('Tu cuenta no puede autorizar ordenes.');

    $action=strtoupper(trim($action));
    if(!in_array($action,['COMENTARIO','APROBAR','RECHAZAR'],true)) throw new InvalidArgumentException('Accion no valida.');
    $comment=trim($comment);
    if($action==='RECHAZAR' && $comment==='') throw new RuntimeException('El comentario es obligatorio para rechazar una ODC.');

    $payload=ordenes_item_payload($itemId);
    if(strtoupper($payload['estado'])!=='PENDIENTE_AUTORIZACION') throw new RuntimeException('Esta ODC ya no esta pendiente de autorizacion.');

    $item=ordenes_get_item($itemId);
    $history=ordenes_history_array($item);
    $reviewerName=trim((string)($user['name']??'Autorizador'));
    $reviewerEmail=strtolower(trim((string)($user['email']??'')));
    $eventAction=$action==='COMENTARIO'?'COMENTARIO':($action==='APROBAR'?'APROBADA':'RECHAZADA');
    $history[]=[
        'fecha'=>(new DateTimeImmutable('now',new DateTimeZone('America/Monterrey')))->format(DATE_ATOM),
        'revision'=>(int)$payload['revision'],
        'accion'=>$eventAction,
        'usuario'=>$reviewerName,
        'correo'=>$reviewerEmail,
        'comentario'=>$comment,
    ];

    $requester=(string)$payload['solicitanteCorreo'];
    $folio=(string)$payload['folio'];
    $provider=(string)$payload['proveedor'];
    $reviewUrl='https://portal.juanpablo.com.mx/ordenes-compra/revisar.php?id='.$itemId;
    $body='<h2>ODC '.$folio.' - '.$eventAction.'</h2>'
        .'<p>Proveedor: '.htmlspecialchars($provider,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</p>'
        .'<p>Revision: R'.(int)$payload['revision'].'</p>'
        .'<p>Revisado por: '.htmlspecialchars($reviewerName,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</p>'
        .'<p>Comentario: '.nl2br(htmlspecialchars($comment!==''?$comment:'Sin comentarios.',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')).'</p>'
        .'<p><a href="'.htmlspecialchars($reviewUrl,ENT_QUOTES,'UTF-8').'">Abrir ODC</a></p>';

    $to=$action==='RECHAZAR'?[$requester]:array_values(array_unique(array_merge([$requester],ordenes_approver_emails())));
    $cc=['jose.santana@juanpablo.com.mx','gabriel.guerra@juanpablo.com.mx'];
    if($action==='RECHAZAR') $cc=array_merge(ordenes_approver_emails(),$cc);

    $attachments=[];
    if($action!=='COMENTARIO'){
        $pdf=odc_pdf_generate($payload,['name'=>$payload['solicitanteNombre'],'email'=>$requester],(string)($_SERVER['DOCUMENT_ROOT']??''));
        $attachments[]=[
            '@odata.type'=>'#microsoft.graph.fileAttachment',
            'name'=>'ODC_'.$folio.'.pdf',
            'contentType'=>'application/pdf',
            'contentBytes'=>base64_encode($pdf),
        ];
        $attachments=array_merge($attachments,ordenes_sp_graph_attachments($itemId));
    }

    ordenes_send_message($eventAction.' ODC '.$folio.' | '.$provider,$body,$to,$cc,$attachments);

    $values=[
        'HistorialAutorizacion'=>json_encode($history,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        'UltimoComentario'=>$comment,
        'UltimaRevisionPor'=>$reviewerName,
        'UltimaRevisionCorreo'=>$reviewerEmail,
    ];
    if($action==='APROBAR'){
        $values['Estado']='APROBADA';
        $values['FechaResolucion']=(new DateTimeImmutable('now',new DateTimeZone('America/Monterrey')))->format('Y-m-d\TH:i:s');
    }elseif($action==='RECHAZAR'){
        $values['Estado']='RECHAZADA';
        $values['FechaResolucion']=(new DateTimeImmutable('now',new DateTimeZone('America/Monterrey')))->format('Y-m-d\TH:i:s');
    }
    ordenes_update_item($itemId,$values);

    return ['ok'=>true,'action'=>$eventAction,'folio'=>$folio];
}

function ordenes_load_user_draft(int $itemId,array $user): array
{
    if($itemId<=0) throw new InvalidArgumentException('Borrador invalido.');
    $email=strtolower(trim((string)($user['email']??'')));
    if($email==='') throw new RuntimeException('La sesion no contiene correo electronico.');

    $item=ordenes_get_item($itemId);
    $ownerField=ordenes_field('SolicitanteCorreo');
    $stateField=ordenes_field('Estado');
    $owner=$ownerField!==null?strtolower(trim((string)($item[$ownerField]??''))):'';
    $state=$stateField!==null?strtoupper(trim((string)($item[$stateField]??''))):'';

    if($owner!==$email) throw new RuntimeException('No tienes permiso para abrir este borrador.');
    if(!in_array($state,['BORRADOR','RECHAZADA'],true)) throw new RuntimeException('La orden seleccionada no esta disponible para correccion.');

    $get=static function(string $name) use ($item): mixed {
        $field=ordenes_field($name);
        return $field!==null?($item[$field]??null):null;
    };

    $items=[];
    $raw=(string)($get('PartidasJson')??'');
    if($raw!==''){
        $decoded=json_decode($raw,true);
        if(is_array($decoded)) $items=$decoded;
    }

    return [
        'itemId'=>$itemId,
        'folio'=>(string)($get('Folio')??''),
        'fecha'=>substr((string)($get('Fecha')??''),0,10),
        'empresaCompradora'=>(string)($get('EmpresaCompradora')??'MEGUESA'),
        'proveedor'=>(string)($get('Proveedor')??''),
        'domicilio'=>(string)($get('Domicilio')??''),
        'rfc'=>(string)($get('RFC')??''),
        'telefono'=>(string)($get('Telefono')??''),
        'ciudadEstado'=>(string)($get('CiudadEstado')??''),
        'condicionPago'=>(string)($get('CondicionPago')??''),
        'tiempoEntrega'=>(string)($get('TiempoEntrega')??''),
        'moneda'=>(string)($get('Moneda')??'MXN'),
        'tipoCambio'=>(float)($get('TipoCambio')??1),
        'items'=>$items,
        'subtotal'=>(float)($get('Subtotal')??0),
        'ivaPct'=>(float)($get('IvaPct')??0),
        'iva'=>(float)($get('IVA')??0),
        'retIsrPct'=>(float)($get('RetIsrPct')??0),
        'retIsr'=>(float)($get('RetencionISR')??0),
        'retIvaPct'=>(float)($get('RetIvaPct')??0),
        'retIva'=>(float)($get('RetencionIVA')??0),
        'total'=>(float)($get('Total')??0),
        'banco'=>(string)($get('Banco')??''),
        'cuenta'=>(string)($get('Cuenta')??''),
        'clabe'=>(string)($get('CLABE')??''),
        'observaciones'=>'',
    ];
}
