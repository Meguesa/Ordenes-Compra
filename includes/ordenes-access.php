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
