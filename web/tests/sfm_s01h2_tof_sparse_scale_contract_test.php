<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

function source_file(string $relative): string {
    global $root;
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        fwrite(STDERR, "[FAIL] missing {$relative}\n");
        exit(1);
    }
    return (string)file_get_contents($path);
}

function ok(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
    echo "[OK] {$message}\n";
}

$measure = source_file('web/remote_station/scripts/measure_tof_sparse_scale.py');
ok(str_contains($measure, 'SFM-S01H2'), 'S01H.2 measurement stage exists');
ok(str_contains($measure, 'SKIPPED_NO_TOF_MEASUREMENT'), 'missing ToF is a non-fatal skip');
ok(str_contains($measure, 'SKIPPED_SPARSE_TEXT_UNAVAILABLE'), 'missing sparse TXT is a non-fatal skip');
ok(str_contains($measure, '"geometry_mutation_enabled": False'), 'S01H.2 cannot mutate geometry');
ok(str_contains($measure, '"sparse_model_modified": False'), 'S01H.2 cannot modify sparse model');
ok(str_contains($measure, '"camera_poses_modified": False'), 'S01H.2 cannot modify poses');
ok(str_contains($measure, '"points3d_modified": False'), 'S01H.2 cannot modify sparse points');
ok(str_contains($measure, '"dense_input_modified": False'), 'S01H.2 cannot modify dense input');
ok(str_contains($measure, '"fusion_enabled": False'), 'S01H.2 cannot enable fusion');
ok(str_contains($measure, 'tof_z_mm / sparse_z_units'), 'scale uses camera-space axial depth ratio');

echo "Result: PASS\n";
