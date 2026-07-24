<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$core = $root
    . '/app/MaklerTour/app/src/main/java/com/example/maklertour/'
    . 'data/capture/StereoCaptureBundlePreflight.kt';
$test = $root
    . '/app/MaklerTour/tools/stereo_capture_bundle_preflight_test.kt';
$packager = $root
    . '/app/MaklerTour/app/src/main/java/com/example/maklertour/'
    . 'data/capture/CaptureBundlePackager.kt';

foreach ([$core, $test, $packager] as $path) {
    if (!is_file($path)) {
        throw new RuntimeException('required file not found: ' . $path);
    }
}

$coreSource = (string) file_get_contents($core);
foreach ([
    'object StereoCaptureBundlePreflight',
    'captureType == "synced_depth_frames"',
    'input.pairs.isNotEmpty()',
    'duplicate pair_index',
    'escapes capture directory',
    'stereo calibration status must be success',
    'stereo_T baseline magnitude must be positive',
    'active rig profile=$profile',
    'calibration rig_id=$calibration',
] as $required) {
    if (!str_contains($coreSource, $required)) {
        throw new RuntimeException(
            'preflight core contract missing: ' . $required
        );
    }
}

$packagerSource = (string) file_get_contents($packager);
foreach ([
    'validateSyncedDepthPreflight',
    'StereoCaptureBundlePreflight.validate',
    '"preflight_status", "passed"',
    '"validated_pairs_count"',
    '"validated_calibration_status"',
    '"validated_baseline_magnitude"',
    'out.delete()',
] as $required) {
    if (!str_contains($packagerSource, $required)) {
        throw new RuntimeException(
            'packager wiring missing: ' . $required
        );
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
        . '/stereo_capture_bundle_preflight_'
        . bin2hex(random_bytes(6));
    if (!mkdir($temp, 0777, true) && !is_dir($temp)) {
        throw new RuntimeException('failed to create temp directory');
    }
    $jar = $temp . '/preflight-test.jar';

    try {
        $compile = implode(' ', [
            escapeshellarg($kotlinc),
            escapeshellarg($core),
            escapeshellarg($test),
            '-include-runtime',
            '-d',
            escapeshellarg($jar),
            '2>&1',
        ]);
        $output = [];
        $code = 0;
        exec($compile, $output, $code);
        if ($code !== 0) {
            throw new RuntimeException(
                "Kotlin preflight compilation failed\n"
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
                "Kotlin preflight test failed\n"
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
