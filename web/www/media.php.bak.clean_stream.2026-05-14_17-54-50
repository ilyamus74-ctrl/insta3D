<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
auth_require_login();

$user = auth_current_user();
$userId = (int)$user['id'];
$role = $user['role'] ?? 'BROKER';

$path = isset($_GET['path']) ? (string)$_GET['path'] : '';
if ($path === '' || strpos($path, "\0") !== false || strpos($path, '../') !== false || strpos($path, '/..') !== false) {
    http_response_code(400); exit('Bad path');
}
if (!preg_match('#^orders/(\d+)/sessions/.+#', $path, $m)) {
    http_response_code(400); exit('Invalid media path');
}
$orderId = (int)$m[1];

$stmt = $dbcnx->prepare("SELECT * FROM tour_orders WHERE id=? LIMIT 1");
if (!$stmt) { http_response_code(500); exit('DB error'); }
$stmt->bind_param('i', $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$order) { http_response_code(404); exit('Order not found'); }

$canView = $role==='ADMIN' || ((int)$order['broker_id']===$userId) || ($role==='OPERATOR' && ((int)$order['operator_id']===$userId || ((int)$order['is_published']===1 && $order['status']==='NEW' && $order['operator_id']===null)));
if (!$canView) { http_response_code(403); exit('Forbidden'); }

$storageRoot = defined('APP_STORAGE_DIR') ? APP_STORAGE_DIR : (__DIR__ . '/../storage');
$storageRootReal = realpath($storageRoot);
if ($storageRootReal === false) { http_response_code(500); exit('Storage root missing'); }

$fullPath = $storageRootReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
$fileReal = realpath($fullPath);
if ($fileReal === false || strpos($fileReal, $storageRootReal . DIRECTORY_SEPARATOR) !== 0 || !is_file($fileReal)) {
    http_response_code(404); exit('File not found');
}

$ext = strtolower((string)pathinfo($fileReal, PATHINFO_EXTENSION));
$contentType = 'application/octet-stream';
switch ($ext) {
    case 'jpg':
    case 'jpeg': $contentType = 'image/jpeg'; break;
    case 'png': $contentType = 'image/png'; break;
    case 'webp': $contentType = 'image/webp'; break;
    case 'gif': $contentType = 'image/gif'; break;
    case 'mp4': $contentType = 'video/mp4'; break;
    case 'mov': $contentType = 'video/quicktime'; break;
}

$size = filesize($fileReal);
$start = 0;
$end = $size - 1;
$statusCode = 200;

if (isset($_SERVER['HTTP_RANGE'])) {
    if (preg_match('/bytes=(\d*)-(\d*)/i', (string)$_SERVER['HTTP_RANGE'], $r)) {
        if ($r[1] !== '') { $start = (int)$r[1]; }
        if ($r[2] !== '') { $end = (int)$r[2]; }
        if ($r[1] === '' && $r[2] !== '') {
            $suffix = (int)$r[2];
            if ($suffix > 0) { $start = max(0, $size - $suffix); $end = $size - 1; }
        }
        if ($start > $end || $start >= $size) {
            header('Content-Range: bytes */' . $size);
            http_response_code(416);
            exit;
        }
        $end = min($end, $size - 1);
        $statusCode = 206;
    }
}

$length = $end - $start + 1;
http_response_code($statusCode);
header('Content-Type: ' . $contentType);
header('Content-Disposition: inline; filename="' . basename($fileReal) . '"');
header('X-Content-Type-Options: nosniff');
header('Accept-Ranges: bytes');
header('Content-Length: ' . $length);
if ($statusCode === 206) {
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}

$fp = fopen($fileReal, 'rb');
if ($fp === false) { http_response_code(500); exit; }
fseek($fp, $start);
$remaining = $length;
while ($remaining > 0 && !feof($fp)) {
    $read = ($remaining > 8192) ? 8192 : $remaining;
    $buffer = fread($fp, $read);
    if ($buffer === false) { break; }
    echo $buffer;
    $remaining -= strlen($buffer);
}
fclose($fp);
exit;
