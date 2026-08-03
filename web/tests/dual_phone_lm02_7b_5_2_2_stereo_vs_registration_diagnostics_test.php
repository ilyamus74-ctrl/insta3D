<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$tool = $root . '/web/remote_station/dual_phone_host/tools/analyze_accumulated_map.py';
$packer = $root . '/web/remote_station/dual_phone_host/scripts/pack_session.sh';

if (!is_file($tool)) {
    fwrite(STDERR, "missing diagnostic tool\n");
    exit(1);
}

$source = file_get_contents($tool);
foreach ([
    'point_cloud_accumulated_confirmed.ply',
    'point_cloud_accumulated_strict.ply',
    'point_cloud_accumulated_keyframe_colors.ply',
    'REGISTRATION_OR_ACCUMULATION_WEAK',
    'LOCAL_STEREO_SPARSE',
] as $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "missing marker: {$needle}\n");
        exit(1);
    }
}

$command = 'python3 ' . escapeshellarg($tool) . ' --self-test 2>&1';
exec($command, $output, $status);
if ($status !== 0 || trim(implode("\n", $output)) !== 'OK') {
    fwrite(STDERR, "python self-test failed:\n" . implode("\n", $output) . "\n");
    exit(1);
}

$packSource = file_get_contents($packer);
foreach ([
    'accumulated_diagnostics.json',
    'point_cloud_accumulated_confirmed.ply',
] as $needle) {
    if (!str_contains($packSource, $needle)) {
        fwrite(STDERR, "packer missing: {$needle}\n");
        exit(1);
    }
}

echo "OK\n";
