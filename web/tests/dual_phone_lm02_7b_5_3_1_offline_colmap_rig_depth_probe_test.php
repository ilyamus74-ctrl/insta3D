<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$paths = [
    'hostHpp' => $root . '/web/remote_station/dual_phone_host/src/host_state.hpp',
    'hostCpp' => $root . '/web/remote_station/dual_phone_host/src/host_state.cpp',
    'liveHpp' => $root . '/web/remote_station/dual_phone_host/src/live_preview_runtime.hpp',
    'liveCpp' => $root . '/web/remote_station/dual_phone_host/src/live_preview_runtime.cpp',
    'stereoHpp' => $root . '/web/remote_station/dual_phone_host/src/stereo_preview.hpp',
    'stereoCpp' => $root . '/web/remote_station/dual_phone_host/src/stereo_preview.cpp',
    'http' => $root . '/web/remote_station/dual_phone_host/src/http_dashboard.cpp',
    'web' => $root . '/web/remote_station/dual_phone_host/web/index.html',
    'prepare' => $root . '/web/remote_station/dual_phone_host/scripts/prepare_offline_colmap_rig.py',
    'export' => $root . '/web/remote_station/dual_phone_host/scripts/export_offline_colmap_trajectory.py',
    'runner' => $root . '/web/remote_station/dual_phone_host/scripts/run_offline_colmap_rig.sh',
    'contract' => $root . '/app/MaklerTour/docs/APP_DUAL_PHONE_LM02_7B_5_3_1_OFFLINE_COLMAP_RIG_DEPTH_PROBE_CONTRACT.md',
];

$contents = [];
foreach ($paths as $name => $path) {
    $value = file_get_contents($path);
    if ($value === false) {
        fwrite(STDERR, "missing required file: {$path}\n");
        exit(1);
    }
    $contents[$name] = $value;
}

$required = [
    'hostCpp' => [
        'MAKLER_COLMAP_PAIR_STRIDE',
        'std::filesystem::path("colmap_frames")',
        '"CAMERA_A"',
        'colmap_pairs.jsonl',
        'archive_colmap_pair_locked(',
        'camera_a_width',
        'colmap_archived_pairs',
    ],
    'liveCpp' => [
        'nlohmann::json depth_probe(',
        'probe_disparity',
        'probe_display_rotation_degrees',
        'kDepthProbeRadiusPixels = 2',
        'distance_m',
        'sample_count',
    ],
    'http' => [
        '/api/depth/probe',
        'query_number(target, "x")',
        'state_.depth_probe(*x, *y)',
    ],
    'web' => [
        'id="depthProbe"',
        'object-fit: contain',
        'scheduleDepthProbe(',
        '/api/depth/probe?x=',
        'Number(result.sequence) !== sequence',
    ],
    'prepare' => [
        'LM02.7B.5.3.1_OFFLINE_COLMAP_RIG',
        'scaled_intrinsics(',
        'rig_config.json',
        'cam_from_rig_translation',
    ],
    'runner' => [
        'rig_configurator',
        '--ImageReader.single_camera_per_folder 1',
        '--Mapper.ba_refine_sensor_from_rig 0',
        '--Mapper.ba_refine_focal_length 0',
        'export_offline_colmap_trajectory.py',
        '--dense',
    ],
    'export' => [
        'FIXED_STEREO_BASELINE',
        'offline_colmap_trajectory.json',
        'observed_stereo_baseline_median_m',
        'live_to_colmap_path_ratio',
    ],
    'contract' => [
        'Synchronized rig capture contract',
        'Offline COLMAP contract',
        'Live depth probe contract',
        '5×5 neighbourhood',
    ],
];

foreach ($required as $file => $tokens) {
    foreach ($tokens as $token) {
        if (!str_contains($contents[$file], $token)) {
            fwrite(STDERR, "missing {$file} token: {$token}\n");
            exit(1);
        }
    }
}

foreach (['hostHpp', 'liveHpp', 'stereoHpp'] as $file) {
    if (!str_contains($contents[$file], 'depth_probe(')) {
        fwrite(STDERR, "missing public depth probe forwarding in {$file}\n");
        exit(1);
    }
}

if (str_contains($contents['liveCpp'], 'metric_colour') === false) {
    fwrite(STDERR, "live metric depth implementation unexpectedly missing\n");
    exit(1);
}

if (!is_executable($paths['runner']) || !is_executable($paths['prepare']) || !is_executable($paths['export'])) {
    fwrite(STDERR, "offline COLMAP scripts must be executable\n");
    exit(1);
}

echo "OK\n";
