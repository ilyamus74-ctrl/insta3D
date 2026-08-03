<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

function sourceFile(string $path): string {
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

$cmake = sourceFile('web/remote_station/dual_phone_host/CMakeLists.txt');
$preview = sourceFile('web/remote_station/dual_phone_host/src/stereo_preview.cpp');
$runtime = sourceFile('web/remote_station/dual_phone_host/src/accumulated_map_runtime.cpp');
$runtimeHeader = sourceFile('web/remote_station/dual_phone_host/src/accumulated_map_runtime.hpp');
$pack = sourceFile('web/remote_station/dual_phone_host/scripts/pack_session.sh');

requireText($cmake, 'features2d');
requireText($cmake, 'src/accumulated_map_runtime.cpp');
requireText($preview, '#include "accumulated_map_runtime.hpp"');
requireText($preview, 'AccumulatedMapRuntime accumulated_map');
requireText($preview, '{"accumulated_map", accumulated_map.status_json()}');
requireText($preview, 'accumulated_map.submit(');
requireText($preview, 'accumulated_map.reset()');
requireText($runtimeHeader, 'class AccumulatedMapRuntime');
requireText($runtime, 'cv::ORB::create');
requireText($runtime, 'cv::solvePnPRansac');
requireText($runtime, 'RELOCALIZED');
requireText($runtime, 'TRACKING_STATIONARY');
requireText($runtime, 'kVoxelMeters = 0.03');
requireText($runtime, 'input_source_profile != "HIGH_640"');
requireText($runtime, 'Clone only after backpressure has accepted this frame');
requireText($runtime, 'point_cloud_accumulated.ply');
requireText($runtime, 'camera_trajectory.json');
requireText($runtime, 'camera_trajectory.ply');
requireText($pack, 'accumulated_map_status.json');
requireText($pack, 'point_cloud_accumulated.ply');
requireText($pack, 'camera_trajectory.json');

echo "OK\n";
