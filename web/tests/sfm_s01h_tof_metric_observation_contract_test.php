<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

function src(string $relative): string {
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

$builder = src('web/remote_station/scripts/build_tof_metric_observations.py');
ok(str_contains($builder, 'SFM-S01H1'), 'S01H.1 metric observation stage exists');
ok(str_contains($builder, 'SKIPPED_NO_TOF'), 'missing ToF is an explicit non-fatal skip');
ok(str_contains($builder, 'SKIPPED_TEMPORAL_UNVERIFIED'), 'failed temporal gate skips ToF measurement');
ok(str_contains($builder, 'SKIPPED_CALIBRATION_UNBOUND'), 'unbound calibration skips ToF measurement');
ok(str_contains($builder, '"geometry_mutation_enabled": False'), 'S01H.1 cannot mutate geometry');
ok(str_contains($builder, '"colmap_input_modified": False'), 'S01H.1 cannot modify COLMAP input');
ok(str_contains($builder, '"dense_input_modified": False'), 'S01H.1 cannot modify dense input');
ok(str_contains($builder, '"fusion_enabled": False'), 'S01H.1 cannot enable fusion');
ok(str_contains($builder, 'z_tof = float(distance_mm)'), 'VL53L8CX distance is axial Z');
ok(!str_contains($builder, 'sqrt(x_tof'), 'ToF ray is not normalized');

$processor = src('web/remote_station/scripts/process_extract_frames.sh');
ok(str_contains($processor, 'build_tof_metric_observations.py'), 'EXTRACT invokes S01H.1 metric measurement');
ok(str_contains($processor, 'RGB/COLMAP processing continues unchanged'), 'S01H.1 failure is explicitly non-blocking');
ok(str_contains($processor, "'tof_metric_measurement'"), 'EXTRACT result publishes S01H.1 report');

$worker = src('web/tools/sfm_remote_worker.php');
ok(str_contains($worker, "'TOF_METRIC'"), 'main pipeline logs S01H.1 summary');
ok(str_contains($worker, 'geometry_mutation=OFF fusion=OFF'), 'pipeline log makes measurement-only boundary explicit');

echo "Result: PASS\n";
