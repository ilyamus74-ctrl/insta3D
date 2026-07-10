<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
auth_require_login();
$user=auth_current_user(); $uid=(int)$user['id']; $role=(string)($user['role'] ?? 'BROKER');
function outa(string $m,int $c=400): void { http_response_code($c); header('Content-Type: text/plain; charset=utf-8'); echo $m; exit; }
function cana(array $o,int $uid,string $role): bool { return $role==='ADMIN' || (int)$o['broker_id']===$uid || ($role==='OPERATOR' && ((int)$o['operator_id']===$uid || ((int)$o['is_published']===1 && (string)$o['status']==='NEW' && $o['operator_id']===null))); }
$jobId=(int)($_GET['job_id'] ?? 0); $rel=(string)($_GET['file'] ?? ''); if($jobId<=0 || $rel==='' || str_contains($rel,"\0") || str_starts_with($rel,'/') || str_contains($rel,'..')){ outa('Bad request',400); }
$st=$dbcnx->prepare('SELECT * FROM sfm_remote_jobs WHERE id=? LIMIT 1'); if(!$st){ outa('job table unavailable',500); } $st->bind_param('i',$jobId); $st->execute(); $job=$st->get_result()->fetch_assoc(); $st->close(); if(!$job){ outa('Not found',404); }
$st=$dbcnx->prepare('SELECT id,broker_id,operator_id,is_published,status FROM tour_orders WHERE id=? LIMIT 1'); $oid=(int)$job['order_id']; $st->bind_param('i',$oid); $st->execute(); $order=$st->get_result()->fetch_assoc(); $st->close(); if(!$order || !cana($order,$uid,$role)){ outa('Forbidden',403); }
$base='/home/makler/web/remote_station/output/job_'.(int)$job['remote_job_id']; $rb=realpath($base); $rp=realpath($base.'/'.$rel); if($rb===false || $rp===false || !is_file($rp) || !str_starts_with($rp,$rb.'/')){ outa('File not found',404); }
$ext=strtolower(pathinfo($rp,PATHINFO_EXTENSION)); $ct=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','json'=>'application/json','csv'=>'text/csv'][$ext] ?? 'application/octet-stream'; header('Content-Type: '.$ct); if($ext!=='jpg' && $ext!=='jpeg'){ header('Content-Disposition: attachment; filename="'.basename($rp).'"'); } readfile($rp);