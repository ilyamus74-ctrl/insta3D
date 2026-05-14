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

$stmt = $dbcnx->prepare("SELECT cs.*,o.id AS order_id,o.title AS order_title,o.address AS order_address,o.broker_id,o.operator_id,o.is_published,o.status AS order_status FROM capture_sessions cs JOIN tour_orders o ON o.id = cs.order_id WHERE cs.id = ? LIMIT 1");
if (!$stmt) api_json(['ok'=>false,'error'=>'db_prepare_session_failed'],500);

$stmt->bind_param('i', $sessionId);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$session) api_json(['ok'=>false,'error'=>'session_not_found'],404);

$orderForAccess = ['id'=>$session['order_id'],'broker_id'=>$session['broker_id'],'operator_id'=>$session['operator_id'],'is_published'=>$session['is_published'],'status'=>$session['order_status']];
if (!can_view_order($orderForAccess, $userId, $role)) api_json(['ok'=>false,'error'=>'forbidden'],403);

$photoPoints = [];

$stmt = $dbcnx->prepare("SELECT id,session_id,app_point_uuid,name,room_name,sequence_number,preview_storage_path,original_storage_path,preview_size_bytes,original_size_bytes,upload_state,initial_yaw_deg,initial_pitch_deg,initial_hfov,created_at FROM photo_points WHERE session_id = ? ORDER BY COALESCE(sequence_number, 999999) ASC, created_at ASC, id ASC");
if (!$stmt) api_json(['ok'=>false,'error'=>'db_prepare_photo_points_failed'],500);

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

$photoPointIds = array_map(static fn(array $pp): int => (int)$pp['id'], $photoPoints);
$photoPointSet = array_fill_keys($photoPointIds, true);
$markerMap = [];
$markerCount = [];
$markerConfSum = [];
$stmt = $dbcnx->prepare("SELECT source_id, marker_id, confidence FROM marker_detections WHERE session_id = ? AND source_type = 'PHOTO_POINT'");
if ($stmt) {
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($row = $rs->fetch_assoc()) {
        $pid = (int)$row['source_id'];
        if (!isset($photoPointSet[$pid])) continue;
        $mid = (int)$row['marker_id'];
        $markerMap[$pid][$mid] = true;
        $markerCount[$pid] = (int)($markerCount[$pid] ?? 0) + 1;
        $markerConfSum[$pid] = (float)($markerConfSum[$pid] ?? 0.0) + (float)$row['confidence'];
    }
    $stmt->close();
}
foreach ($photoPoints as &$pp) {
    $pid = (int)$pp['id'];
    $markers = isset($markerMap[$pid]) ? array_map('intval', array_keys($markerMap[$pid])) : [];
    sort($markers);
    $pp['markers'] = $markers;
    $pp['marker_labels'] = array_map(static fn(int $id): string => 'MT-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT), $markers);
    $pp['marker_detections_count'] = (int)($markerCount[$pid] ?? 0);
    $pp['avg_marker_confidence'] = ($pp['marker_detections_count'] > 0) ? round(((float)($markerConfSum[$pid] ?? 0.0) / $pp['marker_detections_count']), 2) : 0.0;
}
unset($pp);

$links = [];
$stmt = $dbcnx->prepare("SELECT id, from_photo_point_id, to_photo_point_id, yaw_deg, pitch_deg, label FROM tour_point_links WHERE session_id = ? ORDER BY id ASC");

if ($stmt) {
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($row = $rs->fetch_assoc()) {
        $links[] = [
            'id' => (int)$row['id'],
            'from_photo_point_id' => (int)$row['from_photo_point_id'],
            'to_photo_point_id' => (int)$row['to_photo_point_id'],
            'yaw_deg' => isset($row['yaw_deg']) ? (float)$row['yaw_deg'] : 0.0,
            'pitch_deg' => isset($row['pitch_deg']) ? (float)$row['pitch_deg'] : 0.0,
            'label' => (string)($row['label'] ?? ''),
        ];
    }
    $stmt->close();
}

$positions = [];
$stmt = $dbcnx->prepare("SELECT photo_point_id, x_m, y_m, z_m, yaw_deg, source FROM tour_point_positions WHERE session_id = ?");

if ($stmt) {
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($row = $rs->fetch_assoc()) {
        $photoPointId = (int)$row['photo_point_id'];
        $positions[(string)$photoPointId] = [
            'photo_point_id' => $photoPointId,
            'x_m' => isset($row['x_m']) ? (float)$row['x_m'] : 0.0,
            'y_m' => isset($row['y_m']) ? (float)$row['y_m'] : 0.0,
            'z_m' => isset($row['z_m']) ? (float)$row['z_m'] : 0.0,
            'yaw_deg' => isset($row['yaw_deg']) ? (float)$row['yaw_deg'] : 0.0,
            'source' => (string)($row['source'] ?? 'UNKNOWN'),
        ];
    }
    $stmt->close();
}
$job = null; $markerIds = []; $sourceCounts = ['PHOTO_POINT'=>0,'VIDEO_FRAME'=>0];
$stmt = $dbcnx->prepare("SELECT id,status,metric_status,marker_kit_id,marker_dictionary,marker_size_m,markers_detected_count,warning_text,error_text,updated_at FROM processing_jobs WHERE session_id = ? AND job_type = 'MARKER_DETECTION' ORDER BY id DESC LIMIT 1");
if ($stmt) { $stmt->bind_param('i',$sessionId); $stmt->execute(); $job = $stmt->get_result()->fetch_assoc() ?: null; $stmt->close(); }
$stmt = $dbcnx->prepare("SELECT DISTINCT marker_id FROM marker_detections WHERE session_id = ? ORDER BY marker_id ASC");
if ($stmt) { $stmt->bind_param('i',$sessionId); $stmt->execute(); $rs=$stmt->get_result(); while($row=$rs->fetch_assoc()) $markerIds[]=(int)$row['marker_id']; $stmt->close(); }
$stmt = $dbcnx->prepare("SELECT source_type, COUNT(*) AS cnt FROM marker_detections WHERE session_id = ? GROUP BY source_type");
if ($stmt) { $stmt->bind_param('i',$sessionId); $stmt->execute(); $rs=$stmt->get_result(); while($row=$rs->fetch_assoc()) $sourceCounts[(string)$row['source_type']] = (int)$row['cnt']; $stmt->close(); }
$labels = array_map(static fn(int $id): string => 'MT-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT), $markerIds);

$layoutDefinedCount = 0;
$detectedCovered = 0;
$missingLayoutMarkerIds = [];
$layoutSet = [];
$stmt = $dbcnx->prepare("SELECT marker_id FROM marker_kit_layout WHERE marker_kit_id = 'maklertour_kit_v1' AND marker_dictionary = 'APRILTAG_36H11'");
if ($stmt) {
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($row = $rs->fetch_assoc()) {
        $mid = (int)$row['marker_id'];
        $layoutSet[$mid] = true;
    }
    $stmt->close();
}
$layoutDefinedCount = count($layoutSet);
foreach ($markerIds as $mid) {
    if (isset($layoutSet[$mid])) {
        $detectedCovered++;
    } else {
        $missingLayoutMarkerIds[] = $mid;
    }
}

$markerLayoutSummary = [
    'marker_kit_id' => 'maklertour_kit_v1',
    'marker_dictionary' => 'APRILTAG_36H11',
    'defined_markers_count' => $layoutDefinedCount,
    'detected_markers_with_layout_count' => $detectedCovered,
    'missing_layout_marker_ids' => $missingLayoutMarkerIds,
];

$manualPositionsCount = 0; $markerCovPositionsCount = 0; $noMarkerPositionsCount = 0; $autoPositionsCount = 0;
foreach ($positions as $pos) {
    $src = (string)($pos['source'] ?? 'UNKNOWN');
    if ($src === 'MANUAL') $manualPositionsCount++;
    if (in_array($src, ['MARKER_COVISIBILITY', 'MARKER_SEQUENCE_COVISIBILITY'], true)) $markerCovPositionsCount++;
    if (in_array($src, ['AUTO_COVISIBILITY_NO_MARKERS', 'AUTO_SEQUENCE_NO_MARKERS'], true)) $noMarkerPositionsCount++;
    if (in_array($src, ['MARKER_COVISIBILITY', 'AUTO_COVISIBILITY_NO_MARKERS', 'MARKER_SEQUENCE_COVISIBILITY', 'AUTO_SEQUENCE_NO_MARKERS'], true)) $autoPositionsCount++;
}
$algorithm = ($markerCovPositionsCount + $noMarkerPositionsCount) > 0 ? 'MARKER_SEQUENCE_COVISIBILITY_V1' : 'NONE';
$autoMapInfo = [
    'algorithm' => $algorithm,
    'has_auto_positions' => ($markerCovPositionsCount + $noMarkerPositionsCount) > 0,
    'manual_positions_count' => $manualPositionsCount,
    'auto_positions_count' => $autoPositionsCount,
    'marker_cov_positions_count' => $markerCovPositionsCount,
    'no_marker_positions_count' => $noMarkerPositionsCount,
];

api_json(['ok'=>true,'session'=>['id'=>(int)$session['id'],'order_id'=>(int)$session['order_id'],'app_session_uuid'=>$session['app_session_uuid'],'camera_model'=>$session['camera_model'],'status'=>$session['status'],'order_title'=>$session['order_title'],'order_address'=>$session['order_address']], 'processing'=>['job_id'=>$job?(int)$job['id']:null,'status'=>$job['status']??'NOT_CREATED','metric_status'=>$job['metric_status']??'UNKNOWN','marker_kit_id'=>$job['marker_kit_id']??'maklertour_kit_v1','marker_dictionary'=>$job['marker_dictionary']??'APRILTAG_36H11','marker_size_m'=>isset($job['marker_size_m'])?(float)$job['marker_size_m']:0.160,'markers_detected_count'=>isset($job['markers_detected_count'])?(int)$job['markers_detected_count']:0,'warning_text'=>$job['warning_text']??null,'error_text'=>$job['error_text']??null,'updated_at'=>$job['updated_at']??null], 'markers'=>['unique_ids'=>$markerIds,'labels'=>$labels,'source_counts'=>$sourceCounts], 'marker_layout_summary'=>$markerLayoutSummary, 'photo_points'=>$photoPoints, 'links'=>$links, 'positions'=>$positions, 'auto_map_info'=>$autoMapInfo]);

