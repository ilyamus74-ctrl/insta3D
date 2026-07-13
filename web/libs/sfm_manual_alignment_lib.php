<?php
declare(strict_types=1);

const SFM_MANUAL_MERGE_TYPE = 'manual_correspondences_sim3_dense_ply';
const SFM_MANUAL_MERGE_METHOD = 'manual_correspondences_umeyama_sim3';

function sfm_manual_output_root(): string { return dirname(__DIR__) . '/remote_station/output'; }
function sfm_manual_safe_realpath(string $path, bool $mustFile=true): string {
    $root=realpath(sfm_manual_output_root()); if($root===false){ throw new RuntimeException('SfM output root not found'); }
    $real=realpath($path); if($real===false || ($mustFile && !is_file($real))){ throw new RuntimeException('Required file not found: '.basename($path)); }
    if($real!==$root && !str_starts_with($real,$root.DIRECTORY_SEPARATOR)){ throw new RuntimeException('Path is outside allowed SfM output root'); }
    return $real;
}
function sfm_manual_safe_dir(string $path): string {
    $root=realpath(sfm_manual_output_root()); if($root===false){ throw new RuntimeException('SfM output root not found'); }
    if(!is_dir($path) && !mkdir($path,0775,true) && !is_dir($path)){ throw new RuntimeException('Cannot create accepted directory'); }
    $real=realpath($path); if($real===false || ($real!==$root && !str_starts_with($real,$root.DIRECTORY_SEPARATOR))){ throw new RuntimeException('Accepted directory is outside SfM output root'); }
    return $real;
}
function sfm_manual_draft_dir(int $orderId,string $anchorKind,int $anchorId,string $sourceKind,int $sourceId): string {
    $name=sprintf('manual_alignment_order_%d_anchor_%s_%d_source_%s_%d',$orderId,preg_replace('/[^a-z0-9_]+/i','_',$anchorKind),$anchorId,preg_replace('/[^a-z0-9_]+/i','_',$sourceKind),$sourceId);
    return sfm_manual_output_root().'/'.$name;
}
function sfm_manual_can_write_order(array $order,int $userId,string $role): bool { return $role==='ADMIN'||(int)($order['broker_id']??0)===$userId||($role==='OPERATOR'&&(int)($order['operator_id']??0)===$userId); }
function sfm_manual_ensure_order_write_access(mysqli $db,int $orderId,int $userId,string $role): array {
    $st=$db->prepare('SELECT id,broker_id,operator_id,is_published,status FROM tour_orders WHERE id=? LIMIT 1'); if(!$st) throw new RuntimeException('DB prepare error: '.$db->error);
    $st->bind_param('i',$orderId); $st->execute(); $o=$st->get_result()->fetch_assoc(); $st->close();
    if(!$o) throw new RuntimeException('Order not found'); if(!sfm_manual_can_write_order($o,$userId,$role)) throw new RuntimeException('Forbidden: write access required'); return $o;
}
function sfm_manual_ply_vertices(string $path): int { $fh=@fopen($path,'rb'); if(!$fh) return 0; $n=0; while(($l=fgets($fh))!==false){ if(preg_match('/^element\s+vertex\s+(\d+)/',trim($l),$m)) $n=(int)$m[1]; if(trim($l)==='end_header') break; } fclose($fh); return $n; }
function sfm_manual_resolve_remote_model(mysqli $db,int $orderId,string $kind,int $id): array {
    if($kind!=='remote') throw new RuntimeException('Finalize supports dense remote jobs only');
    $st=$db->prepare("SELECT * FROM sfm_remote_jobs WHERE order_id=? AND remote_job_id=? AND job_type IN ('COLMAP_RECONSTRUCTION_PREVIEW','COLMAP_RECONSTRUCTION_HQ') AND status='DONE' LIMIT 1"); if(!$st) throw new RuntimeException('DB prepare error: '.$db->error);
    $st->bind_param('ii',$orderId,$id); $st->execute(); $j=$st->get_result()->fetch_assoc(); $st->close(); if(!$j) throw new RuntimeException('Dense remote job not found for this order');
    $p=json_decode((string)($j['parameters_json']??'{}'),true)?:[]; $ply=sfm_manual_safe_realpath(sfm_manual_output_root().'/job_'.$id.'/merged/merged_fused.ply');
    return ['db_job_id'=>(int)$j['id'],'remote_job_id'=>(int)$j['remote_job_id'],'capture_session_id'=>(int)($j['capture_session_id']??0),'pipeline_run_id'=>(int)($j['pipeline_run_id']??0),'parent_remote_job_id'=>(int)($j['parent_remote_job_id']??0),'model_id'=>isset($p['model_id'])?(int)$p['model_id']:null,'ply'=>$ply,'points'=>sfm_manual_ply_vertices($ply),'row'=>$j];
}
function sfm_manual_rotation_det(array $r): float { return (float)($r[0][0]*($r[1][1]*$r[2][2]-$r[1][2]*$r[2][1])-$r[0][1]*($r[1][0]*$r[2][2]-$r[1][2]*$r[2][0])+$r[0][2]*($r[1][0]*$r[2][1]-$r[1][1]*$r[2][0])); }
function sfm_manual_table_columns(mysqli $db,string $table): array { $rs=$db->query('SHOW COLUMNS FROM `'.$db->real_escape_string($table).'`'); $out=[]; if($rs){while($r=$rs->fetch_assoc()){$out[(string)$r['Field']]=$r;} $rs->close();} return $out; }
function sfm_manual_ensure_schema(mysqli $db): void {
    $db->query("CREATE TABLE IF NOT EXISTS sfm_generated_model_merges (id BIGINT AUTO_INCREMENT PRIMARY KEY, order_id BIGINT NOT NULL, capture_session_id BIGINT NULL, created_by_user_id BIGINT NULL, status VARCHAR(32) NOT NULL DEFAULT 'DONE', merge_type VARCHAR(64) NOT NULL, source_jobs_json JSON NULL, output_path TEXT NOT NULL, result_json_path TEXT NULL, total_points BIGINT NOT NULL DEFAULT 0, message TEXT NULL, created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), KEY idx_sfm_generated_model_merges_order (order_id, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $cols=sfm_manual_table_columns($db,'sfm_generated_model_merges');
    if(!isset($cols['idempotency_key'])){ if(!$db->query('ALTER TABLE sfm_generated_model_merges ADD COLUMN idempotency_key CHAR(64) NULL')){ throw new RuntimeException('DB migration failed adding idempotency_key: '.$db->error); } }
    $idx=$db->query("SHOW INDEX FROM sfm_generated_model_merges WHERE Key_name='uniq_sfm_manual_merge_fingerprint'"); $exists=$idx && $idx->num_rows>0; if($idx){$idx->close();}
    if(!$exists){ if(!$db->query('ALTER TABLE sfm_generated_model_merges ADD UNIQUE KEY uniq_sfm_manual_merge_fingerprint (idempotency_key)')){ throw new RuntimeException('DB migration failed adding manual merge unique index: '.$db->error); } }
}

function sfm_manual_require_idempotency_schema(mysqli $db): void {
    $cols=sfm_manual_table_columns($db,'sfm_generated_model_merges');
    if(!isset($cols['idempotency_key'])){ throw new RuntimeException('Manual merge idempotency schema is not deployed: missing idempotency_key'); }
    $idx=$db->query("SHOW INDEX FROM sfm_generated_model_merges WHERE Key_name='uniq_sfm_manual_merge_fingerprint'");
    $exists=$idx && $idx->num_rows>0; if($idx){$idx->close();}
    if(!$exists){ throw new RuntimeException('Manual merge idempotency schema is not deployed: missing unique index'); }
}
function sfm_manual_pairs_hash(string $pairsPath): string { $pairsPayload=json_decode((string)file_get_contents($pairsPath),true)?:[]; $canonicalPairs=json_encode($pairsPayload['pairs'] ?? [], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION); if($canonicalPairs===false){ throw new RuntimeException('Cannot canonicalize correspondence pairs'); } return hash('sha256',$canonicalPairs); }
function sfm_manual_fingerprint(int $orderId,array $a,array $s,string $pairsHash,string $outMd5,string $anchorMd5,string $sourceMd5): string { return hash('sha256', implode('|',[$orderId,SFM_MANUAL_MERGE_TYPE,$a['db_job_id'],$a['remote_job_id'],$s['db_job_id'],$s['remote_job_id'],$pairsHash,$anchorMd5,$sourceMd5,$outMd5])); }
function sfm_manual_find_existing_merge(mysqli $db,int $orderId,string $fingerprint='',string $out='',string $json=''): ?array { $mt=SFM_MANUAL_MERGE_TYPE; if($fingerprint!==''){ try { $st=$db->prepare('SELECT * FROM sfm_generated_model_merges WHERE idempotency_key=? LIMIT 1'); } catch(Throwable $e) { $st=false; } if($st){$st->bind_param('s',$fingerprint);$st->execute();$r=$st->get_result()->fetch_assoc();$st->close(); if($r)return $r;} } if($out!==''&&$json!==''){ $st=$db->prepare('SELECT * FROM sfm_generated_model_merges WHERE order_id=? AND merge_type=? AND output_path=? AND result_json_path=? ORDER BY id DESC LIMIT 1'); if(!$st) throw new RuntimeException('DB prepare error: '.$db->error); $st->bind_param('isss',$orderId,$mt,$out,$json); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close(); return $r?:null; } return null; }
function sfm_manual_atomic_write_json(string $path,array $data): void { $tmp=$path.'.tmp.'.getmypid(); if(file_put_contents($tmp,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))===false) throw new RuntimeException('Cannot write temp result JSON'); if(!rename($tmp,$path)){ @unlink($tmp); throw new RuntimeException('Cannot replace result JSON'); } }
function sfm_manual_copy_immutable(string $src,string $dst): void { if(is_file($dst)){ if(md5_file($src)!==md5_file($dst)) throw new RuntimeException('Immutable accepted artifact exists with different MD5'); return; } $tmp=$dst.'.tmp.'.getmypid(); if(!copy($src,$tmp)){ throw new RuntimeException('Cannot copy accepted artifact'); } chmod($tmp,0444); if(!rename($tmp,$dst)){ @unlink($tmp); throw new RuntimeException('Cannot publish accepted artifact'); } @chmod($dst,0444); }
function sfm_manual_finalize(mysqli $db,int $orderId,string $anchorKind,int $anchorId,string $sourceKind,int $sourceId,int $userId=0,string $role='ADMIN'): array {
    sfm_manual_require_idempotency_schema($db);
    sfm_manual_ensure_order_write_access($db,$orderId,$userId,$role);
    if($anchorKind===$sourceKind && $anchorId===$sourceId) throw new RuntimeException('Anchor and source must be different models');

    $a=sfm_manual_resolve_remote_model($db,$orderId,$anchorKind,$anchorId);
    $s=sfm_manual_resolve_remote_model($db,$orderId,$sourceKind,$sourceId);
    if($a['capture_session_id']!==$s['capture_session_id']) throw new RuntimeException('Anchor and source must belong to the same capture session');

    $dir=sfm_manual_draft_dir($orderId,$anchorKind,$anchorId,$sourceKind,$sourceId);
    $lockHandle=null;
    $inTx=false;
    try {
        $lockHandle=fopen($dir.'/compute.lock','c');
        if($lockHandle===false){ throw new RuntimeException('Cannot open compute lock'); }
        if(!flock($lockHandle,LOCK_EX)){ throw new RuntimeException('Cannot lock manual alignment draft'); }

        $pairsPath=sfm_manual_safe_realpath($dir.'/correspondence_pairs.json');
        $draftOut=sfm_manual_safe_realpath($dir.'/manual_merged_dense_cloud.ply');
        $draftAligned=sfm_manual_safe_realpath($dir.'/source_aligned_to_anchor.ply');
        $draftJson=sfm_manual_safe_realpath($dir.'/merge_result.json');
        $result=json_decode((string)file_get_contents($draftJson),true);
        if(!is_array($result)) throw new RuntimeException('Result JSON is not readable');
        $pairs=json_decode((string)file_get_contents($pairsPath),true)?:[];
        $pairCount=count($pairs['pairs']??$pairs);
        $pairsHash=sfm_manual_pairs_hash($pairsPath);
        if(isset($result['correspondence_sha256']) && !hash_equals((string)$result['correspondence_sha256'],$pairsHash)){ throw new RuntimeException('Correspondence hash mismatch'); }
        if(isset($result['pairs_count']) && (int)$result['pairs_count']!==$pairCount){ throw new RuntimeException('Pairs count mismatch'); }

        $merged=(int)($result['merged_points']??$result['total_points']??sfm_manual_ply_vertices($draftOut));
        if($merged!==$a['points']+$s['points']) throw new RuntimeException('Merged point count does not equal anchor + source points');
        $scale=(float)($result['scale']??($result['transform']['scale']??0));
        if(!is_finite($scale)||$scale<=0) throw new RuntimeException('Invalid Sim3 scale');
        $rot=$result['rotation']??($result['transform']['rotation']??null);
        if(!is_array($rot)||count($rot)!==3||abs(sfm_manual_rotation_det($rot)-1.0)>0.05) throw new RuntimeException('Rotation determinant is not close to +1');
        if($pairCount<4) throw new RuntimeException('At least four correspondence pairs are required');

        $outMd5=md5_file($draftOut);
        $anchorMd5=md5_file($a['ply']);
        $sourceMd5=md5_file($s['ply']);
        if(isset($result['merged_md5']) && !hash_equals((string)$result['merged_md5'],$outMd5)){ throw new RuntimeException('Merged PLY MD5 mismatch'); }
        if(isset($result['anchor_md5']) && !hash_equals((string)$result['anchor_md5'],$anchorMd5)){ throw new RuntimeException('Anchor PLY MD5 mismatch'); }
        if(isset($result['source_md5']) && !hash_equals((string)$result['source_md5'],$sourceMd5)){ throw new RuntimeException('Source PLY MD5 mismatch'); }
        if($outMd5===$anchorMd5||$outMd5===$sourceMd5) throw new RuntimeException('Merged PLY fingerprint matches a source PLY');
        $fingerprint=sfm_manual_fingerprint($orderId,$a,$s,$pairsHash,$outMd5,$anchorMd5,$sourceMd5);

        if($ex=sfm_manual_find_existing_merge($db,$orderId,$fingerprint)){
            flock($lockHandle,LOCK_UN); fclose($lockHandle); $lockHandle=null;
            return ['ok'=>true,'already_saved'=>true,'merge_id'=>(int)$ex['id'],'merge'=>$ex];
        }

        $sourceJobs=[['db_job_id'=>$a['db_job_id'],'remote_job_id'=>$a['remote_job_id'],'model_id'=>$a['model_id']],['db_job_id'=>$s['db_job_id'],'remote_job_id'=>$s['remote_job_id'],'model_id'=>$s['model_id']]];
        $sj=json_encode($sourceJobs,JSON_UNESCAPED_SLASHES);
        $mt=SFM_MANUAL_MERGE_TYPE;
        $sid=$a['capture_session_id'];
        $msg=sprintf('manual correspondence Sim3; method=%s; anchor DB/remote job=%d/%d; source DB/remote job=%d/%d; pipeline_run_id=%d; pairs=%d; scale=%.9f; RMS=%.8f',SFM_MANUAL_MERGE_METHOD,$a['db_job_id'],$a['remote_job_id'],$s['db_job_id'],$s['remote_job_id'],$a['pipeline_run_id']?:$s['pipeline_run_id'],$pairCount,$scale,(float)($result['rms']??$result['rms_error']??0));

        $db->begin_transaction();
        $inTx=true;
        $acceptedBase=sfm_manual_safe_dir(sfm_manual_output_root().'/accepted_manual_alignments/order_'.$orderId);
        $placeholderOut=$acceptedBase.'/pending_'.$fingerprint.'.ply';
        $placeholderJson=$acceptedBase.'/pending_'.$fingerprint.'.json';
        $st=$db->prepare('INSERT INTO sfm_generated_model_merges (order_id,capture_session_id,created_by_user_id,status,merge_type,source_jobs_json,output_path,result_json_path,total_points,message,idempotency_key) VALUES (?,?,?,\'DONE\',?,?,?,?,?,?,?)');
        if(!$st) throw new RuntimeException('DB prepare error: '.$db->error);
        $st->bind_param('iiissssiss',$orderId,$sid,$userId,$mt,$sj,$placeholderOut,$placeholderJson,$merged,$msg,$fingerprint);
        $st->execute();
        $mergeId=(int)$st->insert_id;
        $st->close();

        $acceptedDir=sfm_manual_safe_dir($acceptedBase.'/merge_'.$mergeId);
        $acceptedOut=$acceptedDir.'/manual_merged_dense_cloud.ply';
        $acceptedJson=$acceptedDir.'/merge_result.json';
        $acceptedPairs=$acceptedDir.'/correspondence_pairs.json';
        $acceptedAligned=$acceptedDir.'/source_aligned_to_anchor.ply';
        sfm_manual_copy_immutable($draftOut,$acceptedOut);
        sfm_manual_copy_immutable($pairsPath,$acceptedPairs);
        sfm_manual_copy_immutable($draftAligned,$acceptedAligned);
        $result['merged_path']=$acceptedOut;
        $result['correspondence_path']=$acceptedPairs;
        $result['aligned_source_path']=$acceptedAligned;
        $result['status']='ACCEPTED';
        $result['finalized_at']=gmdate('c');
        $result['merge_id']=$mergeId;
        $result['merge_type']=$mt;
        $result['method']=SFM_MANUAL_MERGE_METHOD;
        $result['order_id']=$orderId;
        $result['capture_session_id']=$sid;
        $result['pipeline_run_id']=$a['pipeline_run_id']?:$s['pipeline_run_id'];
        $result['anchor_db_job_id']=$a['db_job_id'];
        $result['anchor_remote_job_id']=$a['remote_job_id'];
        $result['source_db_job_id']=$s['db_job_id'];
        $result['source_remote_job_id']=$s['remote_job_id'];
        $result['parent_sparse_remote_job_id']=$a['parent_remote_job_id']?:$s['parent_remote_job_id'];
        $result['source_jobs']=$sourceJobs;
        $result['confirmed_by_user_id']=$userId ?: null;
        $result['idempotency_key']=$fingerprint;
        $result['output_md5']=$outMd5;
        $result['anchor_md5']=$anchorMd5;
        $result['source_md5']=$sourceMd5;
        $result['correspondence_sha256']=$pairsHash;
        sfm_manual_atomic_write_json($acceptedJson,$result);
        @chmod($acceptedJson,0444);
        $up=$db->prepare('UPDATE sfm_generated_model_merges SET output_path=?, result_json_path=? WHERE id=?');
        if(!$up) throw new RuntimeException('DB prepare error: '.$db->error);
        $up->bind_param('ssi',$acceptedOut,$acceptedJson,$mergeId);
        $up->execute();
        $up->close();
        $db->commit();
        $inTx=false;
        flock($lockHandle,LOCK_UN); fclose($lockHandle); $lockHandle=null;
        return ['ok'=>true,'already_saved'=>false,'merge_id'=>$mergeId,'output_path'=>$acceptedOut,'result_json_path'=>$acceptedJson];
    } catch(mysqli_sql_exception $e){
        if($inTx){ $db->rollback(); }
        if(isset($fingerprint) && (int)$e->getCode()===1062 && ($ex=sfm_manual_find_existing_merge($db,$orderId,$fingerprint))){
            if(is_resource($lockHandle)){ flock($lockHandle,LOCK_UN); fclose($lockHandle); }
            return ['ok'=>true,'already_saved'=>true,'merge_id'=>(int)$ex['id'],'merge'=>$ex];
        }
        if(is_resource($lockHandle)){ flock($lockHandle,LOCK_UN); fclose($lockHandle); }
        error_log('manual alignment finalize failed: '.$e->getMessage());
        throw $e;
    } catch(Throwable $e){
        if($inTx){ $db->rollback(); }
        if(is_resource($lockHandle)){ flock($lockHandle,LOCK_UN); fclose($lockHandle); }
        error_log('manual alignment finalize failed: '.$e->getMessage());
        throw $e;
    }
}