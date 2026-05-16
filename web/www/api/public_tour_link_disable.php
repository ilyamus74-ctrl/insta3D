<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
function api_json(array $p,int $c=200):void{http_response_code($c);echo json_encode($p,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
auth_require_login(); if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST') api_json(['ok'=>false],405);
$d=json_decode(file_get_contents('php://input')?:'',true); if(!is_array($d)) api_json(['ok'=>false],400);
$sessionId=(int)($d['session_id']??0); if($sessionId<=0) api_json(['ok'=>false],400);
$u=auth_current_user(); $uid=(int)$u['id']; $role=(string)($u['role']??'BROKER');
$stmt=$dbcnx->prepare("SELECT cs.order_id,o.broker_id,o.operator_id FROM capture_sessions cs JOIN tour_orders o ON o.id=cs.order_id WHERE cs.id=? LIMIT 1"); $stmt->bind_param('i',$sessionId);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close(); if(!$row) api_json(['ok'=>false],404);
$allowed = $role === 'ADMIN' || (int)$row['broker_id'] === $uid || ($role === 'OPERATOR' && (int)$row['operator_id'] === $uid);
if(!$allowed) api_json(['ok'=>false,'error'=>'forbidden'],403);
$stmt=$dbcnx->prepare("UPDATE public_tour_links SET is_active=0,updated_at=NOW(6) WHERE session_id=? AND is_active=1"); $stmt->bind_param('i',$sessionId); if(!$stmt->execute()) api_json(['ok'=>false],500); $disabled=(int)$stmt->affected_rows; $stmt->close();
api_json(['ok'=>true,'disabled_count'=>$disabled]);
