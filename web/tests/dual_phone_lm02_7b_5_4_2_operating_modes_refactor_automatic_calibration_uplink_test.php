<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$runtimePath =
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/' .
    'data/dualphone/DualPhoneLaptopUplinkRuntime.kt';
$cardPath =
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/' .
    'ui/settings/DualPhoneLaptopUplinkCard.kt';
$hostPath =
    $root . '/web/remote_station/dual_phone_host/src/host_state.cpp';
$contractPath =
    $root . '/app/MaklerTour/docs/' .
    'APP_DUAL_PHONE_LM02_7B_5_4_2_OPERATING_MODES_REFACTOR_' .
    'AND_AUTOMATIC_CALIBRATION_UPLINK_CONTRACT.md';

$runtime = file_get_contents($runtimePath);
$card = file_get_contents($cardPath);
$host = file_get_contents($hostPath);
$contract = file_get_contents($contractPath);

if (
    !is_string($runtime) ||
    !is_string($card) ||
    !is_string($host) ||
    !is_string($contract)
) {
    fwrite(STDERR, "required file is missing\n");
    exit(1);
}

$checks = [
    'five exclusive APP modes are contracted' =>
        str_contains($contract, 'STANDALONE_COLMAP') &&
        str_contains($contract, 'DUAL_PHONE_MASTER') &&
        str_contains($contract, 'DUAL_PHONE_SLAVE') &&
        str_contains($contract, 'LAPTOP_STEREO_CLIENT') &&
        str_contains($contract, 'PHONE_USB_STEREO'),
    'separate role and laptop slot are contracted' =>
        str_contains($contract, 'DualPhoneRole') &&
        str_contains($contract, 'LaptopCameraSlot') &&
        str_contains($contract, 'Neither enum substitutes'),
    'sequential patch roadmap is contracted' =>
        str_contains($contract, 'Patch LM02.7B.5.4.3') &&
        str_contains($contract, 'Patch LM02.7B.5.4.4') &&
        str_contains($contract, 'Patch LM02.7B.5.4.5') &&
        str_contains($contract, 'Patch LM02.7B.5.4.6'),
    'runtime owns current settings lookup' =>
        str_contains($runtime, 'DualPhoneStereoSettingsStore') &&
        str_contains($runtime, 'stereoSettingsStore.load()'),
    'runtime no longer accepts stale UI settings argument' =>
        str_contains(
            $runtime,
            'fun start(config: DualPhoneLaptopUplinkConfig)',
        ) &&
        !str_contains(
            $runtime,
            'runtime.start(config, settings)',
        ),
    'handshake is refreshed for start and reconnect' =>
        substr_count($runtime, 'loadHandshakeContext(config)') >= 2 &&
        str_contains($runtime, 'Re-read the authoritative settings/profile'),
    'CAMERA_A requires the active MASTER profile' =>
        str_contains(
            $runtime,
            'CAMERA_A requires an active calibration profile created on the MASTER phone',
        ) &&
        str_contains(
            $runtime,
            'profile.masterDeviceId == settings.deviceId',
        ),
    'rig identity is validated before upload' =>
        str_contains($runtime, 'profile.rigId == settings.rigId') &&
        str_contains(
            $runtime,
            'profile.rigMountRevision == settings.rigMountRevision',
        ),
    'only CAMERA_A attaches the full profile' =>
        str_contains(
            $runtime,
            'config.slot == DualPhoneLaptopCameraSlot.CAMERA_A',
        ) &&
        str_contains(
            $runtime,
            'calibrationProfile = calibrationProfile',
        ),
    'UI uses runtime-owned settings and displays errors' =>
        str_contains($card, 'runtime.start(config)') &&
        str_contains($card, 'exceptionOrNull()') &&
        !str_contains($card, 'runtime.start(config, settings)'),
    'host remains strict CAMERA_A calibration authority' =>
        str_contains($host, 'if (slot == CameraSlot::A)') &&
        str_contains($host, 'stereo_preview_->set_calibration_profile(profile)'),
];

$failed = false;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    $failed = $failed || !$ok;
}

echo 'Result: ' . ($failed ? 'FAIL' : 'PASS') . PHP_EOL;
exit($failed ? 1 : 0);
