<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
auth_require_login();

$user = auth_current_user();
$userId = (int)$user['id'];
$role = (string)($user['role'] ?? 'BROKER');

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

$orderIdRaw = $_GET['order_id'] ?? null;
if (!is_string($orderIdRaw) && !is_int($orderIdRaw)) {
    api_json(['ok' => false, 'error' => 'bad_order_id'], 400);
}
$orderId = filter_var((string)$orderIdRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($orderId === false) {
    api_json(['ok' => false, 'error' => 'bad_order_id'], 400);
}
$orderId = (int)$orderId;

$sessionId = null;
$sessionDir = null;
if (isset($_GET['session_id']) && $_GET['session_id'] !== '') {
    $sid = filter_var((string)$_GET['session_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($sid === false) {
        api_json(['ok' => false, 'error' => 'bad_session_id'], 400);
    }
    $sessionId = (int)$sid;
}
if (isset($_GET['session_dir']) && $_GET['session_dir'] !== '') {
    $sessionDirCandidate = (string)$_GET['session_dir'];
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $sessionDirCandidate)) {
        api_json(['ok' => false, 'error' => 'bad_session_dir'], 400);
    }
    $sessionDir = $sessionDirCandidate;
}
if ($sessionId === null && $sessionDir === null) {
    api_json(['ok' => false, 'error' => 'session_id_or_session_dir_required'], 400);
}

$stmt = $dbcnx->prepare('SELECT id, broker_id, operator_id, is_published, status FROM tour_orders WHERE id = ? LIMIT 1');
if (!$stmt) {
    api_json(['ok' => false, 'error' => 'db_prepare_order_failed'], 500);
}
$stmt->bind_param('i', $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$order) {
    api_json(['ok' => false, 'error' => 'order_not_found'], 404);
}
if (!can_view_order($order, $userId, $role)) {
    api_json(['ok' => false, 'error' => 'forbidden'], 403);
}

if ($sessionId !== null) {
    $stmt = $dbcnx->prepare('SELECT * FROM video_sfm_runs WHERE order_id = ? AND session_id = ? ORDER BY id DESC LIMIT 1');
    if (!$stmt) {
        api_json(['ok' => false, 'error' => 'db_prepare_run_failed'], 500);
    }
    $stmt->bind_param('ii', $orderId, $sessionId);
} else {
    $stmt = $dbcnx->prepare('SELECT * FROM video_sfm_runs WHERE order_id = ? AND session_dir = ? ORDER BY id DESC LIMIT 1');
    if (!$stmt) {
        api_json(['ok' => false, 'error' => 'db_prepare_run_failed'], 500);
    }
    $stmt->bind_param('is', $orderId, $sessionDir);
}
$stmt->execute();
$run = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$run) {
    api_json(['ok' => false, 'error' => 'SfM run not found'], 404);
}

$runSessionDir = trim((string)($run['session_dir'] ?? ''));
if ($runSessionDir === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $runSessionDir)) {
    api_json(['ok' => false, 'error' => 'invalid session_dir in run'], 500);
}

$storageRoot = '/home/makler/web/storage/orders';
$realStorageRoot = realpath($storageRoot);
if ($realStorageRoot === false) {
    api_json(['ok' => false, 'error' => 'storage root missing'], 500);
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
    api_json([
        'ok' => false,
        'error' => 'session path missing',
        'debug' => [
            'session_dir' => $runSessionDir,
            'checked' => $candidateSessionBases,
        ],
    ], 404);
}

$runSessionDir = basename($realSessionBase);

$summaryPath = $realSessionBase . '/sfm/sfm_result_summary.json';
$keyframeLinksPath = $realSessionBase . '/sfm/keyframe_links.jsonl';
$cameraTrajectoryPath = $realSessionBase . '/sfm/trajectory/trajectory_scaled.json';
if (!is_file($summaryPath)) {
    api_json(['ok' => false, 'error' => 'summary file missing'], 404);
}
if (!is_file($keyframeLinksPath)) {
    api_json(['ok' => false, 'error' => 'keyframe_links file missing'], 404);
}
if (!is_file($cameraTrajectoryPath)) {
    api_json(['ok' => false, 'error' => 'camera_trajectory file missing'], 404);
}

$summaryJson = file_get_contents($summaryPath);
if ($summaryJson === false) {
    api_json(['ok' => false, 'error' => 'summary file unreadable'], 500);
}
$summary = json_decode($summaryJson, true);
if (!is_array($summary)) {
    api_json(['ok' => false, 'error' => 'summary file invalid'], 500);
}

$trajectory = [];
$lines = file($keyframeLinksPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($lines === false) {
    api_json(['ok' => false, 'error' => 'keyframe_links file unreadable'], 500);
}
foreach ($lines as $line) {
    $row = json_decode($line, true);
    if (!is_array($row)) {
        continue;
    }
    $kfName = (string)($row['keyframe_name'] ?? '');
    if ($kfName !== '' && !preg_match('/^keyframe_[0-9]{6}\.jpg$/', $kfName)) {
        $kfName = '';
    }
    $previewUrl = '';
    if ($kfName !== '') {
        $effectiveSessionId = isset($run['session_id']) ? (int)$run['session_id'] : 0;
        if ($effectiveSessionId > 0) {
            $previewUrl = '/api/sfm_keyframe_image.php?order_id=' . $orderId
                . '&session_id=' . $effectiveSessionId
                . '&keyframe=' . rawurlencode($kfName);
        } else {
            $previewUrl = '/api/sfm_keyframe_image.php?order_id=' . $orderId
                . '&session_dir=' . rawurlencode($runSessionDir)
                . '&keyframe=' . rawurlencode($kfName);
        }
    }
    $trajectory[] = [
        'keyframe_index' => isset($row['keyframe_index']) ? (int)$row['keyframe_index'] : null,
        'keyframe_name' => $row['keyframe_name'] ?? null,
        'target_frame_index' => isset($row['target_frame_index']) ? (int)$row['target_frame_index'] : null,
        'nearest_frame_index' => isset($row['nearest_frame_index']) ? (int)$row['nearest_frame_index'] : null,
        'nearest_frame_name' => $row['nearest_frame_name'] ?? null,
        'frame_delta' => isset($row['frame_delta']) ? (int)$row['frame_delta'] : null,
        'x_scaled' => isset($row['x_scaled']) ? (float)$row['x_scaled'] : null,
        'y_scaled' => isset($row['y_scaled']) ? (float)$row['y_scaled'] : null,
        'z_scaled' => isset($row['z_scaled']) ? (float)$row['z_scaled'] : null,
        'distance_from_prev_m' => isset($row['distance_from_prev_m']) ? (float)$row['distance_from_prev_m'] : null,
        'segment_break' => isset($row['segment_break']) ? (bool)$row['segment_break'] : false,
        'preview_url' => $previewUrl,
    ];
}


$cameraTrajectoryJson = file_get_contents($cameraTrajectoryPath);
if ($cameraTrajectoryJson === false) {
    api_json(['ok' => false, 'error' => 'camera_trajectory file unreadable'], 500);
}
$cameraTrajectoryRaw = json_decode($cameraTrajectoryJson, true);
if (!is_array($cameraTrajectoryRaw)) {
    api_json(['ok' => false, 'error' => 'camera_trajectory file invalid'], 500);
}

$cameraTrajectoryRows = [];
if (isset($cameraTrajectoryRaw['trajectory_scaled']) && is_array($cameraTrajectoryRaw['trajectory_scaled'])) {
    $cameraTrajectoryRows = $cameraTrajectoryRaw['trajectory_scaled'];
} elseif (array_is_list($cameraTrajectoryRaw)) {
    $cameraTrajectoryRows = $cameraTrajectoryRaw;
}

$cameraTrajectory = [];
$yMin = null;
$yMax = null;
foreach ($cameraTrajectoryRows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $x = isset($row['x_scaled']) ? (float)$row['x_scaled'] : null;
    $y = isset($row['y_scaled']) ? (float)$row['y_scaled'] : null;
    $z = isset($row['z_scaled']) ? (float)$row['z_scaled'] : null;
    if (!is_finite((float)$x) || !is_finite((float)$y) || !is_finite((float)$z)) {
        continue;
    }
    $cameraTrajectory[] = [
        'frame_name' => isset($row['frame_name']) ? (string)$row['frame_name'] : null,
        'frame_index' => isset($row['frame_index']) ? (int)$row['frame_index'] : null,
        'x_scaled' => $x,
        'y_scaled' => $y,
        'z_scaled' => $z,
    ];
    $yMin = $yMin === null ? $y : min($yMin, $y);
    $yMax = $yMax === null ? $y : max($yMax, $y);
}
$yRange = ($yMin !== null && $yMax !== null) ? ($yMax - $yMin) : null;

$keyframePoints = [];
$hasSfmKeyframeTable = false;
$check = $dbcnx->query("SHOW TABLES LIKE 'sfm_keyframe_points'");
if ($check instanceof mysqli_result && $check->num_rows > 0) {
    $hasSfmKeyframeTable = true;
}
if ($check instanceof mysqli_result) {
    $check->free();
}

if ($hasSfmKeyframeTable) {
    $stmt = $dbcnx->prepare('SELECT id, keyframe_index, keyframe_name, nearest_frame_name, x_scaled, y_scaled, z_scaled, segment_break FROM sfm_keyframe_points WHERE order_id = ? AND session_id = ? AND is_active = 1 ORDER BY keyframe_index ASC');
    if ($stmt) {
        $effectiveSessionId = isset($run['session_id']) ? (int)$run['session_id'] : 0;
        $stmt->bind_param('ii', $orderId, $effectiveSessionId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $kfName = (string)($row['keyframe_name'] ?? '');
            $previewUrl = '';
            if ($kfName !== '') {
                if ($effectiveSessionId > 0) {
                    $previewUrl = '/api/sfm_keyframe_image.php?order_id=' . $orderId
                        . '&session_id=' . $effectiveSessionId
                        . '&keyframe=' . rawurlencode($kfName);
                } else {
                    $previewUrl = '/api/sfm_keyframe_image.php?order_id=' . $orderId
                        . '&session_dir=' . rawurlencode($runSessionDir)
                        . '&keyframe=' . rawurlencode($kfName);
                }
            }
            $keyframePoints[] = [
                'id' => isset($row['id']) ? (int)$row['id'] : null,
                'keyframe_index' => isset($row['keyframe_index']) ? (int)$row['keyframe_index'] : null,
                'keyframe_name' => $row['keyframe_name'] ?? null,
                'nearest_frame_name' => $row['nearest_frame_name'] ?? null,
                'preview_url' => $previewUrl,
                'x_scaled' => isset($row['x_scaled']) ? (float)$row['x_scaled'] : null,
                'y_scaled' => isset($row['y_scaled']) ? (float)$row['y_scaled'] : null,
                'z_scaled' => isset($row['z_scaled']) ? (float)$row['z_scaled'] : null,
                'segment_break' => isset($row['segment_break']) ? ((int)$row['segment_break'] === 1) : false,
            ];
        }
        $stmt->close();
    }
}

$respRun = [
    'id' => isset($run['id']) ? (int)$run['id'] : null,
    'order_id' => (int)$run['order_id'],
    'session_id' => isset($run['session_id']) ? (int)$run['session_id'] : null,
    'session_dir' => $runSessionDir,
    'status' => (string)($run['status'] ?? ''),
    'metric_status' => (string)($run['metric_status'] ?? ''),
    'frames_count' => isset($run['frames_count']) ? (int)$run['frames_count'] : null,
    'keyframes_count' => isset($run['keyframes_count']) ? (int)$run['keyframes_count'] : null,
    'marker_count' => isset($run['marker_count']) ? (int)$run['marker_count'] : null,
    'poses_count' => isset($run['poses_count']) ? (int)$run['poses_count'] : null,
    'scale_ok' => isset($run['scale_ok']) ? ((int)$run['scale_ok'] === 1) : null,
    'scale_factor' => isset($run['scale_factor']) ? (float)$run['scale_factor'] : null,
    'scale_samples' => isset($run['scale_samples']) ? (int)$run['scale_samples'] : null,
];

$respSummary = [
    'frames_count' => isset($summary['frames_count']) ? (int)$summary['frames_count'] : null,
    'keyframes_count' => isset($summary['keyframes_count']) ? (int)$summary['keyframes_count'] : null,
    'marker_count' => isset($summary['marker_count']) ? (int)$summary['marker_count'] : null,
    'poses_count' => isset($summary['poses_count']) ? (int)$summary['poses_count'] : null,
    'scale_ok' => isset($summary['scale_ok']) ? (bool)$summary['scale_ok'] : null,
    'scale_factor' => isset($summary['scale_factor']) ? (float)$summary['scale_factor'] : null,
    'scale_samples' => isset($summary['scale_samples']) ? (int)$summary['scale_samples'] : null,
    'keyframe_links_count' => count($trajectory),
    'status' => (string)($summary['status'] ?? ($run['status'] ?? '')),
    'metric_status' => (string)($summary['metric_status'] ?? ($run['metric_status'] ?? '')),
    'trajectory_path_length_m' => isset($summary['trajectory_path_length_m']) ? (float)$summary['trajectory_path_length_m'] : null,
    'trajectory_max_jump_m' => isset($summary['trajectory_max_jump_m']) ? (float)$summary['trajectory_max_jump_m'] : null,
    'trajectory_segment_breaks' => isset($summary['trajectory_segment_breaks']) ? (int)$summary['trajectory_segment_breaks'] : null,
    'camera_trajectory_count' => count($cameraTrajectory),
    'y_min' => $yMin,
    'y_max' => $yMax,
    'y_range_m' => $yRange,
];

api_json([
    'ok' => true,
    'run' => $respRun,
    'summary' => $respSummary,
    'trajectory' => $trajectory,
    'camera_trajectory' => $cameraTrajectory,
    'keyframe_points' => $keyframePoints,
]);
