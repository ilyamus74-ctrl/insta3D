<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/libs/sfm_settings_lib.php';

auth_require_login();
header('Content-Type: application/json; charset=utf-8');
$user=auth_current_user(); $userId=(int)$user['id']; $role=(string)($user['role'] ?? 'BROKER');
function out_json(array $data,int $code=200): void { http_response_code($code); echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function input_settings(): array { $raw=(string)($_POST['settings_json'] ?? file_get_contents('php://input')); $data=json_decode($raw,true); if(isset($data['settings']) && is_array($data['settings'])){$data=$data['settings'];} if(!is_array($data)){ throw new RuntimeException('settings_json must be JSON object'); } return $data; }
function can_access_session(mysqli $db,int $sid,int $uid,string $role): bool { $st=$db->prepare('SELECT o.broker_id,o.operator_id FROM capture_sessions s JOIN tour_orders o ON o.id=s.order_id WHERE s.id=? AND s.deleted_at IS NULL LIMIT 1'); if(!$st){return false;} $st->bind_param('i',$sid); $st->execute(); $row=$st->get_result()->fetch_assoc(); $st->close(); if(!$row){return false;} return $role==='ADMIN'||(int)$row['broker_id']===$uid||(int)$row['operator_id']===$uid; }
try{
    $sid=(int)($_REQUEST['capture_session_id'] ?? 0); if($sid<=0){ throw new RuntimeException('capture_session_id is required'); }
    if(!can_access_session($dbcnx,$sid,$userId,$role)){ out_json(['ok'=>false,'error'=>'Forbidden'],403); }
    $method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
    if($method==='POST'){
        if(!hash_equals((string)($_SESSION['secCode'] ?? ''),(string)($_POST['secCode'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))){ out_json(['ok'=>false,'error'=>'CSRF token mismatch'],403); }
        $action=(string)($_POST['action'] ?? '');
        if($action==='save_user_defaults'){ sfm_save_user_settings($dbcnx,$userId,input_settings()); }
        elseif($action==='save_session_overrides'){ sfm_save_session_settings($dbcnx,$sid,$userId,input_settings()); }
        elseif($action==='reset_user_defaults'){ sfm_reset_user_settings($dbcnx,$userId); }
        elseif($action==='reset_session_overrides'){ sfm_reset_session_settings($dbcnx,$sid,$userId); }
        else { throw new RuntimeException('Unsupported action'); }
    }
    $system=sfm_system_defaults(); $userSettings=sfm_load_user_settings($dbcnx,$userId); $session=sfm_load_session_settings($dbcnx,$sid,$userId); $effective=sfm_merge_settings($system,$userSettings,$session,[]);
    out_json(['ok'=>true,'system_defaults'=>$system,'user_defaults'=>$userSettings,'session_overrides'=>$session,'effective_settings'=>$effective]);
}catch(Throwable $e){ out_json(['ok'=>false,'error'=>$e->getMessage()],400); }