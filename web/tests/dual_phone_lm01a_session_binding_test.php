<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$main = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/MainActivity.kt';
$coordinator = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneLiveStreamSessionCoordinator.kt';
$card = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/session/DualPhoneLiveStreamSessionCard.kt';

foreach ([$main, $coordinator, $card] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing required file: {$path}\n");
        exit(1);
    }
}

$mainText = file_get_contents($main);
$coordinatorText = file_get_contents($coordinator);
$cardText = file_get_contents($card);

$requiredCoordinatorTokens = [
    'class DualPhoneLiveStreamSessionCoordinator',
    'DualPhoneLiveStreamSessionInput',
    'activeCalibrationProfileId',
    'profile.successful',
    'control.calibrationActive',
    'profile.rigMountRevision != settings.rigMountRevision',
    'connectedPeerId != roleIdentity.peerDeviceId',
    'calibrated_size=',
    'control.phase != DualPhoneControlPhase.CONNECTED',
    'DualPhoneControlPhase.RECORDING',
    'controller.prepare(owner)',
];

foreach ($requiredCoordinatorTokens as $token) {
    if (strpos($coordinatorText, $token) === false) {
        fwrite(STDERR, "Missing coordinator contract token: {$token}\n");
        exit(1);
    }
}

if (strpos($mainText, 'DualPhoneLiveStreamSessionCard(') === false) {
    fwrite(STDERR, "Camera session does not render the LM01A card\n");
    exit(1);
}

if (strpos($cardText, 'Старая кнопка «Начать видео-скан» к нему не относится.') === false) {
    fwrite(STDERR, "LM01A card does not distinguish the legacy video scan button\n");
    exit(1);
}

$forbiddenCoordinatorTokens = [
    'import java.net.ServerSocket',
    'import java.net.Socket',
    'import androidx.camera.core.ImageAnalysis',
    'import android.graphics.Bitmap',
];

foreach ($forbiddenCoordinatorTokens as $token) {
    if (strpos($coordinatorText, $token) !== false) {
        fwrite(STDERR, "LM01A-2 unexpectedly owns transport/camera work: {$token}\n");
        exit(1);
    }
}

echo "OK: LM01A-2 session binding and camera-session UI card contract\n";
