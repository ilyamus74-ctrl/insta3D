<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$required = [
    'docs/llm/tasks/APP-DUAL-PHONE-DP04-4-ASYNC-TIMELINE.md' => [
        'asynchronous capture timeline',
        'CAPTURE_WINDOW_START',
        'CAPTURE_WINDOW_STOP',
        'clock_sync_history.jsonl',
        't_common = a * t_local + b',
        'Metric room skeleton safety',
    ],
    'app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/DualPhoneCaptureTimeline.kt' => [
        'capture_events.jsonl',
        'clock_sync_history.jsonl',
        'PIECEWISE_LINEAR_OFFSET_AND_DRIFT',
        'reference_master_ns',
        'recorded_local_elapsed_ns',
    ],
    'app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/PhoneCameraScanProvider.kt' => [
        'PHYSICAL_RECORDING_STARTED',
        'CAPTURE_WINDOW_START',
        'CAPTURE_WINDOW_STOP',
        'ASYNC_PRE_ROLL_POST_ROLL',
    ],
    'app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneClockSyncMath.kt' => [
        'referenceMasterNs',
    ],
    'app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneClockSyncController.kt' => [
        'reference_master_ns',
    ],
    'app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneControlManager.kt' => [
        'Logical START marker',
        'DUAL_PHONE_DEFAULT_POST_ROLL_MS',
        'command_id',
    ],
];

foreach ($required as $relative => $tokens) {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        fwrite(STDERR, "Missing file: {$relative}\n");
        exit(1);
    }
    $content = file_get_contents($path);
    foreach ($tokens as $token) {
        if (!str_contains((string) $content, $token)) {
            fwrite(STDERR, "Missing token '{$token}' in {$relative}\n");
            exit(1);
        }
    }
}

echo "OK\n";
