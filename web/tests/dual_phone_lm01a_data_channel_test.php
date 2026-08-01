<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$channel = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneLiveStreamDataChannel.kt';
$runtime = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneApplicationRuntime.kt';
$card = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/session/DualPhoneLiveStreamSessionCard.kt';
$coordinator = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneLiveStreamSessionCoordinator.kt';
$control = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneControlManager.kt';

foreach ([$channel, $runtime, $card, $coordinator, $control] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing required file: {$path}\n");
        exit(1);
    }
}

$channelText = file_get_contents($channel);
$runtimeText = file_get_contents($runtime);
$cardText = file_get_contents($card);
$coordinatorText = file_get_contents($coordinator);
$controlText = file_get_contents($control);

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
    'DualPhoneApplicationRuntime.get(appContext)',
    'applicationRuntime.enterWorkMode(',
    'applicationRuntime::exitWorkMode',
    'LIVE/HYBRID выбираются только на MASTER.',
    'Data channel:',
    'Кадры камеры ещё не передаются.',
];

foreach ($requiredCard as $token) {
    if (strpos($cardText, $token) === false) {
        fwrite(STDERR, "Missing LM01A-3 UI token: {$token}\n");
        exit(1);
    }
}

$requiredRuntime = [
    'class DualPhoneApplicationRuntime',
    'DualPhoneLiveStreamSessionCoordinator()',
    'DualPhoneLiveStreamDataChannelController()',
    'requestEnterWorkMode(',
    'handleRemoteEnterWorkMode(',
    'Waiting for SLAVE TCP/45831 listener',
    'role = DualPhoneRole.SLAVE',
    'role = DualPhoneRole.MASTER',
];

foreach ($requiredRuntime as $token) {
    if (strpos($runtimeText, $token) === false) {
        fwrite(STDERR, "Missing LM01A-4 runtime token: {$token}\n");
        exit(1);
    }
}

if (strpos($cardText, 'DualPhoneLiveStreamDataChannelController()') !== false) {
    fwrite(STDERR, "Data channel is still owned by the Compose card\n");
    exit(1);
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

foreach ([
    'DualPhoneControlType.ENTER_WORK_MODE',
    'DualPhoneControlType.ENTER_WORK_MODE_ACK',
    'DualPhoneControlType.EXIT_WORK_MODE',
    'DualPhoneControlType.EXIT_WORK_MODE_ACK',
] as $token) {
    if (strpos($controlText, $token) === false) {
        fwrite(STDERR, "Missing MASTER/SLAVE control token: {$token}\n");
        exit(1);
    }
}

echo "OK: LM01A-4 owns the data channel at application scope and MASTER controls SLAVE work mode\n";
