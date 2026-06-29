<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
auth_require_login();
$user = auth_current_user();
$userId = (int)($user['id'] ?? 0);
$role = (string)($user['role'] ?? 'BROKER');
function viewer_json(array $p, int $c=200): void { http_response_code($c); echo json_encode($p, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function viewer_ensure_table(mysqli $db): void {
    $sql = "CREATE TABLE IF NOT EXISTS sfm_viewer_settings (id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id BIGINT NOT NULL, capture_session_id BIGINT NULL, pipeline_run_id BIGINT NULL, settings_json LONGTEXT NOT NULL, updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6), UNIQUE KEY uniq_sfm_viewer_scope (user_id, capture_session_id, pipeline_run_id), KEY idx_sfm_viewer_session (capture_session_id), KEY idx_sfm_viewer_pipeline (pipeline_run_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$db->query($sql)) { viewer_json(['ok'=>false,'error'=>'db_create_settings_failed'],500); }
}
function viewer_can_access(mysqli $db, int $userId, string $role, int $orderId, int $sessionId, ?int $pipelineRunId): bool {
    if ($pipelineRunId && $pipelineRunId > 0) {
        $st=$db->prepare('SELECT r.order_id,r.capture_session_id,o.broker_id,o.operator_id,o.is_published,o.status FROM sfm_pipeline_runs r JOIN tour_orders o ON o.id=r.order_id WHERE r.id=? LIMIT 1');
        if(!$st) return false; $st->bind_param('i',$pipelineRunId); $st->execute(); $row=$st->get_result()->fetch_assoc(); $st->close(); if(!$row) return false; $orderId=(int)$row['order_id']; $sessionId=(int)$row['capture_session_id'];
    } else {
        $st=$db->prepare('SELECT id, broker_id, operator_id, is_published, status FROM tour_orders WHERE id=? LIMIT 1');
        if(!$st) return false; $st->bind_param('i',$orderId); $st->execute(); $row=$st->get_result()->fetch_assoc(); $st->close(); if(!$row) return false;
        $st=$db->prepare('SELECT id FROM capture_sessions WHERE id=? AND order_id=? AND deleted_at IS NULL LIMIT 1');
        if($st){ $st->bind_param('ii',$sessionId,$orderId); $st->execute(); $sess=$st->get_result()->fetch_assoc(); $st->close(); if(!$sess) return false; }
    }
    return $role==='ADMIN' || (int)$row['broker_id']===$userId || ($role==='OPERATOR' && ((int)$row['operator_id']===$userId || ((int)$row['is_published']===1 && (string)$row['status']==='NEW' && $row['operator_id']===null)));
}
function clean_settings(array $s): array {
    $out=[];
    if(isset($s['quaternion']) && is_array($s['quaternion'])) {
        $q=['x'=>(float)($s['quaternion']['x']??0),'y'=>(float)($s['quaternion']['y']??0),'z'=>(float)($s['quaternion']['z']??0),'w'=>(float)($s['quaternion']['w']??1)];
        $len=sqrt($q['x']*$q['x']+$q['y']*$q['y']+$q['z']*$q['z']+$q['w']*$q['w']);
        if($len>0.000001) $out['quaternion']=['x'=>$q['x']/$len,'y'=>$q['y']/$len,'z'=>$q['z']/$len,'w'=>$q['w']/$len];
    }
    if(isset($s['rotation']) && is_array($s['rotation'])) $out['rotation']=['x'=>(float)($s['rotation']['x']??0),'y'=>(float)($s['rotation']['y']??0),'z'=>(float)($s['rotation']['z']??0)];
    if(isset($s['point_size'])) $out['point_size']=max(0.5,min(8.0,(float)$s['point_size']));
    if(isset($s['exposure'])) $out['exposure']=max(0.5,min(3.0,(float)$s['exposure']));
    if(isset($s['background']) && preg_match('/^#[0-9a-fA-F]{6}$/',(string)$s['background'])) $out['background']=strtolower((string)$s['background']);
    if(isset($s['use_outlier_filter'])) $out['use_outlier_filter']=(bool)$s['use_outlier_filter'];
    if(isset($s['outlier_mode']) && in_array($s['outlier_mode'],['off','light','medium','strong'],true)) $out['outlier_mode']=$s['outlier_mode'];
    if(isset($s['preset']) && in_array($s['preset'],['natural','bright','contrast','meshlab'],true)) $out['preset']=$s['preset'];
    if(isset($s['auto_level_on_load'])) $out['auto_level_on_load']=(bool)$s['auto_level_on_load'];
    return $out;
}
viewer_ensure_table($dbcnx);
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $orderId=(int)($_GET['order_id'] ?? 0); $sessionId=(int)($_GET['capture_session_id'] ?? ($_GET['session_id'] ?? 0)); $pipelineRunId=isset($_GET['pipeline_run_id']) && $_GET['pipeline_run_id']!=='' ? (int)$_GET['pipeline_run_id'] : null;
    if($sessionId<0 || $orderId<0) viewer_json(['ok'=>false,'error'=>'bad_params'],400);
    if(($sessionId>0 || $pipelineRunId) && !viewer_can_access($dbcnx,$userId,$role,$orderId,$sessionId,$pipelineRunId)) viewer_json(['ok'=>false,'error'=>'forbidden'],403);
    $defaults=['rotation'=>['x'=>0,'y'=>0,'z'=>0],'point_size'=>2.25,'exposure'=>1.6,'background'=>'#252b3f','use_outlier_filter'=>false,'outlier_mode'=>'off','preset'=>'meshlab','auto_level_on_load'=>true];
    $settings=$defaults;
    $scopes=[[null,null]]; if($sessionId>0)$scopes[]=[$sessionId,null]; if($sessionId>0 && $pipelineRunId)$scopes[]=[$sessionId,$pipelineRunId];
    foreach($scopes as [$sid,$pid]){ $sql='SELECT settings_json FROM sfm_viewer_settings WHERE user_id=? AND '.($sid===null?'capture_session_id IS NULL':'capture_session_id=?').' AND '.($pid===null?'pipeline_run_id IS NULL':'pipeline_run_id=?').' LIMIT 1'; $st=$dbcnx->prepare($sql); if(!$st) continue; if($sid===null){$st->bind_param('i',$userId);} elseif($pid===null){$st->bind_param('ii',$userId,$sid);} else {$st->bind_param('iii',$userId,$sid,$pid);} $st->execute(); $row=$st->get_result()->fetch_assoc(); $st->close(); if($row){$v=json_decode((string)$row['settings_json'],true); if(is_array($v)) $settings=array_replace_recursive($settings,$v);} }
    viewer_json(['ok'=>true,'settings'=>$settings]);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload=json_decode((string)file_get_contents('php://input'),true); if(!is_array($payload)) viewer_json(['ok'=>false,'error'=>'bad_json'],400);
    $orderId=(int)($payload['order_id'] ?? 0); $sessionId=(int)($payload['capture_session_id'] ?? 0); $pipelineRunId=isset($payload['pipeline_run_id']) && $payload['pipeline_run_id'] ? (int)$payload['pipeline_run_id'] : null;
    if(!viewer_can_access($dbcnx,$userId,$role,$orderId,$sessionId,$pipelineRunId)) viewer_json(['ok'=>false,'error'=>'forbidden'],403);
    $settings=clean_settings(is_array($payload['settings'] ?? null)?$payload['settings']:[]); $json=json_encode($settings,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $st=$dbcnx->prepare('INSERT INTO sfm_viewer_settings (user_id,capture_session_id,pipeline_run_id,settings_json,updated_at) VALUES (?,?,?,?,NOW(6)) ON DUPLICATE KEY UPDATE settings_json=VALUES(settings_json), updated_at=NOW(6)'); if(!$st) viewer_json(['ok'=>false,'error'=>'db_prepare_failed'],500);
    $st->bind_param('iiis',$userId,$sessionId,$pipelineRunId,$json); $ok=$st->execute(); $st->close(); if(!$ok) viewer_json(['ok'=>false,'error'=>'db_save_failed'],500);
    viewer_json(['ok'=>true,'settings'=>$settings]);
}
viewer_json(['ok'=>false,'error'=>'method_not_allowed'],405);