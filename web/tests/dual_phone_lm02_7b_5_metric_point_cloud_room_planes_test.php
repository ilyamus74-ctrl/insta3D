<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'cmake' => $root . '/web/remote_station/dual_phone_host/CMakeLists.txt',
    'geometry_hpp' => $root . '/web/remote_station/dual_phone_host/src/room_geometry_runtime.hpp',
    'geometry_cpp' => $root . '/web/remote_station/dual_phone_host/src/room_geometry_runtime.cpp',
    'depth_hpp' => $root . '/web/remote_station/dual_phone_host/src/stereo_depth_runtime.hpp',
    'depth_cpp' => $root . '/web/remote_station/dual_phone_host/src/stereo_depth_runtime.cpp',
    'preview_cpp' => $root . '/web/remote_station/dual_phone_host/src/stereo_preview.cpp',
    'pack' => $root . '/web/remote_station/dual_phone_host/scripts/pack_session.sh',
];

$content = [];
foreach ($files as $name => $path) {
    $value = file_get_contents($path);
    if ($value === false) {
        fwrite(STDERR, "cannot read {$name}: {$path}\n");
        exit(1);
    }
    $content[$name] = $value;
}

$required = [
    'cmake' => ['src/room_geometry_runtime.cpp'],
    'geometry_hpp' => [
        'class RoomGeometryRuntime',
        'const StereoDepthResult& depth',
        'nlohmann::json status_json() const;',
    ],
    'geometry_cpp' => [
        'point_cloud_latest.ply',
        'room_skeleton_latest.ply',
        'room_planes_latest.json',
        'room_edges_latest.json',
        'ROOM_GEOMETRY_READY',
        'kVoxelMeters = 0.04',
        'cv::PCA',
        'kRansacIterations',
        'X_right_Y_up_Z_forward_meters',
    ],
    'depth_hpp' => [
        'cv::Mat geometry_disparity;',
        'cv::Mat geometry_mask;',
        'double principal_x_px = 0.0;',
        'double principal_y_px = 0.0;',
    ],
    'depth_cpp' => [
        'result.geometry_disparity = std::move(dense_disparity);',
        'result.geometry_mask = std::move(dense_closed);',
        'result.principal_x_px',
        'result.principal_y_px',
    ],
    'preview_cpp' => [
        '#include "room_geometry_runtime.hpp"',
        'RoomGeometryRuntime room_geometry;',
        '{"room_geometry", room_geometry.status_json()}',
        'room_geometry.submit(',
        'room_geometry.reset();',
    ],
    'pack' => [
        'point_cloud_latest.ply',
        'room_skeleton_latest.ply',
        'room_planes_latest.json',
        'room_edges_latest.json',
        'room_geometry.jsonl',
        'room_geometry_status.json',
    ],
];

foreach ($required as $name => $needles) {
    foreach ($needles as $needle) {
        if (!str_contains($content[$name], $needle)) {
            fwrite(STDERR, "missing {$name} marker: {$needle}\n");
            exit(1);
        }
    }
}

echo "OK\n";
