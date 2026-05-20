<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
auth_require_login();
$user=auth_current_user(); $userId=(int)$user['id']; $role=(string)($user['role']??'BROKER');
function djson(array $p,int $c=200): void { http_response_code($c); echo json_encode($p, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function can(array $o,int $u,string $r): bool { return $r==='ADMIN'||((int)$o['broker_id']===$u)||($r==='OPERATOR'&&((int)$o['operator_id']===$u||((int)$o['is_published']===1&&(string)$o['status']==='NEW'&&$o['operator_id']===null))); }
function i(mixed $v,string $n): int { $x=filter_var((string)$v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]); if($x===false)djson(['ok'=>false,'error'=>'bad_'.$n],400); return (int)$x; }
$method=$_SERVER['REQUEST_METHOD']??'GET'; $in=$method==='POST'?(json_decode((string)file_get_contents('php://input'),true)?:$_POST):$_GET;
$orderId=i($in['order_id']??null,'order_id'); $sessionId=i($in['session_id']??null,'session_id');
$st=$dbcnx->prepare('SELECT id, broker_id, operator_id, is_published, status FROM tour_orders WHERE id=? LIMIT 1'); $st->bind_param('i',$orderId); $st->execute(); $order=$st->get_result()->fetch_assoc(); $st->close();
if(!$order)djson(['ok'=>false,'error'=>'order_not_found'],404); if(!can($order,$userId,$role)) djson(['ok'=>false,'error'=>'forbidden'],403);
$st=$dbcnx->prepare('SELECT id, app_session_uuid FROM capture_sessions WHERE id=? AND order_id=? AND deleted_at IS NULL LIMIT 1'); $st->bind_param('ii',$sessionId,$orderId); $st->execute(); $session=$st->get_result()->fetch_assoc(); $st->close();
if(!$session)djson(['ok'=>false,'error'=>'session_not_found'],404);
$st=$dbcnx->prepare("SELECT id,status,metric_status,warning_text,error_text,created_at,updated_at FROM processing_jobs WHERE order_id=? AND session_id=? AND job_type='SFM_DENSE_MODEL' ORDER BY id DESC LIMIT 1"); $st->bind_param('ii',$orderId,$sessionId); $st->execute(); $job=$st->get_result()->fetch_assoc(); $st->close();
$st=$dbcnx->prepare('SELECT session_dir FROM video_sfm_runs WHERE order_id=? AND session_id=? ORDER BY id DESC LIMIT 1'); $st->bind_param('ii',$orderId,$sessionId); $st->execute(); $run=$st->get_result()->fetch_assoc(); $st->close();
$sessionDir=(string)($run['session_dir']??$session['app_session_uuid']??''); $base='/home/makler/web/storage/orders/'.$orderId.'/sessions/'.$sessionDir.'/sfm';
$sparseReady=is_file($base.'/3d/sparse_points.ply') && is_file($base.'/colmap/sparse/0/cameras.bin');
$denseSummaryPath=$base.'/mesh/dense_model_summary.json'; $denseReady=is_file($base.'/dense/fused.ply')&&is_file($base.'/mesh/poisson_mesh.ply')&&is_file($denseSummaryPath);
if($method==='GET'){ djson(['ok'=>true,'latest_job'=>$job?:null,'sparse_ready'=>$sparseReady,'dense'=>['available'=>$denseReady]]); }
if(!$sparseReady)djson(['ok'=>false,'error'=>'sparse_missing'],400);
$quality=strtoupper(trim((string)($in['quality']??'LOW'))); if(!in_array($quality,['LOW','MEDIUM','HIGH'],true)) djson(['ok'=>false,'error'=>'bad_quality'],400);
$maxImageSize=max(256,(int)($in['max_image_size']??1024));
$active="'NOT_STARTED','QUEUED','PENDING','RUNNING'"; $st=$dbcnx->prepare("SELECT id,status FROM processing_jobs WHERE order_id=? AND session_id=? AND job_type='SFM_DENSE_MODEL' AND status IN ($active) ORDER BY id DESC LIMIT 1"); $st->bind_param('ii',$orderId,$sessionId); $st->execute(); $ex=$st->get_result()->fetch_assoc(); $st->close();
if($ex)djson(['ok'=>true,'job_id'=>(int)$ex['id'],'status'=>(string)$ex['status']]);
$payload=json_encode(['quality'=>$quality,'max_image_size'=>$maxImageSize,'source'=>'colmap_sparse'], JSON_UNESCAPED_SLASHES);
$type='SFM_DENSE_MODEL'; $status='QUEUED'; $metric='NOT_READY';
$st=$dbcnx->prepare('INSERT INTO processing_jobs (session_id,order_id,job_type,status,metric_status,warning_text,error_text,created_at,updated_at) VALUES (?,?,?,?,?,?,NULL,NOW(6),NOW(6))');
$st->bind_param('iissss',$sessionId,$orderId,$type,$status,$metric,$payload); if(!$st->execute()) djson(['ok'=>false,'error'=>'db_insert_failed'],500); $jobId=(int)$st->insert_id; $st->close();
djson(['ok'=>true,'job_id'=>$jobId,'status'=>'QUEUED']);
