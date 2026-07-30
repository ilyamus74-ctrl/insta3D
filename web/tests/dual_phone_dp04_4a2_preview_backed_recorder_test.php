<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$registry = file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/' .
    'DualPhoneRecorderPreviewRegistry.kt'
);
$card = file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/settings/' .
    'DualPhoneControlSettingsCard.kt'
);
$recorder = file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/' .
    'PhoneCameraVideoRecorder.kt'
);
$provider = file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/' .
    'PhoneCameraScanProvider.kt'
);
$doc = file_get_contents(
    $root . '/docs/llm/tasks/APP-DUAL-PHONE-DP04-4A2-PREVIEW-BACKED-RECORDER.md'
);

$checks = [
    'preview registry exists' => str_contains(
        $registry,
        'object DualPhoneRecorderPreviewRegistry'
    ),
    'settings card owns real PreviewView' => str_contains(
        $card,
        'PreviewView(context)'
    ) && str_contains($card, 'AndroidView('),
    'preview is registered and unregistered' => str_contains(
        $card,
        'DualPhoneRecorderPreviewRegistry.register'
    ) && str_contains($card, 'DualPhoneRecorderPreviewRegistry.unregister'),
    'dual ARM requires preview surface' => str_contains(
        $provider,
        'requirePreviewSurface = true'
    ),
    'preview bind failure has 30 FPS preparation fallback' => str_contains(
        $provider,
        'REQUESTED_MODE_PREVIEW_BIND_FAILED'
    ),
    'recorder binds Preview plus VideoCapture' => str_contains(
        $recorder,
        'preparedPreview'
    ) && str_contains($recorder, 'DUAL_PHONE_PREVIEW_BACKED'),
    'preview surface wait is bounded' => str_contains(
        $recorder,
        'PREVIEW_SURFACE_TIMEOUT_MS = 5_000L'
    ),
    'encoded health wait is ten seconds' => str_contains(
        $recorder,
        'VALID_ENCODED_DATA_TIMEOUT_MS = 10_000L'
    ),
    'outer ARM watchdog covers both attempts' => str_contains(
        file_get_contents(
            $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/' .
            'data/dualphone/DualPhoneControlManager.kt'
        ),
        'ARM_PREPARE_TIMEOUT_MS = 60_000L'
    ),
    'status diagnostics are retained' => str_contains(
        $recorder,
        'lastStatusEventCount'
    ) && str_contains($recorder, 'PhoneVideoAttemptDiagnostics'),
    'two attempts are journaled' => str_contains(
        $provider,
        'RECORDER_ATTEMPT_STARTED'
    ) && str_contains($provider, 'RECORDER_ATTEMPT_FAILED') &&
        str_contains($provider, 'RECORDER_ATTEMPT_READY'),
    'fallback also requires real preview' => substr_count(
        $provider,
        'requirePreviewSurface = true'
    ) >= 2,
    'failed partial video is preserved' => str_contains(
        $provider,
        'video_attempt_${attemptNumber}_failed.mp4'
    ),
    'documentation preserves asynchronous markers' => str_contains(
        $doc,
        'START and STOP remain'
    ),
];

foreach ($checks as $name => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$name}\n");
        exit(1);
    }
}

echo "OK\n";
