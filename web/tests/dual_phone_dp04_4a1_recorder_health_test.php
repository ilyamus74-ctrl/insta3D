<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$recorder = file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/PhoneCameraVideoRecorder.kt'
);
$provider = file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/PhoneCameraScanProvider.kt'
);
$manager = file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneControlManager.kt'
);
$runtime = file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneCaptureRuntime.kt'
);
$docs = file_get_contents(
    $root . '/docs/llm/tasks/APP-DUAL-PHONE-DP04-4A1-RECORDER-HEALTH.md'
);

$checks = [
    'recorder processes Status events' => str_contains(
        $recorder,
        'is VideoRecordEvent.Status'
    ),
    'recorder requires encoded bytes' => str_contains(
        $recorder,
        'MIN_VALID_ENCODED_BYTES'
    ),
    'recorder requires encoded duration' => str_contains(
        $recorder,
        'MIN_VALID_ENCODED_DURATION_NS'
    ),
    'headless mode consumes an analysis stream' => str_contains(
        $recorder,
        'buildHeadlessKeepAliveAnalysis'
    ),
    'regular 30 fps fallback exists' => str_contains(
        $recorder,
        'regular30FpsFallbackModeId'
    ),
    'provider records fallback event' => str_contains(
        $provider,
        'MODE_FALLBACK_SELECTED'
    ),
    'provider records health bytes' => str_contains(
        $provider,
        'pre_roll_bytes_at_ready'
    ),
    'runtime exposes valid encoded data' => str_contains(
        $runtime,
        'validEncodedDataObserved'
    ),
    'fps mismatch is diagnostic rather than fatal' =>
        str_contains($manager, 'val fpsMismatch') &&
        !str_contains(
            $manager,
            "local.height != peerHeight ||\n                            local.fps != peerFps"
        ),
    'docs preserve timestamp pairing' => str_contains(
        $docs,
        'Frame indexes are never assumed to correspond'
    ),
];

$failed = [];
foreach ($checks as $name => $passed) {
    if (!$passed) {
        $failed[] = $name;
    }
}

if ($failed !== []) {
    fwrite(STDERR, "FAILED:\n - " . implode("\n - ", $failed) . "\n");
    exit(1);
}

echo "OK\n";
