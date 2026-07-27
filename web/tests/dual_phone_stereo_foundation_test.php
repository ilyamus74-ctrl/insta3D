<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$settings = $root
    . '/app/MaklerTour/app/src/main/java/com/example/maklertour/'
    . 'data/dualphone/DualPhoneStereoSettings.kt';
$probe = $root
    . '/app/MaklerTour/app/src/main/java/com/example/maklertour/'
    . 'data/dualphone/DualPhoneCapabilityProbe.kt';
$rig = $root
    . '/app/MaklerTour/app/src/main/java/com/maklertour/'
    . 'data/rig/StereoRigProfile.kt';
$main = $root
    . '/app/MaklerTour/app/src/main/java/com/example/maklertour/'
    . 'MainActivity.kt';
$roadmap = $root
    . '/docs/llm/tasks/APP-DUAL-PHONE-STEREO-ROADMAP.md';

foreach ([$settings, $probe, $rig, $main, $roadmap] as $path) {
    if (!is_file($path)) {
        throw new RuntimeException('required file missing: ' . $path);
    }
}

$settingsSource = (string) file_get_contents($settings);
foreach ([
    'enum class DualPhoneRole',
    'MASTER',
    'SLAVE',
    'WIFI_LAN',
    'masterControlsUpload: Boolean = true',
    'preferredVideoModeId',
    'UUID.randomUUID()',
] as $required) {
    if (!str_contains($settingsSource, $required)) {
        throw new RuntimeException(
            'dual-phone settings contract missing: ' . $required
        );
    }
}

$probeSource = (string) file_get_contents($probe);
foreach ([
    'capture_type", "dual_phone_stereo_video"',
    'PhoneCameraLensRepository',
    'preferred_video_mode_id',
    'camera2_characteristics',
    'writeReport',
] as $required) {
    if (!str_contains($probeSource, $required)) {
        throw new RuntimeException(
            'capability probe contract missing: ' . $required
        );
    }
}

$rigSource = (string) file_get_contents($rig);
foreach ([
    'enum class StereoRigTopology',
    'DUAL_PHONE',
    'REMOTE_PHONE',
    'cam0DeviceId',
    'cam1DeviceId',
    'cam0CameraId',
    'cam1CameraId',
] as $required) {
    if (!str_contains($rigSource, $required)) {
        throw new RuntimeException(
            'rig identity contract missing: ' . $required
        );
    }
}

$mainSource = (string) file_get_contents($main);
foreach ([
    'Phone recording quality',
    'Dual-phone stereo',
    'onSelectMode',
    'saveSelectedVideoMode',
    'DualPhoneCapabilityProbe',
    'The Master owns the dual_capture_id',
    '60 FPS is shown only',
] as $required) {
    if (!str_contains($mainSource, $required)) {
        throw new RuntimeException(
            'settings UI contract missing: ' . $required
        );
    }
}

$roadmapSource = (string) file_get_contents($roadmap);
foreach ([
    'Primary control transport: Wi-Fi LAN',
    'Reliable commands: TCP',
    'Clock synchronization: UDP',
    'dual_capture_id',
    'The Master stores the reusable calibration',
    'DP01',
    'DP07',
] as $required) {
    if (!str_contains($roadmapSource, $required)) {
        throw new RuntimeException(
            'dual-phone roadmap missing: ' . $required
        );
    }
}

echo "OK\n";
