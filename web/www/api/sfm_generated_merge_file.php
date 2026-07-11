<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
auth_require_login();
$user=auth_current_user(); $uid=(int)$user['id']; $role=(string)($user['role'] ?? 'BROKER');
function gm_fail(int $c,string $m): never { http_response_code($c); exit($m); }
function gm_can(array $o,int $uid,string $role): bool { return $role==='ADMIN' || (int)$o['broker_id']===$uid || ($role==='OPERATOR' && ((int)$o['operator_id']===$uid || ((int)$o['is_published']===1 && (string)$o['status']==='NEW' && $o['operator_id']===null))); }
function gm_inside(string $path): bool { $root=realpath('/home/makler/web/remote_station/output'); $real=realpath($path); return $root!==false && $real!==false && ($real===$root || strpos($real,rtrim($root,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)===0); }
$mergeId=(int)($_GET['merge_id'] ?? 0); $file=(string)($_GET['file'] ?? '');
if($mergeId<=0 || !in_array($file,['ply','result'],true)){ gm_fail(400,'Bad request'); }
$st=$dbcnx->prepare('SELECT m.*, o.broker_id, o.operator_id, o.is_published, o.status FROM sfm_generated_model_merges m JOIN tour_orders o ON o.id=m.order_id WHERE m.id=? LIMIT 1'); if(!$st){gm_fail(500,'DB prepare error');}
$st->bind_param('i',$mergeId); $st->execute(); $m=$st->get_result()->fetch_assoc(); $st->close(); if(!$m){gm_fail(404,'Merge not found');}
if(!gm_can($m,$uid,$role)){ gm_fail(403,'Forbidden'); }
$path=$file==='ply'?(string)$m['output_path']:(string)$m['result_json_path']; if($path==='' || !gm_inside($path) || !is_file($path) || filesize($path)<=0){ gm_fail(404,'File not found'); }
header('Content-Type: '.($file==='result'?'application/json':'application/octet-stream'));
header('Content-Length: '.filesize($path));
header('Content-Disposition: attachment; filename="'.($file==='result'?'merge_result.json':'merged_dense_cloud.ply').'"');
readfile($path);