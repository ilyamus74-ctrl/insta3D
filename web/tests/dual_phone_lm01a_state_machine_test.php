<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$sourceDir = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone';
$testFile = $root . '/app/MaklerTour/app/src/test/java/com/example/maklertour/data/dualphone/DualPhoneLiveStreamControllerTest.kt';

$files = [
    $sourceDir . '/DualPhoneLiveStreamState.kt',
    $sourceDir . '/DualPhoneLiveStreamStats.kt',
    $sourceDir . '/DualPhoneLiveStreamFrame.kt',
    $sourceDir . '/DualPhoneLiveStreamController.kt',
    $testFile,
];

foreach ($files as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "Missing LM01A-1 file: {$file}\n");
        exit(1);
    }
}

$source = implode("\n", array_map(
    static fn(string $file): string => (string) file_get_contents($file),
    $files,
));

$required = [
    'enum class DualPhoneLiveStreamState',
    'SYNC_VIDEO',
    'LIVE_METRIC',
    'HYBRID',
    'data class DualPhoneLiveStreamOwner',
    'sessionUuid',
    'dualCaptureId',
    'calibrationIdentity',
    'rigMountRevision',
    'class DualPhoneLiveStreamController',
    'fun reconcileOwner(',
    'fun prepare(',
    'fun markReady(',
    'fun start(',
    'fun beginStop(',
    'fun completeStop(',
    'rotationAppliedDegrees == 0',
    'MAX_PAYLOAD_BYTES: Int = 256 * 1024',
];

foreach ($required as $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "Missing LM01A-1 contract token: {$needle}\n");
        exit(1);
    }
}

$forbidden = [
    'java.net.Socket',
    'java.net.ServerSocket',
    'android.graphics.Bitmap',
    'androidx.camera.core.ImageAnalysis',
];

foreach ($forbidden as $needle) {
    if (str_contains($source, $needle)) {
        fwrite(STDERR, "LM01A-1 must not implement transport or image capture: {$needle}\n");
        exit(1);
    }
}

echo "OK: LM01A-1 session ownership and state machine contract\n";
