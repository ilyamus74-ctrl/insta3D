<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'cmake' => $root . '/web/remote_station/dual_phone_host/CMakeLists.txt',
    'live_hpp' => $root . '/web/remote_station/dual_phone_host/src/live_preview_runtime.hpp',
    'live_cpp' => $root . '/web/remote_station/dual_phone_host/src/live_preview_runtime.cpp',
    'preview_hpp' => $root . '/web/remote_station/dual_phone_host/src/stereo_preview.hpp',
    'preview_cpp' => $root . '/web/remote_station/dual_phone_host/src/stereo_preview.cpp',
    'host_hpp' => $root . '/web/remote_station/dual_phone_host/src/host_state.hpp',
    'host_cpp' => $root . '/web/remote_station/dual_phone_host/src/host_state.cpp',
    'http' => $root . '/web/remote_station/dual_phone_host/src/http_dashboard.cpp',
    'runtime' => $root . '/web/remote_station/dual_phone_host/src/stereo_depth_runtime.cpp',
    'dashboard' => $root . '/web/remote_station/dual_phone_host/web/index.html',
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
    'cmake' => [
        'src/live_preview_runtime.cpp',
    ],
    'live_hpp' => [
        'class LivePreviewRuntime',
        'void submit(StereoPreviewPair pair, ResolvedCalibration calibration);',
        'nlohmann::json status_json() const;',
    ],
    'live_cpp' => [
        'constexpr std::chrono::milliseconds kLiveInterval{200};',
        'constexpr int kLivePortraitWidth = 360;',
        'constexpr int kLivePortraitHeight = 640;',
        'LIVE_PREVIEW_READY',
        'dropped_pending_pairs',
        'selected_preview_latest.jpg',
        'cv::StereoSGBM::MODE_SGBM_3WAY',
    ],
    'preview_hpp' => [
        'nlohmann::json live_status_json() const;',
    ],
    'preview_cpp' => [
        '#include "live_preview_runtime.hpp"',
        'live_runtime.submit(',
        '{"live_preview", live}',
        'return live_runtime.image();',
    ],
    'host_hpp' => [
        'nlohmann::json live_preview_json() const;',
    ],
    'host_cpp' => [
        'HostState::live_preview_json() const',
        'stereo_preview_->live_status_json()',
    ],
    'http' => [
        'path == "/api/live-preview"',
        'state_.live_preview_json().dump()',
    ],
    'runtime' => [
        'std::string selected_mode = "HIGH_640";',
        'std::string active_profile = "HIGH_640";',
    ],
    'dashboard' => [
        'setInterval(refreshLivePreview, 100);',
        'setInterval(refresh, 500);',
        'LIVE ${liveWork}',
        'GEOMETRY ${depth.selection_mode',
        '/api/live-preview',
        'value="HIGH_640" selected',
    ],
    'pack' => [
        'live_preview.jsonl',
        'live_preview_status.json',
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
    $content['preview_cpp'],
    'operator_preview_source(depth, preview_mode)'
)) {
    fwrite(STDERR, "geometry contour still encodes the operator preview\n");
    exit(1);
}

echo "OK\n";
