<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$android = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour';

$mode = (string) file_get_contents(
    $android . '/data/dualphone/ApplicationCaptureMode.kt',
);
$settings = (string) file_get_contents(
    $android . '/data/dualphone/DualPhoneStereoSettings.kt',
);
$selector = (string) file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/settings/' .
    'ApplicationCaptureModeSelector.kt',
);
$controlCard = (string) file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/settings/' .
    'DualPhoneControlSettingsCard.kt',
);
$main = (string) file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/MainActivity.kt',
);
$uplink = (string) file_get_contents(
    $android . '/data/dualphone/DualPhoneLaptopUplinkRuntime.kt',
);
$host = (string) file_get_contents(
    $root . '/web/remote_station/dual_phone_host/src/host_state.cpp',
);
$contract = (string) file_get_contents(
    $root . '/app/MaklerTour/docs/' .
    'APP_DUAL_PHONE_LM02_7B_5_4_3_APPLICATION_CAPTURE_MODE_MODEL_AND_MIGRATION_CONTRACT.md',
);

$fiveModes = [
    'STANDALONE_COLMAP',
    'DUAL_PHONE_MASTER',
    'DUAL_PHONE_SLAVE',
    'LAPTOP_STEREO_CLIENT',
    'PHONE_USB_STEREO',
];

$checks = [
    'exact five application modes are declared' =>
        array_reduce(
            $fiveModes,
            static fn (bool $ok, string $token): bool =>
                $ok && str_contains($mode, $token),
            true,
        ),
    'phone-to-phone role derivation is explicit' =>
        str_contains($mode, 'val phoneToPhoneRoleOrNull') &&
        str_contains($mode, 'LAPTOP_STEREO_CLIENT') &&
        str_contains($mode, '-> null') &&
        str_contains($mode, 'PHONE_USB_STEREO') &&
        str_contains($mode, 'DUAL_PHONE_MASTER -> DualPhoneRole.MASTER'),
    'legacy role migration is explicit' =>
        str_contains($mode, 'migrateFromLegacyRole') &&
        str_contains($mode, 'DualPhoneRole.MASTER -> DUAL_PHONE_MASTER') &&
        str_contains($mode, 'DualPhoneRole.SLAVE -> DUAL_PHONE_SLAVE'),
    'settings schema stores application mode' =>
        str_contains($settings, 'DUAL_PHONE_SETTINGS_SCHEMA_VERSION = 7') &&
        str_contains($settings, 'KEY_APPLICATION_MODE') &&
        str_contains($settings, 'application_capture_mode'),
    'settings migration persists mode and derived role once' =>
        str_contains($settings, 'rawApplicationMode') &&
        str_contains($settings, 'applicationMode.phoneToPhoneRoleOrNull') &&
        str_contains($settings, '.putString(KEY_APPLICATION_MODE, applicationMode.name)') &&
        str_contains($settings, '.putString(KEY_ROLE, derivedRole.name)'),
    'save path never derives mode backwards from role' =>
        str_contains($settings, 'val applicationMode = settings.applicationMode') &&
        !str_contains($settings, 'migrateFromLegacyRole(settings.role)'),
    'top settings selector exposes all entries' =>
        str_contains($selector, 'Режим работы приложения') &&
        str_contains($selector, 'ApplicationCaptureMode.entries.forEach') &&
        str_contains($selector, 'onModeSelected(candidate)') &&
        !str_contains($selector, 'DualPhoneStereoSettingsStore'),
    'selector is rendered before mode-scoped controls' =>
        ($selectorPosition = strpos($main, 'ApplicationCaptureModeSelector(')) !== false &&
        ($controlsPosition = strpos($main, 'DualPhoneControlSettingsCard(')) !== false &&
        $selectorPosition < $controlsPosition,
    'CAMERA_A automatic calibration authority remains strict' =>
        str_contains($uplink, 'CAMERA_A requires an active calibration profile') &&
        str_contains($uplink, 'CAMERA_A must use the profile created by this MASTER phone') &&
        str_contains($host, 'if (slot == CameraSlot::A)'),
    'contract preserves staged refactor order' =>
        str_contains($contract, 'LM02.7B.5.4.4') &&
        str_contains($contract, 'LM02.7B.5.4.5') &&
        str_contains($contract, 'LM02.7B.5.4.6'),
];

$failed = false;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    $failed = $failed || !$ok;
}

echo 'Result: ' . ($failed ? 'FAIL' : 'PASS') . PHP_EOL;
exit($failed ? 1 : 0);
