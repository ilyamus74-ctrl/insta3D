<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
function usage(): void {
    echo "Usage:\n";
    echo "  php cleanup_sfm_artifacts.php --pipeline-run-id <id> [--dry-run|--delete] [--include-logs] [--force-recent]\n";
    echo "  php cleanup_sfm_artifacts.php --older-than YYYY-MM-DD [--dry-run|--delete] [--video-scan-id <id>] [--mode preview|standard|fullhd] [--include-logs] [--force-recent]\n";
}

$opts = getopt('', ['pipeline-run-id:', 'older-than:', 'dry-run', 'delete', 'video-scan-id:', 'mode:', 'include-logs', 'force-recent', 'help']);
if (isset($opts['help'])) { usage(); exit(0); }
if (isset($opts['pipeline-run-id']) && !preg_match('/^[1-9][0-9]*$/', (string)$opts['pipeline-run-id'])) { fwrite(STDERR, "ERROR: bad --pipeline-run-id\n"); exit(2); }
if (!isset($opts['pipeline-run-id']) && !isset($opts['older-than'])) { usage(); exit(2); }
if (isset($opts['older-than']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$opts['older-than'])) { fwrite(STDERR, "ERROR: bad --older-than, expected YYYY-MM-DD\n"); exit(2); }
if (isset($opts['mode']) && !in_array((string)$opts['mode'], ['preview','standard','fullhd'], true)) { fwrite(STDERR, "ERROR: bad --mode\n"); exit(2); }
$connectCandidates = ['/home/makler/web/configs/connectDB.php', dirname(__DIR__) . '/configs/connectDB.php'];
foreach ($connectCandidates as $connectFile) { if (is_file($connectFile)) { require_once $connectFile; break; } }
if (!isset($dbcnx) || !($dbcnx instanceof mysqli)) { fwrite(STDERR, "ERROR: failed to initialize mysqli via connectDB.php\n"); exit(1); }
require_once __DIR__ . '/sfm_pipeline.php';
require_once __DIR__ . '/sfm_cleanup.php';
ensure_sfm_pipeline_tables($dbcnx);

$delete = isset($opts['delete']);
if (isset($opts['dry-run'])) { $delete = false; }
$options = [
    'pipeline_run_id' => isset($opts['pipeline-run-id']) ? (int)$opts['pipeline-run-id'] : null,
    'older_than' => isset($opts['older-than']) ? (string)$opts['older-than'] : null,
    'video_scan_id' => isset($opts['video-scan-id']) ? (string)$opts['video-scan-id'] : null,
    'mode' => isset($opts['mode']) ? (string)$opts['mode'] : null,
    'delete' => $delete,
    'include_logs' => isset($opts['include-logs']),
    'force_recent' => isset($opts['force-recent']),
];

echo ($delete ? "DELETE" : "DRY-RUN") . " SfM artifact cleanup; logs " . ($options['include_logs'] ? "included" : "preserved") . "\n";
$runs = sfm_cleanup_select_runs($dbcnx, $options);
$logPath = null;
if ($delete) {
    $logDir = SFM_CLEANUP_WEB_OUTPUT_BASE . '/cleanup_logs';
    if (!is_dir($logDir)) { @mkdir($logDir, 0775, true); }
    $logPath = $logDir . '/sfm_cleanup_' . date('Ymd_His') . '_' . getmypid() . '.log';
    file_put_contents($logPath, "SfM cleanup started " . date('c') . "\n", FILE_APPEND);
    echo "cleanup_log: $logPath\n";
}
$totalBytes = 0; $hadErrors = false;
foreach ($runs as $run) {
    $pid = (int)$run['id'];
    echo "\npipeline_run_id: {$pid} capture_session_id: {$run['capture_session_id']} video_scan_id: {$run['video_scan_id']} mode: {$run['pipeline_mode']} status: {$run['status']} created_at: {$run['created_at']}\n";
    if (!empty($run['protected'])) {
        echo "  SKIP protected (active, latest for video/mode, or recent <24h without --force-recent)\n";
        continue;
    }
    $res = sfm_cleanup_pipeline_run_artifacts($dbcnx, $pid, $options);
    foreach ($res['jobs'] as $job) {
        echo "  remote_job_id: {$job['remote_job_id']} job_type: {$job['job_type']} status: {$job['status']}\n";
        foreach ($job['web'] as $p) {
            $state = !empty($p['missing']) ? 'missing' : ($delete ? 'deleted' : 'would delete');
            echo "    [$state] {$p['path']} size_bytes=" . (int)($p['size_bytes'] ?? 0) . "\n";
        }
    }
    foreach ($res['deleted_paths'] as $path) { echo "    " . ($delete ? '[deleted]' : '[would delete]') . " $path\n"; }
    foreach ($res['missing_paths'] as $path) { echo "    [missing] $path\n"; }
    foreach ($res['errors'] as $err) { $hadErrors = true; echo "    [error] " . ($err['path'] ?? '') . ' ' . ($err['message'] ?? json_encode($err)) . "\n"; }
    $totalBytes += (int)$res['freed_bytes'];
    if ($delete && $logPath) { file_put_contents($logPath, json_encode($res, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND); }
}
echo "\nEstimated " . ($delete ? "freed" : "reclaimable") . " bytes: $totalBytes\n";
exit($hadErrors ? 1 : 0);