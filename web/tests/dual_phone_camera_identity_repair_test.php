<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$repair = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/calibration/DualPhoneCalibrationCameraIdentityRepair.kt';
$card = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/session/DualPhoneLiveStreamSessionCard.kt';

foreach ([$repair, $card] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing required file: {$path}\n");
        exit(1);
    }
}

$repairText = file_get_contents($repair);
$cardText = file_get_contents($card);

$repairTokens = [
    'object DualPhoneCalibrationCameraIdentityRepair',
    'Existing non-blank IDs are never overwritten',
    'stored != candidate',
    'masterCameraId = repairedMaster',
    'slaveCameraId = repairedSlave',
];

foreach ($repairTokens as $token) {
    if (strpos($repairText, $token) === false) {
        fwrite(STDERR, "Missing repair token: {$token}\n");
        exit(1);
    }
}

$cardTokens = [
    'PhoneCameraLensRepository',
    'selectedOrDefault().first.cameraId',
    'controlSnapshot.peerCameraId',
    'Восстановить ID камер',
    'profileStore.save(repairedProfile)',
];

foreach ($cardTokens as $token) {
    if (strpos($cardText, $token) === false) {
        fwrite(STDERR, "Missing card repair token: {$token}\n");
        exit(1);
    }
}

echo "OK: accepted profile can repair missing camera IDs without overwriting conflicts\n";
