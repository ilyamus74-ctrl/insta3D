<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$main = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/MainActivity.kt';
$card = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/session/DualPhoneLiveStreamSessionCard.kt';

foreach ([$main, $card] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing required file: {$path}\n");
        exit(1);
    }
}

$mainText = file_get_contents($main);
$cardText = file_get_contents($card);

$requiredMain = [
    'rememberDualPhoneCaptureSelected()',
    'if (dualPhoneCaptureSelected)',
    '"Dual-phone capture"',
    'DualPhoneLiveStreamSessionCard(',
    'capture_mode_photo',
    'capture_mode_video',
];

foreach ($requiredMain as $token) {
    if (strpos($mainText, $token) === false) {
        fwrite(STDERR, "Missing Camera role-visibility token: {$token}\n");
        exit(1);
    }
}

if (substr_count($mainText, 'DualPhoneLiveStreamSessionCard(') !== 1) {
    fwrite(STDERR, "LM01A card must be rendered exactly once in CameraScreen\n");
    exit(1);
}

$requiredCard = [
    'fun rememberDualPhoneCaptureSelected(): Boolean',
    'Lifecycle.Event.ON_RESUME',
    'settingsStore.load().role',
    'role != DualPhoneRole.STANDALONE',
];

foreach ($requiredCard as $token) {
    if (strpos($cardText, $token) === false) {
        fwrite(STDERR, "Missing role refresh token: {$token}\n");
        exit(1);
    }
}

echo "OK: Camera menu switches between standalone capture and dual-phone LIVE/HYBRID\n";
