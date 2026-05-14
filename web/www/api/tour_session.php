<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

auth_require_login();

$user = auth_current_user();
$userId = (int)$user['id'];
$role = $user['role'] ?? 'BROKER';

function api_json(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

function media_url(?string $path): string {
    $path = trim((string)$path);
    if ($path === '') {
        return '';
    }
    return '/media.php?path=' . rawurlencode($path);
}

function viewer_panorama_path(?string $originalPath): string {
    $originalPath = trim((string)$originalPath);
    if ($originalPath === '') {
        return '';
    }

    $viewerPath = str_replace('/photos/originals/', '/photos/viewer/', $originalPath);

    if ($viewerPath !== $originalPath) {
        $full = APP_STORAGE_DIR . '/' . ltrim($viewerPath, '/');
        if (is_file($full)) {
            return $viewerPath;
        }
    }

    return $originalPath;
}

$sessionId = (int)($_GET['session_id'] ?? 0);
if ($sessionId <= 0) {
    api_json(['ok' => false, 'error' => 'bad_session_id'], 400);
}

$stmt = $dbcnx->prepare("
    SELECT
        cs.*,
        o.id AS order_id,
        o.title AS order_title,
        o.address AS order_address,
        o.broker_id,
        o.operator_id,
        o.is_published,
        o.status AS order_status
    FROM capture_sessions cs
    JOIN tour_orders o ON o.id = cs.order_id
    WHERE cs.id = ?
    LIMIT 1
");
if (!$stmt) {
    api_json(['ok' => false, 'error' => 'db_prepare_session_failed'], 500);
}

$stmt->bind_param('i', $sessionId);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$session) {
    api_json(['ok' => false, 'error' => 'session_not_found'], 404);
}

$orderForAccess = [
    'id' => $session['order_id'],
    'broker_id' => $session['broker_id'],
    'operator_id' => $session['operator_id'],
    'is_published' => $session['is_published'],
    'status' => $session['order_status'],
];

if (!can_view_order($orderForAccess, $userId, $role)) {
    api_json(['ok' => false, 'error' => 'forbidden'], 403);
}

$photoPoints = [];

$stmt = $dbcnx->prepare("
    SELECT
        id,
        session_id,
        app_point_uuid,
        name,
        room_name,
        sequence_number,
        preview_storage_path,
        original_storage_path,
        preview_size_bytes,
        original_size_bytes,
        upload_state,
        initial_yaw_deg,
        initial_pitch_deg,
        initial_hfov,
        created_at
    FROM photo_points
    WHERE session_id = ?
    ORDER BY
        COALESCE(sequence_number, 999999) ASC,
        created_at ASC,
        id ASC
");
if (!$stmt) {
    api_json(['ok' => false, 'error' => 'db_prepare_photo_points_failed'], 500);
}

$stmt->bind_param('i', $sessionId);
$stmt->execute();
$rs = $stmt->get_result();

while ($p = $rs->fetch_assoc()) {
    $photoPoints[] = [
        'id' => (int)$p['id'],
        'name' => (string)($p['name'] ?: ('Point #' . $p['id'])),
        'room_name' => $p['room_name'],
        'sequence_number' => $p['sequence_number'] !== null ? (int)$p['sequence_number'] : null,
        'upload_state' => $p['upload_state'],
        'preview_url' => media_url($p['preview_storage_path'] ?? ''),
        'panorama_url' => media_url(viewer_panorama_path($p['original_storage_path'] ?? '')),
        'original_url' => media_url($p['original_storage_path'] ?? ''),
        'preview_size_bytes' => $p['preview_size_bytes'] !== null ? (int)$p['preview_size_bytes'] : null,
        'original_size_bytes' => $p['original_size_bytes'] !== null ? (int)$p['original_size_bytes'] : null,
        'initial_yaw_deg' => isset($p['initial_yaw_deg']) ? (float)$p['initial_yaw_deg'] : 0.0,
        'initial_pitch_deg' => isset($p['initial_pitch_deg']) ? (float)$p['initial_pitch_deg'] : 0.0,
        'initial_hfov' => isset($p['initial_hfov']) ? (float)$p['initial_hfov'] : 100.0,
    ];
}
$stmt->close();

$job = null;

$stmt = $dbcnx->prepare("
    SELECT
        id,
        status,
        metric_status,
        marker_kit_id,
        marker_dictionary,
        marker_size_m,
        markers_detected_count,
        warning_text,
        error_text,
        updated_at
    FROM processing_jobs
    WHERE session_id = ?
      AND job_type = 'MARKER_DETECTION'
    ORDER BY id DESC
    LIMIT 1
");
if ($stmt) {
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $job = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

$markerIds = [];
$stmt = $dbcnx->prepare("
    SELECT DISTINCT marker_id
    FROM marker_detections
    WHERE session_id = ?
    ORDER BY marker_id ASC
");
if ($stmt) {
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($row = $rs->fetch_assoc()) {
        $markerIds[] = (int)$row['marker_id'];
    }
    $stmt->close();
}

$sourceCounts = [
    'PHOTO_POINT' => 0,
    'VIDEO_FRAME' => 0,
];

$stmt = $dbcnx->prepare("
    SELECT source_type, COUNT(*) AS cnt
    FROM marker_detections
    WHERE session_id = ?
    GROUP BY source_type
");
if ($stmt) {
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($row = $rs->fetch_assoc()) {
        $sourceCounts[(string)$row['source_type']] = (int)$row['cnt'];
    }
    $stmt->close();
}

$labels = array_map(
    static fn(int $id): string => 'MT-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT),
    $markerIds
);

api_json([
    'ok' => true,
    'session' => [
        'id' => (int)$session['id'],
        'order_id' => (int)$session['order_id'],
        'app_session_uuid' => $session['app_session_uuid'],
        'camera_model' => $session['camera_model'],
        'status' => $session['status'],
        'order_title' => $session['order_title'],
        'order_address' => $session['order_address'],
    ],
    'processing' => [
        'job_id' => $job ? (int)$job['id'] : null,
        'status' => $job['status'] ?? 'NOT_CREATED',
        'metric_status' => $job['metric_status'] ?? 'UNKNOWN',
        'marker_kit_id' => $job['marker_kit_id'] ?? 'maklertour_kit_v1',
        'marker_dictionary' => $job['marker_dictionary'] ?? 'APRILTAG_36H11',
        'marker_size_m' => isset($job['marker_size_m']) ? (float)$job['marker_size_m'] : 0.160,
        'markers_detected_count' => isset($job['markers_detected_count']) ? (int)$job['markers_detected_count'] : 0,
        'warning_text' => $job['warning_text'] ?? null,
        'error_text' => $job['error_text'] ?? null,
        'updated_at' => $job['updated_at'] ?? null,
    ],
    'markers' => [
        'unique_ids' => $markerIds,
        'labels' => $labels,
        'source_counts' => $sourceCounts,
    ],
    'photo_points' => $photoPoints,
]);
