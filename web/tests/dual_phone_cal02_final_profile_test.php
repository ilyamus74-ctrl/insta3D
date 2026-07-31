<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$paths = [
    'models' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/calibration/DualPhoneCalibrationProfile.kt',
    'stereo' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/calibration/DualPhoneStereoCalibrationEstimator.kt',
    'store' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/calibration/DualPhoneCalibrationProfileStore.kt',
    'live' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/calibration/DualPhoneLiveIntrinsicsEstimator.kt',
    'observation' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneCalibrationControl.kt',
    'protocol' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneControlProtocol.kt',
    'manager' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneControlManager.kt',
    'fullscreen' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/settings/DualPhoneCalibrationFullscreen.kt',
    'settingsCard' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/settings/DualPhoneControlSettingsCard.kt',
    'main' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/MainActivity.kt',
];

foreach ($paths as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing {$name}: {$path}\n");
        exit(1);
    }
}

$content = array_map(static fn(string $path): string => file_get_contents($path), $paths);

$required = [
    'models' => [
        'DualPhoneCalibrationProfileResult',
        'DualPhoneStereoEstimate',
        'MAX_STEREO_RMS_PX',
        'master_intrinsics',
        'slave_intrinsics',
        'baseline_delta_mm',
    ],
    'stereo' => [
        'DualPhoneStereoCalibrationEstimator',
        'CALIB_FIX_INTRINSIC',
        'MIN_COMMON_CHARUCO_IDS',
        'commonIds',
        'baseline - operatorBaselineMm',
    ],
    'store' => [
        'dual_phone_calibration_profiles',
        'activeCalibrationProfileId',
        'CalibrationStatus.CALIBRATED',
        'StereoRigTopology.DUAL_PHONE',
    ],
    'live' => [
        'MAX_INTRINSICS_RMS_PX',
        'MIN_FINAL_FRAMES',
        'imageWidth',
        'fun toJson()',
        'fun fromJson',
    ],
    'observation' => [
        'DualPhoneCharucoCorner',
        'charuco_corners',
        'image_width',
        'image_height',
    ],
    'protocol' => [
        'CALIBRATION_INTRINSICS',
        'CALIBRATION_RESULT',
    ],
    'manager' => [
        'reportCalibrationIntrinsics',
        'publishCalibrationResult',
        'calibrationMasterIntrinsics',
        'calibrationSlaveIntrinsics',
        'calibrationFinalResult',
        'accepted_master_observation',
        'accepted_slave_observation',
    ],
    'fullscreen' => [
        'DualPhoneStereoCalibrationEstimator',
        'profileStore.save',
        'stereoEstimator.solve',
        'КАЛИБРОВОЧНЫЙ ПРОФИЛЬ ПРИНЯТ',
        'Заданный базис:',
        'finalIntrinsicsLine',
    ],
    'settingsCard' => [
        'onSaveRigGeometry',
        'requireNotNull(baselineMm)',
    ],
    'main' => [
        'onSaveRigGeometry =',
        'val persisted = dualPhoneStore.load()',
        'Геометрия сохранена:',
    ],
];

foreach ($required as $name => $needles) {
    foreach ($needles as $needle) {
        if (!str_contains($content[$name], $needle)) {
            fwrite(STDERR, "{$name} missing token: {$needle}\n");
            exit(1);
        }
    }
}

if (str_contains(
    $content['settingsCard'],
    'DualPhoneStereoSettingsStore(context).save('
)) {
    fwrite(STDERR, "Settings card must not save a stale copy of dual-phone settings\n");
    exit(1);
}

if (!str_contains(
    $content['stereo'],
    'masterById.keys.intersect(slaveById.keys)'
)) {
    fwrite(STDERR, "Stereo solve must match ChArUco corners by common IDs\n");
    exit(1);
}

echo "OK\n";
