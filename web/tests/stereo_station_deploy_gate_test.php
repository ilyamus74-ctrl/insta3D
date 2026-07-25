<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$deploy = $root . '/remote_station/deploy_station.sh';
if (!is_file($deploy)) {
    throw new RuntimeException('deploy_station.sh not found');
}

$source = (string) file_get_contents($deploy);
foreach ([
    '"$LOCAL_DIR"/scripts/*.py',
    "test -x '\$STATION_BASE/scripts/stereo_visual_odometry.py'",
    "test -x '\$STATION_BASE/scripts/stereo_global_fusion.py'",
    '-m py_compile',
    "grep -q 'stereo_visual_odometry.py'",
    "grep -q 'stereo_global_fusion.py'",
] as $required) {
    if (!str_contains($source, $required)) {
        throw new RuntimeException('stereo deploy gate missing: ' . $required);
    }
}

$lines = [];
$code = 0;
exec('bash -n ' . escapeshellarg($deploy) . ' 2>&1', $lines, $code);
if ($code !== 0) {
    throw new RuntimeException(
        "deploy_station.sh syntax failed\n" . implode("\n", $lines)
    );
}

echo "OK\n";
