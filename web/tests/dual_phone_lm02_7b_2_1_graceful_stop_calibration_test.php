<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$checks = [
    'dashboard stop endpoint' => [
        'web/remote_station/dual_phone_host/src/http_dashboard.cpp',
        '/api/control/stop',
    ],
    'dashboard F8 shortcut' => [
        'web/remote_station/dual_phone_host/web/index.html',
        "event.key === 'F8'",
    ],
    'run wrapper records exact session path' => [
        'web/remote_station/dual_phone_host/scripts/run.sh',
        '--session-path-file',
    ],
    'run wrapper packs on exit by default' => [
        'web/remote_station/dual_phone_host/scripts/run.sh',
        'MAKLER_PACK_ON_EXIT:-1',
    ],
    'camera A loads accepted calibration' => [
        'app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneLaptopUplinkRuntime.kt',
        'DualPhoneCalibrationProfileStore',
    ],
    'camera A hello carries calibration object' => [
        'app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneLaptopUplinkRuntime.kt',
        '"calibration_profile"',
    ],
    'host persists stereo calibration' => [
        'web/remote_station/dual_phone_host/src/host_state.cpp',
        'stereo_calibration.json',
    ],
    'diagnostic package includes calibration' => [
        'web/remote_station/dual_phone_host/scripts/pack_session.sh',
        'stereo_calibration.json',
    ],
];

$failed = false;
foreach ($checks as $label => [$relative, $needle]) {
    $path = $root . '/' . $relative;
    $content = is_file($path) ? file_get_contents($path) : false;
    if ($content !== false && str_contains($content, $needle)) {
        echo "[OK] {$label}
";
    } else {
        echo "[FAIL] {$label}
";
        $failed = true;
    }
}
echo $failed ? "Result: FAIL
" : "Result: PASS
";
exit($failed ? 1 : 0);
