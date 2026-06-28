<?php
declare(strict_types=1);

const SFM_CLEANUP_WEB_OUTPUT_BASE = '/home/makler/web/remote_station/output';
const SFM_CLEANUP_STATION_BASE_DEFAULT = '/home/makler_storage';
const SFM_CLEANUP_ACTIVE_STATUSES = ['RUNNING','QUEUED','STARTED','PROCESSING','ACTIVE','PLANNING','RUNNING_CHUNKS','MERGING','CANCELLING','RESTARTING'];

function sfm_cleanup_is_numeric_id($id): bool
{
    return is_int($id) ? $id > 0 : (is_string($id) && preg_match('/^[1-9][0-9]*$/', $id) === 1);
}

function sfm_cleanup_path_size(string $path): int
{
    if (!file_exists($path) && !is_link($path)) { return 0; }
    if (is_link($path) || is_file($path)) { return (int)@filesize($path); }
    $total = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        if ($file->isLink()) { continue; }
        if ($file->isFile()) { $total += (int)$file->getSize(); }
    }
    return $total;
}

function sfm_cleanup_delete_path(string $path, bool $delete): array
{
    $size = sfm_cleanup_path_size($path);
    if (!$delete) { return ['path'=>$path,'size_bytes'=>$size,'deleted'=>false]; }
    if (is_link($path) || is_file($path)) {
        if (!@unlink($path)) { throw new RuntimeException('failed to delete file: '.$path); }
        return ['path'=>$path,'size_bytes'=>$size,'deleted'=>true];
    }
    if (is_dir($path)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            /** @var SplFileInfo $file */
            $p = $file->getPathname();
            if ($file->isLink() || $file->isFile()) { if (!@unlink($p)) { throw new RuntimeException('failed to delete file: '.$p); } }
            elseif ($file->isDir()) { if (!@rmdir($p)) { throw new RuntimeException('failed to delete dir: '.$p); } }
        }
        if (!@rmdir($path)) { throw new RuntimeException('failed to delete dir: '.$path); }
        return ['path'=>$path,'size_bytes'=>$size,'deleted'=>true];
    }
    return ['path'=>$path,'size_bytes'=>0,'deleted'=>false];
}

function sfm_cleanup_remote_job_ids(mysqli $db, int $pipelineRunId): array
{
    $jobs = [];
    $st = $db->prepare('SELECT remote_job_id, job_type, status FROM sfm_remote_jobs WHERE pipeline_run_id=? ORDER BY id ASC');
    if (!$st) { throw new RuntimeException('prepare failed: '.$db->error); }
    $st->bind_param('i', $pipelineRunId);
    $st->execute();
    $rs = $st->get_result();
    while ($row = $rs->fetch_assoc()) {
        $rid = (string)($row['remote_job_id'] ?? '');
        if (!sfm_cleanup_is_numeric_id($rid)) { continue; }
        $jobs[] = ['remote_job_id'=>(int)$rid, 'job_type'=>(string)($row['job_type'] ?? ''), 'status'=>(string)($row['status'] ?? '')];
    }
    $st->close();
    return $jobs;
}

function sfm_cleanup_column_exists(mysqli $db, string $table, string $column): bool
{
    $st = $db->prepare('SHOW COLUMNS FROM `' . $db->real_escape_string($table) . '` LIKE ?');
    if (!$st) { return false; }
    $st->bind_param('s', $column);
    $st->execute();
    $rs = $st->get_result();
    $ok = $rs && $rs->num_rows > 0;
    $st->close();
    return $ok;
}

function sfm_cleanup_update_metadata_if_available(mysqli $db, int $pipelineRunId, array $result): void
{
    if (!sfm_cleanup_column_exists($db, 'sfm_pipeline_runs', 'artifacts_deleted_at') || !sfm_cleanup_column_exists($db, 'sfm_pipeline_runs', 'artifacts_deleted_json')) {
        return;
    }
    $json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $st = $db->prepare('UPDATE sfm_pipeline_runs SET artifacts_deleted_at=NOW(6), artifacts_deleted_json=? WHERE id=?');
    if ($st) { $st->bind_param('si', $json, $pipelineRunId); $st->execute(); $st->close(); }
}

function sfm_cleanup_pipeline_run_artifacts(mysqli $db, int $pipelineRunId, array $options = []): array
{
    $delete = !empty($options['delete']);
    $includeLogs = !empty($options['include_logs']);
    $result = ['pipeline_run_id'=>$pipelineRunId,'delete'=>$delete,'include_logs'=>$includeLogs,'jobs'=>[],'deleted_paths'=>[],'missing_paths'=>[],'errors'=>[],'freed_bytes'=>0];
    foreach (sfm_cleanup_remote_job_ids($db, $pipelineRunId) as $job) {
        $id = (int)$job['remote_job_id'];
        $entry = $job + ['web'=>[], 'station'=>[]];
        $webPath = SFM_CLEANUP_WEB_OUTPUT_BASE . '/job_' . $id;
        if (is_link($webPath) || file_exists($webPath)) {
            try { $d = sfm_cleanup_delete_path($webPath, $delete); $entry['web'][] = $d; $result['deleted_paths'][] = $webPath; $result['freed_bytes'] += (int)$d['size_bytes']; }
            catch (Throwable $e) { $result['errors'][] = ['path'=>$webPath,'message'=>$e->getMessage()]; }
        } else { $result['missing_paths'][] = $webPath; $entry['web'][] = ['path'=>$webPath,'missing'=>true,'size_bytes'=>0]; }
        $result['jobs'][] = $entry;
    }
    $remote = sfm_cleanup_station_artifacts($pipelineRunId, $result['jobs'], $options);
    foreach ($remote['paths'] as $p) { if (!empty($p['missing'])) { $result['missing_paths'][] = $p['path']; } else { $result['deleted_paths'][] = $p['path']; $result['freed_bytes'] += (int)($p['size_bytes'] ?? 0); } }
    foreach ($remote['errors'] as $e) { $result['errors'][] = $e; }
    if ($delete) { sfm_cleanup_update_metadata_if_available($db, $pipelineRunId, $result); }
    return $result;
}

function sfm_cleanup_station_artifacts(int $pipelineRunId, array $jobs, array $options): array
{
    $conf = (string)($options['station_conf'] ?? __DIR__ . '/stations.conf');
    if (!is_file($conf)) { return ['paths'=>[], 'errors'=>[['message'=>'station config not found: '.$conf]]]; }
    $ids = [];
    foreach ($jobs as $j) { if (sfm_cleanup_is_numeric_id($j['remote_job_id'] ?? null)) { $ids[] = (string)(int)$j['remote_job_id']; } }
    if (!$ids) { return ['paths'=>[], 'errors'=>[]]; }
    $script = __DIR__ . '/cleanup_station_artifacts.sh';
    $cmd = array_merge(['bash', $script, $conf, !empty($options['delete']) ? '--delete' : '--dry-run', !empty($options['include_logs']) ? '--include-logs' : '--no-logs'], $ids);
    $des = [1=>['pipe','w'], 2=>['pipe','w']];
    $proc = proc_open($cmd, $des, $pipes);
    if (!is_resource($proc)) { return ['paths'=>[], 'errors'=>[['message'=>'failed to start station cleanup helper']]]; }
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[2]); $code = proc_close($proc);
    $data = json_decode($out, true);
    if (!is_array($data)) { return ['paths'=>[], 'errors'=>[['message'=>'station cleanup helper failed: '.$err, 'exit_code'=>$code]]]; }
    $errors = $data['errors'] ?? [];
    if ($code !== 0) { $errors[] = ['message'=>'station cleanup helper exit '.$code.': '.$err]; }
    return ['paths'=>$data['paths'] ?? [], 'errors'=>$errors];
}

function sfm_cleanup_protected_pipeline_run_ids(mysqli $db, bool $forceRecent=false): array
{
    $protected = [];
    $active = "'" . implode("','", array_map([$db, 'real_escape_string'], SFM_CLEANUP_ACTIVE_STATUSES)) . "'";
    $sql = "SELECT id FROM sfm_pipeline_runs WHERE status IN ($active)" . ($forceRecent ? '' : " OR created_at >= (NOW() - INTERVAL 1 DAY)");
    if ($rs = $db->query($sql)) { while ($r = $rs->fetch_assoc()) { $protected[(int)$r['id']] = true; } $rs->close(); }
    $sql = "SELECT MAX(id) id FROM sfm_pipeline_runs GROUP BY capture_session_id, video_scan_id, pipeline_mode";
    if ($rs = $db->query($sql)) { while ($r = $rs->fetch_assoc()) { $protected[(int)$r['id']] = true; } $rs->close(); }
    return $protected;
}

function sfm_cleanup_select_runs(mysqli $db, array $options): array
{
    $where = []; $types = ''; $params = [];
    if (!empty($options['pipeline_run_id'])) { $where[]='id=?'; $types.='i'; $params[]=(int)$options['pipeline_run_id']; }
    if (!empty($options['older_than'])) { $where[]='created_at < ?'; $types.='s'; $params[]=(string)$options['older_than'].' 00:00:00'; }
    if (!empty($options['video_scan_id'])) { $where[]='CAST(video_scan_id AS CHAR)=?'; $types.='s'; $params[]=(string)$options['video_scan_id']; }
    if (!empty($options['mode'])) { $where[]='pipeline_mode=?'; $types.='s'; $params[]=(string)$options['mode']; }
    $sql = 'SELECT id,capture_session_id,video_scan_id,pipeline_mode,status,created_at FROM sfm_pipeline_runs' . ($where ? ' WHERE '.implode(' AND ', $where) : '') . ' ORDER BY id ASC';
    $st = $db->prepare($sql); if (!$st) { throw new RuntimeException($db->error); }
    if ($types !== '') { $st->bind_param($types, ...$params); }
    $st->execute(); $rs=$st->get_result(); $rows=[]; $protected=sfm_cleanup_protected_pipeline_run_ids($db, !empty($options['force_recent']));
    while ($r=$rs->fetch_assoc()) { $r['protected'] = isset($protected[(int)$r['id']]); $rows[]=$r; }
    $st->close(); return $rows;
}