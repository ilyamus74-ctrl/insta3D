<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
auth_require_login();

$user = auth_current_user();
$userId = (int)$user['id'];
$role = (string)($user['role'] ?? 'BROKER');

function svp_json(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function svp_can_view_order(array $order, int $userId, string $role): bool {
    return $role === 'ADMIN' || ((int)$order['broker_id'] === $userId) || ($role === 'OPERATOR' && ((int)$order['operator_id'] === $userId || ((int)$order['is_published'] === 1 && (string)$order['status'] === 'NEW' && $order['operator_id'] === null)));
}
function svp_int(mixed $v, string $name): int {
    $x = filter_var((string)$v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($x === false) svp_json(['ok'=>false,'error'=>'bad_'.$name], 400);
    return (int)$x;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = [];
if ($method === 'POST') {
    $raw = (string)file_get_contents('php://input');
    $json = json_decode($raw, true);
    $input = is_array($json) ? $json : $_POST;
} else {
    $input = $_GET;
}

$orderId = svp_int($input['order_id'] ?? null, 'order_id');
$sessionId = svp_int($input['session_id'] ?? null, 'session_id');

$stmt = $dbcnx->prepare('SELECT id, broker_id, operator_id, is_published, status FROM tour_orders WHERE id=? LIMIT 1');
$stmt->bind_param('i', $orderId); $stmt->execute(); $order = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$order) svp_json(['ok'=>false,'error'=>'order_not_found'],404);
if (!svp_can_view_order($order, $userId, $role)) svp_json(['ok'=>false,'error'=>'forbidden'],403);

$stmt = $dbcnx->prepare('SELECT id, app_session_uuid FROM capture_sessions WHERE id=? AND order_id=? AND deleted_at IS NULL LIMIT 1');
$stmt->bind_param('ii', $sessionId, $orderId); $stmt->execute(); $session = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$session) svp_json(['ok'=>false,'error'=>'session_not_found'],404);

if ($method === 'GET') {
    $stmt = $dbcnx->prepare("SELECT id,job_type,status,metric_status,markers_detected_count,warning_text,error_text,created_at,updated_at FROM processing_jobs WHERE order_id=? AND session_id=? AND job_type='SFM_VIDEO_PIPELINE' ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('ii', $orderId, $sessionId); $stmt->execute(); $job = $stmt->get_result()->fetch_assoc(); $stmt->close();

    $stmt = $dbcnx->prepare('SELECT * FROM video_sfm_runs WHERE order_id=? AND session_id=? ORDER BY id DESC LIMIT 1');
    $stmt->bind_param('ii', $orderId, $sessionId); $stmt->execute(); $run = $stmt->get_result()->fetch_assoc(); $stmt->close();

    $sessionDir = (string)($run['session_dir'] ?? $session['app_session_uuid'] ?? '');
    $base = '/home/makler/web/storage/orders/'.$orderId.'/sessions/'.$sessionDir.'/sfm';
    $artifacts = [
        'sfm_summary' => is_file($base.'/sfm_result_summary.json'),
        'viewer_keyframes' => is_dir($base.'/viewer_keyframes') && (bool)glob($base.'/viewer_keyframes/*.jpg'),
        'sparse_3d' => is_file($base.'/3d/sfm_3d_summary.json'),
    ];

    svp_json([
        'ok'=>true,'order_id'=>$orderId,'session_id'=>$sessionId,
        'latest_job'=>$job ?: null,
        'latest_run'=>$run ? [
            'id'=>(int)$run['id'],'status'=>(string)($run['status'] ?? ''),'metric_status'=>(string)($run['metric_status'] ?? ''),
            'frames_count'=>(int)($run['frames_count'] ?? 0),'keyframes_count'=>(int)($run['keyframes_count'] ?? 0),'marker_count'=>(int)($run['marker_count'] ?? 0),'poses_count'=>(int)($run['poses_count'] ?? 0),
            'scale_ok'=>(bool)($run['scale_ok'] ?? false),'scale_factor'=>isset($run['scale_factor'])?(float)$run['scale_factor']:null,'scale_samples'=>(int)($run['scale_samples'] ?? 0),
        ] : null,
        'artifacts'=>$artifacts,
    ]);
}

$cameraType = strtoupper(trim((string)($input['camera_type'] ?? 'INSTA360_DUAL_VIDEO')));
if (!in_array($cameraType, ['INSTA360_DUAL_VIDEO','PHONE_VIDEO'], true)) svp_json(['ok'=>false,'error'=>'bad_camera_type'],400);

$storageRoot = '/home/makler/web/storage/orders/'.$orderId.'/sessions';
$realRoot = realpath($storageRoot);
if ($realRoot === false) svp_json(['ok'=>false,'error'=>'storage_root_missing'],500);
$videoPath = trim((string)($input['video_path'] ?? ''));
if ($videoPath === '') {
    $stmt = $dbcnx->prepare('SELECT storage_path FROM video_scans WHERE order_id=? AND session_id=? AND deleted_at IS NULL AND COALESCE(upload_state,"") <> "DELETED" ORDER BY id DESC LIMIT 1');
    $stmt->bind_param('ii', $orderId, $sessionId); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($row && !empty($row['storage_path'])) $videoPath = '/home/makler/web/storage/'.ltrim((string)$row['storage_path'],'/');
}
if ($videoPath === '') {
    $stmt = $dbcnx->prepare('SELECT video_path FROM video_sfm_runs WHERE order_id=? AND session_id=? ORDER BY id DESC LIMIT 1');
    $stmt->bind_param('ii', $orderId, $sessionId); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    $videoPath = (string)($row['video_path'] ?? '');
}
$videoReal = realpath($videoPath);
if ($videoReal === false || strpos($videoReal, rtrim($realRoot,'/').'/') !== 0 || !is_file($videoReal)) svp_json(['ok'=>false,'error'=>'bad_video_path'],400);

$active = ['NOT_STARTED','QUEUED','PENDING','RUNNING'];
$list = "'".implode("','", $active)."'";
$stmt = $dbcnx->prepare("SELECT id,status FROM processing_jobs WHERE order_id=? AND session_id=? AND job_type='SFM_VIDEO_PIPELINE' AND status IN ($list) ORDER BY id DESC LIMIT 1");
$stmt->bind_param('ii', $orderId, $sessionId); $stmt->execute(); $existing = $stmt->get_result()->fetch_assoc(); $stmt->close();
if ($existing) svp_json(['ok'=>true,'job_id'=>(int)$existing['id'],'status'=>(string)$existing['status']]);

$payload = [
  'video_path'=>$videoReal,'camera_type'=>$cameraType,'sfm_fps'=>(float)($input['sfm_fps'] ?? 3),'keyframe_fps'=>(float)($input['keyframe_fps'] ?? 0.33),
  'frame_width'=>(int)($input['frame_width'] ?? 1920),'marker_size_m'=>(float)($input['marker_size_m'] ?? 0.16),'marker_family'=>(string)($input['marker_family'] ?? 'tag36h11')
];
$payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$jobType = 'SFM_VIDEO_PIPELINE';
$status = 'QUEUED';
$metric = 'NOT_READY';
$stmt = $dbcnx->prepare('INSERT INTO processing_jobs (session_id,order_id,job_type,status,metric_status,warning_text,error_text,created_at,updated_at) VALUES (?,?,?,?,?,?,NULL,NOW(6),NOW(6))');
$stmt->bind_param('iissss', $sessionId, $orderId, $jobType, $status, $metric, $payloadJson);
if (!$stmt->execute()) svp_json(['ok'=>false,'error'=>'db_insert_failed'],500);
$jobId = (int)$stmt->insert_id; $stmt->close();
svp_json(['ok'=>true,'job_id'=>$jobId,'status'=>'QUEUED']);
