<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
function sourceText(string $path): string {
    global $root;
    $value = file_get_contents($root . '/' . $path);
    if ($value === false) {
        fwrite(STDERR, "cannot read $path\n");
        exit(1);
    }
    return $value;
}
function requireText(string $source, string $needle): void {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "missing: $needle\n");
        exit(1);
    }
}

$cmake = sourceText('web/remote_station/dual_phone_host/CMakeLists.txt');
$runtime = sourceText(
    'web/remote_station/dual_phone_host/src/accumulated_map_runtime_continuous.cpp'
);

requireText($cmake, 'src/accumulated_map_runtime_continuous.cpp');
if (str_contains($cmake, "\n    src/accumulated_map_runtime.cpp\n")) {
    fwrite(STDERR, "legacy accumulated runtime is still compiled\n");
    exit(1);
}
requireText($runtime, 'ROTATION_HOMOGRAPHY');
requireText($runtime, 'PNP_DEPTH');
requireText($runtime, 'estimate_pose_continuous');
requireText($runtime, 'RELOCALIZATION_YAW_ROLLBACK');
requireText($runtime, 'SPARSE_DEPTH_INCONSISTENT');
requireText($runtime, 'TRACKING_ROTATION');
requireText($runtime, 'POSE_REJECTED');
requireText($runtime, 'rotation_only_keyframes');
requireText($runtime, 'pose_rejected_frames');
requireText($runtime, 'accumulated_yaw_deg');
requireText($runtime, 'schema_version", 2');
requireText($runtime, 'point_cloud_accumulated.ply');

echo "OK\n";
