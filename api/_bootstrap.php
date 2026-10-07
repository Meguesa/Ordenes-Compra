<?php

declare(strict_types=1);

$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
require_once $root . '/includes/bootstrap.php';
portal_require_authentication();
require_once dirname(__DIR__) . '/includes/ordenes-access.php';
ordenes_require_preview_access();
require_once dirname(__DIR__) . '/includes/ordenes-sharepoint.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function ordenes_api_json_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) throw new InvalidArgumentException('El cuerpo JSON no es valido.');
    return $decoded;
}

function ordenes_api_require_csrf(): void
{
    $expected = (string)($_SESSION['ordenes_csrf'] ?? '');
    $received = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($expected === '' || $received === '' || !hash_equals($expected, $received)) {
        throw new RuntimeException('La sesion de seguridad no es valida. Recarga la pagina.');
    }
}

function ordenes_api_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ordenes_api_error(Throwable $error, int $status = 400): never
{
    ordenes_api_response(['ok' => false, 'error' => $error->getMessage()], $status);
}
