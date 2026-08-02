<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$paths = [
    'cmake' => $root . '/web/remote_station/dual_phone_host/CMakeLists.txt',
    'header' => $root . '/web/remote_station/dual_phone_host/src/stereo_preview.hpp',
    'processor' => $root . '/web/remote_station/dual_phone_host/src/stereo_preview.cpp',
    'processing' => $root . '/web/remote_station/dual_phone_host/src/stereo_preview_processing.cpp',
    'processingHeader' => $root . '/web/remote_station/dual_phone_host/src/stereo_preview_processing.hpp',
    'stateHeader' => $root . '/web/remote_station/dual_phone_host/src/host_state.hpp',
    'state' => $root . '/web/remote_station/dual_phone_host/src/host_state.cpp',
    'dashboard' => $root . '/web/remote_station/dual_phone_host/src/http_dashboard.cpp',
    'html' => $root . '/web/remote_station/dual_phone_host/web/index.html',
    'install' => $root . '/web/remote_station/dual_phone_host/scripts/install_fedora41.sh',
    'pack' => $root . '/web/remote_station/dual_phone_host/scripts/pack_session.sh',
    'readme' => $root . '/web/remote_station/dual_phone_host/README.md',
    'contract' => $root . '/app/MaklerTour/docs/APP_DUAL_PHONE_LM02_7B_3_RECTIFICATION_PREVIEW_CONTRACT.md',
];

foreach ($paths as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing {$name}: {$path}\n");
        exit(1);
    }
}

$content = array_map(static fn(string $path): string => file_get_contents($path), $paths);

$required = [
    'cmake' => [
        'find_package(OpenCV 4 REQUIRED COMPONENTS core imgcodecs imgproc calib3d)',
        'src/stereo_preview.cpp',
        'src/stereo_preview_processing.cpp',
        '${OpenCV_LIBS}',
    ],
    'header' => [
        'struct StereoPreviewPair',
        'enum class StereoPreviewImage',
        'void clear_calibration_profile()',
        'std::optional<std::vector<std::uint8_t>> image',
    ],
    'processor' => [
        'cv::stereoRectify(',
        'cv::initUndistortRectifyMap(',
        'STEREO_PREVIEW_READY',
        'STEREO_PREVIEW_FAILED',
        'valid_disparity_ratio',
        'queue_replaced',
        'last_success_pair_index',
    ],
    'processing' => [
        'cv::StereoSGBM::create(',
        'roles_reversed',
        'profile.rotation[column * 3 + row]',
        'connected CAMERA_A/CAMERA_B device IDs do not match calibration profile',
    ],
    'processingHeader' => [
        'struct ResolvedCalibration',
        'struct DisparityOutput',
        'ResolvedCalibration resolve_profile(',
    ],
    'stateHeader' => [
        '#include "stereo_preview.hpp"',
        'stereo_preview_image(',
        'std::unique_ptr<StereoPreview> stereo_preview_',
    ],
    'state' => [
        'LM02.7B.3_CALIBRATED_RECTIFICATION_PREVIEW',
        'stereo_preview_->set_calibration_profile(profile)',
        'stereo_preview_->submit(std::move(preview_pair))',
        '{"stereo_preview", stereo_preview_->status_json()}',
    ],
    'dashboard' => [
        '/stereo/rectified_a.jpg',
        '/stereo/rectified_b.jpg',
        '/stereo/disparity.jpg',
        'stereo_preview_image(kind)',
    ],
    'html' => [
        'Calibrated rectification',
        'STEREOSGBM',
        '/stereo/rectified_a.jpg',
        '/stereo/disparity.jpg',
        'last_success_pair_index',
        'valid_disparity_ratio',
    ],
    'install' => ['opencv-devel'],
    'pack' => ['stereo_preview.jsonl', 'stereo_preview_status.json'],
    'readme' => [
        'bounded latest-pair worker',
        'runtime CAMERA_A/CAMERA_B order is the reverse',
        'does not yet publish',
    ],
    'contract' => [
        'T_runtime = -transpose(R_profile) * T_profile',
        'No operator baseline or disparity heat-map value',
        'does not publish metric depth',
    ],
];

foreach ($required as $name => $needles) {
    foreach ($needles as $needle) {
        if (!str_contains($content[$name], $needle)) {
            fwrite(STDERR, "{$name} missing token: {$needle}\n");
            exit(1);
        }
    }
}

if (str_contains($content['processing'], 'operator_lens_baseline_mm')) {
    fwrite(STDERR, "Preview must not substitute operator baseline for calibrated T\n");
    exit(1);
}

if (!str_contains(
    $content['processor'],
    'pending = std::move(value)'
) || !str_contains(
    $content['processor'],
    'if (pending) queue_replaced += 1'
)) {
    fwrite(STDERR, "Stereo processing queue must remain bounded to the latest pair\n");
    exit(1);
}

echo "OK\n";
