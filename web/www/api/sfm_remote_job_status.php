<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
auth_require_login();
$user=auth_current_user(); $userId=(int)$user['id']; $role=(string)($user['role'] ?? 'BROKER');
function srj_json(array $p,int $c=200): void { http_response_code($c); header('Content-Type: application/json; charset=utf-8'); echo json_encode($p,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function srj_can_view(array $order,int $uid,string $role): bool { return $role==='ADMIN' || (int)$order['broker_id']===$uid || ($role==='OPERATOR' && ((int)$order['operator_id']===$uid || ((int)$order['is_published']===1 && (string)$order['status']==='NEW' && $order['operator_id']===null))); }
function srj_tail(string $file,int $lines=100): string { if(!is_file($file)) return ''; $data=@file($file); if($data===false) return ''; return implode('', array_slice($data, -$lines)); }
$jobId=(int)($_GET['job_id'] ?? 0); $remoteJobId=(int)($_GET['remote_job_id'] ?? 0); if($jobId<=0 && $remoteJobId<=0) srj_json(['ok'=>false,'error'=>'bad_job_id'],400);
$sql=$jobId>0?'SELECT * FROM sfm_remote_jobs WHERE id=? LIMIT 1':'SELECT * FROM sfm_remote_jobs WHERE remote_job_id=? ORDER BY id DESC LIMIT 1'; $id=$jobId>0?$jobId:$remoteJobId;
$st=$dbcnx->prepare($sql); if(!$st) srj_json(['ok'=>false,'error'=>'table_missing'],500); $st->bind_param('i',$id); $st->execute(); $job=$st->get_result()->fetch_assoc(); $st->close(); if(!$job) srj_json(['ok'=>false,'error'=>'job_not_found'],404);
$orderId=(int)$job['order_id']; $st=$dbcnx->prepare('SELECT id,broker_id,operator_id,is_published,status FROM tour_orders WHERE id=? LIMIT 1'); $st->bind_param('i',$orderId); $st->execute(); $order=$st->get_result()->fetch_assoc(); $st->close(); if(!$order || !srj_can_view($order,$userId,$role)) srj_json(['ok'=>false,'error'=>'forbidden'],403);
$remote=(int)$job['remote_job_id']; $base='/home/makler/web/remote_station/output/job_'.$remote; $realBase=realpath($base) ?: $base;
$file=(string)($_GET['file'] ?? '');
if($file!==''){
  $path=null; $ctype='text/plain; charset=utf-8'; $download=false;
  if($file==='status'){$path=$base.'/status.json'; $ctype='application/json; charset=utf-8';}
  elseif($file==='result'){$path=$base.'/result.json'; $ctype='application/json; charset=utf-8';}
  elseif($file==='logs'){ header('Content-Type: text/plain; charset=utf-8'); foreach(glob($base.'/logs/*.log') ?: [] as $lf){ $rl=realpath($lf); if($rl && (realpath($base)===false || strpos($rl,realpath($base).'/')===0)){ echo "===== ".basename($lf)." =====\n".srj_tail($rl,100)."\n"; } } exit; }
  elseif($file==='ply') { foreach(array_merge(glob($base.'/*.ply') ?: [], glob($base.'/sparse/*.ply') ?: []) as $pf){ $path=$pf; break; } $ctype='application/octet-stream'; $download=true; }
  if(!$path || !is_file($path)) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'file_not_found'; exit; }
  $rp=realpath($path); $rb=realpath($base); if(!$rp || ($rb && strpos($rp,$rb.'/')!==0)) { http_response_code(403); exit('forbidden'); }
  header('Content-Type: '.$ctype); if($download) header('Content-Disposition: attachment; filename="'.basename($rp).'"'); readfile($rp); exit;
}
$statusJson=[]; $statusPath=$base.'/status.json';
if(is_file($statusPath)){
  $decoded=json_decode((string)file_get_contents($statusPath),true);
  if(is_array($decoded)) $statusJson=$decoded;
}
$job['status']=(string)($job['status'] ?? 'UNKNOWN');
$job['progress_percent']=(int)($job['progress_percent'] ?? 0);
$job['message']=(string)($job['message'] ?? '');
srj_json(['ok'=>true,'job'=>$job,'remote_status'=>$statusJson,'files'=>['status'=>is_file($base.'/status.json'),'result'=>is_file($base.'/result.json'),'logs'=>(bool)(glob($base.'/logs/*.log') ?: []),'ply'=>(bool)(glob($base.'/*.ply') ?: [])]]);
