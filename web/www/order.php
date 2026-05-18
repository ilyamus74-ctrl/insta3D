<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
auth_require_login();
$user = auth_current_user(); $userId=(int)$user['id']; $role=$user['role'] ?? 'BROKER';
$orderId=(int)($_GET['id']??0); if($orderId<=0){http_response_code(400);exit('Bad order id');}

function status_meta(string $status): array { $m=['NEW'=>['bg-secondary','bi-circle','Новая'],'ASSIGNED'=>['bg-primary','bi-person-check','В работе'],'IN_PROGRESS'=>['bg-info','bi-camera','Съемка'],'CAPTURED'=>['bg-warning','bi-check2-square','Отснята'],'UPLOADING'=>['bg-warning','bi-cloud-upload','Загружается'],'UPLOADED'=>['bg-success','bi-cloud-check','Загружена'],'PROCESSING'=>['bg-info','bi-gear','Обработка'],'READY'=>['bg-success','bi-check-circle','Готова'],'COMPLETED'=>['bg-dark','bi-check2-all','Завершена'],'CLOSED'=>['bg-dark','bi-lock','Закрыта']]; $x=$m[$status]??['bg-secondary','bi-circle',$status]; return ['class'=>$x[0],'icon'=>$x[1],'label'=>$x[2]]; }
function load_order(mysqli $dbcnx,int $orderId): ?array { $stmt=$dbcnx->prepare("SELECT o.*,b.full_name broker_name,b.email broker_email,op.full_name operator_name,op.email operator_email FROM tour_orders o LEFT JOIN users b ON b.id=o.broker_id LEFT JOIN users op ON op.id=o.operator_id WHERE o.id=? LIMIT 1"); if(!$stmt){return null;} $stmt->bind_param('i',$orderId); $stmt->execute(); $o=$stmt->get_result()->fetch_assoc()?:null; $stmt->close(); return $o; }
function bytes_human($bytes): string { $b=(float)$bytes; if($b<=0){return '0 B';} $u=['B','KB','MB','GB','TB']; $i=0; while($b>=1024 && $i<count($u)-1){$b/=1024;$i++;} return round($b,2).' '.$u[$i]; }

$order=load_order($dbcnx,$orderId); if(!$order){http_response_code(404);exit('Order not found');}
$canView = $role==='ADMIN' || ((int)$order['broker_id']===$userId) || ($role==='OPERATOR' && ((int)$order['operator_id']===$userId || ((int)$order['is_published']===1 && $order['status']==='NEW' && $order['operator_id']===null)));
if(!$canView){http_response_code(403);exit('Forbidden');}
$canEdit = $role==='ADMIN' || (int)$order['broker_id']===$userId;
$canDeleteMedia = $role==='ADMIN' || (int)$order['broker_id']===$userId || ($role==='OPERATOR' && (int)$order['operator_id']===$userId);
$canOperatorClose = $role==='ADMIN' || ($role==='OPERATOR' && (int)$order['operator_id']===$userId && empty($order['operator_closed_at']));
$canBrokerClose = $role==='ADMIN' || ((int)$order['broker_id']===$userId && empty($order['broker_closed_at']));
$canReopen = $role==='ADMIN' || (int)$order['broker_id']===$userId;
$canCreatePublicLink = $role==='ADMIN' || (int)$order['broker_id']===$userId || ($role==='OPERATOR' && (int)$order['operator_id']===$userId);
$error=null; $success=isset($_GET['updated'])?'Заявка обновлена':(isset($_GET['closed'])?'Заявка закрыта':(isset($_GET['reopened'])?'Заявка переоткрыта':(isset($_GET['job_queued'])?'Задача обработки меток поставлена в очередь':(isset($_GET['photo_deleted'])?'Снимок удалён':(isset($_GET['session_deleted'])?'Сессия удалена':null)))));

function table_exists(mysqli $dbcnx,string $table): bool { $t=$dbcnx->real_escape_string($table); $r=$dbcnx->query("SHOW TABLES LIKE '".$t."'"); $ok=$r && $r->num_rows>0; if($r){$r->close();} return $ok; }
function column_exists(mysqli $dbcnx,string $table,string $column): bool { $t=$dbcnx->real_escape_string($table); $c=$dbcnx->real_escape_string($column); $r=$dbcnx->query("SHOW COLUMNS FROM `".$t."` LIKE '".$c."'"); $ok=$r && $r->num_rows>0; if($r){$r->close();} return $ok; }
function move_session_to_trash(int $orderId,string $appSessionUuid): ?string {
  $safeUuid=preg_replace('/[^a-zA-Z0-9._-]+/','_',$appSessionUuid);
  $srcBase=realpath(APP_STORAGE_DIR.'/orders'); if($srcBase===false){return null;}
  $src=$srcBase.'/'.$orderId.'/sessions/'.$safeUuid;
  if(is_link($src) || !is_dir($src)){return null;}
  $realSrc=realpath($src); if($realSrc===false || strpos($realSrc,$srcBase.'/')!==0){return null;}
  $trashBase=APP_STORAGE_DIR.'/.trash/orders/'.$orderId.'/sessions';
  if(!is_dir($trashBase) && !@mkdir($trashBase,0775,true) && !is_dir($trashBase)){ return null; }
  $dst=$trashBase.'/'.$safeUuid.'.'.date('Ymd_His');
  if(!@rename($realSrc,$dst)){ error_log('Failed to move deleted capture session to trash src='.$realSrc.' dst='.$dst); return null; }
  return ltrim(str_replace(APP_STORAGE_DIR.'/','',$dst),'/');
}

if($_SERVER['REQUEST_METHOD']==='POST'){
 $action=$_POST['action']??'';
 if($action==='update_order' && $canEdit){
   if($role!=='ADMIN' && $order['status']==='CLOSED'){ $error='Закрытую заявку редактировать нельзя'; }
   else {
    $title=trim($_POST['title']??''); $address=trim($_POST['address']??''); $area=trim($_POST['area_m2']??''); $cn=trim($_POST['customer_name']??''); $cp=trim($_POST['customer_phone']??''); $ce=trim($_POST['customer_email']??''); $pub=isset($_POST['is_published'])?1:0; $areaV=$area!==''?(float)$area:null;
    $st=$dbcnx->prepare("UPDATE tour_orders SET title=?,address=?,area_m2=?,customer_name=?,customer_phone=?,customer_email=?,is_published=? WHERE id=?");
    if($st){$st->bind_param('ssdsssii',$title,$address,$areaV,$cn,$cp,$ce,$pub,$orderId); if($st->execute()){audit_log($userId,'ORDER_UPDATED','TOUR_ORDER',$orderId,'Заявка обновлена');$st->close();header('Location: /order.php?id='.$orderId.'&updated=1');exit;} $error='DB execute error: '.$st->error; $st->close();}
   }
 }


 if($action==='create_processing_job_web' && $canEdit){
   $captureSessionId=(int)($_POST['capture_session_id']??0);
   if($captureSessionId<=0){
     $error='Не выбрана capture session для обработки';
   } else {
     $st=$dbcnx->prepare("SELECT id FROM capture_sessions WHERE id=? AND order_id=? LIMIT 1");
     if($st){
       $st->bind_param('ii',$captureSessionId,$orderId);
       $st->execute();
       $sessionExists=$st->get_result()->fetch_assoc();
       $st->close();
       if(!$sessionExists){
         $error='Capture session не найдена для этой заявки';
       } else {
         $jobType='MARKER_DETECTION';
         $st=$dbcnx->prepare("INSERT INTO processing_jobs (session_id, order_id, job_type, status, metric_status, marker_expected, marker_kit_id, marker_dictionary, marker_size_m) VALUES (?, ?, ?, 'QUEUED', 'UNKNOWN', 1, 'maklertour_kit_v1', 'APRILTAG_36H11', 0.1600) ON DUPLICATE KEY UPDATE updated_at = NOW(6)");
         if($st){
           $st->bind_param('iis',$captureSessionId,$orderId,$jobType);
           if($st->execute()){
             $st->close();
             audit_log($userId,'PROCESSING_JOB_CREATED_WEB','TOUR_ORDER',$orderId,'Создана задача обработки marker detection из web',['capture_session_id'=>$captureSessionId,'job_type'=>$jobType]);
             header('Location: /order.php?id='.$orderId.'&job_queued=1');
             exit;
           }
           $error='DB execute error: '.$st->error;
           $st->close();
         }
       }
     }
   }
 }
 if($action==='operator_close_order' && $canOperatorClose){
   $st=$dbcnx->prepare("UPDATE tour_orders SET operator_closed_at=NOW(6), operator_closed_by=?, status=IF(broker_closed_at IS NULL,'READY','COMPLETED') WHERE id=?"); if($st){$st->bind_param('ii',$userId,$orderId);$st->execute();$st->close();audit_log($userId,'ORDER_OPERATOR_CLOSED','TOUR_ORDER',$orderId,'Закрытие со стороны оператора');header('Location: /order.php?id='.$orderId.'&closed=1');exit;}
 }
 if($action==='broker_close_order' && $canBrokerClose){
   $st=$dbcnx->prepare("UPDATE tour_orders SET broker_closed_at=NOW(6), broker_closed_by=?, status=IF(operator_closed_at IS NULL,status,'COMPLETED') WHERE id=?"); if($st){$st->bind_param('ii',$userId,$orderId);$st->execute();$st->close();audit_log($userId,'ORDER_BROKER_CLOSED','TOUR_ORDER',$orderId,'Закрытие со стороны брокера');header('Location: /order.php?id='.$orderId.'&closed=1');exit;}
 }
 if($action==='reopen_order' && $canReopen){
   $st=$dbcnx->prepare("UPDATE tour_orders SET operator_closed_at=NULL,operator_closed_by=NULL,broker_closed_at=NULL,broker_closed_by=NULL,status=IF(operator_id IS NULL,'NEW','ASSIGNED') WHERE id=?"); if($st){$st->bind_param('i',$orderId);$st->execute();$st->close();audit_log($userId,'ORDER_REOPENED','TOUR_ORDER',$orderId,'Заявка переоткрыта');header('Location: /order.php?id='.$orderId.'&reopened=1');exit;}
 }

 if($action==='delete_photo_point' && $canDeleteMedia){
   $photoPointId=(int)($_POST['photo_point_id']??0);
   if($photoPointId<=0){ $error='Неверный photo point'; }
   else{
     $dbcnx->begin_transaction();
     try{
       $st=$dbcnx->prepare("SELECT pp.id, pp.session_id FROM photo_points pp JOIN capture_sessions cs ON cs.id = pp.session_id WHERE pp.id = ? AND cs.order_id = ? AND pp.deleted_at IS NULL AND cs.deleted_at IS NULL LIMIT 1 FOR UPDATE");
       if(!$st){ throw new RuntimeException('prepare failed'); }
       $st->bind_param('ii',$photoPointId,$orderId); $st->execute(); $pp=$st->get_result()->fetch_assoc(); $st->close();
       if(!$pp){ throw new RuntimeException('Фото уже удалено или не найдено'); }
       $set=["deleted_at = NOW(6)","deleted_by = ?","delete_reason = 'deleted_from_order_web'"]; if(column_exists($dbcnx,'photo_points','upload_state')){$set[]="upload_state = 'DELETED'";} if(column_exists($dbcnx,'photo_points','updated_at')){$set[]="updated_at = NOW(6)";}
       $sql="UPDATE photo_points SET ".implode(', ',$set)." WHERE id = ?"; $st=$dbcnx->prepare($sql); if(!$st){ throw new RuntimeException('prepare failed'); } $st->bind_param('ii',$userId,$photoPointId); $st->execute(); $st->close();
       $sid=(int)$pp['session_id'];
       if(table_exists($dbcnx,'marker_detections') && column_exists($dbcnx,'marker_detections','source_type') && column_exists($dbcnx,'marker_detections','source_id')){ $st=$dbcnx->prepare("DELETE FROM marker_detections WHERE session_id = ? AND source_type = 'PHOTO_POINT' AND source_id = ?"); if($st){$st->bind_param('ii',$sid,$photoPointId);$st->execute();$st->close();}}
       if(table_exists($dbcnx,'tour_point_links')){ $st=$dbcnx->prepare("DELETE FROM tour_point_links WHERE session_id = ? AND (from_photo_point_id = ? OR to_photo_point_id = ?)"); if($st){$st->bind_param('iii',$sid,$photoPointId,$photoPointId);$st->execute();$st->close();}}
       if(table_exists($dbcnx,'tour_point_positions')){ $st=$dbcnx->prepare("DELETE FROM tour_point_positions WHERE session_id = ? AND photo_point_id = ?"); if($st){$st->bind_param('ii',$sid,$photoPointId);$st->execute();$st->close();}}
       $dbcnx->commit();
       audit_log($userId,'PHOTO_POINT_DELETED','TOUR_ORDER',$orderId,'Фото удалено из web',['photo_point_id'=>$photoPointId,'capture_session_id'=>$sid]);
       header('Location: /order.php?id='.$orderId.'&photo_deleted=1'); exit;
     }catch(Throwable $e){ $dbcnx->rollback(); $error=$e->getMessage(); }
   }
 }
 if($action==='delete_capture_session' && $canDeleteMedia){
   $captureSessionId=(int)($_POST['capture_session_id']??0);
   if($captureSessionId<=0){ $error='Неверная capture session'; }
   else{
     $trashPath=null;$appSessionUuid='';
     $dbcnx->begin_transaction();
     try{
       $st=$dbcnx->prepare("SELECT id, order_id, app_session_uuid FROM capture_sessions WHERE id = ? AND order_id = ? AND deleted_at IS NULL LIMIT 1 FOR UPDATE");
       if(!$st){ throw new RuntimeException('prepare failed'); }
       $st->bind_param('ii',$captureSessionId,$orderId); $st->execute(); $sess=$st->get_result()->fetch_assoc(); $st->close();
       if(!$sess){ throw new RuntimeException('Сессия уже удалена или не найдена'); }
       $appSessionUuid=(string)($sess['app_session_uuid'] ?? '');
       $set=["deleted_at = NOW(6)","deleted_by = ?","delete_reason = 'deleted_from_order_web'"]; if(column_exists($dbcnx,'capture_sessions','updated_at')){$set[]="updated_at = NOW(6)";}
       $st=$dbcnx->prepare("UPDATE capture_sessions SET ".implode(', ',$set)." WHERE id = ?"); if(!$st){ throw new RuntimeException('prepare failed'); } $st->bind_param('ii',$userId,$captureSessionId); $st->execute(); $st->close();
       $ppSet=["deleted_at = NOW(6)","deleted_by = ?","delete_reason = 'session_deleted_from_order_web'"]; if(column_exists($dbcnx,'photo_points','upload_state')){$ppSet[]="upload_state = 'DELETED'";} if(column_exists($dbcnx,'photo_points','updated_at')){$ppSet[]="updated_at = NOW(6)";}
       $st=$dbcnx->prepare("UPDATE photo_points SET ".implode(', ',$ppSet)." WHERE session_id = ? AND deleted_at IS NULL"); if($st){$st->bind_param('ii',$userId,$captureSessionId);$st->execute();$st->close();}
       $vsSet=["deleted_at = NOW(6)","deleted_by = ?","delete_reason = 'session_deleted_from_order_web'"]; if(column_exists($dbcnx,'video_scans','upload_state')){$vsSet[]="upload_state = 'DELETED'";} if(column_exists($dbcnx,'video_scans','updated_at')){$vsSet[]="updated_at = NOW(6)";}
       $st=$dbcnx->prepare("UPDATE video_scans SET ".implode(', ',$vsSet)." WHERE session_id = ? AND deleted_at IS NULL"); if($st){$st->bind_param('ii',$userId,$captureSessionId);$st->execute();$st->close();}
       foreach(['marker_detections','tour_point_links','tour_point_positions','processing_jobs','public_tour_links'] as $tbl){ if(table_exists($dbcnx,$tbl)){ $st=$dbcnx->prepare("DELETE FROM ".$tbl." WHERE session_id = ?"); if($st){$st->bind_param('i',$captureSessionId);$st->execute();$st->close();} } }
       $dbcnx->commit();
       $trashPath=move_session_to_trash($orderId,$appSessionUuid);
       audit_log($userId,'CAPTURE_SESSION_DELETED','TOUR_ORDER',$orderId,'Сессия удалена из web',['order_id'=>$orderId,'capture_session_id'=>$captureSessionId,'app_session_uuid'=>$appSessionUuid,'trash_path'=>$trashPath]);
       header('Location: /order.php?id='.$orderId.'&session_deleted=1'); exit;
     }catch(Throwable $e){ $dbcnx->rollback(); $error=$e->getMessage(); }
   }
 }
}
$order=load_order($dbcnx,$orderId); $order['status_meta']=status_meta((string)$order['status']);

$captureSessions=[];$videoScans=[];$capturePoints=[];$photoPoints=[];

$stmt=$dbcnx->prepare("SELECT * FROM capture_sessions WHERE order_id=? AND deleted_at IS NULL ORDER BY created_at DESC, id DESC");
if($stmt){
  $stmt->bind_param('i',$orderId);
  $stmt->execute();
  $rs=$stmt->get_result();
  while($row=$rs->fetch_assoc()){
    $row['photos']=[];
    $row['videos']=[];
    $row['photo_count']=0;
    $row['video_count']=0;
    $captureSessions[]=$row;
  }
  $stmt->close();
}
$sessionById=[];
foreach($captureSessions as $k=>$s){$sessionById[(int)$s['id']]=$k;}

$stmt=$dbcnx->prepare("SELECT pp.*, cs.app_session_uuid FROM photo_points pp JOIN capture_sessions cs ON cs.id = pp.session_id WHERE cs.order_id = ? AND pp.deleted_at IS NULL AND cs.deleted_at IS NULL AND COALESCE(pp.upload_state,'') <> 'DELETED' ORDER BY cs.created_at DESC, pp.sequence_number ASC, pp.created_at ASC, pp.id ASC");
if($stmt){
  $stmt->bind_param('i',$orderId);
  $stmt->execute();
  $rs=$stmt->get_result();
  while($p=$rs->fetch_assoc()){
    $p['display_name']=($p['name'] ?? '')!=='' ? $p['name'] : ((($p['title'] ?? '')!=='') ? $p['title'] : ('Photo #'.$p['id']));
    $previewPath=(string)($p['preview_storage_path'] ?? ''); if($previewPath===''){$previewPath=(string)($p['preview_path'] ?? '');}
    $originalPath=(string)($p['original_storage_path'] ?? ''); if($originalPath===''){$originalPath=(string)($p['original_path'] ?? '');}
    $p['preview_effective_path']=$previewPath;
    $p['original_effective_path']=$originalPath;
    $p['preview_url']=$previewPath!==''?('/media.php?path='.rawurlencode($previewPath)):'';
    $p['original_url']=$originalPath!==''?('/media.php?path='.rawurlencode($originalPath)):'';
    $p['preview_size_human']=bytes_human((float)($p['preview_size_bytes'] ?? 0));
    $p['original_size_human']=bytes_human((float)($p['original_size_bytes'] ?? 0));
    $photoPoints[]=$p;
    $capturePoints[]=$p;
    $sid=(int)$p['session_id'];
    if(isset($sessionById[$sid])){ $idx=$sessionById[$sid]; $captureSessions[$idx]['photos'][]=$p; $captureSessions[$idx]['photo_count']++; }
  }
  $stmt->close();
}

$stmt=$dbcnx->prepare("SELECT vs.*, cs.app_session_uuid FROM video_scans vs JOIN capture_sessions cs ON cs.id = vs.session_id WHERE cs.order_id = ? AND vs.deleted_at IS NULL AND cs.deleted_at IS NULL AND COALESCE(vs.upload_state,'') <> 'DELETED' ORDER BY cs.created_at DESC, vs.created_at DESC, vs.id DESC");
if($stmt){
  $stmt->bind_param('i',$orderId);
  $stmt->execute();
  $rs=$stmt->get_result();
  while($v=$rs->fetch_assoc()){
    $v['media_url']=(!empty($v['storage_path']))?('/media.php?path='.rawurlencode((string)$v['storage_path'])):'';
    $v['size_human']=bytes_human((float)($v['size_bytes'] ?? 0));
    $videoScans[]=$v;
    $sid=(int)$v['session_id'];
    if(isset($sessionById[$sid])){ $idx=$sessionById[$sid]; $captureSessions[$idx]['videos'][]=$v; $captureSessions[$idx]['video_count']++; }
  }
  $stmt->close();
}


$processingJobsBySession = [];
$stmt=$dbcnx->prepare("SELECT * FROM processing_jobs WHERE order_id = ? AND session_id IN (SELECT id FROM capture_sessions WHERE order_id = ? AND deleted_at IS NULL) ORDER BY created_at DESC, id DESC");
if($stmt){
  $stmt->bind_param('ii',$orderId,$orderId);
  $stmt->execute();
  $rs=$stmt->get_result();
  while($job=$rs->fetch_assoc()){
    $sid=(int)$job['session_id'];
    if(!isset($processingJobsBySession[$sid])){
      $processingJobsBySession[$sid]=$job;
    }
  }
  $stmt->close();
}

$markerDetectionsBySession = [];
$stmt=$dbcnx->prepare("SELECT * FROM marker_detections WHERE session_id IN (SELECT id FROM capture_sessions WHERE order_id = ? AND deleted_at IS NULL) ORDER BY id DESC");
if($stmt){
  $stmt->bind_param('i',$orderId);
  $stmt->execute();
  $rs=$stmt->get_result();
  while($d=$rs->fetch_assoc()){
    $sid=(int)$d['session_id'];
    if(!isset($markerDetectionsBySession[$sid])){ $markerDetectionsBySession[$sid]=[]; }
    $markerDetectionsBySession[$sid][]=$d;
  }
  $stmt->close();
}

foreach($captureSessions as $idx=>$session){
  $sid=(int)$session['id'];
  $captureSessions[$idx]['processing_job']=$processingJobsBySession[$sid] ?? null;

  $detections = $markerDetectionsBySession[$sid] ?? [];
  $unique=[];
  $sourceCounts=['PHOTO_POINT'=>0,'VIDEO_FRAME'=>0];
  foreach($detections as $det){
    $mid=(int)($det['marker_id'] ?? 0);
    if($mid>0){ $unique[$mid]=true; }
    $stype=(string)($det['source_type'] ?? '');
    if(isset($sourceCounts[$stype])){ $sourceCounts[$stype]++; }
  }
  $markerIds=array_keys($unique);
  sort($markerIds);
  $captureSessions[$idx]['marker_detections']=$detections;
  $captureSessions[$idx]['marker_unique_ids']=$markerIds;
  $captureSessions[$idx]['marker_detections_count']=count($detections);
  $captureSessions[$idx]['marker_source_counts']=$sourceCounts;
}

$publicLinksBySession=[];
$publicLinksBySession = [];

$hasPublicTourLinks = false;
$res = $dbcnx->query("SHOW TABLES LIKE 'public_tour_links'");
if ($res) {
    $hasPublicTourLinks = $res->num_rows > 0;
    $res->close();
}

if ($hasPublicTourLinks) {
    $stmt = $dbcnx->prepare("SELECT session_id, token, is_active FROM public_tour_links WHERE order_id=? ORDER BY id DESC");
    if ($stmt) {
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) {
            $sid = (int)$r['session_id'];
            if (!isset($publicLinksBySession[$sid])) {
                $publicLinksBySession[$sid] = $r;
            }
        }
        $stmt->close();
    }
}

foreach ($captureSessions as $idx => $session) {
    $sid = (int)$session['id'];
    $captureSessions[$idx]['public_link'] = $publicLinksBySession[$sid] ?? null;
}

$mediaTotals=['sessions'=>count($captureSessions),'photos'=>count($photoPoints),'videos'=>count($videoScans)];

$smarty->assign('current_user',$user);
$smarty->assign('order',$order);
$smarty->assign('canEdit',$canEdit);
$smarty->assign('canDeleteMedia',$canDeleteMedia);
$smarty->assign('canCreatePublicLink', $canCreatePublicLink);
$smarty->assign('canOperatorClose',$canOperatorClose);
$smarty->assign('canBrokerClose',$canBrokerClose);
$smarty->assign('canReopen',$canReopen);
$smarty->assign('captureSessions',$captureSessions);
$smarty->assign('photoPoints',$photoPoints);
$smarty->assign('capturePoints',$capturePoints);
$smarty->assign('videoScans',$videoScans);
$smarty->assign('mediaTotals',$mediaTotals);
$smarty->assign('error',$error);
$smarty->assign('success',$success);
$smarty->display('maklertour_order.html');
