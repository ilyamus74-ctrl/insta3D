<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'live_hpp' => $root . '/web/remote_station/dual_phone_host/src/live_preview_runtime.hpp',
    'live_cpp' => $root . '/web/remote_station/dual_phone_host/src/live_preview_runtime.cpp',
    'preview_cpp' => $root . '/web/remote_station/dual_phone_host/src/stereo_preview.cpp',
    'dashboard' => $root . '/web/remote_station/dual_phone_host/web/index.html',
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
    'live_hpp' => [
        'nlohmann::json select_profile(std::string mode);',
    ],
    'live_cpp' => [
        '{"FHD_1920", 1080, 1920, 1000}',
        '{"ULTRA_960", 540, 960, 400}',
        '{"HIGH_640", 360, 640, 200}',
        '{"QUALITY_480", 270, 480, 200}',
        '{"BALANCED_320", 180, 320, 200}',
        '"resolution_policy", "MATCH_PROFILE"',
        '"input_replayed"',
        '"fresh_input_frames"',
        '"replayed_input_frames"',
        '"publish_age_ms"',
        '"source_age_ms"',
        'LIVE_PREVIEW_STALE_PROFILE_DISCARDED',
        'replayed_input_for_job',
        'heartbeat_tick % 1000U',
        'last_job = std::move(*pending);',
        'condition.notify_all();',
    ],
    'preview_cpp' => [
        'result["live_preview"] = live_runtime.select_profile(selected_mode);',
    ],
    'dashboard' => [
        'wantedSelectedSequence',
        'pumpSelectedPreview',
        'selectedLoadTimer',
        'heartbeat=${Date.now()}',
        'live.publish_age_ms',
        'live.input_replayed',
        'latestStereoStatus',
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

if (str_contains(
    $content['live_cpp'],
    'constexpr int kLivePortraitWidth = 360;'
)) {
    fwrite(STDERR, "live preview is still hard-coded to 360x640\n");
    exit(1);
}

if (str_contains(
    $content['live_cpp'],
    "\n            sequence = 0;\n"
)) {
    fwrite(STDERR, "live sequence is still reset and can freeze the browser\n");
    exit(1);
}

echo "OK\n";
