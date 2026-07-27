<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$recovery = $root . '/web/tools/sfm_recover_partial_dense.php';
$restore = $root . '/web/remote_station/restore_job_to_station.sh';
$worker = $root . '/web/tools/sfm_remote_worker.php';
$pipeline = $root . '/web/remote_station/sfm_pipeline.php';

foreach ([$recovery, $restore, $worker, $pipeline] as $path) {
    if (!is_file($path)) {
        throw new RuntimeException('required file missing: ' . $path);
    }
}

$recoverySource = (string)file_get_contents($recovery);
foreach ([
    '--pipeline-run-id',
    '--model-id',
    'target-images-per-chunk',
    'max-images-per-chunk',
    'num-src-images',
    'ram-reserve-mb',
    'restore_job_to_station.sh',
    "stage='DENSE_PLAN'",
    "status='RUNNING'",
    'DELETE FROM sfm_remote_cleanup_runs',
    'apriltag_assist.json',
    'partial_dense_component_recovery',
] as $required) {
    if (!str_contains($recoverySource, $required)) {
        throw new RuntimeException(
            'recovery contract missing: ' . $required
        );
    }
}

$workerSource = (string)file_get_contents($worker);
foreach ([
    '$params[\'target_images_per_chunk\'] ?? $dense[\'target_images_per_chunk\']',
    '$params[\'max_images_per_chunk\'] ?? $dense[\'max_images_per_chunk\']',
    '$params[\'overlap_images\'] ?? $dense[\'chunk_overlap\']',
] as $required) {
    if (!str_contains($workerSource, $required)) {
        throw new RuntimeException(
            'per-job safe chunk override missing: ' . $required
        );
    }
}

$pipelineSource = (string)file_get_contents($pipeline);
foreach ([
    'dirname($modelDir, 2) . \'/apriltag_assist.json\'',
    '$pipelineDir . \'/apriltag_assist.json\'',
    "'Preserved report status=%s sim3=%s models=%s->%s'",
] as $required) {
    if (!str_contains($pipelineSource, $required)) {
        throw new RuntimeException(
            'AprilTag persistence contract missing: ' . $required
        );
    }
}

$php = trim((string)(
    getenv('PHP_BINARY')
    ?: PHP_BINARY
    ?: shell_exec('command -v php 2>/dev/null')
));
if ($php !== '' && is_executable($php)) {
    foreach ([$recovery, $pipeline] as $path) {
        $output = [];
        $code = 0;
        exec(
            escapeshellarg($php)
            . ' -l '
            . escapeshellarg($path)
            . ' 2>&1',
            $output,
            $code
        );
        if ($code !== 0) {
            throw new RuntimeException(
                'PHP lint failed for ' . $path . "\n" .
                implode("\n", $output)
            );
        }
    }
}

$bash = trim((string)shell_exec('command -v bash 2>/dev/null'));
if ($bash !== '' && is_executable($bash)) {
    $output = [];
    $code = 0;
    exec(
        escapeshellarg($bash)
        . ' -n '
        . escapeshellarg($restore)
        . ' 2>&1',
        $output,
        $code
    );
    if ($code !== 0) {
        throw new RuntimeException(
            "restore helper bash syntax failed\n" .
            implode("\n", $output)
        );
    }
}

echo "OK\n";
