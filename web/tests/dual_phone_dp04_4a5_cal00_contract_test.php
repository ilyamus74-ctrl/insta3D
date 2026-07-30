<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$files = [
    'settings' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneStereoSettings.kt',
    'ui' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/settings/DualPhoneControlSettingsCard.kt',
    'profile' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneCalibrationProfile.kt',
    'contract' => $root . '/docs/llm/tasks/APP-DUAL-PHONE-CAL00-CONTRACT.md',
];

foreach ($files as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing {$name}: {$path}\n");
        exit(1);
    }
}

$settings = file_get_contents($files['settings']);
$ui = file_get_contents($files['ui']);
$profile = file_get_contents($files['profile']);
$contract = file_get_contents($files['contract']);

$requiredSettings = [
    'operatorLensBaselineMm',
    'rigMountRevision',
    'activeCalibrationProfileId',
    'operator_lens_baseline_mm',
];

$requiredUi = [
    'Lens-center distance, mm',
    'Save rig geometry',
    'Receiving video and telemetry from Slave',
    'CircularProgressIndicator',
];

$requiredProfile = [
    'DualPhoneCalibrationIdentity',
    'calibrationOrderDeviceId0',
    'rolesReversed',
    'findReusable',
];

$requiredContract = [
    'fullscreen',
    'realtime',
    'optical centre to optical centre',
    'Camera identities are canonicalized as an unordered pair',
    'R_10 = transpose(R_01)',
    'CAL01A fullscreen synchronized calibration screen',
];

foreach ([
    'settings' => [$settings, $requiredSettings],
    'ui' => [$ui, $requiredUi],
    'profile' => [$profile, $requiredProfile],
    'contract' => [$contract, $requiredContract],
] as $name => [$content, $needles]) {
    foreach ($needles as $needle) {
        if (!str_contains($content, $needle)) {
            fwrite(STDERR, "{$name} missing contract token: {$needle}\n");
            exit(1);
        }
    }
}

if (str_contains($contract, 'independent direct uploads are the primary')) {
    fwrite(STDERR, "Independent uploads must not be the primary workflow\n");
    exit(1);
}

echo "OK\n";

