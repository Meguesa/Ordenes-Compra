<?php

declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') throw new RuntimeException('Metodo no permitido.');
    ordenes_api_require_csrf();
    $result = ordenes_try_prepare_schema();
    ordenes_api_response([
        'ok' => count($result['missing']) === 0,
        'list' => ORDENES_LIST_TITLE,
        'created' => $result['created'],
        'errors' => $result['errors'],
        'missing' => $result['missing'],
    ], count($result['missing']) === 0 ? 200 : 409);
} catch (Throwable $error) {
    ordenes_api_error($error, 500);
}
