<?php
declare(strict_types=1);

function source_storage_root(): string
{
    return '/home/storage/orders';
}

function legacy_source_storage_root(): string
{
    return '/home/makler/web/storage/orders';
}

function storage_safe_session_uuid(string $uuid): string
{
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $uuid);
    return $safe !== '' ? $safe : 'session';
}

function source_order_storage_dir(int $orderId, bool $legacy = false): string
{
    return rtrim($legacy ? legacy_source_storage_root() : source_storage_root(), '/') . '/' . $orderId;
}

function capture_session_videos_dir(int $orderId, string $sessionUuid, bool $legacy = false, bool $withOrderSuffix = false): string
{
    $safe = storage_safe_session_uuid($sessionUuid);
    $sessionDir = $safe;
    if ($withOrderSuffix && !str_ends_with($safe, '_' . $orderId)) {
        $sessionDir .= '_' . $orderId;
    }
    return source_order_storage_dir($orderId, $legacy) . '/sessions/' . $sessionDir . '/videos';
}