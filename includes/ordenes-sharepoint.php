<?php
declare(strict_types=1);

const ORDENES_LIST_TITLE = 'BI_Ordenes_Compra';

function ordenes_config(): array {
    $raw = require '/home/juanpab1/portal-config/config.php';
    if (!is_array($raw)) throw new RuntimeException('Configuracion privada no disponible.');
    $cfg = [
        'tenantId'=>trim((string)($raw['portal_access_tenant_id'] ?? $raw['solicitud_backend_tenant_id'] ?? '')),
        'clientId'=>trim((string)($raw['portal_access_client_id'] ?? $raw['solicitud_backend_client_id'] ?? '')),
        'clientSecret'=>trim((string)($raw['portal_access_client_secret'] ?? $raw['solicitud_backend_client_secret'] ?? '')),
        'siteId'=>trim((string)($raw['portal_access_sharepoint_site_id'] ?? $raw['solicitud_sharepoint_site_id'] ?? '')),
        'pfxPath'=>trim((string)($raw['portal_access_sharepoint_pfx_path'] ?? $raw['solicitud_sharepoint_pfx_path'] ?? '')),
        'pfxPassword'=>(string)($raw['portal_access_sharepoint_pfx_password'] ?? $raw['solicitud_sharepoint_pfx_password'] ?? ''),
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
      'Proveedor'=>['Proveedor'],'RFC'=>['RFC'],'Telefono'=>['Telefono','Teléfono'],'CiudadEstado'=>['CiudadEstado','Ciudad Estado'],
      'CondicionPago'=>['CondicionPago','Condicion de Pago'],'TiempoEntrega'=>['TiempoEntrega','Tiempo de Entrega'],'Moneda'=>['Moneda'],
      'TipoCambio'=>['TipoCambio','Tipo de Cambio'],'PartidasJson'=>['PartidasJson','Partidas'],'Subtotal'=>['Subtotal'],'IvaPct'=>['IvaPct'],
      'IVA'=>['IVA'],'RetIsrPct'=>['RetIsrPct'],'RetencionISR'=>['RetencionISR','Retencion ISR'],'RetIvaPct'=>['RetIvaPct'],
      'RetencionIVA'=>['RetencionIVA','Retencion IVA'],'Total'=>['Total'],'Banco'=>['Banco'],'Cuenta'=>['Cuenta'],'CLABE'=>['CLABE'],
      'SolicitanteNombre'=>['SolicitanteNombre','Solicitante Nombre'],'SolicitanteCorreo'=>['SolicitanteCorreo','Solicitante Correo'],
      'Estado'=>['Estado','Estatus'],'Ambiente'=>['Ambiente']
    ];
}

function ordenes_schema(): array {
    $text=['Folio','EmpresaCompradora','Proveedor','RFC','Telefono','CiudadEstado','CondicionPago','TiempoEntrega','Moneda','Banco','Cuenta','CLABE','SolicitanteNombre','SolicitanteCorreo','Estado','Ambiente'];
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
        foreach($cand as $c) if(strcasecmp((string)$f['InternalName'],$c)===0 || ordenes_norm((string)$f['Title'])===ordenes_norm($c)) return (string)$f['InternalName'];
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

function ordenes_map(array $values,bool $all=true): array {
    $out=[]; $missing=[];
    foreach($values as $k=>$v){
        $field=ordenes_field((string)$k);
        if($field===null){
            if($all && $k!=='Title') $missing[]=(string)$k;
            continue;
        }
        $out[$field]=$v;
    }
    if($missing) throw new RuntimeException('Faltan columnas en '.ORDENES_LIST_TITLE.': '.implode(', ',$missing).'.');
    return $out;
}

function ordenes_create_item(array $values): array {
    $s=ordenes_session(); $payload=ordenes_map($values,true);
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
    $s=ordenes_session(); $payload=ordenes_map($values,true);
    $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if(!is_string($json)) throw new RuntimeException('No fue posible preparar la actualizacion.');
    ordenes_http_json(ordenes_list_base().'/items('.$id.')','POST',[
        'Authorization: Bearer '.$s['token'],'Accept: application/json;odata=nometadata','Content-Type: application/json;odata=nometadata',
        'IF-MATCH: *','X-HTTP-Method: MERGE'
    ],$json);
}
