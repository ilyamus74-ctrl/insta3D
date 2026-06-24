<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
require_once dirname(__DIR__,2) . '/libs/sfm_debug_public_lib.php';
header('Content-Type: application/json; charset=utf-8'); function j(array $p,int $c=200):void{http_response_code($c);echo json_encode($p,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
auth_require_login(); if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST') j(['ok'=>false],405); $d=json_decode(file_get_contents('php://input')?:'',true); if(!is_array($d)) j(['ok'=>false],400); $sid=(int)($d['session_id']??0); if($sid<=0) j(['ok'=>false],400);
$u=auth_current_user(); $uid=(int)$u['id']; $role=(string)($u['role']??'BROKER'); $st=$dbcnx->prepare('SELECT cs.order_id,o.broker_id,o.operator_id FROM capture_sessions cs JOIN tour_orders o ON o.id=cs.order_id WHERE cs.id=? LIMIT 1'); $st->bind_param('i',$sid);$st->execute();$row=$st->get_result()->fetch_assoc();$st->close(); if(!$row) j(['ok'=>false],404); $allowed=$role==='ADMIN'||(int)$row['broker_id']===$uid||($role==='OPERATOR'&&(int)$row['operator_id']===$uid); if(!$allowed) j(['ok'=>false],403);
sfm_debug_public_ensure_schema($dbcnx); $st=$dbcnx->prepare('UPDATE sfm_debug_public_links SET revoked_at=NOW(6) WHERE capture_session_id=? AND revoked_at IS NULL'); $st->bind_param('i',$sid); $st->execute(); $n=(int)$st->affected_rows; $st->close(); if(function_exists('audit_log')) @audit_log($uid,'DEBUG_PUBLIC_LINK_REVOKED','CAPTURE_SESSION',$sid,'Debug public link revoked',['count'=>$n]); j(['ok'=>true,'revoked_count'=>$n]);
