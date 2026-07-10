<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
auth_require_login();
$user=auth_current_user(); $uid=(int)$user['id']; $role=(string)($user['role'] ?? 'BROKER');
function cb_out(string $m,int $c=400): void { http_response_code($c); header('Content-Type: text/plain; charset=utf-8'); echo $m; exit; }
function cb_can_view(array $o,int $uid,string $role): bool { return $role==='ADMIN' || (int)$o['broker_id']===$uid || ($role==='OPERATOR' && ((int)$o['operator_id']===$uid || ((int)$o['is_published']===1 && (string)$o['status']==='NEW' && $o['operator_id']===null))); }
function cb_inside(string $path,string $dir): bool { $dir=rtrim($dir,DIRECTORY_SEPARATOR); return $path===$dir || str_starts_with($path,$dir.DIRECTORY_SEPARATOR); }
$id=(int)($_GET['capture_bundle_id'] ?? 0); if($id<=0){ cb_out('Bad capture_bundle_id',400); }
$st=$dbcnx->prepare('SELECT * FROM capture_bundles WHERE id=? LIMIT 1'); if(!$st){ cb_out('capture_bundles unavailable',500); }
$st->bind_param('i',$id); $st->execute(); $bundle=$st->get_result()->fetch_assoc(); $st->close(); if(!$bundle){ cb_out('Not found',404); }
$oid=(int)$bundle['order_id']; $st=$dbcnx->prepare('SELECT id,broker_id,operator_id,is_published,status FROM tour_orders WHERE id=? LIMIT 1'); $st->bind_param('i',$oid); $st->execute(); $order=$st->get_result()->fetch_assoc(); $st->close(); if(!$order || !cb_can_view($order,$uid,$role)){ cb_out('Forbidden',403); }
$storage=(string)$bundle['storage_path']; $path=str_starts_with($storage,'/')?$storage:rtrim(APP_STORAGE_DIR,'/').'/'.ltrim($storage,'/');
$sidecar=(string)($_GET['sidecar'] ?? '');
if($sidecar==='manifest'){ $path=$path.'.json'; }
elseif($sidecar!==''){ cb_out('Bad sidecar',400); }
$real=realpath($path); $orders=realpath(rtrim(APP_STORAGE_DIR,'/').'/orders'); if($real===false || $orders===false || !is_file($real) || !cb_inside($real,$orders)){ cb_out('File unavailable',404); }
if(!preg_match('/\.(tgz|json)$/i',$real)){ cb_out('File type not allowed',403); }
$download=basename($real); header('Content-Type: '.(preg_match('/\.json$/i',$real)?'application/json':'application/gzip')); header('Content-Length: '.(string)filesize($real)); header('Content-Disposition: attachment; filename="'.str_replace('"','',$download).'"'); readfile($real);