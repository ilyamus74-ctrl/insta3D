<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/api/form_helpers.php';

$config = require __DIR__ . '/../configs/maklertour_config.php';

function mt_order_status_meta(string $status): array {
    $map = [
        'NEW' => ['bg-secondary', 'bi-circle', 'Новая'],
        'ASSIGNED' => ['bg-primary', 'bi-person-check', 'В работе'],
        'IN_PROGRESS' => ['bg-info', 'bi-camera', 'Съемка'],
        'CAPTURED' => ['bg-warning', 'bi-check2-square', 'Отснята'],
        'UPLOADING' => ['bg-warning', 'bi-cloud-upload', 'Загружается'],
        'UPLOADED' => ['bg-success', 'bi-cloud-check', 'Загружена'],
        'PROCESSING' => ['bg-info', 'bi-gear', 'Обработка'],
        'READY' => ['bg-success', 'bi-check-circle', 'Готова оператором'],
        'COMPLETED' => ['bg-dark', 'bi-check2-all', 'Завершена'],
        'CLOSED' => ['bg-dark', 'bi-lock', 'Архив'],
    ];
    $meta = $map[$status] ?? ['bg-secondary', 'bi-circle', $status];
    return ['class' => $meta[0], 'icon' => $meta[1], 'label' => $meta[2]];
}

function mt_count_operator_active(mysqli $dbcnx, int $userId): int {
    $st = $dbcnx->prepare("SELECT COUNT(*) c FROM tour_orders WHERE operator_id=? AND operator_closed_at IS NULL AND status NOT IN ('COMPLETED','CLOSED')");
    $st->bind_param('i', $userId); $st->execute(); $c=(int)($st->get_result()->fetch_assoc()['c']??0); $st->close(); return $c;
}
function mt_count_broker_active(mysqli $dbcnx, int $userId): int {
    $st = $dbcnx->prepare("SELECT COUNT(*) c FROM tour_orders WHERE broker_id=? AND broker_closed_at IS NULL AND status NOT IN ('COMPLETED','CLOSED')");
    $st->bind_param('i', $userId); $st->execute(); $c=(int)($st->get_result()->fetch_assoc()['c']??0); $st->close(); return $c;
}
auth_require_login();
$user = auth_current_user(); $userId=(int)$user['id']; $role=$user['role']??'BROKER';
$error=null; $success = isset($_GET['created'])?'Заявка создана':(isset($_GET['taken'])?'Заявка взята в работу':null);

if ($_SERVER['REQUEST_METHOD']==='POST') {
 $action=$_POST['action']??'';
 if ($action==='create_order') {
  $formToken = trim($_POST['form_token'] ?? '');
  if (!mt_consume_form_token($dbcnx,$userId,'create_order',$formToken)) $error='Форма устарела, обновите страницу.';
  elseif (mt_count_broker_active($dbcnx,$userId) >= (int)$config['max_active_orders_per_broker']) $error='Достигнут лимит активных заявок брокера.';
  else {
   $title=trim($_POST['title']??''); $address=trim($_POST['address']??'');
   if($title===''||$address===''){ $error='Заполните название и адрес объекта'; }
   else {
    $areaM2=trim($_POST['area_m2']??''); $areaValue=$areaM2!==''?(float)$areaM2:null; $cn=trim($_POST['customer_name']??''); $cp=trim($_POST['customer_phone']??''); $ce=trim($_POST['customer_email']??''); $pub=isset($_POST['is_published'])?1:0; $token=bin2hex(random_bytes(16));
    $createdOperatorId = $role === 'OPERATOR' ? $userId : null;
    $createdStatus = $role === 'OPERATOR' ? 'ASSIGNED' : 'NEW';
    $st=$dbcnx->prepare("INSERT INTO tour_orders (broker_id,operator_id,title,address,area_m2,customer_name,customer_phone,customer_email,status,is_published,public_token) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    if($st){$st->bind_param('iissdssssis',$userId,$createdOperatorId,$title,$address,$areaValue,$cn,$cp,$ce,$createdStatus,$pub,$token); if($st->execute()){ $id=(int)$st->insert_id; audit_log($userId,'ORDER_CREATED','TOUR_ORDER',$id,'Создана заявка'); $st->close(); header('Location: /orders.php?created=1'); exit;} $error='DB execute error: '.$st->error; $st->close();}
   }
  }
 }
 if ($action==='take_order' && in_array($role,['ADMIN','OPERATOR'],true)) {
  if (mt_count_operator_active($dbcnx,$userId) >= (int)$config['max_active_orders_per_operator']) $error='Достигнут лимит активных заявок оператора.';
  else { $oid=(int)($_POST['order_id']??0); $st=$dbcnx->prepare("UPDATE tour_orders SET operator_id=?,status='ASSIGNED' WHERE id=? AND status='NEW' AND operator_id IS NULL AND is_published=1"); if($st){$st->bind_param('ii',$userId,$oid); $st->execute(); if($st->affected_rows===1){audit_log($userId,'ORDER_TAKEN','TOUR_ORDER',$oid,'Оператор взял заявку в работу');$st->close();header('Location: /orders.php?taken=1');exit;} $st->close(); $error='Заявку уже взяли или она недоступна';}}
 }
 if ($action==='operator_close_order') {
  $oid=(int)($_POST['order_id']??0);
  $st=$dbcnx->prepare("SELECT broker_closed_at, operator_id FROM tour_orders WHERE id=? LIMIT 1"); $st->bind_param('i',$oid); $st->execute(); $o=$st->get_result()->fetch_assoc(); $st->close();
  if($o && ($role==='ADMIN' || ($role==='OPERATOR' && (int)$o['operator_id']===$userId))) {
    $status = !empty($o['broker_closed_at']) ? 'COMPLETED' : 'READY';
    $st=$dbcnx->prepare("UPDATE tour_orders SET operator_closed_at=NOW(6), operator_closed_by=?, status=? WHERE id=?");
    if($st){
        $st->bind_param('isi',$userId,$status,$oid);
        if($st->execute()){ $st->close(); audit_log($userId,'ORDER_OPERATOR_CLOSED','TOUR_ORDER',$oid,'Оператор закрыл свою часть'); header('Location: /orders.php'); exit; }
        $error='DB execute error: '.$st->error;
        $st->close();
    } else {
        $error='DB prepare error: '.$dbcnx->error;
    }
  }
 }
 if ($action==='broker_close_order') {
  $oid=(int)($_POST['order_id']??0);
  $st=$dbcnx->prepare("SELECT operator_closed_at, broker_id, status FROM tour_orders WHERE id=? LIMIT 1"); $st->bind_param('i',$oid); $st->execute(); $o=$st->get_result()->fetch_assoc(); $st->close();
  if($o && ($role==='ADMIN' || (int)$o['broker_id']===$userId)) {
    $status = !empty($o['operator_closed_at']) ? 'COMPLETED' : (string)$o['status'];
    $st=$dbcnx->prepare("UPDATE tour_orders SET broker_closed_at=NOW(6), broker_closed_by=?, status=? WHERE id=?");
    if($st){
        $st->bind_param('isi',$userId,$status,$oid);
        if($st->execute()){ $st->close(); audit_log($userId,'ORDER_BROKER_CLOSED','TOUR_ORDER',$oid,'Брокер закрыл свою часть'); header('Location: /orders.php'); exit; }
        $error='DB execute error: '.$st->error;
        $st->close();
    } else {
        $error='DB prepare error: '.$dbcnx->error;
    }
  }
 }
 if ($action==='reopen_order') {
  $oid=(int)($_POST['order_id']??0);
  $st=$dbcnx->prepare("SELECT operator_id, broker_id FROM tour_orders WHERE id=? LIMIT 1"); $st->bind_param('i',$oid); $st->execute(); $o=$st->get_result()->fetch_assoc(); $st->close();
  if($o && ($role==='ADMIN' || (int)$o['broker_id']===$userId)) {
   $status=((int)$o['operator_id']>0)?'ASSIGNED':'NEW';
   $st=$dbcnx->prepare("UPDATE tour_orders SET operator_closed_at=NULL,operator_closed_by=NULL,broker_closed_at=NULL,broker_closed_by=NULL,status=? WHERE id=?"); $st->bind_param('si',$status,$oid); $st->execute(); $st->close(); audit_log($userId,'ORDER_REOPENED','TOUR_ORDER',$oid,'Заявка переоткрыта'); header('Location: /orders.php'); exit;
  }
 }
}

$filters = [ 'scope'=>trim((string)($_GET['scope']??'')), 'status'=>trim((string)($_GET['status']??'')), 'is_published'=>trim((string)($_GET['is_published']??'')), 'customer'=>trim((string)($_GET['customer']??'')), 'area_min'=>trim((string)($_GET['area_min']??'')), 'area_max'=>trim((string)($_GET['area_max']??'')), 'created_from'=>trim((string)($_GET['created_from']??'')), 'created_to'=>trim((string)($_GET['created_to']??'')), 'updated_from'=>trim((string)($_GET['updated_from']??'')), 'updated_to'=>trim((string)($_GET['updated_to']??'')), 'broker_id'=>(int)($_GET['broker_id']??0), 'operator_id'=>(int)($_GET['operator_id']??0)];
if($filters['scope']===''){ $filters['scope']= $role==='OPERATOR'?'active,available':($role==='BROKER'?'active,ready':'active'); }
$scopeParts = array_values(array_intersect(['active','available','mine','ready','completed','all'], array_map('trim', explode(',',$filters['scope']))));
if(!$scopeParts){$scopeParts=['active'];}

$conds=[];$types='';$vals=[];
if($role==='ADMIN'){ $base='1=1'; }
elseif($role==='OPERATOR'){ $base="(o.operator_id=? OR o.broker_id=? OR (o.is_published=1 AND o.status='NEW' AND o.operator_id IS NULL))"; $types.='ii'; $vals[]=$userId; $vals[]=$userId; }
else { $base='o.broker_id=?'; $types.='i'; $vals[]=$userId; }
$scopeConds=[]; foreach($scopeParts as $s){ if($s==='all'){ $scopeConds=['1=1']; break; } if($s==='active')$scopeConds[]="(o.status NOT IN ('READY','COMPLETED','CLOSED') AND (o.operator_closed_at IS NULL OR o.broker_closed_at IS NULL))"; if($s==='available')$scopeConds[]="(o.status='NEW' AND o.operator_id IS NULL AND o.is_published=1)"; if($s==='mine')$scopeConds[]="(o.broker_id={$userId} OR o.operator_id={$userId})"; if($s==='ready')$scopeConds[]="(o.status='READY' OR o.operator_closed_at IS NOT NULL)"; if($s==='completed')$scopeConds[]="(o.status='COMPLETED')"; }
$conds[]='('.implode(' OR ',$scopeConds).')';
if($filters['status']!==''){ $conds[]='o.status=?'; $types.='s'; $vals[]=$filters['status']; }
if($filters['is_published']!==''){ $conds[]='o.is_published=?'; $types.='i'; $vals[]=(int)$filters['is_published']; }
if($filters['broker_id']>0){ $conds[]='o.broker_id=?'; $types.='i'; $vals[]=$filters['broker_id']; }
if($filters['operator_id']>0){ $conds[]='o.operator_id=?'; $types.='i'; $vals[]=$filters['operator_id']; }
if($filters['customer']!==''){ $conds[]='o.customer_name LIKE ?'; $types.='s'; $vals[]='%'.$filters['customer'].'%'; }
if($filters['area_min']!==''){ $conds[]='o.area_m2 >= ?'; $types.='d'; $vals[]=(float)$filters['area_min']; }
if($filters['area_max']!==''){ $conds[]='o.area_m2 <= ?'; $types.='d'; $vals[]=(float)$filters['area_max']; }
foreach([['created_from','created_at >= ?'],['created_to','created_at <= ?'],['updated_from','updated_at >= ?'],['updated_to','updated_at <= ?']] as $d){ if($filters[$d[0]]!==''){ $conds[]='o.'.$d[1]; $types.='s'; $vals[]=$filters[$d[0]].' 00:00:00'; }}
$sql="SELECT o.*, b.full_name broker_name, op.full_name operator_name FROM tour_orders o LEFT JOIN users b ON b.id=o.broker_id LEFT JOIN users op ON op.id=o.operator_id WHERE {$base} AND ".implode(' AND ',$conds)." ORDER BY o.updated_at DESC LIMIT 300";
$st=$dbcnx->prepare($sql); if($st){ if($types!=='') $st->bind_param($types,...$vals); $st->execute(); $rs=$st->get_result(); $orders=[]; while($r=$rs->fetch_assoc()){$r['status_meta']=mt_order_status_meta((string)$r['status']); $orders[]=$r;} $st->close(); } else {$orders=[]; $error='DB prepare error: '.$dbcnx->error;}

$createOrderToken = mt_create_form_token($dbcnx, $userId, 'create_order');
$smarty->assign('createOrderToken',$createOrderToken); $smarty->assign('current_user',$user); $smarty->assign('orders',$orders); $smarty->assign('filters',$filters); $smarty->assign('error',$error); $smarty->assign('success',$success);

$smarty->display('maklertour_orders.html');
