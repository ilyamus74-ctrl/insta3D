<?php
declare(strict_types=1);

require_once __DIR__ . '/auto_photo_bundle_materialize_lib.php';

const AUTO_PHOTO_PREPARE_JOB_TYPE = 'MAKLERTOUR_AUTO_PHOTO_PREPARE';

function auto_photo_prepare_fail(string $code): void { throw new RuntimeException($code); }
function auto_photo_prepare_regular(string $path, string $code): array {
    $s = @lstat($path);
    if (is_link($path) || $s === false || (($s['mode'] & 0170000) !== 0100000)) auto_photo_prepare_fail($code);
    return $s;
}
function auto_photo_prepare_json(string $path, string $code): array {
    auto_photo_prepare_regular($path, $code);
    $v = json_decode((string)file_get_contents($path), true);
    if (!is_array($v)) auto_photo_prepare_fail($code);
    return $v;
}
function auto_photo_prepare_safe_filename(string $name): bool {
    return preg_match('/^frame_[0-9]{6}\.jpe?g$/i', $name) === 1 && basename($name) === $name;
}
function auto_photo_prepare_inside(string $path, string $dir): bool {
    $real = realpath($path); $root = realpath($dir);
    return $real !== false && $root !== false && str_starts_with($real, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
}
/** Validates the materialized source only; it never creates remote or local output. */
function auto_photo_prepare_plan(array $row, array $options = []): array {
    foreach (['source_path','photos_dir','index_path','materialization_path','remote_job_id','output_path'] as $forbidden) {
        if (array_key_exists($forbidden, $options)) auto_photo_prepare_fail('caller_path_override_rejected');
    }
    if ((int)($row['id'] ?? 0) <= 0) auto_photo_prepare_fail('capture_bundle_missing');
    if (($row['capture_type'] ?? '') !== AUTO_PHOTO_BUNDLE_CAPTURE_TYPE) auto_photo_prepare_fail('invalid_capture_type');
    $archive = auto_photo_bundle_archive_path_for_row($row);
    $base = auto_photo_bundle_materialize_validate_base_dir($row, $archive);
    $index = auto_photo_prepare_json($base . '/index.json', 'unsafe_index_file');
    $mat = auto_photo_prepare_json($base . '/materialization.json', 'unsafe_materialization_file');
    if (($index['validation_status'] ?? null) !== 'VALID') auto_photo_prepare_fail('index_not_valid');
    if (($mat['status'] ?? null) !== 'READY') auto_photo_prepare_fail('materialization_not_ready');
    foreach (['capture_bundle_id','app_bundle_uuid','archive_sha256'] as $k) {
        $expected = $k === 'capture_bundle_id' ? (int)$row['id'] : ($k === 'archive_sha256' ? hash_file('sha256', $archive) : (string)$row['app_bundle_uuid']);
        if (($mat[$k] ?? null) !== $expected || ($index[$k] ?? null) !== $expected) auto_photo_prepare_fail($k . '_mismatch');
    }
    $photosDir = $base . '/photos';
    $ds = @lstat($photosDir);
    if (is_link($photosDir) || $ds === false || (($ds['mode'] & 0170000) !== 0040000) || realpath($photosDir) !== $photosDir) auto_photo_prepare_fail('unsafe_photos_directory');
    if (!is_array($index['photos'] ?? null) || !is_array($mat['photos'] ?? null) || (int)($index['photos_count'] ?? -1) !== count($index['photos']) || (int)($index['photos_count_actual'] ?? -1) !== count($index['photos']) || (int)($mat['photos_count'] ?? -1) !== count($mat['photos']) || count($index['photos']) !== count($mat['photos'])) auto_photo_prepare_fail('photos_count_mismatch');
    $byName=[]; foreach ($mat['photos'] as $p) { if (!is_array($p) || !isset($p['filename']) || isset($byName[$p['filename']])) auto_photo_prepare_fail('duplicate_filename'); $byName[$p['filename']]=$p; }
    $frames=[]; $seen=[];
    foreach ($index['photos'] as $p) {
        $name=(string)($p['filename'] ?? ''); if (!auto_photo_prepare_safe_filename($name)) auto_photo_prepare_fail('unsafe_filename');
        if (isset($seen[$name])) auto_photo_prepare_fail('duplicate_filename'); $seen[$name]=true;
        $m=$byName[$name] ?? null; if (!is_array($m)) auto_photo_prepare_fail('jpeg_list_mismatch');
        foreach (['archive_path','size_bytes','width','height'] as $k) { $iv=$k==='size_bytes'?(int)($p['file_size_bytes']??-1):(int)($p[$k]??0); $mv=(int)($m[$k]??-1); if ($k==='archive_path') { if (($p[$k]??'') !== ($m[$k]??'')) auto_photo_prepare_fail('jpeg_list_mismatch'); } elseif ($iv !== $mv) auto_photo_prepare_fail('jpeg_list_mismatch'); }
        $indexSha=(string)($p['sha256'] ?? ''); $materializationSha=(string)($m['sha256'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $indexSha) || !preg_match('/^[a-f0-9]{64}$/', $materializationSha) || !hash_equals($indexSha,$materializationSha)) auto_photo_prepare_fail('jpeg_sha256_list_mismatch');
        if ((int)($m['width']??0)<=0 || (int)($m['height']??0)<=0) auto_photo_prepare_fail('photo_dimensions_invalid');
        $file=$photosDir.'/'.$name; auto_photo_prepare_regular($file,'photo_not_regular'); if (!auto_photo_prepare_inside($file,$photosDir)) auto_photo_prepare_fail('photo_realpath_escape');
        if ((int)filesize($file)!==(int)$m['size_bytes']) auto_photo_prepare_fail('photo_size_mismatch');
        $sha=hash_file('sha256',$file); if (!is_string($sha) || !hash_equals((string)($m['sha256']??''),$sha)) auto_photo_prepare_fail('photo_sha256_mismatch');
        $frames[]=['source'=>$file,'filename'=>$name,'size_bytes'=>(int)$m['size_bytes'],'sha256'=>$sha,'width'=>(int)$m['width'],'height'=>(int)$m['height']];
    }
    $actual=array_values(array_diff(scandir($photosDir) ?: [], ['.','..'])); sort($actual); $wanted=array_column($frames,'filename'); sort($wanted); if ($actual !== $wanted) auto_photo_prepare_fail('jpeg_list_mismatch');
    $sidecars=[]; foreach (['camera_metadata.json'=>'camera_info.json','scan_imu.jsonl'=>'imu.jsonl','photos_metadata.jsonl'=>'photos_metadata.jsonl','manifest.json'=>'manifest.json','bundle_manifest.json'=>'bundle_manifest.json'] as $out=>$in) { $p=$base.'/'.$in; if (file_exists($p) || is_link($p)) { auto_photo_prepare_regular($p,'unsafe_sidecar'); if (!auto_photo_prepare_inside($p,$base) || filesize($p)<=0) auto_photo_prepare_fail('unsafe_sidecar'); $sha=hash_file('sha256',$p); if(!is_string($sha))auto_photo_prepare_fail('unsafe_sidecar'); if(str_ends_with($out,'.json') && !is_array(json_decode((string)file_get_contents($p),true)))auto_photo_prepare_fail('invalid_sidecar'); if(str_ends_with($out,'.jsonl')){ $h=fopen($p,'rb'); if(!$h)auto_photo_prepare_fail('invalid_sidecar'); try{while(($line=fgets($h))!==false){if(trim($line)!==''&&!is_array(json_decode($line,true)))auto_photo_prepare_fail('invalid_sidecar');}}finally{fclose($h);} } $sidecars[]=['filename'=>$out,'source'=>$p,'size_bytes'=>(int)filesize($p),'sha256'=>$sha]; } }
    return ['capture_bundle_id'=>(int)$row['id'],'order_id'=>(int)($row['order_id']??0),'capture_session_id'=>(int)($row['capture_session_id']??0),'app_bundle_uuid'=>(string)$row['app_bundle_uuid'],'photos_dir'=>$photosDir,'frames'=>$frames,'sidecars'=>$sidecars,'parameters'=>['source_type'=>'auto_photo_bundle','capture_bundle_id'=>(int)$row['id'],'capture_type'=>AUTO_PHOTO_BUNDLE_CAPTURE_TYPE,'app_bundle_uuid'=>(string)$row['app_bundle_uuid'],'input_images'=>count($frames),'already_selected_frames'=>true,'pipeline_mode'=>'prepare']];
}
function auto_photo_prepare_active_job_exists(mysqli $db, int $bundleId): bool {
    $q=$db->prepare("SELECT parameters_json FROM sfm_remote_jobs WHERE job_type=? AND status IN ('QUEUED','RUNNING')"); if (!$q) auto_photo_prepare_fail('prepare_job_query_failed'); $t=AUTO_PHOTO_PREPARE_JOB_TYPE; $q->bind_param('s',$t); $q->execute(); $r=$q->get_result(); while($row=$r->fetch_assoc()){ $p=json_decode((string)($row['parameters_json']??'{}'),true); if(is_array($p)&&(int)($p['capture_bundle_id']??0)===$bundleId){$q->close();return true;} } $q->close(); return false;
}
