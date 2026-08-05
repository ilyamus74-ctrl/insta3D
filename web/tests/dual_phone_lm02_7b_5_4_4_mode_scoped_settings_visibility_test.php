<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$android = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour';
$data = $android . '/data/dualphone';
$ui = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/settings';

$visibility = (string) file_get_contents(
    $data . '/ApplicationCaptureModeSettingsVisibility.kt',
);
$selector = (string) file_get_contents(
    $ui . '/ApplicationCaptureModeSelector.kt',
);
$control = (string) file_get_contents(
    $ui . '/DualPhoneControlSettingsCard.kt',
);
$main = (string) file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/MainActivity.kt',
);
$contract = (string) file_get_contents(
    $root . '/app/MaklerTour/docs/' .
    'APP_DUAL_PHONE_LM02_7B_5_4_4_MODE_SCOPED_SETTINGS_VISIBILITY_CONTRACT.md',
);

$checks = [
    'visibility policy covers all five modes' =>
        str_contains($visibility, 'STANDALONE_COLMAP') &&
        str_contains($visibility, 'DUAL_PHONE_MASTER') &&
        str_contains($visibility, 'DUAL_PHONE_SLAVE') &&
        str_contains($visibility, 'LAPTOP_STEREO_CLIENT') &&
        str_contains($visibility, 'PHONE_USB_STEREO'),
    'standalone exposes phone recording only' =>
        preg_match(
            '/STANDALONE_COLMAP\s*->\s*ApplicationCaptureModeSettingsVisibility\(\s*' .
            'showPhoneRecordingSettings = true,/s',
            $visibility,
        ) === 1,
    'slave does not expose phone recording or rig settings' =>
        preg_match(
            '/DUAL_PHONE_SLAVE\s*->\s*ApplicationCaptureModeSettingsVisibility\(\s*' .
            'showDualPhoneIdentitySettings = true,\s*' .
            'showDualPhoneControlSettings = true,/s',
            $visibility,
        ) === 1,
    'phone usb exposes rig and phone recording' =>
        preg_match(
            '/PHONE_USB_STEREO\s*->\s*ApplicationCaptureModeSettingsVisibility\(\s*' .
            'showStereoRigSettings = true,\s*' .
            'showPhoneRecordingSettings = true,/s',
            $visibility,
        ) === 1,
    'selector reports complete settings to parent' =>
        str_contains($selector, 'onModeSelected: (ApplicationCaptureMode) -> Unit') &&
        str_contains($selector, 'onModeSelected(candidate)'),
    'settings screen renders selector before scoped blocks' =>
        strpos($main, 'ApplicationCaptureModeSelector(') !== false &&
        strpos($main, 'ApplicationCaptureModeSelector(') <
            strpos($main, 'if (settingsVisibility.showStereoRigSettings)'),
    'settings screen scopes all legacy sections' =>
        str_contains($main, 'settingsVisibility.showStereoRigSettings') &&
        str_contains($main, 'settingsVisibility.showPhoneRecordingSettings') &&
        str_contains($main, 'settingsVisibility.showDualPhoneIdentitySettings') &&
        str_contains($main, 'settingsVisibility.showDualPhoneControlSettings'),
    'legacy direct role buttons removed' =>
        !str_contains($main, 'DualPhoneRole.entries.forEach { role ->'),
    'mode selection updates compose state immediately' =>
        str_contains(
            $main,
            'dualPhoneSettings = persistedSettings',
        ) &&
        str_contains(
            $main,
            'onModeSelected = onApplicationModeSelected',
        ),
    'standalone preserves existing stereo topology' =>
        str_contains(
            $main,
            'ApplicationCaptureMode.STANDALONE_COLMAP ->' . PHP_EOL .
            '                    activeProfile.topology',
        ),
    'laptop menu does not render phone pairing controls' =>
        str_contains(
            $control,
            'ApplicationCaptureMode.LAPTOP_STEREO_CLIENT',
        ) &&
        str_contains($control, 'DualPhoneLaptopUplinkCard(settings = settings)') &&
        strpos($control, 'return@Column') !== false,
    'master and slave branches use application mode' =>
        str_contains($control, 'ApplicationCaptureMode.DUAL_PHONE_MASTER') &&
        str_contains($control, 'ApplicationCaptureMode.DUAL_PHONE_SLAVE'),
    'contract fixes calibration ownership' =>
        str_contains($contract, 'MASTER/CAMERA_A') &&
        str_contains($contract, 'only source of the full laptop calibration JSON'),
];

$failed = false;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    $failed = $failed || !$ok;
}

echo 'Result: ' . ($failed ? 'FAIL' : 'PASS') . PHP_EOL;
exit($failed ? 1 : 0);
