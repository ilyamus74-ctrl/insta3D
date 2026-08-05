<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$android = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour';
$data = $android . '/data/dualphone';
$ui = $android . '/ui/settings';

$mode = (string) file_get_contents($data . '/ApplicationCaptureMode.kt');
$settings = (string) file_get_contents($data . '/DualPhoneStereoSettings.kt');
$producer = (string) file_get_contents($data . '/DualPhoneReducedFrameProducer.kt');
$runtime = (string) file_get_contents($data . '/DualPhoneLaptopUplinkRuntime.kt');
$selector = (string) file_get_contents($ui . '/ApplicationCaptureModeSelector.kt');
$main = (string) file_get_contents($android . '/MainActivity.kt');
$host = (string) file_get_contents(
    $root . '/web/remote_station/dual_phone_host/src/main.cpp',
);
$contract = (string) file_get_contents(
    $root . '/app/MaklerTour/docs/' .
    'APP_DUAL_PHONE_LM02_7B_5_4_6_FINAL_MODE_RUNTIME_AND_MIGRATION_CONTRACT.md',
);

$fiveModes = [
    'STANDALONE_COLMAP',
    'DUAL_PHONE_MASTER',
    'DUAL_PHONE_SLAVE',
    'LAPTOP_STEREO_CLIENT',
    'PHONE_USB_STEREO',
];

$stopApplication = strpos($main, 'DualPhoneApplicationRuntime.get(context.applicationContext)');
$stopLaptop = strpos($main, 'DualPhoneLaptopUplinkRuntime.get(context.applicationContext)');
$stopControl = strpos($main, 'dualPhoneControl.stop()', $stopLaptop ?: 0);
$saveMode = strpos($main, 'dualPhoneStore.save(updatedSettings)');

$checks = [
    'exact five operating modes remain' =>
        array_reduce(
            $fiveModes,
            static fn (bool $ok, string $token): bool =>
                $ok && str_contains($mode, $token),
            true,
        ),
    'phone roles exist only for direct phone modes' =>
        str_contains($mode, 'val phoneToPhoneRoleOrNull') &&
        str_contains($mode, 'DUAL_PHONE_MASTER -> DualPhoneRole.MASTER') &&
        str_contains($mode, 'DUAL_PHONE_SLAVE -> DualPhoneRole.SLAVE') &&
        !str_contains($mode, 'compatibilityRole'),
    'settings schema 7 is mode authoritative' =>
        str_contains($settings, 'DUAL_PHONE_SETTINGS_SCHEMA_VERSION = 7') &&
        str_contains($settings, 'val applicationMode = settings.applicationMode') &&
        str_contains($settings, 'KEY_SCHEMA_VERSION') &&
        !str_contains($settings, 'migrateFromLegacyRole(settings.role)'),
    'legacy role migration remains one-way load fallback' =>
        str_contains($settings, 'ApplicationCaptureMode.migrateFromLegacyRole(storedRole)') &&
        str_contains($settings, 'storedSchemaVersion < DUAL_PHONE_SETTINGS_SCHEMA_VERSION'),
    'selector does not persist independently' =>
        str_contains($selector, 'onModeSelected: (ApplicationCaptureMode) -> Unit') &&
        str_contains($selector, 'onModeSelected(candidate)') &&
        !str_contains($selector, 'DualPhoneStereoSettingsStore'),
    'mode switch stops incompatible runtimes before save' =>
        $stopApplication !== false &&
        $stopLaptop !== false &&
        $stopControl !== false &&
        $saveMode !== false &&
        $stopApplication < $stopLaptop &&
        $stopLaptop < $stopControl &&
        $stopControl < $saveMode,
    'same-mode selection is a no-op' =>
        str_contains($main, 'targetMode == dualPhoneSettings.applicationMode') &&
        str_contains($main, 'return@modeChange'),
    'laptop producer has dedicated neutral entry point' =>
        str_contains($producer, 'fun startLaptop(') &&
        str_contains($producer, 'DualPhoneRole.STANDALONE') &&
        str_contains($runtime, 'producer.startLaptop(owner)') &&
        !str_contains($runtime, 'val producerRole = if'),
    'calibration authority remains strict' =>
        str_contains($runtime, 'CAMERA_A must use the profile created by this MASTER phone') &&
        str_contains($host, 'CAMERA_A must send the full calibration profile') &&
        str_contains($host, 'CAMERA_B must not send the full calibration profile'),
    'final contract records validated thermal map' =>
        str_contains($contract, 'TEMPORAL STRICT · READY') &&
        str_contains($contract, 'thermal map became available') &&
        str_contains($contract, 'closes the LM02.7B.5.4'),
];

$failed = false;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    $failed = $failed || !$ok;
}

echo 'Result: ' . ($failed ? 'FAIL' : 'PASS') . PHP_EOL;
exit($failed ? 1 : 0);
