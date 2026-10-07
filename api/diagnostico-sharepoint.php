<?php

declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

try {
    $missing = ordenes_missing_schema_fields();
    ordenes_api_response([
        'ok' => true,
        'list' => ORDENES_LIST_TITLE,
        'ready' => count($missing) === 0,
        'missing' => $missing,
        'expected' => ordenes_expected_schema(),
    ]);
} catch (Throwable $error) {
    ordenes_api_error($error, 500);
}
