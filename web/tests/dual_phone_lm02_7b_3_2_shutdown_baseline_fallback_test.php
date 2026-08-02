<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$paths = [
    'main' => $root . '/web/remote_station/dual_phone_host/src/main.cpp',
    'http' => $root . '/web/remote_station/dual_phone_host/src/http_dashboard.cpp',
    'preview' => $root . '/web/remote_station/dual_phone_host/src/stereo_preview.cpp',
    'processing' => $root . '/web/remote_station/dual_phone_host/src/stereo_preview_processing.cpp',
    'processingHeader' => $root . '/web/remote_station/dual_phone_host/src/stereo_preview_processing.hpp',
    'html' => $root . '/web/remote_station/dual_phone_host/web/index.html',
];

foreach ($paths as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing {$name}: {$path}\n");
        exit(1);
    }
}

$content = array_map(static fn(string $path): string => file_get_contents($path), $paths);

$required = [
    'main' => [
        'std::vector<std::thread> camera_threads',
        'SO_RCVTIMEO',
        'camera_threads.emplace_back',
        'camera_thread.joinable()',
    ],
    'http' => [
        'try { handle_client(client); } catch (...) {}',
        'if (callback) callback();',
    ],
    'preview' => [
        'rectification_axis(',
        'calibration.translation_mm',
        'projection_shift(',
    ],
    'processing' => [
        'translation_mm[0]',
        'translation_mm[1]',
        'fallback_shift =',
        'stereo calibration contains no usable X/Y baseline',
    ],
    'processingHeader' => [
        'const std::array<double, 3>& translation_mm',
    ],
    'html' => [
        'grid-template-columns: minmax(0,1fr)',
    ],
];

foreach ($required as $name => $needles) {
    foreach ($needles as $needle) {
        if (!str_contains($content[$name], $needle)) {
            fwrite(STDERR, "{$name} missing token: {$needle}\n");
            exit(1);
        }
    }
}

if (str_contains($content['main'], 'std::thread(handle_camera, client, std::ref(state)).detach()')) {
    fwrite(STDERR, "Camera workers must be joined before HostState destruction\n");
    exit(1);
}

if (str_contains($content['http'], 'std::thread([this, client]')) {
    fwrite(STDERR, "Dashboard request handlers must not outlive HttpDashboard/HostState\n");
    exit(1);
}

if (str_contains($content['http'], 'std::this_thread::sleep_for')) {
    fwrite(STDERR, "Stop callback must not run in a detached delayed thread\n");
    exit(1);
}

echo "OK\n";
