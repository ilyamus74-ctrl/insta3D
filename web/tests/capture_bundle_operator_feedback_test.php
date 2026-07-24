<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$viewModel = $root
    . '/app/MaklerTour/app/src/main/java/com/example/maklertour/'
    . 'state/AppStateViewModel.kt';
$mainActivity = $root
    . '/app/MaklerTour/app/src/main/java/com/example/maklertour/'
    . 'MainActivity.kt';
$notice = $root
    . '/app/MaklerTour/app/src/main/java/com/example/maklertour/'
    . 'state/CaptureBundleNotice.kt';
$kotlinTest = $root
    . '/app/MaklerTour/tools/capture_bundle_notice_mapper_test.kt';

foreach ([$viewModel, $mainActivity, $notice, $kotlinTest] as $path) {
    if (!is_file($path)) {
        throw new RuntimeException('required file not found: ' . $path);
    }
}

$viewModelSource = (string) file_get_contents($viewModel);
foreach ([
    'CaptureBundlePreflightException',
    'captureBundleNotice = MutableStateFlow',
    'captureBundleNoticeFor(e)',
    'CaptureBundleNoticeCode.QUEUED',
    'CaptureBundleNoticeCode.PACKAGING_FAILED',
    'fun dismissCaptureBundleNotice()',
    'captureBundleNotice = runtime.captureBundleNotice',
] as $required) {
    if (!str_contains($viewModelSource, $required)) {
        throw new RuntimeException(
            'view-model feedback wiring missing: ' . $required
        );
    }
}

$mainSource = (string) file_get_contents($mainActivity);
foreach ([
    'CaptureBundleNoticeDialog(',
    'state.captureBundleNotice?.let',
    'viewModel::dismissCaptureBundleNotice',
    'R.string.capture_bundle_notice_error_title',
    'R.string.capture_bundle_notice_success_title',
    'R.string.capture_bundle_notice_technical_detail',
] as $required) {
    if (!str_contains($mainSource, $required)) {
        throw new RuntimeException(
            'operator dialog wiring missing: ' . $required
        );
    }
}

$noticeSource = (string) file_get_contents($notice);
foreach ([
    'enum class CaptureBundleNoticeCode',
    'CALIBRATION_NOT_SELECTED',
    'CALIBRATION_INVALID',
    'NO_STEREO_PAIRS',
    'PAIR_FILES_INVALID',
    'RIG_MISMATCH',
    'RESOLUTION_MISMATCH',
    'INVALID_CAPTURE',
    'PACKAGING_FAILED',
    'fun captureBundleNoticeFor',
] as $required) {
    if (!str_contains($noticeSource, $required)) {
        throw new RuntimeException(
            'notice mapper contract missing: ' . $required
        );
    }
}

foreach ([
    '/app/MaklerTour/app/src/main/res/values/capture_bundle_notices.xml',
    '/app/MaklerTour/app/src/main/res/values-ru/capture_bundle_notices.xml',
    '/app/MaklerTour/app/src/main/res/values-uk/capture_bundle_notices.xml',
    '/app/MaklerTour/app/src/main/res/values-de/capture_bundle_notices.xml',
] as $relative) {
    $source = (string) file_get_contents($root . $relative);
    foreach ([
        'capture_bundle_notice_success_title',
        'capture_bundle_notice_error_title',
        'capture_bundle_notice_calibration_not_selected',
        'capture_bundle_notice_calibration_invalid',
        'capture_bundle_notice_no_pairs',
        'capture_bundle_notice_pair_files_invalid',
        'capture_bundle_notice_rig_mismatch',
        'capture_bundle_notice_resolution_mismatch',
        'capture_bundle_notice_invalid_capture',
        'capture_bundle_notice_packaging_failed',
        'capture_bundle_notice_technical_detail',
        'capture_bundle_notice_close',
    ] as $required) {
        if (!str_contains($source, 'name="' . $required . '"')) {
            throw new RuntimeException(
                'localized string missing: ' . $relative . ' ' . $required
            );
        }
    }
}

$kotlinc = trim((string) (
    getenv('KOTLINC')
    ?: shell_exec('command -v kotlinc 2>/dev/null')
));
$java = trim((string) (
    getenv('JAVA')
    ?: shell_exec('command -v java 2>/dev/null')
));

if (
    $kotlinc !== ''
    && $java !== ''
    && is_executable($kotlinc)
    && is_executable($java)
) {
    $temp = sys_get_temp_dir()
        . '/capture_bundle_notice_'
        . bin2hex(random_bytes(6));
    if (!mkdir($temp, 0777, true) && !is_dir($temp)) {
        throw new RuntimeException('failed to create temp directory');
    }
    $jar = $temp . '/notice-test.jar';

    try {
        $output = [];
        $code = 0;
        exec(
            implode(' ', [
                escapeshellarg($kotlinc),
                escapeshellarg($notice),
                escapeshellarg($kotlinTest),
                '-include-runtime',
                '-d',
                escapeshellarg($jar),
                '2>&1',
            ]),
            $output,
            $code
        );
        if ($code !== 0) {
            throw new RuntimeException(
                "Kotlin notice compilation failed\n"
                . implode("\n", $output)
            );
        }

        $output = [];
        $code = 0;
        exec(
            escapeshellarg($java)
            . ' -jar '
            . escapeshellarg($jar)
            . ' 2>&1',
            $output,
            $code
        );
        if ($code !== 0 || trim(implode("\n", $output)) !== 'OK') {
            throw new RuntimeException(
                "Kotlin notice test failed\n"
                . implode("\n", $output)
            );
        }
    } finally {
        if (is_file($jar)) {
            unlink($jar);
        }
        if (is_dir($temp)) {
            rmdir($temp);
        }
    }
}

echo "OK\n";
