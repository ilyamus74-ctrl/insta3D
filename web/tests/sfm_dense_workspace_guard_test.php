<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$worker = $root . '/tools/sfm_remote_worker.php';
$process = $root
    . '/remote_station/scripts/process_colmap_dense_chunk.sh';
$health = $root . '/remote_station/get_remote_job_health.sh';
$cancel = $root . '/remote_station/cancel_remote_job.sh';
$validator = $root
    . '/remote_station/scripts/validate_colmap_dense_workspace.py';
$normalizer = $root
    . '/remote_station/scripts/normalize_colmap_dense_workspace.py';

foreach (
    [$worker, $process, $health, $cancel, $validator, $normalizer]
    as $path
) {
    if (!is_file($path)) {
        throw new RuntimeException('missing file: ' . $path);
    }
}

$workerText = (string)file_get_contents($worker);
foreach ([
    'SFM_DENSE_HEALTH_GRACE_SECONDS',
    "\$remoteStatus === 'UNKNOWN'",
    'log_has_sigabrt',
    "cancel_remote_job.sh",
    "(string)(\$job['chunk_index'] ?? '')",
] as $token) {
    if (!str_contains($workerText, $token)) {
        throw new RuntimeException(
            'dense watchdog contract missing: ' . $token
        );
    }
}

$processText = (string)file_get_contents($process);
foreach ([
    'rm -rf "$SANITIZED_IMAGES_DIR" "$UNDISTORTED_DIR"',
    'validate_colmap_dense_workspace.py',
    'dense_workspace_validation.json',
    'WORKSPACE_PREFLIGHT',
    'WORKSPACE_NORMALIZATION',
    'model_converter',
    'normalize_colmap_dense_workspace.py',
    'dense_workspace_normalization.json',
    '--PatchMatchStereo.max_image_size "$PATCH_MATCH_MAX_IMAGE_SIZE"',
] as $token) {
    if (!str_contains($processText, $token)) {
        throw new RuntimeException(
            'dense workspace guard missing: ' . $token
        );
    }
}

$healthText = (string)file_get_contents($health);
if (
    str_contains(
        $healthText,
        'grep -Eq "makler_job_${rid}|job_${parent}/"'
    )
) {
    throw new RuntimeException(
        'health check still matches unrelated parent containers'
    );
}
if (!str_contains($healthText, 'chunk_${chunk}')) {
    throw new RuntimeException('health check is not chunk-specific');
}

$cancelText = (string)file_get_contents($cancel);
if (str_contains($cancelText, 'index($0,p)')) {
    throw new RuntimeException(
        'cancel script still removes unrelated parent containers'
    );
}
if (!str_contains($cancelText, 'makler_job_${rid}')) {
    throw new RuntimeException('exact remote container cleanup missing');
}

$normalizerText = (string)file_get_contents($normalizer);
foreach ([
    'ffprobe',
    'ffmpeg',
    'mismatch_count_before',
    'normalized_image_count',
    'mismatch_count_after',
    'force_patchmatch_bitmap_rescale',
] as $token) {
    if (!str_contains($normalizerText, $token)) {
        throw new RuntimeException(
            'decoded-dimension normalizer missing: ' . $token
        );
    }
}

echo "OK\n";
