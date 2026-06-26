<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once dirname(__DIR__,2) . '/libs/sfm_debug_public_lib.php';
header('Content-Type: application/json; charset=utf-8');
$debugToken=(string)($_GET['debug_token'] ?? '');
$debugPublic=null;
if($debugToken!==''){ sfm_debug_public_headers(); $debugPublic=sfm_debug_public_validate($dbcnx,$debugToken,true); $userId=0; $role='DEBUG_PUBLIC'; }
else { auth_require_login(); $user = auth_current_user(); $userId = (int)$user['id']; $role = (string)($user['role'] ?? 'BROKER'); }

function api3d_json(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api3d_ply_info(string $path): array {
    $out=['valid'=>false,'vertices'=>0,'faces'=>0]; if(!is_file($path)||filesize($path)<=100) return $out;
    $fh=@fopen($path,'rb'); if(!$fh) return $out; if(fread($fh,3)!=="ply"){fclose($fh); return $out;} rewind($fh);
    while(($line=fgets($fh))!==false){ $line=trim($line); if(preg_match('/^element\s+vertex\s+(\d+)$/',$line,$m)) $out['vertices']=(int)$m[1]; if(preg_match('/^element\s+face\s+(\d+)$/',$line,$m)) $out['faces']=(int)$m[1]; if($line==='end_header'){ $out['valid']=$out['vertices']>0; break; } }
    fclose($fh); return $out;
}
function can_view_order(array $order, int $userId, string $role): bool {
    return $role === 'ADMIN' || ((int)$order['broker_id'] === $userId)
        || ($role === 'OPERATOR' && ((int)$order['operator_id'] === $userId || ((int)$order['is_published'] === 1 && (string)$order['status'] === 'NEW' && $order['operator_id'] === null)));
}


$pipelineRunId = filter_var((string)($_GET['pipeline_run_id'] ?? ''), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$artifact = (string)($_GET['artifact'] ?? 'sparse');
if ($pipelineRunId !== false && $pipelineRunId !== null && $pipelineRunId > 0) {
    if (!in_array($artifact, ['sparse','dense','mesh'], true)) api3d_json(['ok'=>false,'error'=>'bad_artifact'],400);
    require_once dirname(__DIR__, 2) . '/remote_station/sfm_pipeline.php'; ensure_sfm_pipeline_tables($dbcnx);
    $st=$dbcnx->prepare('SELECT r.*, cs.app_session_uuid, o.broker_id, o.operator_id, o.is_published, o.status AS order_status FROM sfm_pipeline_runs r JOIN capture_sessions cs ON cs.id=r.capture_session_id JOIN tour_orders o ON o.id=r.order_id WHERE r.id=? LIMIT 1');
    if(!$st) api3d_json(['ok'=>false,'error'=>'db_prepare_pipeline_failed'],500);
    $pid=(int)$pipelineRunId; $st->bind_param('i',$pid); $st->execute(); $run=$st->get_result()->fetch_assoc(); $st->close();
    if(!$run) api3d_json(['ok'=>false,'error'=>'pipeline_not_found'],404);
    $order=['broker_id'=>$run['broker_id'],'operator_id'=>$run['operator_id'],'is_published'=>$run['is_published'] ?? 0,'status'=>$run['order_status'] ?? ''];
    if($debugPublic){ if((int)$debugPublic['capture_session_id'] !== (int)$run['capture_session_id']) api3d_json(['ok'=>false,'error'=>'forbidden'],403); } elseif(!can_view_order($order,$userId,$role)) api3d_json(['ok'=>false,'error'=>'forbidden'],403);
    $resolverLink = $debugPublic ?: [
        'order_id'=>(int)$run['order_id'],
        'capture_session_id'=>(int)$run['capture_session_id'],
        'app_session_uuid'=>(string)($run['app_session_uuid'] ?? ''),
    ];
    $artifactTypes=['sparse'=>'sparse_ply','dense'=>'dense_ply','mesh'=>'mesh_ply'];
    $resolved=[];
    foreach(['sparse_ply','dense_ply','mesh_ply','camera_trajectory','sparse_diagnostics','world_alignment'] as $atype){
        $resolved[$atype]=sfm_debug_public_artifact_path($dbcnx,$resolverLink,$pid,$atype);
    }
    $selected=$resolved[$artifactTypes[$artifact]] ?? null;
    $selectedInfo=$selected ? api3d_ply_info($selected['path']) : ['valid'=>false,'vertices'=>0,'faces'=>0];
    if(!$selectedInfo['valid'] || ($artifact==='mesh' && $selectedInfo['faces']<=0)) api3d_json(['ok'=>false,'error'=>$artifact==='mesh'?'Pipeline has no mesh artifact':'Artifact not found'],404);
    $sparseInfo=($resolved['sparse_ply'] ?? null) ? api3d_ply_info($resolved['sparse_ply']['path']) : ['valid'=>false,'vertices'=>0,'faces'=>0];
    $denseInfo=($resolved['dense_ply'] ?? null) ? api3d_ply_info($resolved['dense_ply']['path']) : ['valid'=>false,'vertices'=>0,'faces'=>0];
    $meshInfo=($resolved['mesh_ply'] ?? null) ? api3d_ply_info($resolved['mesh_ply']['path']) : ['valid'=>false,'vertices'=>0,'faces'=>0];
    $trajPath=(string)(($resolved['camera_trajectory']['path'] ?? ''));
    $poses=0; if($trajPath!=='' && is_file($trajPath)){ $tj=json_decode((string)file_get_contents($trajPath),true); if(is_array($tj)){$poses=count($tj['poses'] ?? []);} }
    $artifactUrl=function($atype) use($debugPublic,$debugToken,$pid){ if($debugPublic){return '/debug_share_file.php?token='.rawurlencode($debugToken).'&pipeline_run_id='.$pid.'&artifact_type='.$atype;} $m=['sparse_ply'=>'sparse','dense_ply'=>'dense','mesh_ply'=>'mesh']; return '/api/sfm_pipeline_artifact.php?pipeline_run_id='.$pid.'&artifact='.($m[$atype] ?? $atype); };
    api3d_json(['ok'=>true,'pipeline_run_id'=>$pid,'artifact'=>$artifact,'summary'=>['points_count'=>$sparseInfo['vertices'],'camera_poses_count'=>$poses,'keyframe_points_count'=>$poses,'camera_trajectory_available'=>$trajPath!=='' && is_file($trajPath)], 'artifacts'=>['sparse_points_ply_url'=>$artifactUrl('sparse_ply'),'camera_trajectory_url'=>$artifactUrl('camera_trajectory'),'sparse_diagnostics_url'=>$artifactUrl('sparse_diagnostics'),'world_alignment_url'=>$artifactUrl('world_alignment'),'keyframe_points_url'=>$artifactUrl('camera_trajectory')], 'sparse'=>['available'=>$sparseInfo['valid'],'points'=>$sparseInfo['vertices'],'sparse_ply_url'=>$artifactUrl('sparse_ply')], 'dense'=>['available'=>$denseInfo['valid'],'fused_ply_url'=>$artifactUrl('dense_ply'),'points'=>$denseInfo['vertices']], 'mesh'=>['available'=>$meshInfo['valid']&&$meshInfo['faces']>0,'mesh_ply_url'=>$artifactUrl('mesh_ply'),'vertices'=>$meshInfo['vertices'],'faces'=>$meshInfo['faces']], 'selected'=>['artifact'=>$artifact,'vertices'=>$selectedInfo['vertices'],'faces'=>$selectedInfo['faces']]]);
}

$orderId = filter_var((string)($_GET['order_id'] ?? ''), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$sessionId = filter_var((string)($_GET['session_id'] ?? ''), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($orderId === false || $sessionId === false) api3d_json(['ok'=>false,'error'=>'bad_params'],400);
$orderId=(int)$orderId; $sessionId=(int)$sessionId;

$stmt = $dbcnx->prepare('SELECT id, broker_id, operator_id, is_published, status FROM tour_orders WHERE id = ? LIMIT 1');
if (!$stmt) api3d_json(['ok'=>false,'error'=>'db_prepare_order_failed'],500);
$stmt->bind_param('i', $orderId); $stmt->execute(); $order = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$order) api3d_json(['ok'=>false,'error'=>'order_not_found'],404);
if($debugPublic){ if((int)$debugPublic['order_id']!==$orderId || (int)$debugPublic['capture_session_id']!==$sessionId) api3d_json(['ok'=>false,'error'=>'forbidden'],403); } elseif (!can_view_order($order, $userId, $role)) api3d_json(['ok'=>false,'error'=>'forbidden'],403);

$stmt = $dbcnx->prepare('SELECT session_dir FROM video_sfm_runs WHERE order_id = ? AND session_id = ? ORDER BY id DESC LIMIT 1');
if (!$stmt) api3d_json(['ok'=>false,'error'=>'db_prepare_run_failed'],500);
$stmt->bind_param('ii', $orderId, $sessionId); $stmt->execute(); $run = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$run) api3d_json(['ok'=>false,'error'=>'sfm_run_not_found'],404);

$sessionDir = trim((string)($run['session_dir'] ?? ''));
if ($sessionDir === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $sessionDir)) api3d_json(['ok'=>false,'error'=>'invalid_session_dir'],500);

$base = '/home/makler/web/storage/orders/' . $orderId . '/sessions/' . $sessionDir . '/sfm/3d';
$summaryPath = $base . '/sfm_3d_summary.json';
$plyPath = $base . '/sparse_points.ply';
$trajPath = $base . '/camera_trajectory.json';
$keyPath = $base . '/keyframe_points_3d.json';
if (!is_file($summaryPath) || !is_file($plyPath) || !is_file($trajPath) || !is_file($keyPath)) api3d_json(['ok'=>false,'error'=>'artifacts_missing'],404);

$summary = json_decode((string)file_get_contents($summaryPath), true);
if (!is_array($summary)) api3d_json(['ok'=>false,'error'=>'bad_summary'],500);

$prefix = 'orders/' . $orderId . '/sessions/' . $sessionDir . '/sfm/3d/';
$densePrefix = 'orders/' . $orderId . '/sessions/' . $sessionDir . '/sfm/';
$denseSummaryPath = '/home/makler/web/storage/' . $densePrefix . 'mesh/dense_model_summary.json';
$denseSummary = null;
$denseAvailable = is_file('/home/makler/web/storage/' . $densePrefix . 'dense/fused.ply');
if ($denseAvailable && is_file($denseSummaryPath)) {
    $denseSummary = json_decode((string)file_get_contents($denseSummaryPath), true);
    if (!is_array($denseSummary)) $denseSummary = null;
}
$sparseInfo = api3d_ply_info($plyPath);
api3d_json([
    'ok' => true,
    'summary' => $summary,
    'artifacts' => [
        'sparse_points_ply_url' => '/media.php?path=' . rawurlencode($prefix . 'sparse_points.ply'),
        'camera_trajectory_url' => '/media.php?path=' . rawurlencode($prefix . 'camera_trajectory.json'),
        'keyframe_points_url' => '/media.php?path=' . rawurlencode($prefix . 'keyframe_points_3d.json'),
    ],
    'sparse' => [
        'available' => $sparseInfo['valid'],
        'points' => $sparseInfo['vertices'],
        'sparse_ply_url' => '/media.php?path=' . rawurlencode($prefix . 'sparse_points.ply'),
    ],

    'dense' => $denseAvailable ? [
        'available' => true,
        'summary' => $denseSummary,
        'fused_ply_url' => '/media.php?path=' . rawurlencode($densePrefix . 'dense/fused.ply'),
        'mesh_ply_url' => '/media.php?path=' . rawurlencode($densePrefix . 'mesh/poisson_mesh.ply'),
    ] : [
        'available' => false,
    ],
]);
