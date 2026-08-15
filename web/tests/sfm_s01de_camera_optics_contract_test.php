<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$cameraInfo = (string) file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/PhoneCameraInfoCollector.kt'
);
$manifest = (string) file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/PhoneScanManifestWriter.kt'
);
$metadata = (string) file_get_contents(
    $root . '/web/remote_station/scripts/camera_metadata.py'
);
$sparse = (string) file_get_contents(
    $root . '/web/remote_station/scripts/process_colmap_sparse.sh'
);
$worker = (string) file_get_contents(
    $root . '/web/tools/sfm_remote_worker.php'
);
$migration = (string) file_get_contents(
    $root . '/web/tools/sfm_s01de_migrate_video_metadata.php'
);
$contract = (string) file_get_contents(
    $root . '/docs/llm/tasks/SFM-S01-SINGLE-SERVER-RECONSTRUCTION.md'
);

$checks = [
    'Android records Camera2 factory intrinsics' =>
        str_contains($cameraInfo, 'LENS_INTRINSIC_CALIBRATION') &&
        str_contains($cameraInfo, 'camera2_intrinsic_calibration') &&
        str_contains($cameraInfo, 'SENSOR_PRE_CORRECTION_ACTIVE_ARRAY_PIXELS'),

    'Android records Brown-Conrady distortion without making it mandatory' =>
        str_contains($cameraInfo, 'LENS_DISTORTION') &&
        str_contains($cameraInfo, '"BROWN_CONRADY"') &&
        str_contains($cameraInfo, 'camera2DistortionJson ?: JSONObject.NULL'),

    'capture identity includes focus mode and profile key' =>
        str_contains($cameraInfo, '"focus_mode"') &&
        str_contains($cameraInfo, '"calibration_profile_key"') &&
        str_contains($manifest, 'cameraInfoValue("focus_mode")') &&
        str_contains($manifest, 'cameraInfoValue("calibration_profile_key")'),

    'factory sensor calibration is explicitly not injected blindly' =>
        str_contains($cameraInfo, '"usable_for_colmap", false') &&
        str_contains($cameraInfo, 'Factory Camera2 calibration is sensor-space'),

    'GrafikStation metadata preserves optical state and capture identity' =>
        str_contains($metadata, "'capture_source'") &&
        str_contains($metadata, "'focus_mode'") &&
        str_contains($metadata, "'camera2_intrinsic_calibration'") &&
        str_contains($metadata, "'colmap_camera_prior'"),

    'capture source prefers top-level manifest identity over nested Camera2 source' =>
        str_contains($metadata, "mf.get('source')") &&
        str_contains($metadata, "find(src,['capture_source','captureSource'])") &&
        !str_contains($metadata, "find(src,['source','capture_source','captureSource'])"),

    'PHONE_CAMERA SINGLE forces one shared COLMAP camera even without K/D prior' =>
        str_contains($sparse, '$capture_source" == "PHONE_CAMERA"') &&
        str_contains($sparse, 'COLMAP_CAMERA_SINGLE_FROM_METADATA="1"') &&
        str_contains($sparse, 'SINGLE phone video detected') &&
        str_contains($sparse, '--ImageReader.single_camera'),

    'verified future prior can configure COLMAP shared intrinsics' =>
        str_contains($sparse, '--ImageReader.single_camera') &&
        str_contains($sparse, '--ImageReader.camera_params') &&
        str_contains($sparse, 'usable_for_colmap') &&
        str_contains($sparse, 'Verified prior requested unsupported camera model'),

    'worker supports explicit DB metadata paths plus legacy fallback' =>
        str_contains($worker, "['imu_path', 'imu_storage_path']") &&
        str_contains($worker, "['camera_info_path', 'camera_info_storage_path']") &&
        str_contains($worker, "['manifest_path', 'manifest_storage_path']") &&
        str_contains($worker, "\$stem . '_imu.jsonl'"),

    'migration reserves optional ToF path without requiring ToF' =>
        str_contains($migration, "'camera_info_path'") &&
        str_contains($migration, "'manifest_path'") &&
        str_contains($migration, "'imu_path'") &&
        str_contains($migration, "'tof_registered_path'"),

    'roadmap is SINGLE then STEREO then LIVE and ToF is optional' =>
        str_contains($contract, 'SFM-S01A') &&
        str_contains($contract, 'ToF is an enhancement, never a prerequisite'),
];

$failed = false;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    $failed = $failed || !$ok;
}

echo 'Result: ' . ($failed ? 'FAIL' : 'PASS') . PHP_EOL;
exit($failed ? 1 : 0);
