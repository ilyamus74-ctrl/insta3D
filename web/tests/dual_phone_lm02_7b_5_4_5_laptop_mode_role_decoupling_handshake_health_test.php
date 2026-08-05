<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$android = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour';
$data = $android . '/data/dualphone';
$ui = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/settings';
$host = $root . '/web/remote_station/dual_phone_host/src/main.cpp';

$mode = (string) file_get_contents($data . '/ApplicationCaptureMode.kt');
$runtime = (string) file_get_contents($data . '/DualPhoneLaptopUplinkRuntime.kt');
$card = (string) file_get_contents($ui . '/DualPhoneLaptopUplinkCard.kt');
$main = (string) file_get_contents($host);
$contract = (string) file_get_contents(
    $root . '/app/MaklerTour/docs/' .
    'APP_DUAL_PHONE_LM02_7B_5_4_5_LAPTOP_MODE_ROLE_DECOUPLING_AND_HANDSHAKE_HEALTH_CONTRACT.md',
);

$checks = [
    'laptop compatibility role is neutral' =>
        preg_match(
            '/STANDALONE_COLMAP, LAPTOP_STEREO_CLIENT, PHONE_USB_STEREO\s*->\s*' .
            'DualPhoneRole\.STANDALONE/s',
            $mode,
        ) === 1,
    'runtime gates on application mode' =>
        str_contains($runtime, 'ApplicationCaptureMode.LAPTOP_STEREO_CLIENT') &&
        str_contains($runtime, "Select the application mode 'Two phones -> laptop/PC'"),
    'runtime no longer requires persisted slave role' =>
        !str_contains(
            $runtime,
            'settings.role == DualPhoneRole.SLAVE',
        ) &&
        !str_contains(
            $runtime,
            'Select the transitional SLAVE transport role',
        ),
    'legacy frame role is derived only from laptop slot' =>
        str_contains($runtime, 'val producerRole = if') &&
        str_contains($runtime, 'DualPhoneLaptopCameraSlot.CAMERA_A') &&
        str_contains($runtime, 'localRole = "LAPTOP_${config.slot.name}"'),
    'both slots require an active profile id' =>
        str_contains(
            $runtime,
            'CAMERA_A requires an active calibration profile created on the MASTER phone',
        ) &&
        str_contains(
            $runtime,
            'CAMERA_B requires the active dual-phone calibration profile ID',
        ),
    'camera A retains strict profile ownership checks' =>
        str_contains($runtime, 'CAMERA_A must use the profile created by this MASTER phone') &&
        str_contains($runtime, 'CAMERA_A rig ID does not match the active calibration profile') &&
        str_contains($runtime, 'CAMERA_A mount revision does not match the active calibration profile'),
    'host validates A authority and full profile' =>
        str_contains($main, 'CAMERA_A must be calibration authority') &&
        str_contains($main, 'CAMERA_A must send the full calibration profile') &&
        str_contains($main, 'CAMERA_A profile is not owned by this MASTER phone'),
    'host rejects B authority and profile JSON' =>
        str_contains($main, 'CAMERA_B cannot be calibration authority') &&
        str_contains($main, 'CAMERA_B must not send the full calibration profile'),
    'camera B waits and reconnects until A activates calibration' =>
        str_contains($main, 'WAITING_FOR_CAMERA_A') &&
        str_contains($runtime, 'responseCalibrationReason') &&
        str_contains($runtime, 'calibrationReason == "HANDSHAKING"'),
    'host rejects cross-slot profile mismatch' =>
        str_contains($main, 'calibration profile ID mismatch between CAMERA_A and CAMERA_B'),
    'hello ack exposes calibration health' =>
        str_contains($main, 'reported_calibration_profile_id') &&
        str_contains($main, 'host_calibration_profile_id') &&
        str_contains($main, 'host_calibration_ready') &&
        str_contains($main, 'calibration_revision') &&
        str_contains($main, 'calibration_reason'),
    'android verifies host activation for camera A' =>
        str_contains($runtime, 'Laptop did not activate CAMERA_A calibration profile') &&
        str_contains($runtime, 'Laptop host calibration profile does not match this phone'),
    'UI displays calibration handshake state' =>
        str_contains($card, 'Host calibration:') &&
        str_contains($card, 'snapshot.calibrationRevision') &&
        str_contains($card, 'snapshot.calibrationReason') &&
        !str_contains($card, 'Local role must be SLAVE'),
    'contract preserves authority and next stage' =>
        str_contains($contract, 'CAMERA_A') &&
        str_contains($contract, 'CAMERA_B') &&
        str_contains($contract, 'LM02.7B.5.4.6'),
];

$failed = false;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    $failed = $failed || !$ok;
}

echo 'Result: ' . ($failed ? 'FAIL' : 'PASS') . PHP_EOL;
exit($failed ? 1 : 0);
