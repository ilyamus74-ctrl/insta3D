<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
function src(string $path): string {
    global $root;
    $value = file_get_contents($root . '/' . $path);
    if ($value === false) { fwrite(STDERR, "cannot read $path\n"); exit(1); }
    return $value;
}
function need(string $source, string $needle): void {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "missing: $needle\n"); exit(1);
    }
}

$cmake = src('web/remote_station/dual_phone_host/CMakeLists.txt');
$runtime = src('web/remote_station/dual_phone_host/src/accumulated_map_runtime_gyro.cpp');
$header = src('web/remote_station/dual_phone_host/src/accumulated_map_runtime.hpp');
$host = src('web/remote_station/dual_phone_host/src/host_state.cpp');
$preview = src('web/remote_station/dual_phone_host/src/stereo_preview.cpp');
$pack = src('web/remote_station/dual_phone_host/scripts/pack_session.sh');

need($cmake, 'src/accumulated_map_runtime_gyro.cpp');
if (str_contains($cmake, "\n    src/accumulated_map_runtime_continuous.cpp\n")) {
    fwrite(STDERR, "continuous runtime still compiled\n"); exit(1);
}
need($header, 'accept_imu');
need($header, 'notify_camera_event');
need($host, 'stereo_preview_->accept_imu');
need($host, 'stereo_preview_->notify_camera_event');
need($preview, 'device_ids[slot_index] == device_id');
need($runtime, 'GYRO_ASSISTED_RECONNECT_SAFE');
need($runtime, 'GYRO_ONLY_ROTATION');
need($runtime, 'GYRO_VISUAL_FUSED');
need($runtime, 'SEGMENT_RESUMED');
need($runtime, 'keyframe_observations');
need($runtime, 'point_cloud_accumulated_multiview.ply');
need($runtime, 'pose_validation.jsonl');
need($pack, 'point_cloud_accumulated_multiview.ply');
need($pack, 'pose_validation.jsonl');

echo "OK\n";
