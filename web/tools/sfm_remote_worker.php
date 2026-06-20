<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$connectCandidates = ['/home/makler/web/configs/connectDB.php', __DIR__ . '/../configs/connectDB.php'];
foreach ($connectCandidates as $connectFile) {
    if (is_file($connectFile)) {
        require_once $connectFile;
        break;
    }
}
$appCandidates = ['/home/makler/web/configs/app.php', __DIR__ . '/../configs/app.php'];
foreach ($appCandidates as $appFile) {
    if (is_file($appFile)) {
        require_once $appFile;
        break;
    }
}
if (!isset($dbcnx) || !($dbcnx instanceof mysqli)) {
    fwrite(STDERR, "ERROR: failed to initialize mysqli via connectDB.php\n");
    exit(1);
}
if (!defined('APP_STORAGE_DIR')) {
    define('APP_STORAGE_DIR', __DIR__ . '/../storage');
}

const SFM_REMOTE_BASE = '/home/makler/web/remote_station';
const SFM_REMOTE_CONF = '/home/makler/web/remote_station/stations.conf';
const SFM_REMOTE_OUTPUT = '/home/makler/web/remote_station/output';
const SFM_REMOTE_STORAGE_OUTPUT = '/home/makler_storage/output';

function worker_log(string $message): void
{
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n");
}

function ensure_sfm_remote_jobs_table(mysqli $db): void
{
    $sql = "CREATE TABLE IF NOT EXISTS sfm_remote_jobs (id BIGINT AUTO_INCREMENT PRIMARY KEY, order_id BIGINT NOT NULL, capture_session_id BIGINT NOT NULL, job_type VARCHAR(64) NOT NULL, remote_job_id INT NOT NULL, parent_remote_job_id INT NULL, input_path TEXT NULL, output_path TEXT NULL, status VARCHAR(32) NOT NULL DEFAULT 'QUEUED', progress_percent INT DEFAULT 0, message TEXT NULL, result_json_path TEXT NULL, log_path TEXT NULL, created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6), KEY idx_sfm_remote_jobs_order_session (order_id, capture_session_id), KEY idx_sfm_remote_jobs_remote (remote_job_id), KEY idx_sfm_remote_jobs_status_updated (status, updated_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$db->query($sql)) {
        throw new RuntimeException('failed to ensure sfm_remote_jobs: ' . $db->error);
    }
}

function set_job(mysqli $db, int $id, string $status, int $progress, string $message): void
{
    $message = mb_substr($message, 0, 60000);
    $st = $db->prepare('UPDATE sfm_remote_jobs SET status=?, progress_percent=?, message=?, updated_at=NOW(6) WHERE id=?');
    if (!$st) {
        throw new RuntimeException('prepare update failed: ' . $db->error);
    }
    $st->bind_param('sisi', $status, $progress, $message, $id);
    $st->execute();
    $st->close();
}

function run_command(array $args): array
{
    $cmd = implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    $out = [];
    $code = 0;
    exec($cmd, $out, $code);
    return [$code, implode("\n", $out), $cmd];
}

function safe_session_video_path(mysqli $db, array $job): string
{
    $input = (string)($job['input_path'] ?? '');
    $orderId = (int)$job['order_id'];
    $sessionId = (int)$job['capture_session_id'];
    $st = $db->prepare('SELECT app_session_uuid FROM capture_sessions WHERE id=? AND order_id=? AND deleted_at IS NULL LIMIT 1');
    if (!$st) {
        throw new RuntimeException('prepare session lookup failed: ' . $db->error);
    }
    $st->bind_param('ii', $sessionId, $orderId);
    $st->execute();
    $session = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$session) {
        throw new RuntimeException('capture session not found');
    }
    $safeUuid = preg_replace('/[^a-zA-Z0-9._-]+/', '_', (string)$session['app_session_uuid']);
    $base = realpath(rtrim(APP_STORAGE_DIR, '/') . '/orders/' . $orderId . '/sessions/' . $safeUuid . '/videos');
    $real = realpath($input);
    if ($base === false || $real === false || !is_file($real) || strpos($real, $base . '/') !== 0) {
        throw new RuntimeException('input_path is outside allowed session videos directory');
    }
    return $real;
}

function frames_path_for_parent(int $parentRemoteJobId): string
{
    if ($parentRemoteJobId <= 0) {
        throw new RuntimeException('missing parent_remote_job_id');
    }
    return SFM_REMOTE_STORAGE_OUTPUT . '/job_' . $parentRemoteJobId . '/frames';
}

function model_id_from_job(array $job): int
{
    $out = (string)($job['output_path'] ?? '');
    if (preg_match('/sparse_(\d+)\.ply$/', $out, $m)) {
        return (int)$m[1];
    }
    return 0;
}

function claim_next_job(mysqli $db): ?array
{
    $db->begin_transaction();
    try {
        $res = $db->query("SELECT * FROM sfm_remote_jobs WHERE status='QUEUED' ORDER BY created_at ASC, id ASC LIMIT 1 FOR UPDATE");
        $job = $res ? $res->fetch_assoc() : null;
        if ($res) {
            $res->close();
        }
        if (!$job) {
            $db->commit();
            return null;
        }
        $id = (int)$job['id'];
        $st = $db->prepare("UPDATE sfm_remote_jobs SET status='RUNNING', message='Worker picked up job', updated_at=NOW(6) WHERE id=? AND status='QUEUED'");
        if (!$st) {
            throw new RuntimeException('prepare claim failed: ' . $db->error);
        }
        $st->bind_param('i', $id);
        $st->execute();
        $ok = $st->affected_rows === 1;
        $st->close();
        $db->commit();
        return $ok ? $job : null;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function launch_job(mysqli $db, array $job): void
{
    $id = (int)$job['id'];
    $remoteJobId = (int)$job['remote_job_id'];
    $type = (string)$job['job_type'];
    if ($remoteJobId <= 0) {
        throw new RuntimeException('bad remote_job_id');
    }
    if ($type === 'EXTRACT_FRAMES') {
        $input = safe_session_video_path($db, $job);
        $args = [SFM_REMOTE_BASE . '/run_extract_frames_job.sh', SFM_REMOTE_CONF, (string)$remoteJobId, $input];
    } elseif ($type === 'COLMAP_SPARSE') {
        $parent = (int)($job['parent_remote_job_id'] ?? 0);
        $args = [SFM_REMOTE_BASE . '/run_colmap_sparse_job.sh', SFM_REMOTE_CONF, (string)$remoteJobId, frames_path_for_parent($parent)];
    } elseif ($type === 'EXPORT_PLY') {
        $parent = (int)($job['parent_remote_job_id'] ?? $remoteJobId);
        $modelId = model_id_from_job($job);
        $args = [SFM_REMOTE_BASE . '/export_sparse_ply.sh', SFM_REMOTE_CONF, (string)$parent, (string)$modelId, SFM_REMOTE_OUTPUT];
    } else {
        throw new RuntimeException('unknown job_type: ' . $type);
    }

    [$code, $output] = run_command($args);
    if ($code !== 0) {
        set_job($db, $id, 'ERROR', (int)($job['progress_percent'] ?? 0), $output !== '' ? $output : 'Command failed with exit code ' . $code);
        worker_log("ERROR launch {$type} id={$id} remote={$remoteJobId} exit={$code}");
        return;
    }
    set_job($db, $id, $type === 'EXPORT_PLY' ? 'DONE' : 'RUNNING', $type === 'EXPORT_PLY' ? 100 : 0, $output !== '' ? $output : 'job launched');
    worker_log("launched {$type} id={$id} remote={$remoteJobId}");
}

function sync_running_jobs(mysqli $db): void
{
    $res = $db->query("SELECT * FROM sfm_remote_jobs WHERE status='RUNNING' ORDER BY updated_at ASC LIMIT 20");
    if (!$res) {
        return;
    }
    while ($job = $res->fetch_assoc()) {
        $id = (int)$job['id'];
        $remote = (int)$job['remote_job_id'];
        [$code, $raw] = run_command([SFM_REMOTE_BASE . '/get_station_status.sh', SFM_REMOTE_CONF, (string)$remote]);
        $json = json_decode($raw, true);
        if ($code !== 0 || !is_array($json)) {
            set_job($db, $id, 'RUNNING', (int)($job['progress_percent'] ?? 0), $raw !== '' ? $raw : 'waiting for remote status');
            continue;
        }
        $remoteStatus = strtoupper((string)($json['status'] ?? 'RUNNING'));
        $progress = (int)($json['progress_percent'] ?? $json['progress'] ?? $job['progress_percent'] ?? 0);
        $message = (string)($json['message'] ?? $raw);
        if ($remoteStatus === 'DONE') {
            [$fetchCode, $fetchOut] = run_command([
                SFM_REMOTE_BASE . '/fetch_job_result.sh',
                SFM_REMOTE_CONF,
                (string)$remote,
                SFM_REMOTE_OUTPUT,
            ]);
            set_job($db, $id, $fetchCode === 0 ? 'DONE' : 'ERROR', $fetchCode === 0 ? 100 : $progress, $fetchOut !== '' ? $fetchOut : $message);
        } elseif ($remoteStatus === 'ERROR' || $remoteStatus === 'FAILED') {
            set_job($db, $id, 'ERROR', $progress, $message);
        } else {
            set_job($db, $id, 'RUNNING', $progress, $message);
        }
    }
    $res->close();
}

ensure_sfm_remote_jobs_table($dbcnx);
worker_log('MaklerTour SfM remote worker started');
while (true) {
    try {
        sync_running_jobs($dbcnx);
        $job = claim_next_job($dbcnx);
        if ($job) {
            launch_job($dbcnx, $job);
        }
    } catch (Throwable $e) {
        worker_log('ERROR ' . $e->getMessage());
    }
    sleep(2);
}