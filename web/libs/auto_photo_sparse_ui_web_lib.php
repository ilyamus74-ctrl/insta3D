<?php
declare(strict_types=1);

require_once __DIR__ . '/auto_photo_sparse_ui_lib.php';
require_once __DIR__ . '/auto_photo_sparse_web_lib.php';

const AUTO_PHOTO_SPARSE_UI_WEB_MANIFEST_MAX_BYTES = 2097152;

function auto_photo_sparse_ui_web_empty(bool $canManage): array { return auto_photo_sparse_ui_build([], null, [], $canManage); }
function auto_photo_sparse_ui_web_log(string $code, int $orderId): void { error_log('auto_photo_sparse_ui_loader ' . $code . ' order_id=' . $orderId); }
function auto_photo_sparse_ui_web_positive_id(mixed $value): ?int {
    if (is_int($value) && $value > 0) return $value;
    return is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) ? (int) $value : null;
}
function auto_photo_sparse_ui_web_parameters(mixed $raw): ?array {
    try { $parameters=json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR); } catch (JsonException) { return null; }
    return is_array($parameters) ? $parameters : null;
}
function auto_photo_sparse_ui_web_bundle(array $row, int $orderId): ?array {
    $id=auto_photo_sparse_ui_web_positive_id($row['id']??null); $session=auto_photo_sparse_ui_web_positive_id($row['capture_session_id']??null);
    $uuid=trim((string)($row['app_bundle_uuid']??'')); $status=trim((string)($row['status']??''));
    if ((int)($row['order_id']??0)!==$orderId || (string)($row['capture_type']??'')!==AUTO_PHOTO_BUNDLE_CAPTURE_TYPE || $id===null || $session===null || $uuid==='' || $status==='') return null;
    $summary=auto_photo_sparse_ui_web_bundle_index_summary($row);
    return ['id'=>$id,'capture_session_id'=>$session,'app_bundle_uuid'=>$uuid,'photos_count'=>(int)($summary['photos_count']??0),'photos_count_known'=>$summary!==null,'status'=>$status];
}
function auto_photo_sparse_ui_web_bundle_index_summary(array $bundleRow): ?array {
    try {$archive=auto_photo_bundle_resolve_archive_path($bundleRow);$path=auto_photo_bundle_index_cache_path($bundleRow,$archive);} catch(Throwable) {return null;}
    $bundleDir=dirname($path);$bundlesDir=dirname($bundleDir);
    foreach([$bundlesDir,$bundleDir] as $directory){$stat=@lstat($directory);if(is_link($directory)||$stat===false||(($stat['mode']&0170000)!==0040000))return null;}
    $stat=@lstat($path); if(is_link($path)||$stat===false||(($stat['mode']&0170000)!==0100000)||(int)$stat['size']>4*1024*1024)return null;
    $realBundleDir=realpath($bundleDir);$realPath=realpath($path);if($realBundleDir===false||$realPath===false||$realBundleDir!==$bundleDir||dirname($realPath)!==$realBundleDir)return null;
    try {$index=json_decode((string)file_get_contents($realPath),true,512,JSON_THROW_ON_ERROR);} catch(Throwable) {return null;}
    if(!is_array($index)||($index['schema_version']??null)!==AUTO_PHOTO_BUNDLE_INDEX_SCHEMA_VERSION||(int)($index['capture_bundle_id']??0)!==(int)($bundleRow['id']??0)||(int)($index['order_id']??0)!==(int)($bundleRow['order_id']??0)||(int)($index['capture_session_id']??0)!==(int)($bundleRow['capture_session_id']??0)||(string)($index['app_bundle_uuid']??'')!==(string)($bundleRow['app_bundle_uuid']??'')||($index['capture_type']??null)!==AUTO_PHOTO_BUNDLE_CAPTURE_TYPE||($index['validation_status']??null)!=='VALID'||!empty($index['blocking_errors'])||!is_int($index['photos_count_actual']??null)||(int)$index['photos_count_actual']<0)return null;
    if(isset($index['photos'])&&(!is_array($index['photos'])||count($index['photos'])!==$index['photos_count_actual']))return null;
    return ['photos_count'=>$index['photos_count_actual']];
}
function auto_photo_sparse_ui_web_prepare_for_bundle(array $row, int $orderId, array $bundle): ?array {
    $id=auto_photo_sparse_ui_web_positive_id($row['id']??null); $remote=auto_photo_sparse_ui_web_positive_id($row['remote_job_id']??null);
    $parameters=auto_photo_sparse_ui_web_parameters($row['parameters_json']??null);
    if ((int)($row['order_id']??0)!==$orderId || (int)($row['capture_session_id']??0)!==(int)$bundle['capture_session_id'] || (string)($row['job_type']??'')!==AUTO_PHOTO_PREPARE_JOB_TYPE || $id===null || $remote===null || $parameters===null || ($parameters['source_type']??null)!=='auto_photo_bundle' || ($parameters['pipeline_mode']??null)!=='prepare' || ($parameters['capture_bundle_id']??null)!==$bundle['id'] || ($parameters['app_bundle_uuid']??null)!==$bundle['app_bundle_uuid']) return null;
    return $row;
}
function auto_photo_sparse_ui_web_sparse(array $row, int $orderId, array $bundle): ?array {
    $parameters=auto_photo_sparse_ui_web_parameters($row['parameters_json']??null);
    if ((int)($row['order_id']??0)!==$orderId || (int)($row['capture_session_id']??0)!==(int)$bundle['capture_session_id'] || (string)($row['job_type']??'')!=='COLMAP_SPARSE' || ($row['pipeline_run_id']??null)!==null || auto_photo_sparse_ui_web_positive_id($row['id']??null)===null || auto_photo_sparse_ui_web_positive_id($row['remote_job_id']??null)===null || auto_photo_sparse_ui_web_positive_id($row['parent_remote_job_id']??null)===null || $parameters===null || ($parameters['source_type']??null)!=='auto_photo_prepare' || ($parameters['standalone_sparse']??null)!==true || ($parameters['capture_bundle_id']??null)!==$bundle['id'] || ($parameters['app_bundle_uuid']??null)!==$bundle['app_bundle_uuid']) return null;
    return $row;
}
function auto_photo_sparse_ui_web_sparse_prepare(array $sparse,array $prepare): bool {
    $parameters=auto_photo_sparse_ui_web_parameters($sparse['parameters_json']??null);
    if ($parameters===null) return false;
    $prepareDb=auto_photo_sparse_ui_web_positive_id($parameters['prepare_job_id']??null);
    $prepareRemote=auto_photo_sparse_ui_web_positive_id($parameters['prepare_remote_job_id']??null);
    $prepareId=auto_photo_sparse_ui_web_positive_id($prepare['id']??null);
    $prepareRowRemote=auto_photo_sparse_ui_web_positive_id($prepare['remote_job_id']??null);
    $parent=auto_photo_sparse_ui_web_positive_id($sparse['parent_remote_job_id']??null);
    return $prepareDb!==null && $prepareRemote!==null && $prepareId!==null && $prepareRowRemote!==null && $parent!==null && $prepareDb===$prepareId && $prepareRemote===$prepareRowRemote && $prepareRemote===$parent;
}
function auto_photo_sparse_ui_web_input_images(?array $job): int {
    if (!is_array($job)) return 0;
    $parameters=auto_photo_sparse_ui_web_parameters($job['parameters_json']??null);
    return is_array($parameters) ? max(0,(int)($parameters['input_images']??0)) : 0;
}
function auto_photo_sparse_ui_web_export_file_ready(int $remoteJobId,int $modelId,string $outputPath): bool {
    if ($remoteJobId<=0 || $modelId<0) return false;
    try { $jobDirectory=auto_photo_sparse_output_path($remoteJobId); } catch (Throwable) { return false; }
    $expected=$jobDirectory.'/sparse_'.$modelId.'.ply';
    if (!hash_equals($expected,$outputPath) || is_link($jobDirectory) || is_link($expected)) return false;
    $jobStat=@lstat($jobDirectory); $fileStat=@lstat($expected);
    if ($jobStat===false || $fileStat===false || (($jobStat['mode']&0170000)!==0040000) || (($fileStat['mode']&0170000)!==0100000) || (int)$fileStat['size']<=0) return false;
    $realJob=realpath($jobDirectory); $realFile=realpath($expected);
    return $realJob!==false && $realFile!==false && str_starts_with($realFile,rtrim($realJob,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
}
function auto_photo_sparse_ui_web_dense_ply_info(array $row): array {
    $remote=auto_photo_sparse_ui_web_positive_id($row['remote_job_id']??null); if($remote===null) return ['points'=>0,'size'=>0,'ready'=>false];
    $base=auto_photo_sparse_output_path($remote); $path=$base.'/merged/merged_fused.ply';
    if ((string)($row['status']??'')!=='DONE' || !hash_equals($path,(string)($row['output_path']??'')) || is_link($base)||is_link($path)) return ['points'=>0,'size'=>0,'ready'=>false];
    $bs=@lstat($base); $fs=@lstat($path); if($bs===false||$fs===false||(($bs['mode']&0170000)!==0040000)||(($fs['mode']&0170000)!==0100000)||(int)$fs['size']<=0) return ['points'=>0,'size'=>0,'ready'=>false];
    $rb=realpath($base); $rp=realpath($path); if($rb===false||$rp===false||!str_starts_with($rp,rtrim($rb,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) return ['points'=>0,'size'=>0,'ready'=>false];
    $fh=@fopen($rp,'rb'); if(!$fh)return ['points'=>0,'size'=>0,'ready'=>false]; $points=0;$valid=false; while(($line=fgets($fh))!==false){$line=trim($line);if(preg_match('/^element\\s+vertex\\s+([1-9][0-9]*)$/',$line,$m))$points=(int)$m[1];if($line==='end_header'){$valid=$points>0;break;}if(ftell($fh)>65536)break;}fclose($fh); return ['points'=>$valid?$points:0,'size'=>$valid?(int)$fs['size']:0,'ready'=>$valid];
}
function auto_photo_sparse_ui_web_export(array $row, int $orderId, array $sparse): ?array {
    $parameters=auto_photo_sparse_ui_web_parameters($row['parameters_json']??null);
    $remote=auto_photo_sparse_ui_web_positive_id($row['remote_job_id']??null); $parent=auto_photo_sparse_ui_web_positive_id($row['parent_remote_job_id']??null);
    if ((int)($row['order_id']??0)!==$orderId || (int)($row['capture_session_id']??0)!==(int)$sparse['capture_session_id'] || (string)($row['job_type']??'')!=='EXPORT_PLY' || auto_photo_sparse_ui_web_positive_id($row['id']??null)===null || $remote===null || $parent===null || $remote===(int)$sparse['remote_job_id'] || $parent!==(int)$sparse['remote_job_id'] || $parameters===null || ($parameters['source_type']??null)!=='auto_photo_sparse' || ($parameters['standalone_photo_export']??null)!==true || ($parameters['sparse_job_id']??null)!==(int)$sparse['remote_job_id']) return null;
    $modelId=auto_photo_sparse_manifest_model_id($parameters['model_id']??null);
    if ($modelId===null) return auto_photo_sparse_ui_active(auto_photo_sparse_ui_status($row['status']??'')) ? $row : null;
    $expected=auto_photo_sparse_output_path($remote).'/sparse_'.$modelId.'.ply';
    if (!hash_equals($expected,(string)($row['output_path']??''))) return null;
    return auto_photo_sparse_ui_status($row['status']??'')!=='DONE' || auto_photo_sparse_ui_web_export_file_ready($remote,$modelId,(string)$row['output_path']) ? $row : null;
}
function auto_photo_sparse_ui_web_components(int $remoteJobId): array {
    if ($remoteJobId<=0) return ['models'=>[]];
    $jobDirectory=auto_photo_sparse_output_path($remoteJobId); $colmapDirectory=$jobDirectory.'/colmap'; $path=$colmapDirectory.'/sparse_components.json';
    $jobStat=@lstat($jobDirectory); $colmapStat=@lstat($colmapDirectory); $fileStat=@lstat($path);
    if (is_link($jobDirectory)||is_link($colmapDirectory)||is_link($path)||$jobStat===false||$colmapStat===false||$fileStat===false||(($jobStat['mode']&0170000)!==0040000)||(($colmapStat['mode']&0170000)!==0040000)||(($fileStat['mode']&0170000)!==0100000)||(int)$fileStat['size']>AUTO_PHOTO_SPARSE_UI_WEB_MANIFEST_MAX_BYTES) return ['models'=>[]];
    $realJob=realpath($jobDirectory); $realColmap=realpath($colmapDirectory); $realPath=realpath($path);
    if ($realJob===false||$realColmap===false||$realPath===false||dirname($realColmap)!==$realJob||!str_starts_with($realPath,rtrim($realColmap,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) return ['models'=>[]];
    $contents=@file_get_contents($realPath); if (!is_string($contents)) return ['models'=>[]];
    try { $components=json_decode($contents,true,512,JSON_THROW_ON_ERROR); } catch (JsonException) { return ['models'=>[]]; }
    return is_array($components)&&is_array($components['models']??null) ? $components : ['models'=>[]];
}
function auto_photo_sparse_ui_web_prepares_for_bundle(array $prepareRows, int $orderId, array $bundle): array {
    $valid=[]; foreach ($prepareRows as $row) if (is_array($row) && ($prepare=auto_photo_sparse_ui_web_prepare_for_bundle($row,$orderId,$bundle))!==null) $valid[]=$prepare;
    usort($valid,static fn(array $a,array $b):int=>(int)$b['id']<=>(int)$a['id']); return $valid;
}
function auto_photo_sparse_ui_web_bundle_candidate(array $row,array $sparseRows,array $prepareRows,int $orderId): ?array {
    $bundle=auto_photo_sparse_ui_web_bundle($row,$orderId); if($bundle===null)return null;
    $prepares=auto_photo_sparse_ui_web_prepares_for_bundle($prepareRows,$orderId,$bundle);
    $sparseId=0;$sparseStatus='';
    foreach($sparseRows as $sparseRow){
        if(!is_array($sparseRow)||($sparse=auto_photo_sparse_ui_web_sparse($sparseRow,$orderId,$bundle))===null)continue;
        foreach($prepares as $prepare)if(auto_photo_sparse_ui_web_sparse_prepare($sparse,$prepare)&&(int)$sparse['id']>$sparseId){$sparseId=(int)$sparse['id'];$sparseStatus=auto_photo_sparse_ui_status($sparse['status']??'');}
    }
    $prepare=$prepares[0]??null;
    return ['bundle'=>$bundle,'sparse_id'=>$sparseId,'sparse_status'=>$sparseStatus,'prepare_id'=>(int)($prepare['id']??0),'prepare_status'=>is_array($prepare)?auto_photo_sparse_ui_status($prepare['status']??''):''];
}
function auto_photo_sparse_ui_web_candidates(array $bundles,array $sparseRows,array $prepareRows,int $orderId): array {
    $candidates=[];foreach($bundles as $row)if(is_array($row)&&($candidate=auto_photo_sparse_ui_web_bundle_candidate($row,$sparseRows,$prepareRows,$orderId))!==null)$candidates[]=$candidate;
    return $candidates;
}
function auto_photo_sparse_ui_web_select_bundle(array $bundles,array $sparseRows,array $prepareRows,int $orderId,?int $preferredBundleId=null): ?array {
    $candidates=auto_photo_sparse_ui_web_candidates($bundles,$sparseRows,$prepareRows,$orderId);
    if($preferredBundleId!==null&&$preferredBundleId>0)foreach($candidates as $candidate)if((int)$candidate['bundle']['id']===$preferredBundleId)return $candidate['bundle'];
    usort($candidates,static fn(array $a,array $b):int=>[$b['sparse_id'],$b['prepare_id'],$b['bundle']['id']] <=> [$a['sparse_id'],$a['prepare_id'],$a['bundle']['id']]);
    return $candidates[0]['bundle']??null;
}
function auto_photo_sparse_ui_web_bundle_options(array $bundles,array $sparseRows,array $prepareRows,int $orderId,int $selectedBundleId): array {
    $options=[];
    foreach(auto_photo_sparse_ui_web_candidates($bundles,$sparseRows,$prepareRows,$orderId) as $candidate){
        $bundle=$candidate['bundle'];
        $stage=$candidate['sparse_id']>0?'Sparse '.($candidate['sparse_status']?:'—'):($candidate['prepare_id']>0?'Prepare '.($candidate['prepare_status']?:'—'):'Не обработан');
        $options[]=[
            'id'=>(int)$bundle['id'],
            'capture_session_id'=>(int)$bundle['capture_session_id'],
            'photos_count'=>(int)($bundle['photos_count']??0),
            'photos_count_known'=>($bundle['photos_count_known']??false)===true,
            'status'=>(string)$bundle['status'],
            'stage'=>$stage,
            'selected'=>(int)$bundle['id']===$selectedBundleId,
            'select_url'=>'/order_simple.php?id='.$orderId.'&auto_photo_bundle_id='.(int)$bundle['id'].'#simple-photo-sfm',
        ];
    }
    usort($options,static fn(array $a,array $b):int=>$b['id']<=>$a['id']);
    return $options;
}
function auto_photo_sparse_ui_web_build_from_rows(int $orderId,bool $canManage,array $bundles,array $sparseRows,array $prepareRows,array $exportsBySparse,?callable $componentsReader=null,array $denseBySparse=[],?int $preferredBundleId=null): array {
    $bundle=auto_photo_sparse_ui_web_select_bundle($bundles,$sparseRows,$prepareRows,$orderId,$preferredBundleId); if($bundle===null) return auto_photo_sparse_ui_web_empty($canManage);
    $prepares=auto_photo_sparse_ui_web_prepares_for_bundle($prepareRows,$orderId,$bundle); $prepareJob=$prepares[0]??null;
    usort($sparseRows,static fn(array $a,array $b):int=>(int)($b['id']??0)<=>(int)($a['id']??0)); $runs=[]; $newestSparse=null;
    foreach($sparseRows as $row) { if(!is_array($row)||($sparse=auto_photo_sparse_ui_web_sparse($row,$orderId,$bundle))===null) continue; $linked=false; foreach($prepares as $prepare) $linked=$linked||auto_photo_sparse_ui_web_sparse_prepare($sparse,$prepare); if(!$linked) continue;
        $newestSparse ??= $sparse; $exports=[]; foreach(($exportsBySparse[(int)$sparse['remote_job_id']]??[]) as $exportRow) if(is_array($exportRow)&&($export=auto_photo_sparse_ui_web_export($exportRow,$orderId,$sparse))!==null)$exports[]=$export;
        $components=$componentsReader!==null?$componentsReader((int)$sparse['remote_job_id']):auto_photo_sparse_ui_web_components((int)$sparse['remote_job_id']); $runs[]=['job'=>$sparse,'components'=>is_array($components)?$components:['models'=>[]],'exports'=>$exports,'dense'=>$denseBySparse[(int)$sparse['remote_job_id']]??[]];
    }
    $photosCount=auto_photo_sparse_ui_web_input_images($prepareJob); if($photosCount<=0)$photosCount=auto_photo_sparse_ui_web_input_images($newestSparse); if($photosCount>0){$bundle['photos_count']=$photosCount;$bundle['photos_count_known']=true;}
    $blocking=false;foreach($prepares as $prepare)if(in_array(auto_photo_sparse_ui_status($prepare['status']??''),['QUEUED','RUNNING','DONE'],true)){$blocking=true;break;}$bundle['can_prepare']=$canManage&&(int)$bundle['id']>0&&in_array((string)$bundle['status'],['UPLOADED','PROCESSING','READY','COMPLETED'],true)&&!$blocking;
    $dto=auto_photo_sparse_ui_build($bundle,$prepareJob,$runs,$canManage);
    $dto['bundle_options']=auto_photo_sparse_ui_web_bundle_options($bundles,$sparseRows,$prepareRows,$orderId,(int)$bundle['id']);
    return $dto;
}
function auto_photo_sparse_ui_web_query(mysqli $db,string $sql,string $types,array $values): ?array {
    $statement=$db->prepare($sql); if(!$statement)return null; try { if(!$statement->bind_param($types,...$values)||!$statement->execute())return null; $result=$statement->get_result(); if(!$result)return null; $rows=[]; while($row=$result->fetch_assoc())$rows[]=$row; return $rows; } finally { $statement->close(); }
}
function auto_photo_sparse_ui_web_load(mysqli $db,int $orderId,bool $canManage,?int $preferredBundleId=null): array {
    if($orderId<=0)return auto_photo_sparse_ui_web_empty($canManage);
    try {
        /* Ranking: explicit valid bundle, then sparse chain DB id, prepare DB id, bundle DB id. */
        $bundles=auto_photo_sparse_ui_web_query($db,'SELECT id, order_id, capture_session_id, app_bundle_uuid, capture_type, filename, storage_path, size_bytes, status FROM capture_bundles WHERE order_id=? AND capture_type=? ORDER BY id DESC LIMIT 20','is',[$orderId,AUTO_PHOTO_BUNDLE_CAPTURE_TYPE]);
        $sparseRows=auto_photo_sparse_ui_web_query($db,"SELECT id, order_id, capture_session_id, job_type, remote_job_id, parent_remote_job_id, status, progress_percent, message, parameters_json, pipeline_run_id FROM sfm_remote_jobs WHERE order_id=? AND job_type='COLMAP_SPARSE' AND pipeline_run_id IS NULL ORDER BY id DESC LIMIT 20",'i',[$orderId]);
        $prepareRows=auto_photo_sparse_ui_web_query($db,'SELECT id, order_id, capture_session_id, job_type, remote_job_id, status, progress_percent, message, parameters_json FROM sfm_remote_jobs WHERE order_id=? AND job_type=? ORDER BY id DESC LIMIT 20','is',[$orderId,AUTO_PHOTO_PREPARE_JOB_TYPE]);
        if($bundles===null||$sparseRows===null||$prepareRows===null)throw new RuntimeException('query_failed');
        $bundle=auto_photo_sparse_ui_web_select_bundle($bundles,$sparseRows,$prepareRows,$orderId,$preferredBundleId); if($bundle===null)return auto_photo_sparse_ui_web_empty($canManage);
        $exportsBySparse=[]; $denseBySparse=[]; foreach($sparseRows as $row) if(is_array($row)&&($sparse=auto_photo_sparse_ui_web_sparse($row,$orderId,$bundle))!==null) { $remote=(int)$sparse['remote_job_id']; $rows=auto_photo_sparse_ui_web_query($db,"SELECT id, order_id, capture_session_id, job_type, remote_job_id, parent_remote_job_id, output_path, status, progress_percent, message, parameters_json FROM sfm_remote_jobs WHERE order_id=? AND capture_session_id=? AND job_type='EXPORT_PLY' AND parent_remote_job_id=? ORDER BY id DESC LIMIT 100",'iii',[$orderId,(int)$sparse['capture_session_id'],$remote]); if($rows===null)throw new RuntimeException('export_query_failed'); $exportsBySparse[$remote]=$rows; $dense=auto_photo_sparse_ui_web_query($db,"SELECT id, order_id, capture_session_id, job_type, remote_job_id, parent_remote_job_id, output_path, status, progress_percent, message, parameters_json, reconstruction_mode, pipeline_run_id FROM sfm_remote_jobs WHERE order_id=? AND capture_session_id=? AND parent_remote_job_id=? AND job_type='COLMAP_RECONSTRUCTION_PREVIEW' AND pipeline_run_id IS NULL ORDER BY id DESC LIMIT 100",'iii',[$orderId,(int)$sparse['capture_session_id'],$remote]); if($dense===null)throw new RuntimeException('dense_query_failed'); $denseBySparse[$remote]=$dense; }
        foreach($denseBySparse as &$rows) foreach($rows as &$denseRow){$info=auto_photo_sparse_ui_web_dense_ply_info($denseRow);$denseRow['_dense_points']=$info['points'];$denseRow['_dense_file_size']=$info['size'];$denseRow['_dense_download_ready']=$info['ready'];} unset($denseRow,$rows);
        return auto_photo_sparse_ui_web_build_from_rows($orderId,$canManage,$bundles,$sparseRows,$prepareRows,$exportsBySparse,null,$denseBySparse,$preferredBundleId);
    } catch(Throwable) { auto_photo_sparse_ui_web_log('controlled_failure',$orderId); return auto_photo_sparse_ui_web_empty($canManage); }
}
