<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
require_once dirname(__DIR__,2) . '/libs/sfm_debug_public_lib.php';
header('Content-Type: application/json; charset=utf-8');
function j(array $p,int $c=200):void{http_response_code($c);echo json_encode($p,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
auth_require_login(); if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST') j(['ok'=>false],405);
$d=json_decode(file_get_contents('php://input')?:'',true); if(!is_array($d)) j(['ok'=>false],400);
$sid=(int)($d['session_id']??0); $ttl=(string)($d['expires']??'7d'); if($sid<=0) j(['ok'=>false],400);
$u=auth_current_user(); $uid=(int)$u['id']; $role=(string)($u['role']??'BROKER');
$st=$dbcnx->prepare('SELECT cs.order_id,o.broker_id,o.operator_id FROM capture_sessions cs JOIN tour_orders o ON o.id=cs.order_id WHERE cs.id=? AND cs.deleted_at IS NULL LIMIT 1'); $st->bind_param('i',$sid);$st->execute();$row=$st->get_result()->fetch_assoc();$st->close(); if(!$row) j(['ok'=>false],404);
$allowed=$role==='ADMIN'||(int)$row['broker_id']===$uid||($role==='OPERATOR'&&(int)$row['operator_id']===$uid); if(!$allowed) j(['ok'=>false,'error'=>'forbidden'],403);
$opts=array_merge(SFM_DEBUG_PUBLIC_DEFAULT_OPTIONS, is_array($d['options']??null)?$d['options']:[]);
foreach($opts as $k=>$v){$opts[$k]=(bool)$v;} $optionsJson=json_encode($opts,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
$expiresSql='DATE_ADD(NOW(6), INTERVAL 7 DAY)'; if($ttl==='24h')$expiresSql='DATE_ADD(NOW(6), INTERVAL 24 HOUR)'; elseif($ttl==='30d')$expiresSql='DATE_ADD(NOW(6), INTERVAL 30 DAY)'; elseif($ttl==='none')$expiresSql='NULL';
sfm_debug_public_ensure_schema($dbcnx); $token=bin2hex(random_bytes(32)); $hash=sfm_debug_public_hash($token); $oid=(int)$row['order_id'];
$dbcnx->begin_transaction();
$rv=$dbcnx->prepare('UPDATE sfm_debug_public_links SET revoked_at=NOW(6) WHERE capture_session_id=? AND revoked_at IS NULL'); if($rv){$rv->bind_param('i',$sid);$rv->execute();$rv->close();}
$sql="INSERT INTO sfm_debug_public_links (token_hash,order_id,capture_session_id,created_by,created_at,expires_at,options_json) VALUES (?,?,?,?,NOW(6),$expiresSql,?)"; $ins=$dbcnx->prepare($sql); if(!$ins){$dbcnx->rollback();j(['ok'=>false,'error'=>'db_prepare'],500);} $ins->bind_param('siiis',$hash,$oid,$sid,$uid,$optionsJson); if(!$ins->execute()){$dbcnx->rollback();j(['ok'=>false,'error'=>'db_execute'],500);} $id=(int)$ins->insert_id; $ins->close(); $dbcnx->commit();
if(function_exists('audit_log')) @audit_log($uid,'DEBUG_PUBLIC_LINK_CREATED','CAPTURE_SESSION',$sid,'Debug public link created',['link_id'=>$id,'expires'=>$ttl]);
j(['ok'=>true,'token'=>$token,'url'=>'/debug_share.php?token='.rawurlencode($token),'expires'=>$ttl]);
