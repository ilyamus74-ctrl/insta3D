<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$depthHeader = $root . '/web/remote_station/dual_phone_host/src/stereo_depth_runtime.hpp';
$depthSource = $root . '/web/remote_station/dual_phone_host/src/stereo_depth_runtime.cpp';
$mapSource = $root . '/web/remote_station/dual_phone_host/src/accumulated_map_runtime_gyro.cpp';
$packSource = $root . '/web/remote_station/dual_phone_host/scripts/pack_session.sh';

$files = [
    $depthHeader => file_get_contents($depthHeader),
    $depthSource => file_get_contents($depthSource),
    $mapSource => file_get_contents($mapSource),
    $packSource => file_get_contents($packSource),
];
foreach ($files as $path => $contents) {
    if ($contents === false) {
        fwrite(STDERR, "Unable to read {$path}\n");
        exit(1);
    }
}

$combined = implode("\n", array_values($files));
$needles = [
    'strict_geometry_disparity',
    'strict_geometry_mask',
    'temporal_strict_voxels',
    '_local_temporal_strict.ply',
    '_world_temporal_strict.ply',
    'point_cloud_accumulated_temporal_strict_raw.ply',
    'point_cloud_accumulated_temporal_strict_multiview.ply',
    'temporal_strict_overlap_fraction',
];
foreach ($needles as $needle) {
    if (!str_contains($combined, $needle)) {
        fwrite(STDERR, "Missing TEMPORAL STRICT wall diagnostic token: {$needle}\n");
        exit(1);
    }
}

if (!str_contains(
    $files[$depthSource],
    'result.geometry_disparity = std::move(dense_disparity);'
)) {
    fwrite(STDERR, "Dense geometry must remain authoritative\n");
    exit(1);
}

echo "OK\n";
