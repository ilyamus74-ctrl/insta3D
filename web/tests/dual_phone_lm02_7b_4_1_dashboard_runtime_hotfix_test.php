<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$runtime = file_get_contents(
    $root . '/web/remote_station/dual_phone_host/src/stereo_depth_runtime.cpp'
);
$preview = file_get_contents(
    $root . '/web/remote_station/dual_phone_host/src/stereo_preview.cpp'
);
$dashboard = file_get_contents(
    $root . '/web/remote_station/dual_phone_host/web/index.html'
);

if ($runtime === false || $preview === false || $dashboard === false) {
    fwrite(STDERR, "cannot read LM02.7B.4.1 target files\n");
    exit(1);
}

foreach ([
    'std::size_t insertion = count;',
    'if (count >= values.size()) break;',
    'values[insertion] = value;',
    'std::min(',
] as $needle) {
    if (!str_contains($runtime, $needle)) {
        fwrite(STDERR, "missing runtime hotfix marker: {$needle}\n");
        exit(1);
    }
}
if (str_contains($runtime, 'std::sort(values.begin(), values.begin() + count)')) {
    fwrite(STDERR, "small-array std::sort warning path is still present\n");
    exit(1);
}

foreach ([
    'const bool publish_preview_images =',
    'if (publish_preview_images) {',
] as $needle) {
    if (!str_contains($preview, $needle)) {
        fwrite(STDERR, "missing preview throttle marker: {$needle}\n");
        exit(1);
    }
}

foreach ([
    'let refreshInFlight = false;',
    'tick - lastStereoImageRefreshAt >= 1000',
    'setInterval(refresh, 500)',
    'loading="lazy" decoding="async"',
] as $needle) {
    if (!str_contains($dashboard, $needle)) {
        fwrite(STDERR, "missing dashboard throttle marker: {$needle}\n");
        exit(1);
    }
}
if (str_contains($dashboard, 'position: sticky')) {
    fwrite(STDERR, "sticky header still breaks full-page captures\n");
    exit(1);
}

echo "OK\n";
