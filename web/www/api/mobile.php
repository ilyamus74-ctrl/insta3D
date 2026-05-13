<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function api_json(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_get_bearer_token(): ?string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if ($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }

    if (preg_match('/Bearer\s+(.+)/i', $header, $m)) {
        return trim($m[1]);
    }

    return null;
}


function api_request_meta(array $extra = []): array {
    return array_merge([
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
        'uri' => $_SERVER['REQUEST_URI'] ?? null,
    ], $extra);
}

function api_audit_mobile_token_invalid(mysqli $dbcnx, string $reason): void {
    $token = api_get_bearer_token();
    $tokenHashPrefix = null;

    if ($token) {
        $tokenHashPrefix = substr(hash('sha256', $token), 0, 12);
    }

    audit_log(
        null,
        'MOBILE_TOKEN_INVALID',
        'MOBILE_TOKEN',
        null,
        'Невалидный мобильный token',
        api_request_meta([
            'reason' => $reason,
            'token_hash_prefix' => $tokenHashPrefix,
        ])
    );
}

function api_current_mobile_user(mysqli $dbcnx): ?array {
    $token = api_get_bearer_token();
    if (!$token) {
        return null;
    }

    $hash = hash('sha256', $token);

    $stmt = $dbcnx->prepare("
        SELECT u.*
        FROM mobile_tokens mt
        JOIN users u ON u.id = mt.user_id
        WHERE mt.token_hash = ?
          AND u.is_active = 1
          AND (mt.expires_at IS NULL OR mt.expires_at > NOW(6))
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("s", $hash);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res->fetch_assoc() ?: null;
    $stmt->close();

    if ($user) {
        $stmtU = $dbcnx->prepare("
            UPDATE mobile_tokens
            SET last_used_at = NOW(6)
            WHERE token_hash = ?
        ");
        if ($stmtU) {
            $stmtU->bind_param("s", $hash);
            $stmtU->execute();
            $stmtU->close();
        }
    }

    return $user;
}

function api_require_mobile_user(mysqli $dbcnx): array {
    $token = api_get_bearer_token();

    if (!$token) {
        api_json(['ok' => false, 'error' => 'unauthorized'], 401);
    }

    $user = api_current_mobile_user($dbcnx);

    if (!$user) {
            api_audit_mobile_token_invalid($dbcnx, 'token_not_found_or_expired');
            api_json(['ok' => false, 'error' => 'unauthorized'], 401);
    }
    return $user;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $deviceName = trim($_POST['device_name'] ?? '');
    $deviceFingerprint = trim($_POST['device_fingerprint'] ?? '');

    if ($username === '' || $password === '') {

        audit_log(
            null,
            'MOBILE_LOGIN_FAILED',
            'USER',
            null,
            'Неудачный вход в мобильное приложение',
            api_request_meta([
                'username' => $username,
                'device_name' => $deviceName,
                'device_fingerprint' => $deviceFingerprint,
                'reason' => 'missing_username_or_password',
            ])
        );
        api_json(['ok' => false, 'error' => 'missing username or password'], 400);
    }

    $stmt = $dbcnx->prepare("
        SELECT id, username, email, full_name, password_hash, role, is_active
        FROM users
        WHERE username = ? OR email = ?
        LIMIT 1
    ");

    if (!$stmt) {
        api_json(['ok' => false, 'error' => 'db prepare error'], 500);
    }

    $stmt->bind_param("ss", $username, $username);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res->fetch_assoc();
    $stmt->close();

    $loginFailReason = null;
    $failedUserId = null;

    if (!$user) {
        $loginFailReason = 'user_not_found';
    } else {
        $failedUserId = (int)$user['id'];

        if ((int)$user['is_active'] !== 1) {
            $loginFailReason = 'user_inactive';
        } elseif (!password_verify($password, $user['password_hash'])) {
            $loginFailReason = 'bad_password';
        }
    }

    if ($loginFailReason !== null) {
        audit_log(
            $failedUserId,
            'MOBILE_LOGIN_FAILED',
            'USER',
            $failedUserId,
            'Неудачный вход в мобильное приложение',
            api_request_meta([
                'username' => $username,
                'device_name' => $deviceName,
                'device_fingerprint' => $deviceFingerprint,
                'reason' => $loginFailReason,
            ])
        );

        api_json(['ok' => false, 'error' => 'invalid credentials'], 403);
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $userId = (int)$user['id'];

    $stmt = $dbcnx->prepare("
        INSERT INTO mobile_tokens
        (user_id, token_hash, device_name, device_fingerprint, expires_at)
        VALUES (?, ?, ?, ?, DATE_ADD(NOW(6), INTERVAL 90 DAY))
    ");

    if (!$stmt) {
        api_json(['ok' => false, 'error' => 'db token prepare error'], 500);
    }

    $stmt->bind_param("isss", $userId, $tokenHash, $deviceName, $deviceFingerprint);

if (!$stmt->execute()) {
    api_json(['ok' => false, 'error' => 'db token execute error'], 500);
}

$stmt->close();

    audit_log(
        $userId,
        'MOBILE_LOGIN',
        'USER',
        $userId,
        'Вход из мобильного приложения',
        api_request_meta([
            'device_name' => $deviceName,
            'device_fingerprint' => $deviceFingerprint,
        ])
    );

api_json([
    'ok' => true,
    'token' => $token,
    'user' => [
        'id' => $userId,
        'username' => $user['username'],
        'email' => $user['email'],
        'full_name' => $user['full_name'],
        'role' => $user['role'],
    ],
]);
}

if ($action === 'orders') {
    $user = api_require_mobile_user($dbcnx);
    $userId = (int)$user['id'];
    $role = $user['role'] ?? 'BROKER';

    if ($role === 'ADMIN') {
        $stmt = $dbcnx->prepare("
            SELECT *
            FROM tour_orders
            ORDER BY updated_at DESC
            LIMIT 200
        ");
    } elseif ($role === 'OPERATOR') {
    $stmt = $dbcnx->prepare("
        SELECT *
        FROM tour_orders
        WHERE broker_id = ?
           OR operator_id = ?
           OR (status = 'NEW' AND operator_id IS NULL AND is_published = 1)
        ORDER BY updated_at DESC
        LIMIT 200
    ");
    if ($stmt) {
        $stmt->bind_param("ii", $userId, $userId);
    }
    } else {
        $stmt = $dbcnx->prepare("
            SELECT *
            FROM tour_orders
            WHERE broker_id = ?
            ORDER BY updated_at DESC
            LIMIT 200
        ");
        if ($stmt) {
            $stmt->bind_param("i", $userId);
        }
    }

    if (!$stmt) {
        api_json(['ok' => false, 'error' => 'db prepare error'], 500);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    $orders = [];
    while ($row = $res->fetch_assoc()) {
        $orders[] = $row;
    }

    $stmt->close();

    api_json(['ok' => true, 'orders' => $orders]);
}

if ($action === 'take_order') {
    $user = api_require_mobile_user($dbcnx);
    $userId = (int)$user['id'];
    $role = $user['role'] ?? 'BROKER';

    if (!in_array($role, ['ADMIN', 'OPERATOR'], true)) {
        api_json(['ok' => false, 'error' => 'forbidden'], 403);
    }

    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        api_json(['ok' => false, 'error' => 'invalid order_id'], 400);
    }

    $stmt = $dbcnx->prepare("\n        UPDATE tour_orders\n        SET operator_id = ?,\n            status = 'ASSIGNED'\n        WHERE id = ?\n          AND status = 'NEW'\n          AND operator_id IS NULL\n    ");

    if (!$stmt) {
        api_json(['ok' => false, 'error' => 'db prepare error'], 500);
    }

    $stmt->bind_param("ii", $userId, $orderId);
    if (!$stmt->execute()) {
        $stmt->close();
        api_json(['ok' => false, 'error' => 'db execute error'], 500);
    }

    $affectedRows = $stmt->affected_rows;
    $stmt->close();

    if ($affectedRows === 1) {
        audit_log($userId, 'ORDER_TAKEN', 'TOUR_ORDER', $orderId, 'Заявка взята в работу');
        api_json(['ok' => true]);
    }

    api_json(['ok' => false, 'error' => 'order already taken or unavailable'], 409);
}

if ($action === 'create_session') {
    $user = api_require_mobile_user($dbcnx);
    $userId = (int)$user['id'];
    $role = $user['role'] ?? 'BROKER';

    $orderId = (int)($_POST['order_id'] ?? 0);
    $appSessionUuid = trim($_POST['app_session_uuid'] ?? '');
    $cameraModel = trim($_POST['camera_model'] ?? '');

    if ($orderId <= 0 || $appSessionUuid === '') {
        api_json(['ok' => false, 'error' => 'missing order_id or app_session_uuid'], 400);
    }

    $stmt = $dbcnx->prepare("
    SELECT id
    FROM tour_orders
    WHERE id = ?
      AND (
          ? = 'ADMIN'
          OR operator_id = ?
          OR broker_id = ?
      )
    LIMIT 1
    ");

    if (!$stmt) {
        api_json(['ok' => false, 'error' => 'db prepare error'], 500);
    }

    $stmt->bind_param("isii", $orderId, $role, $userId, $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $order = $res->fetch_assoc();
    $stmt->close();

    if (!$order) {
        api_json(['ok' => false, 'error' => 'order not assigned to this operator'], 403);
    }

    $stmt = $dbcnx->prepare("
        INSERT INTO capture_sessions
        (order_id, operator_id, app_session_uuid, camera_model, status, started_at)
        VALUES (?, ?, ?, ?, 'CAPTURED', NOW(6))
        ON DUPLICATE KEY UPDATE
            camera_model = VALUES(camera_model),
            updated_at = NOW(6)
    ");

    if (!$stmt) {
        api_json(['ok' => false, 'error' => 'db insert session prepare error'], 500);
    }

    $stmt->bind_param("iiss", $orderId, $userId, $appSessionUuid, $cameraModel);

    if (!$stmt->execute()) {
        api_json(['ok' => false, 'error' => 'db insert session execute error: ' . $stmt->error], 500);
    }

    $stmt->close();

    $stmt = $dbcnx->prepare("
        SELECT id
        FROM capture_sessions
        WHERE app_session_uuid = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $appSessionUuid);
    $stmt->execute();
    $res = $stmt->get_result();
    $session = $res->fetch_assoc();
    $stmt->close();

    $stmt = $dbcnx->prepare("
        UPDATE tour_orders
        SET status = 'CAPTURED'
        WHERE id = ?
          AND status IN ('ASSIGNED','IN_PROGRESS')
    ");
    if ($stmt) {
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $stmt->close();
    }

    api_json([
        'ok' => true,
        'capture_session_id' => (int)$session['id'],
    ]);
}

if ($action === 'upload_video_scan') {
    $user = api_require_mobile_user($dbcnx);
    $userId = (int)$user['id'];
    $role = $user['role'] ?? 'BROKER';

    error_log('UPLOAD_VIDEO_SCAN POST=' . json_encode($_POST, JSON_UNESCAPED_UNICODE));
    error_log('UPLOAD_VIDEO_SCAN FILES=' . json_encode(array_map(function($f) {
    return [
        'name' => $f['name'] ?? null,
        'type' => $f['type'] ?? null,
        'size' => $f['size'] ?? null,
        'error' => $f['error'] ?? null,
    ];
}, $_FILES), JSON_UNESCAPED_UNICODE));

    $orderId = (int)($_POST['order_id'] ?? 0);
    $captureSessionId = (int)($_POST['capture_session_id'] ?? 0);
    $appScanUuid = trim($_POST['app_scan_uuid'] ?? '');
    $durationSec = (int)($_POST['duration_sec'] ?? 0);
    $localCameraUrl = trim($_POST['local_camera_url'] ?? '');

    if ($orderId <= 0 || $captureSessionId <= 0 || $appScanUuid === '') {
        api_json(['ok' => false, 'error' => 'missing required fields'], 400);
    }

    $hasDirectVideo = !empty($_FILES['video']) && (int)($_FILES['video']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
    $isChunkMode = isset($_POST['chunk_index']) || isset($_POST['total_chunks']) || isset($_POST['upload_id']);

    if (!$hasDirectVideo) {
        api_json(['ok' => false, 'error' => 'missing video file'], 400);
    }

    $stmt = $dbcnx->prepare("
    SELECT cs.id, cs.app_session_uuid
    FROM capture_sessions cs
    JOIN tour_orders o ON o.id = cs.order_id
    WHERE cs.id = ?
      AND cs.order_id = ?
      AND (
         ? = 'ADMIN'
         OR o.operator_id = ?
         OR o.broker_id = ?
     )
   LIMIT 1
    ");

    if (!$stmt) {
        api_json(['ok' => false, 'error' => 'db session check prepare error'], 500);
    }

    $stmt->bind_param("iisii", $captureSessionId, $orderId, $role, $userId, $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $session = $res->fetch_assoc();
    $stmt->close();

    if (!$session) {
        api_json(['ok' => false, 'error' => 'capture session not found or access denied'], 403);
    }
$safeSessionUuid = preg_replace('/[^a-zA-Z0-9._-]+/', '_', (string)$session['app_session_uuid']);
if ($safeSessionUuid === '') {
    $safeSessionUuid = 'session_' . $captureSessionId;
}

$safeScanUuid = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $appScanUuid);
if ($safeScanUuid === '') {
    $safeScanUuid = 'scan_' . time();
}

$base = realpath(__DIR__ . '/../../storage');

if ($base === false) {
    $base = __DIR__ . '/../../storage';

    if (!is_dir($base) && !mkdir($base, 0775, true)) {
        api_json([
            'ok' => false,
            'error' => 'failed to create storage root',
            'storage_root' => $base,
        ], 500);
    }

    $base = realpath($base);
}

if ($base === false || !is_dir($base) || !is_writable($base)) {
    api_json([
        'ok' => false,
        'error' => 'storage root is not writable',
        'storage_root' => (string)$base,
    ], 500);
}

$uploadRoot = $base;

$orderDir = $uploadRoot . '/orders/' . $orderId . '/sessions/' . $safeSessionUuid . '/videos';

if (!is_dir($orderDir) && !mkdir($orderDir, 0775, true)) {
    api_json([
        'ok' => false,
        'error' => 'failed to create order dir',
        'order_dir' => $orderDir,
    ], 500);
}

if (!is_writable($orderDir)) {
    api_json([
        'ok' => false,
        'error' => 'order dir is not writable',
        'order_dir' => $orderDir,
    ], 500);
}


    $originalName = basename($_FILES['video']['name']);
    $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $originalName);
    if ($safeName === '') {
        $safeName = 'video_' . time() . '.mp4';
    }

    $finalName = $safeScanUuid . '_' . $safeName;
    $targetPath = $orderDir . '/' . $finalName;
    $relativePath = 'orders/' . $orderId . '/sessions/' . $safeSessionUuid . '/videos/' . $finalName;
    $sizeBytes = null;

    if ($isChunkMode) {
        $chunkIndex = (int)($_POST['chunk_index'] ?? -1);
        $totalChunks = (int)($_POST['total_chunks'] ?? 0);
        $uploadIdRaw = trim((string)($_POST['upload_id'] ?? ''));
        $uploadId = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $uploadIdRaw);
        $chunkSizeDeclared = (int)($_POST['chunk_size'] ?? 0);
        $totalSizeDeclared = (int)($_POST['total_size'] ?? 0);
        $isLastChunk = $chunkIndex >= 0 && $totalChunks > 0 && $chunkIndex === ($totalChunks - 1);

        if ($chunkIndex < 0 || $totalChunks <= 0 || $chunkIndex >= $totalChunks || $uploadId === '') {
            api_json([
                'ok' => false,
                'error' => 'invalid chunk metadata',
                'chunk_index' => $chunkIndex,
                'total_chunks' => $totalChunks,
            ], 400);
        }
        
        $chunksDir = $orderDir . '/.chunks';
        if (!is_dir($chunksDir) && !mkdir($chunksDir, 0775, true)) {
            api_json(['ok' => false, 'error' => 'failed to create chunks dir'], 500);
        }
        $uploadDir = $chunksDir . '/' . $safeScanUuid . '_' . $uploadId;
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
            api_json(['ok' => false, 'error' => 'failed to create upload chunks dir'], 500);
        }

        $metaPath = $uploadDir . '/meta.json';
        $meta = [];
        if (is_file($metaPath)) {
            $metaRaw = file_get_contents($metaPath);
            $metaDecoded = json_decode((string)$metaRaw, true);
            if (is_array($metaDecoded)) {
                $meta = $metaDecoded;
            }
        }
        if (isset($meta['total_chunks']) && (int)$meta['total_chunks'] !== $totalChunks) {
            api_json(['ok' => false, 'error' => 'total_chunks mismatch for upload_id'], 409);
        }

        $chunkFile = $uploadDir . '/' . str_pad((string)$chunkIndex, 6, '0', STR_PAD_LEFT) . '.part';
        if (!move_uploaded_file($_FILES['video']['tmp_name'], $chunkFile)) {
            api_json(['ok' => false, 'error' => 'failed to store chunk'], 500);
        }
        $actualChunkSize = filesize($chunkFile) ?: 0;
        if ($chunkSizeDeclared > 0 && $actualChunkSize !== $chunkSizeDeclared) {
            @unlink($chunkFile);
            api_json(['ok' => false, 'error' => 'chunk_size mismatch'], 409);
        }

        $meta['upload_id'] = $uploadId;
        $meta['safe_scan_uuid'] = $safeScanUuid;
        $meta['total_chunks'] = $totalChunks;
        $meta['total_size'] = $totalSizeDeclared > 0 ? $totalSizeDeclared : ($meta['total_size'] ?? null);
        if (!isset($meta['chunks']) || !is_array($meta['chunks'])) {
            $meta['chunks'] = [];
        }
        $meta['chunks'][(string)$chunkIndex] = [
            'size' => $actualChunkSize,
            'updated_at' => gmdate('c'),
        ];
        $meta['updated_at'] = gmdate('c');
        if (file_put_contents($metaPath, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
            api_json(['ok' => false, 'error' => 'failed to persist chunk metadata'], 500);
        }

        if (!$isLastChunk) {
            api_json([
                'ok' => true,
                'chunk_received' => $chunkIndex,
                'total_chunks' => $totalChunks,
                'received_chunks' => count($meta['chunks']),
                'upload_complete' => false,
            ]);
        }

        for ($i = 0; $i < $totalChunks; $i++) {
            $partPath = $uploadDir . '/' . str_pad((string)$i, 6, '0', STR_PAD_LEFT) . '.part';
            if (!is_file($partPath)) {
                api_json(['ok' => false, 'error' => 'missing chunk before finalize', 'missing_chunk' => $i], 409);
            }
        }

        $out = fopen($targetPath, 'wb');
        if (!$out) {
            api_json(['ok' => false, 'error' => 'failed to open target video file'], 500);
        }
        $assembledSize = 0;
        for ($i = 0; $i < $totalChunks; $i++) {
            $partPath = $uploadDir . '/' . str_pad((string)$i, 6, '0', STR_PAD_LEFT) . '.part';
            $partSize = filesize($partPath) ?: 0;
            if (isset($meta['chunks'][(string)$i]['size']) && (int)$meta['chunks'][(string)$i]['size'] !== $partSize) {
                fclose($out);
                api_json(['ok' => false, 'error' => 'chunk size drift detected', 'chunk_index' => $i], 409);
            }
            $in = fopen($partPath, 'rb');
            if (!$in) {
                fclose($out);
                api_json(['ok' => false, 'error' => 'failed to open chunk for finalize', 'chunk_index' => $i], 500);
            }
            $assembledSize += stream_copy_to_stream($in, $out);
            fclose($in);
        }
        fclose($out);
        if (($meta['total_size'] ?? 0) > 0 && $assembledSize !== (int)$meta['total_size']) {
            api_json(['ok' => false, 'error' => 'total_size mismatch after finalize'], 409);
        }

        for ($i = 0; $i < $totalChunks; $i++) {
            $partPath = $uploadDir . '/' . str_pad((string)$i, 6, '0', STR_PAD_LEFT) . '.part';
            @unlink($partPath);
        }
        @unlink($metaPath);
        @rmdir($uploadDir);
        $sizeBytes = filesize($targetPath) ?: null;
    } else {
        // Fallback for small files: old single-request upload flow.
        if (!is_uploaded_file($_FILES['video']['tmp_name'])) {
            api_json([
                'ok' => false,
                'error' => 'tmp file is not uploaded file',
                'tmp_name' => $_FILES['video']['tmp_name'] ?? null,
            ], 500);
        }

        if (!move_uploaded_file($_FILES['video']['tmp_name'], $targetPath)) {
            api_json([
                'ok' => false,
                'error' => 'failed to move uploaded file',
                'tmp_name' => $_FILES['video']['tmp_name'] ?? null,
                'target_path' => $targetPath,
                'target_dir' => $orderDir,
                'dir_writable' => is_writable($orderDir),
            ], 500);
        }

        $sizeBytes = filesize($targetPath) ?: null;
    }

    $stmt = $dbcnx->prepare("
        INSERT INTO video_scans
        (session_id, app_scan_uuid, filename, local_camera_url, storage_path, size_bytes, duration_sec, upload_state, processing_state)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'UPLOADED', 'NOT_STARTED')
        ON DUPLICATE KEY UPDATE
            filename = VALUES(filename),
            local_camera_url = VALUES(local_camera_url),
            storage_path = VALUES(storage_path),
            size_bytes = VALUES(size_bytes),
            duration_sec = VALUES(duration_sec),
            upload_state = 'UPLOADED',
            updated_at = NOW(6)
    ");

    if (!$stmt) {
        api_json(['ok' => false, 'error' => 'db video insert prepare error: ' . $dbcnx->error], 500);
    }

    $stmt->bind_param(
        "issssii",
        $captureSessionId,
        $appScanUuid,
        $finalName,
        $localCameraUrl,
        $relativePath,
        $sizeBytes,
        $durationSec
    );

    if (!$stmt->execute()) {
        api_json(['ok' => false, 'error' => 'db video insert execute error: ' . $stmt->error], 500);
    }

    $stmt->close();

$stmt = $dbcnx->prepare("
    UPDATE tour_orders
    SET status = 'UPLOADED'
    WHERE id = ?
      AND status IN ('ASSIGNED','IN_PROGRESS','CAPTURED','UPLOADING')
    ");
if ($stmt) {
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $stmt->close();
}

    audit_log(
        $userId,
        'VIDEO_UPLOADED',
        'TOUR_ORDER',
        $orderId,
        'Загружен video scan',
        api_request_meta([
            'capture_session_id' => $captureSessionId,
            'app_scan_uuid' => $appScanUuid,
            'storage_path' => $relativePath,
            'size_bytes' => $sizeBytes,
            'duration_sec' => $durationSec,
            'local_camera_url' => $localCameraUrl,
        ])
    );

    api_json([
        'ok' => true,
        'upload_complete' => true,
        'storage_path' => $relativePath,
        'size_bytes' => $sizeBytes,
    ]);
}


if ($action === 'upload_photo_point') {
    $user = api_require_mobile_user($dbcnx);
    $userId = (int)$user['id'];
    $role = $user['role'] ?? 'BROKER';


    error_log('UPLOAD_PHOTO_POINT POST=' . json_encode($_POST, JSON_UNESCAPED_UNICODE));
    error_log('UPLOAD_PHOTO_POINT FILES=' . json_encode(array_map(function($f) {
    return [
        'name' => $f['name'] ?? null,
        'type' => $f['type'] ?? null,
        'size' => $f['size'] ?? null,
        'error' => $f['error'] ?? null,
    ];
}, $_FILES), JSON_UNESCAPED_UNICODE));

    $orderId = (int)($_POST['order_id'] ?? 0);
    $captureSessionId = (int)($_POST['capture_session_id'] ?? 0);
    $appPointUuid = trim($_POST['app_point_uuid'] ?? '');
    $pointName = trim($_POST['point_name'] ?? '');
    $roomName = trim($_POST['room_name'] ?? '');
    $sequenceNumber = (int)($_POST['sequence_number'] ?? 0);
    $cameraFileUrl = trim($_POST['camera_file_url'] ?? '');
    $cameraLocalPath = trim($_POST['camera_local_path'] ?? '');

    if ($orderId <= 0 || $captureSessionId <= 0 || $appPointUuid === '' || $pointName === '') {
        api_json(['ok' => false, 'error' => 'missing required fields'], 400);
    }

    $stmt = $dbcnx->prepare("
    SELECT cs.id, cs.app_session_uuid
    FROM capture_sessions cs
    JOIN tour_orders o ON o.id = cs.order_id
    WHERE cs.id = ?
      AND cs.order_id = ?
      AND (
          ? = 'ADMIN'
          OR o.operator_id = ?
          OR o.broker_id = ?
      )
    LIMIT 1
    ");

    if (!$stmt) api_json(['ok' => false, 'error' => 'db session check prepare error'], 500);
    $stmt->bind_param("iisii", $captureSessionId, $orderId, $role, $userId, $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $session = $res->fetch_assoc();
    $stmt->close();
    if (!$session) api_json(['ok' => false, 'error' => 'capture session not found or access denied'], 403);

    $safeSessionUuid = preg_replace('/[^a-zA-Z0-9._-]+/', '_', (string)$session['app_session_uuid']);
    if ($safeSessionUuid === '') $safeSessionUuid = 'session_' . $captureSessionId;
    $safePointUuid = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $appPointUuid);
    if ($safePointUuid === '') $safePointUuid = 'point_' . time();

    $base = realpath(__DIR__ . '/../../storage');
    if ($base === false) {
        $base = __DIR__ . '/../../storage';
        if (!is_dir($base) && !mkdir($base, 0775, true)) api_json(['ok' => false, 'error' => 'failed to create storage root'], 500);
        $base = realpath($base);
    }
    if ($base === false || !is_dir($base) || !is_writable($base)) api_json(['ok' => false, 'error' => 'storage root is not writable'], 500);

    $previewsDir = $base . '/orders/' . $orderId . '/sessions/' . $safeSessionUuid . '/photos/previews';
    $originalsDir = $base . '/orders/' . $orderId . '/sessions/' . $safeSessionUuid . '/photos/originals';
    if (!is_dir($previewsDir) && !mkdir($previewsDir, 0775, true)) api_json(['ok' => false, 'error' => 'failed to create previews dir'], 500);
    if (!is_dir($originalsDir) && !mkdir($originalsDir, 0775, true)) api_json(['ok' => false, 'error' => 'failed to create originals dir'], 500);

    $previewStoragePath = null; $originalStoragePath = null; $previewSizeBytes = null; $originalSizeBytes = null;
    foreach ([['key'=>'preview','dir'=>$previewsDir,'sub'=>'previews'], ['key'=>'original','dir'=>$originalsDir,'sub'=>'originals']] as $spec) {
        $key = $spec['key'];
        if (!empty($_FILES[$key]) && $_FILES[$key]['error'] === UPLOAD_ERR_OK) {
            $originalName = basename($_FILES[$key]['name']);
            $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $originalName);
            if ($safeName === '') $safeName = $key . '_' . time() . '.jpg';
            $finalName = $safePointUuid . '_' . $safeName;
            $targetPath = $spec['dir'] . '/' . $finalName;
            if (!move_uploaded_file($_FILES[$key]['tmp_name'], $targetPath)) api_json(['ok' => false, 'error' => 'failed to move ' . $key], 500);
            $relativePath = 'orders/' . $orderId . '/sessions/' . $safeSessionUuid . '/photos/' . $spec['sub'] . '/' . $finalName;
            $sizeBytes = filesize($targetPath) ?: null;
            if ($key === 'preview') { $previewStoragePath = $relativePath; $previewSizeBytes = $sizeBytes; }
            else { $originalStoragePath = $relativePath; $originalSizeBytes = $sizeBytes; }
        }
    }

    $stmt = $dbcnx->prepare("
        INSERT INTO photo_points
        (session_id, app_point_uuid, name, room_name, sequence_number, camera_file_url, camera_local_path, preview_storage_path, original_storage_path, preview_size_bytes, original_size_bytes, upload_state)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'UPLOADED')
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            room_name = VALUES(room_name),
            sequence_number = VALUES(sequence_number),
            camera_file_url = VALUES(camera_file_url),
            camera_local_path = VALUES(camera_local_path),
            preview_storage_path = COALESCE(VALUES(preview_storage_path), preview_storage_path),
            original_storage_path = COALESCE(VALUES(original_storage_path), original_storage_path),
            preview_size_bytes = COALESCE(VALUES(preview_size_bytes), preview_size_bytes),
            original_size_bytes = COALESCE(VALUES(original_size_bytes), original_size_bytes),
            upload_state = 'UPLOADED',
            updated_at = NOW(6)
    ");
    if (!$stmt) api_json(['ok' => false, 'error' => 'db photo insert prepare error: ' . $dbcnx->error], 500);
    $roomNameVal = $roomName !== '' ? $roomName : null;
    $sequenceVal = $sequenceNumber > 0 ? $sequenceNumber : null;
    $cameraFileUrlVal = $cameraFileUrl !== '' ? $cameraFileUrl : null;
    $cameraLocalPathVal = $cameraLocalPath !== '' ? $cameraLocalPath : null;
    $stmt->bind_param("isssissssii", $captureSessionId, $appPointUuid, $pointName, $roomNameVal, $sequenceVal, $cameraFileUrlVal, $cameraLocalPathVal, $previewStoragePath, $originalStoragePath, $previewSizeBytes, $originalSizeBytes);
    if (!$stmt->execute()) api_json(['ok' => false, 'error' => 'db photo insert execute error: ' . $stmt->error], 500);
    $stmt->close();

    audit_log($userId, 'PHOTO_UPLOADED', 'TOUR_ORDER', $orderId, 'Загружен photo point', api_request_meta(['capture_session_id'=>$captureSessionId, 'app_point_uuid'=>$appPointUuid, 'preview_storage_path'=>$previewStoragePath, 'original_storage_path'=>$originalStoragePath]));
    api_json(['ok'=>true,'preview_storage_path'=>$previewStoragePath,'original_storage_path'=>$originalStoragePath,'preview_size_bytes'=>$previewSizeBytes,'original_size_bytes'=>$originalSizeBytes]);
}

if ($action === 'ping') {
    $user = api_require_mobile_user($dbcnx);
    $userId = (int)$user['id'];

    audit_log(
        $userId,
        'MOBILE_APP_OPEN',
        'USER',
        $userId,
        'Открытие мобильного приложения',
        api_request_meta([])
    );

    api_json([
        'ok' => true,
        'user' => [
            'id' => $userId,
            'username' => $user['username'],
            'email' => $user['email'],
            'full_name' => $user['full_name'],
            'role' => $user['role'],
        ],
    ]);
}

if ($action === 'logout') {
    $token = api_get_bearer_token();

    if (!$token) {
        api_json(['ok' => true]);
    }

    $hash = hash('sha256', $token);

    $stmt = $dbcnx->prepare("
        SELECT mt.user_id, u.username, u.email
        FROM mobile_tokens mt
        JOIN users u ON u.id = mt.user_id
        WHERE mt.token_hash = ?
        LIMIT 1
    ");

    $userId = null;

    if ($stmt) {
        $stmt->bind_param("s", $hash);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        if ($row) {
            $userId = (int)$row['user_id'];
        }
    }

    if ($userId !== null) {
        audit_log(
            $userId,
            'MOBILE_LOGOUT',
            'USER',
            $userId,
            'Выход из мобильного приложения',
            api_request_meta([
                'token_hash_prefix' => substr($hash, 0, 12),
            ])
        );
    } else {
        api_audit_mobile_token_invalid($dbcnx, 'logout_token_not_found');
    }

    $stmt = $dbcnx->prepare("
        DELETE FROM mobile_tokens
        WHERE token_hash = ?
    ");

    if ($stmt) {
        $stmt->bind_param("s", $hash);
        $stmt->execute();
        $stmt->close();
    }

    api_json(['ok' => true]);
}

api_json(['ok' => false, 'error' => 'unknown action'], 404);
