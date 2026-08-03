<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$cmake = file_get_contents(
    $root . '/web/remote_station/dual_phone_host/CMakeLists.txt'
);
$state = file_get_contents(
    $root . '/web/remote_station/dual_phone_host/src/operator_preview_state.cpp'
);
$preview = file_get_contents(
    $root . '/web/remote_station/dual_phone_host/src/stereo_preview.cpp'
);
$http = file_get_contents(
    $root . '/web/remote_station/dual_phone_host/src/http_dashboard.cpp'
);
$dashboard = file_get_contents(
    $root . '/web/remote_station/dual_phone_host/web/index.html'
);
$pack = file_get_contents(
    $root . '/web/remote_station/dual_phone_host/scripts/pack_session.sh'
);

if (in_array(false, [$cmake, $state, $preview, $http, $dashboard, $pack], true)) {
    fwrite(STDERR, "cannot read LM02.7B.4.2 target files\n");
    exit(1);
}

foreach ([
    'src/operator_preview_state.cpp',
    'selected_preview_jpeg',
    'selected_preview_sequence',
    'operator_preview_source',
    'selected_preview_latest.jpg',
    '/api/depth/preview/',
    '/stereo/selected.jpg',
    'id="previewMode"',
    'id="selectedPreview"',
    'setInterval(refresh, 250)',
] as $needle) {
    $haystack = $cmake . $state . $preview . $http . $dashboard . $pack;
    if (!str_contains($haystack, $needle)) {
        fwrite(STDERR, "missing selected-preview marker: {$needle}\n");
        exit(1);
    }
}

foreach ([
    'id="rectifiedA"',
    'id="rectifiedB"',
    'id="depthRaw"',
    'id="depthFiltered"',
    'id="depthStrict"',
    'id="confidence"',
] as $needle) {
    if (str_contains($dashboard, $needle)) {
        fwrite(STDERR, "non-selected dashboard image remains: {$needle}\n");
        exit(1);
    }
}

foreach ([
    'last_processed_image_write',
    'publish_preview_images',
] as $needle) {
    if (str_contains($preview, $needle)) {
        fwrite(STDERR, "legacy multi-preview throttle remains: {$needle}\n");
        exit(1);
    }
}

echo "OK\n";
