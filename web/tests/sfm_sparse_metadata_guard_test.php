<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$scriptPath = $root . '/remote_station/scripts/process_colmap_sparse.sh';
$script = @file_get_contents($scriptPath);

$checks = [
    'process_colmap_sparse.sh is readable' => is_string($script),
    'metadata path is resolved before the guard' => is_string($script)
        && str_contains(
            $script,
            'META_PATH="$(find_camera_metadata_json || true)"'
        ),
    'metadata path requires non-empty regular file' => is_string($script)
        && str_contains(
            $script,
            'if [[ -n "$META_PATH" && -f "$META_PATH" ]]; then'
        ),
    'metadata JSON is read only inside the guard' => is_string($script)
        && str_contains(
            $script,
            'CAMERA_METADATA_RESULT="$(cat "$META_PATH")"'
        ),
    'unsafe always-success one-line condition is absent' => is_string($script)
        && !str_contains(
            $script,
            'if META_PATH="$(find_camera_metadata_json || true)"; then CAMERA_METADATA_RESULT="$(cat "$META_PATH")"; fi'
        ),
];

$ok = true;
foreach ($checks as $name => $passed) {
    echo ($passed ? 'OK ' : 'FAIL ') . $name . PHP_EOL;
    if (!$passed) {
        $ok = false;
    }
}
