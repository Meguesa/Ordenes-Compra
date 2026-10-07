<?php

declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

function ordenes_num(mixed $value): float
{
    return is_numeric($value) ? (float)$value : 0.0;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') throw new RuntimeException('Metodo no permitido.');
    ordenes_api_require_csrf();

    $missingSchema = ordenes_missing_schema_fields();
    if (count($missingSchema) > 0) {
        ordenes_api_response([
            'ok' => false,
            'code' => 'SCHEMA_MISSING',
            'error' => 'La lista ' . ORDENES_LIST_TITLE . ' aun no tiene todas las columnas necesarias.',
            'missing' => $missingSchema,
        ], 409);
    }

    $input = ordenes_api_json_input();
    $user = portal_user();
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
        $qty = max(0.0, ordenes_num($row['qty'] ?? 0));
        $description = trim((string)($row['description'] ?? ''));
        $price = max(0.0, ordenes_num($row['price'] ?? 0));
        if ($description === '' && $qty <= 0 && $price <= 0) continue;
        if ($description === '' || $qty <= 0) throw new RuntimeException('Cada partida debe tener cantidad mayor a cero y descripcion.');
        $amount = round($qty * $price, 2);
        $items[] = ['qty' => $qty, 'description' => $description, 'price' => round($price, 2), 'amount' => $amount];
        $subtotal += $amount;
    }
    if (count($items) === 0) throw new RuntimeException('Agrega al menos una partida antes de guardar.');
    $subtotal = round($subtotal, 2);

    $ivaPct = max(0.0, min(100.0, ordenes_num($input['ivaPct'] ?? 0)));
    $retIsrPct = max(0.0, min(100.0, ordenes_num($input['retIsrPct'] ?? 0)));
    $retIvaPct = max(0.0, min(100.0, ordenes_num($input['retIvaPct'] ?? 0)));
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
        'RFC' => $rfc,
        'Telefono' => trim((string)($input['telefono'] ?? '')),
        'CiudadEstado' => trim((string)($input['ciudadEstado'] ?? '')),
        'CondicionPago' => $condicionPago,
        'TiempoEntrega' => trim((string)($input['tiempoEntrega'] ?? '')),
        'Moneda' => strtoupper(trim((string)($input['moneda'] ?? 'MXN'))),
        'TipoCambio' => max(0.0, ordenes_num($input['tipoCambio'] ?? 1)),
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

    ordenes_api_response([
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
    ]);
} catch (Throwable $error) {
    ordenes_api_error($error, 400);
}
