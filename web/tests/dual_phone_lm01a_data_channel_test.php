<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$channel = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneLiveStreamDataChannel.kt';
$card = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/session/DualPhoneLiveStreamSessionCard.kt';
$coordinator = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneLiveStreamSessionCoordinator.kt';
$control = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneControlManager.kt';

foreach ([$channel, $card, $coordinator] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing required file: {$path}\n");
        exit(1);
    }
}

$channelText = file_get_contents($channel);
$cardText = file_get_contents($card);
$coordinatorText = file_get_contents($coordinator);

$requiredChannel = [
    'class DualPhoneLiveStreamDataChannelController',
    'ServerSocket()',
    'Socket()',
    'DualPhoneRole.SLAVE ->',
    'runSlaveServer',
    'DualPhoneRole.MASTER ->',
    'runMasterClient',
    'const val DEFAULT_PORT: Int = 45831',
    'PacketType.HELLO',
    'PacketType.HELLO_ACK',
    'PacketType.PING',
    'PacketType.PONG',
    'Calibration identity mismatch',
    'Recording mode identity mismatch',
];

foreach ($requiredChannel as $token) {
    if (strpos($channelText, $token) === false) {
        fwrite(STDERR, "Missing data-channel token: {$token}\n");
        exit(1);
    }
}

$requiredCard = [
    'DualPhoneLiveStreamDataChannelController()',
    'DualPhoneLiveStreamDataChannelConfig(',
    'peerHost = controlSnapshot.peerHost',
    'coordinator.markTransportReady()',
    'Data channel:',
    'Кадры камеры ещё не передаются.',
];

foreach ($requiredCard as $token) {
    if (strpos($cardText, $token) === false) {
        fwrite(STDERR, "Missing LM01A-3 UI token: {$token}\n");
        exit(1);
    }
}

if (strpos($coordinatorText, 'markTransportReconnecting') === false) {
    fwrite(STDERR, "Coordinator does not expose reconnect transition\n");
    exit(1);
}

$forbidden = [
    'androidx.camera.core.ImageAnalysis',
    'android.graphics.Bitmap',
    'JPEG',
    'rotationAppliedDegrees =',
];

foreach ($forbidden as $token) {
    if (strpos($channelText, $token) !== false) {
        fwrite(STDERR, "LM01A-3 unexpectedly contains frame/camera work: {$token}\n");
        exit(1);
    }
}

if (is_file($control)) {
    $controlText = file_get_contents($control);
    if (strpos($controlText, 'DualPhoneLiveStreamDataChannelController') !== false) {
        fwrite(STDERR, "Frame data channel was incorrectly embedded in control manager\n");
        exit(1);
    }
}

echo "OK: LM01A-3 uses a separate authenticated TCP data channel with heartbeats\n";
