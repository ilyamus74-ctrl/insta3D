<?php
declare(strict_types=1);

ob_start();

require_once __DIR__ . '/../configs/secure.php';
require_once __DIR__ . '/../configs/app.php';

$token = trim((string)($_GET['token'] ?? ''));
$path  = trim((string)($_GET['path'] ?? ''));

function public_media_exit(int $code): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($code);
    exit;
}

if ($token === '' || $path === '') {
    public_media_exit(400);
}

if (
    str_starts_with($path, '/') ||
    str_contains($path, '..') ||
    str_contains($path, '\\') ||
    str_contains($path, "\0") ||
    preg_match('/\.php$/i', $path)
) {
    public_media_exit(403);
}

$stmt = $dbcnx->prepare("
    SELECT
        ptl.order_id,
        ptl.session_id,
        cs.app_session_uuid
    FROM public_tour_links ptl
    JOIN capture_sessions cs ON cs.id = ptl.session_id
    WHERE ptl.token = ?
      AND ptl.is_active = 1
      AND (ptl.expires_at IS NULL OR ptl.expires_at > NOW(6))
    LIMIT 1
");

if (!$stmt) {
    error_log('public_media db prepare failed: ' . $dbcnx->error);
    public_media_exit(500);
}

$stmt->bind_param('s', $token);
$stmt->execute();
$lnk = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$lnk) {
    public_media_exit(404);
}

$orderId = (int)$lnk['order_id'];
$sessionUuid = trim((string)$lnk['app_session_uuid']);

$prefix = 'orders/' . $orderId . '/sessions/' . $sessionUuid . '/photos/';

if (strncmp($path, $prefix, strlen($prefix)) !== 0) {
    error_log('public_media denied prefix mismatch path=' . $path);
    public_media_exit(403);
}

$allowed = (bool)preg_match(
    '#^orders/\d+/sessions/[A-Za-z0-9._-]+/photos/(viewer_light|viewer_hd|previews)/[A-Za-z0-9._-]+\.(jpe?g)$#i',
    $path
);

if (!$allowed) {
    error_log('public_media denied disallowed path=' . $path);
    public_media_exit(403);
}

$root = defined('APP_STORAGE_DIR')
    ? rtrim((string)APP_STORAGE_DIR, '/')
    : '/home/makler/web/storage';

$full = $root . '/' . ltrim($path, '/');

if (!is_file($full) || !is_readable($full)) {
    error_log('public_media missing/unreadable full=' . $full);
    public_media_exit(404);
}

$size = filesize($full);
if ($size === false || $size <= 0) {
    error_log('public_media empty file full=' . $full);
    public_media_exit(404);
}

/*
 * Критично: до отдачи JPEG вычищаем любой вывод,
 * который мог появиться из подключенных файлов.
 */
while (ob_get_level() > 0) {
    ob_end_clean();
}

http_response_code(200);
header('Content-Type: image/jpeg');
header('Content-Length: ' . (string)$size);
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');

$fp = fopen($full, 'rb');
if (!$fp) {
    http_response_code(404);
    exit;
}

fpassthru($fp);
fclose($fp);
exit;
