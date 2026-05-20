<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
auth_require_login();

$user = auth_current_user();
$userId = (int)$user['id'];
$role = (string)($user['role'] ?? 'BROKER');

function image_error(int $code): void {
    http_response_code($code);
    exit;
}

function can_view_order(array $order, int $userId, string $role): bool {
    return $role === 'ADMIN'
        || ((int)$order['broker_id'] === $userId)
        || (
            $role === 'OPERATOR'
            && (
                (int)$order['operator_id'] === $userId
                || (
                    (int)$order['is_published'] === 1
                    && (string)$order['status'] === 'NEW'
                    && $order['operator_id'] === null
                )
            )
        );
}

$orderIdRaw = $_GET['order_id'] ?? null;
if (!is_string($orderIdRaw) && !is_int($orderIdRaw)) {
    image_error(400);
}
$orderId = filter_var((string)$orderIdRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($orderId === false) {
    image_error(400);
}
$orderId = (int)$orderId;

$sessionId = null;
$sessionDir = null;
if (isset($_GET['session_id']) && $_GET['session_id'] !== '') {
    $sid = filter_var((string)$_GET['session_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($sid === false) {
        image_error(400);
    }
    $sessionId = (int)$sid;
}
if (isset($_GET['session_dir']) && $_GET['session_dir'] !== '') {
    $sessionDirCandidate = (string)$_GET['session_dir'];
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $sessionDirCandidate)) {
        image_error(400);
    }
    $sessionDir = $sessionDirCandidate;
}
if ($sessionId === null && $sessionDir === null) {
    image_error(400);
}

$keyframe = (string)($_GET['keyframe'] ?? '');
if (!preg_match('/^keyframe_[0-9]{6}\.jpg$/', $keyframe)) {
    image_error(400);
}

$stmt = $dbcnx->prepare('SELECT id, broker_id, operator_id, is_published, status FROM tour_orders WHERE id = ? LIMIT 1');
if (!$stmt) {
    image_error(500);
}
$stmt->bind_param('i', $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$order) {
    image_error(404);
}
if (!can_view_order($order, $userId, $role)) {
    image_error(403);
}

if ($sessionId !== null) {
    $stmt = $dbcnx->prepare('SELECT * FROM video_sfm_runs WHERE order_id = ? AND session_id = ? ORDER BY id DESC LIMIT 1');
    if (!$stmt) {
        image_error(500);
    }
    $stmt->bind_param('ii', $orderId, $sessionId);
} else {
    $stmt = $dbcnx->prepare('SELECT * FROM video_sfm_runs WHERE order_id = ? AND session_dir = ? ORDER BY id DESC LIMIT 1');
    if (!$stmt) {
        image_error(500);
    }
    $stmt->bind_param('is', $orderId, $sessionDir);
}
$stmt->execute();
$run = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$run) {
    image_error(404);
}

$runSessionDir = trim((string)($run['session_dir'] ?? ''));
if ($runSessionDir === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $runSessionDir)) {
    image_error(500);
}

$storageRoot = '/home/makler/web/storage/orders';
$realStorageRoot = realpath($storageRoot);
if ($realStorageRoot === false) {
    image_error(500);
}

$candidateSessionBases = [];
$candidateSessionBases[] = $storageRoot . '/' . $orderId . '/sessions/' . $runSessionDir;

$runVideoPath = (string)($run['video_path'] ?? '');
if ($runVideoPath !== '' && $runVideoPath[0] === '/' && is_file($runVideoPath)) {
    $candidateSessionBases[] = dirname(dirname($runVideoPath));
}

$runSfmBasePath = (string)($run['sfm_base_path'] ?? '');
if ($runSfmBasePath !== '' && $runSfmBasePath[0] === '/') {
    $candidateSessionBases[] = basename($runSfmBasePath) === 'sfm'
        ? dirname($runSfmBasePath)
        : $runSfmBasePath;
}

$candidateSessionBases = array_values(array_unique($candidateSessionBases));
$realSessionBase = false;
$storagePrefix = rtrim($realStorageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

foreach ($candidateSessionBases as $candidate) {
    $realCandidate = realpath($candidate);
    if ($realCandidate === false) {
        continue;
    }
    if (strpos($realCandidate, $storagePrefix) !== 0) {
        continue;
    }
    if (!is_dir($realCandidate . '/sfm')) {
        continue;
    }
    $realSessionBase = $realCandidate;
    break;
}

if ($realSessionBase === false) {
    image_error(404);
}

$keyframesDir = $realSessionBase . '/sfm/keyframes';
$realKeyframesDir = realpath($keyframesDir);
if ($realKeyframesDir === false || !is_dir($realKeyframesDir)) {
    image_error(404);
}

$imagePath = $realKeyframesDir . '/' . $keyframe;
$realImagePath = realpath($imagePath);
$keyframesPrefix = rtrim($realKeyframesDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
if ($realImagePath === false || strpos($realImagePath, $keyframesPrefix) !== 0 || !is_file($realImagePath)) {
    image_error(404);
}

$size = filesize($realImagePath);
if ($size === false) {
    image_error(500);
}

header('Content-Type: image/jpeg');
header('Content-Length: ' . (string)$size);
header('Cache-Control: private, max-age=3600');
readfile($realImagePath);
exit;
