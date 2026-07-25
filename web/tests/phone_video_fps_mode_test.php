<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$mode = $root
    . '/app/MaklerTour/app/src/main/java/com/example/maklertour/'
    . 'data/phonecamera/PhoneVideoMode.kt';
$lens = $root
    . '/app/MaklerTour/app/src/main/java/com/example/maklertour/'
    . 'data/phonecamera/PhoneCameraLens.kt';
$recorder = $root
    . '/app/MaklerTour/app/src/main/java/com/example/maklertour/'
    . 'data/phonecamera/PhoneCameraVideoRecorder.kt';
$provider = $root
    . '/app/MaklerTour/app/src/main/java/com/example/maklertour/'
    . 'data/phonecamera/PhoneCameraScanProvider.kt';
$viewModel = $root
    . '/app/MaklerTour/app/src/main/java/com/example/maklertour/'
    . 'state/AppStateViewModel.kt';
$main = $root
    . '/app/MaklerTour/app/src/main/java/com/example/maklertour/'
    . 'MainActivity.kt';
$kotlinTest = $root
    . '/app/MaklerTour/tools/phone_video_mode_policy_test.kt';

foreach ([$mode, $lens, $recorder, $provider, $viewModel, $main, $kotlinTest] as $path) {
    if (!is_file($path)) {
        throw new RuntimeException('required file not found: ' . $path);
    }
}

$modeSource = (string) file_get_contents($mode);
foreach ([
    'data class PhoneVideoMode',
    'object PhoneVideoModePolicy',
    'listOf(30, 60)',
    'PhoneVideoSizeCapability',
    '1280 && it.height == 720 && it.fps == 30',
] as $required) {
    if (!str_contains($modeSource, $required)) {
        throw new RuntimeException('mode policy missing: ' . $required);
    }
}

$lensSource = (string) file_get_contents($lens);
foreach ([
    'supportedVideoModes: List<PhoneVideoMode>',
    'PhoneVideoModePolicy.availableModes',
    'saveSelectedVideoMode',
    'getSelectedVideoMode',
] as $required) {
    if (!str_contains($lensSource, $required)) {
        throw new RuntimeException('lens mode catalog missing: ' . $required);
    }
}

$recorderSource = (string) file_get_contents($recorder);
foreach ([
    'VideoCapture.Builder(recorder)',
    'setTargetFrameRate(Range(mode.fps, mode.fps))',
    'Quality.FHD',
    'Quality.UHD',
    'enableCalibrationAnalysis',
] as $required) {
    if (!str_contains($recorderSource, $required)) {
        throw new RuntimeException('recorder FPS wiring missing: ' . $required);
    }
}

$providerSource = (string) file_get_contents($provider);
if (!str_contains($providerSource, 'enableCalibrationAnalysis = false')) {
    throw new RuntimeException('simple video must not bind calibration analysis');
}

$viewModelSource = (string) file_get_contents($viewModel);
if (!str_contains($viewModelSource, 'videoMode: PhoneVideoMode?')) {
    throw new RuntimeException('view-model video mode wiring missing');
}

$mainSource = (string) file_get_contents($main);
foreach ([
    'selectedVideoModeId',
    'availableVideoModes',
    'selectedVideoMode?.label',
    'onSelectVideoMode',
    '60 FPS отображается только',
] as $required) {
    if (!str_contains($mainSource, $required)) {
        throw new RuntimeException('operator mode selection missing: ' . $required);
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
        . '/phone_video_mode_'
        . bin2hex(random_bytes(6));
    if (!mkdir($temp, 0777, true) && !is_dir($temp)) {
        throw new RuntimeException('failed to create temp directory');
    }
    $jar = $temp . '/phone-video-mode-test.jar';

    try {
        $output = [];
        $code = 0;
        exec(
            implode(' ', [
                escapeshellarg($kotlinc),
                escapeshellarg($mode),
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
                "Kotlin mode compilation failed\n"
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
                "Kotlin mode test failed\n"
                . implode("\n", $output)
            );
        }
    } finally {
        if (is_file($jar)) unlink($jar);
        if (is_dir($temp)) rmdir($temp);
    }
}

echo "OK\n";
