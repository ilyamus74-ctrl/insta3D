<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
auth_require_login();
$user=auth_current_user(); $userId=(int)$user['id']; $role=(string)($user['role'] ?? 'BROKER');
function srj_json(array $p,int $c=200): void { http_response_code($c); header('Content-Type: application/json; charset=utf-8'); echo json_encode($p,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function srj_can_view(array $order,int $uid,string $role): bool { return $role==='ADMIN' || (int)$order['broker_id']===$uid || ($role==='OPERATOR' && ((int)$order['operator_id']===$uid || ((int)$order['is_published']===1 && (string)$order['status']==='NEW' && $order['operator_id']===null))); }
function srj_tail(string $file,int $lines=100): string { if(!is_file($file)) return ''; $data=@file($file); if($data===false) return ''; return implode('', array_slice($data, -$lines)); }

function srj_send_ply_file(string $path, string $base, string $downloadName): void {
  while (ob_get_level() > 0) {
    ob_end_clean();
  }

  header_remove();

  if (!is_file($path) || !is_readable($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('PLY file not found');
  }

  $rp = realpath($path);
  $rb = realpath($base);
  if (!$rp || ($rb && strpos($rp, $rb . '/') !== 0)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('forbidden');
  }

  $head = file_get_contents($rp, false, null, 0, 3);
  if ($head !== 'ply') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Invalid PLY header');
  }

  $handle = fopen($rp, 'rb');
  if ($handle === false) {
    http_response_code(500);
    exit;
  }

  header('Content-Type: application/octet-stream');
  header('Content-Disposition: attachment; filename="' . basename($downloadName) . '"');
  header('Content-Length: ' . filesize($rp));
  header('X-Content-Type-Options: nosniff');
  header('Cache-Control: no-store');

  fpassthru($handle);
  fclose($handle);
  exit;
}
function srj_is_valid_ply_file(string $file): bool {
  $fh=@fopen($file,'rb');
  if($fh===false) return false;
  $head=(string)fread($fh,4096);
  fclose($fh);
  if(!(strncmp($head,"ply\n",4)===0 || strncmp($head,"ply\r\n",5)===0)) return false;
  return (bool)preg_match('/^format (?:ascii|binary_little_endian) 1\.0\r?$/m',$head);
}
$jobId=(int)($_GET['job_id'] ?? 0); $remoteJobId=(int)($_GET['remote_job_id'] ?? 0); if($jobId<=0 && $remoteJobId<=0) srj_json(['ok'=>false,'error'=>'bad_job_id'],400);
$sql=$jobId>0?'SELECT * FROM sfm_remote_jobs WHERE id=? LIMIT 1':'SELECT * FROM sfm_remote_jobs WHERE remote_job_id=? ORDER BY id DESC LIMIT 1'; $id=$jobId>0?$jobId:$remoteJobId;
$st=$dbcnx->prepare($sql); if(!$st) srj_json(['ok'=>false,'error'=>'table_missing'],500); $st->bind_param('i',$id); $st->execute(); $job=$st->get_result()->fetch_assoc(); $st->close(); if(!$job) srj_json(['ok'=>false,'error'=>'job_not_found'],404);
$orderId=(int)$job['order_id']; $st=$dbcnx->prepare('SELECT id,broker_id,operator_id,is_published,status FROM tour_orders WHERE id=? LIMIT 1'); $st->bind_param('i',$orderId); $st->execute(); $order=$st->get_result()->fetch_assoc(); $st->close(); if(!$order || !srj_can_view($order,$userId,$role)) srj_json(['ok'=>false,'error'=>'forbidden'],403);
$remote=(int)$job['remote_job_id']; $base='/home/makler/web/remote_station/output/job_'.$remote; $realBase=realpath($base) ?: $base;
$file=(string)($_GET['file'] ?? '');
if($file!==''){
  $path=null; $ctype='text/plain; charset=utf-8'; $download=false; $downloadName=null;
  if($file==='status'){$path=$base.'/status.json'; $ctype='application/json; charset=utf-8';}
  elseif($file==='result'){$path=$base.'/result.json'; $ctype='application/json; charset=utf-8';}
  elseif($file==='logs'){ header('Content-Type: text/plain; charset=utf-8'); foreach(glob($base.'/logs/*.log') ?: [] as $lf){ $rl=realpath($lf); if($rl && (realpath($base)===false || strpos($rl,realpath($base).'/')===0)){ echo "===== ".basename($lf)." =====\n".srj_tail($rl,100)."\n"; } } exit; }
  elseif($file==='ply') {
    if ((string)($job['job_type'] ?? '') === 'COLMAP_DENSE') { $path=$base.'/dense/fused.ply'; $downloadName='job_'.$remote.'_dense_fused.ply'; }
    elseif (in_array((string)($job['job_type'] ?? ''), ['COLMAP_RECONSTRUCTION_PREVIEW','COLMAP_RECONSTRUCTION_HQ'], true)) { $path=$base.'/merged/merged_fused.ply'; $downloadName='job_'.$remote.'_merged_fused.ply'; }
    elseif ((string)($job['job_type'] ?? '') === 'COLMAP_MESH') { $which=(string)($_GET['mesh'] ?? 'cleaned'); $name=$which==='poisson'?'mesh_poisson.ply':'mesh_cleaned.ply'; $path=$base.'/mesh/'.$name; $downloadName='job_'.$remote.'_'.$name; }
    elseif ((string)($job['job_type'] ?? '') !== 'EXPORT_PLY') { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'file_not_found'; exit; }
    else {
    $parent=(int)($job['parent_remote_job_id'] ?? 0); $out=(string)($job['output_path'] ?? ''); $model=0;
    if (preg_match('/sparse_(\d+)\.ply|model[_-]?(\d+)/', $out, $m)) { $model=(int)(($m[1] ?? '') !== '' ? $m[1] : $m[2]); }
    if ($parent>0) { $base='/home/makler/web/remote_station/output/job_'.$parent; $realBase=realpath($base) ?: $base; $path=$base.'/colmap/sparse/'.$model.'/model.ply'; $downloadName='job_'.$parent.'_sparse_'.$model.'_model.ply'; }
    }
    $ctype='application/octet-stream'; $download=true;
  }
  if ($file === 'ply') { srj_send_ply_file((string)$path, $base, (string)($downloadName ?: basename((string)$path))); }
  if(!$path || !is_file($path)) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'file_not_found'; exit; }
  $rp=realpath($path); $rb=realpath($base); if(!$rp || ($rb && strpos($rp,$rb.'/')!==0)) { http_response_code(403); exit('forbidden'); }
  header('Content-Type: '.$ctype); if($download) header('Content-Disposition: attachment; filename="'.($downloadName ?: basename($rp)).'"'); readfile($rp); exit;
}
$statusJson=[]; $statusPath=$base.'/status.json';
if(is_file($statusPath)){
  $decoded=json_decode((string)file_get_contents($statusPath),true);
  if(is_array($decoded)) $statusJson=$decoded;
}
$job['status']=(string)($job['status'] ?? 'UNKNOWN');
$job['progress_percent']=(int)($job['progress_percent'] ?? 0);
$job['message']=(string)($job['message'] ?? '');
srj_json(['ok'=>true,'job'=>$job,'remote_status'=>$statusJson,'files'=>['status'=>is_file($base.'/status.json'),'result'=>is_file($base.'/result.json'),'logs'=>(bool)(glob($base.'/logs/*.log') ?: []),'ply'=>is_file($base.'/dense/fused.ply') || (bool)(glob($base.'/*.ply') ?: [])]]);
