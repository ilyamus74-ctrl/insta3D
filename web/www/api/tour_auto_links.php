<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../libs/tour_auto_links_lib.php';
header('Content-Type: application/json; charset=utf-8');
auth_require_login();
function resp(array $p, int $c=200): void { http_response_code($c); echo json_encode($p, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function can_view_order_auto(array $order, int $userId, string $role): bool {
 return $role==='ADMIN'||((int)$order['broker_id']===$userId)||($role==='OPERATOR'&&((int)$order['operator_id']===$userId||((int)$order['is_published']===1&&(string)$order['status']==='NEW'&&$order['operator_id']===null)));
}
$data=json_decode(file_get_contents('php://input') ?: '{}', true); if(!is_array($data)) resp(['ok'=>false,'error'=>'bad_json'],400);
$sessionId=(int)($data['session_id']??0); $overwriteAuto=(bool)($data['overwrite_auto']??true); $overwriteManual=(bool)($data['overwrite_manual']??false); if($sessionId<=0) resp(['ok'=>false,'error'=>'bad_session_id'],400);
$user=auth_current_user(); $userId=(int)$user['id']; $role=(string)($user['role']??'BROKER');
$stmt=$dbcnx->prepare("SELECT cs.id, cs.order_id, o.broker_id, o.operator_id, o.is_published, o.status FROM capture_sessions cs JOIN tour_orders o ON o.id=cs.order_id WHERE cs.id=? LIMIT 1"); if(!$stmt) resp(['ok'=>false,'error'=>'db_prepare_session_failed'],500);
$stmt->bind_param('i',$sessionId); $stmt->execute(); $session=$stmt->get_result()->fetch_assoc(); $stmt->close(); if(!$session) resp(['ok'=>false,'error'=>'session_not_found'],404);
if(!can_view_order_auto($session,$userId,$role)) resp(['ok'=>false,'error'=>'forbidden'],403);
try { $r=run_tour_auto_links($dbcnx,$sessionId,$overwriteAuto,$overwriteManual); resp(['ok'=>true,'session_id'=>$sessionId,'algorithm'=>$r['algorithm']??TOUR_AUTO_LINKS_ALGORITHM,'created_count'=>(int)($r['created_count']??0),'updated_count'=>(int)($r['updated_count']??0),'skipped_count'=>(int)($r['skipped_count']??0),'warnings'=>$r['warnings']??[]]); }
catch(Throwable $e){ resp(['ok'=>false,'error'=>'auto_links_failed','message'=>$e->getMessage()],500);} 
