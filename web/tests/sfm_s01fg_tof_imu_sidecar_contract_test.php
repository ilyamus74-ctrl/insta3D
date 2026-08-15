<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

function source(string $relative): string {
    global $root;
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        fwrite(STDERR, "[FAIL] missing file: {$relative}\n");
        exit(1);
    }
    return (string)file_get_contents($path);
}

function ok(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
    echo "[OK] {$message}\n";
}

$tofRecorder = source('app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/TofCaptureSidecarRecorder.kt');
ok(str_contains($tofRecorder, '"tof_frames.jsonl"'), 'PHONE_CAMERA records a dedicated raw ToF sidecar');
ok(str_contains($tofRecorder, '"distance_mm"') && str_contains($tofRecorder, '"sigma_mm"'), 'raw ToF sidecar preserves distance and sigma arrays');
ok(str_contains($tofRecorder, '"target_status"') && str_contains($tofRecorder, '"nb_target_detected"'), 'raw ToF sidecar preserves target validity arrays');
ok(str_contains($tofRecorder, 'FROZEN_TOF_TO_CAMERA_EXTRINSICS'), 'capture freezes active ToF to CAMERA_A extrinsics');

$provider = source('app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/PhoneCameraScanProvider.kt');
ok(str_contains($provider, 'tofCaptureSidecarRecorder.start(baseDir)'), 'regular PHONE_CAMERA starts ToF sidecar capture');
ok(str_contains($provider, 'Mp4VideoPtsExtractor.extract('), 'regular PHONE_CAMERA emits encoder PTS telemetry');
ok(str_contains($provider, 'cameraXStartElapsedNs = video.cameraXStartElapsedNs'), 'encoder PTS sidecar carries CameraX elapsed-realtime anchor');
$imuRecorder = source('app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/ImuRecorder.kt');
ok(str_contains($imuRecorder, 'rebaseVideoTimeline'), 'IMU timeline is rebased to the video CameraX start anchor');
ok(str_contains($imuRecorder, 'video_timeline_anchor_source'), 'IMU sidecar records its authoritative video timeline anchor');
ok(str_contains($provider, 'tofFramesFile = tofSummary?.path?.let(::File)'), 'manifest receives captured ToF sidecar');

$manifest = source('app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/PhoneScanManifestWriter.kt');
foreach (['tof_frames', 'tof_calibration', 'encoder_pts'] as $key) {
    ok(str_contains($manifest, "\"{$key}\""), "manifest declares {$key}");
}

$upload = source('app/MaklerTour/app/src/main/java/com/example/maklertour/auth/MobileUploadApi.kt');
foreach (['"tof_frames"', '"tof_calibration"', '"encoder_pts"'] as $part) {
    ok(str_contains($upload, $part), "Android multipart upload includes {$part}");
}

$mobile = source('web/www/api/mobile.php');
foreach (['_tof_frames.jsonl', '_tof_calibration.json', '_encoder_pts.jsonl'] as $suffix) {
    ok(str_contains($mobile, $suffix), "mobile API persists {$suffix}");
}

$runner = source('web/remote_station/run_extract_frames_job.sh');
foreach (['tof_frames_path', 'tof_calibration_path', 'encoder_pts_path'] as $key) {
    ok(str_contains($runner, $key), "extract runner transfers {$key}");
}

$processor = source('web/remote_station/scripts/process_extract_frames.sh');
ok(str_contains($processor, '$JOB_ROOT/tof_frames.jsonl'), 'extract output preserves raw ToF sidecar');
ok(str_contains($processor, '$JOB_ROOT/tof_calibration.json'), 'extract output preserves frozen ToF calibration');
ok(str_contains($processor, '$JOB_ROOT/encoder_pts.jsonl'), 'extract output preserves encoder PTS');
ok(str_contains($processor, 'build_selected_sensor_associations.py'), 'extract builds selected JPEG sensor associations');

$selector = source('web/remote_station/scripts/select_quality_frames.py');
ok(str_contains($selector, 'start_time=0'), 'quality extraction uses deterministic zero-based FPS schedule');
ok(str_contains($selector, "'video_pts_us'"), 'selected JPEG metadata records video PTS');

$association = source('web/remote_station/scripts/build_selected_sensor_associations.py');
ok(str_contains($association, 'CANDIDATE_CAMERAX_START_REALTIME'), 'S01G measures a conservative CameraX/Camera2 timeline candidate');
ok(str_contains($association, '"fusion_enabled": False'), 'S01G does not enable ToF fusion');
ok(str_contains($association, '"ready_for_tof_geometry": False'), 'S01G keeps ToF geometry/fusion gate closed pending measured review');
ok(str_contains($association, 'nearest_imu_record'), 'S01G preserves per-selected-frame IMU association');

echo "Result: PASS\n";
