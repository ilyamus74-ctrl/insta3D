<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$math = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneClockSyncMath.kt';
$controller = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneClockSyncController.kt';
$manager = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneControlManager.kt';
$protocol = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneControlProtocol.kt';
$ui = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/settings/DualPhoneControlSettingsCard.kt';
$kotlinTest = $root . '/app/MaklerTour/tools/dual_phone_clock_sync_math_test.kt';

foreach ([$math, $controller, $manager, $protocol, $ui, $kotlinTest] as $path) {
    if (!is_file($path)) {
        throw new RuntimeException('Missing DP03 source: ' . $path);
    }
}

$mathText = (string) file_get_contents($math);
foreach ([
    'DualPhoneClockSyncSample',
    'roundTripNs',
    'offsetNs',
    'estimateRound',
    'estimateDriftPpm',
    'masterToSlaveNs',
    'EXCELLENT',
    'GOOD',
] as $token) {
    if (!str_contains($mathText, $token)) {
        throw new RuntimeException('Clock math contract missing: ' . $token);
    }
}

$controllerText = (string) file_get_contents($controller);
foreach ([
    'DatagramSocket',
    'CLOCK_SYNC_REQUEST',
    'CLOCK_SYNC_RESPONSE',
    't1_master_ns',
    't2_slave_ns',
    't3_slave_ns',
    'PROBE_TIMEOUT_MS',
    'PERIODIC_SYNC_INTERVAL_MS',
    'applyRemoteStatus',
] as $token) {
    if (!str_contains($controllerText, $token)) {
        throw new RuntimeException('Clock runtime contract missing: ' . $token);
    }
}

$managerText = (string) file_get_contents($manager);
foreach ([
    'clockSyncController.startMaster',
    'clockSyncController.startSlave',
    'clockSyncController.masterToSlaveNs',
    'slave_elapsed_realtime_ns',
    'CLOCK_SYNC_STATUS',
    'MAX_START_LATE_NS',
] as $token) {
    if (!str_contains($managerText, $token)) {
        throw new RuntimeException('Manager DP03 wiring missing: ' . $token);
    }
}

$protocolText = (string) file_get_contents($protocol);
if (!str_contains($protocolText, 'CLOCK_SYNC_STATUS')) {
    throw new RuntimeException('TCP clock status token missing');
}

$uiText = (string) file_get_contents($ui);
foreach ([
    'clock sync (DP03)',
    'Clock sync:',
    'Estimated timing uncertainty:',
    'snapshot.clockSync.ready',
] as $token) {
    if (!str_contains($uiText, $token)) {
        throw new RuntimeException('DP03 operator UI missing: ' . $token);
    }
}

$kotlinc = trim((string) (
    getenv('KOTLINC') ?: shell_exec('command -v kotlinc 2>/dev/null')
));
$java = trim((string) (
    getenv('JAVA') ?: shell_exec('command -v java 2>/dev/null')
));

if ($kotlinc !== '' && $java !== '' && is_executable($kotlinc) && is_executable($java)) {
    $temp = sys_get_temp_dir() . '/dual_phone_clock_' . bin2hex(random_bytes(6));
    if (!mkdir($temp, 0777, true) && !is_dir($temp)) {
        throw new RuntimeException('Failed to create test directory');
    }
    $jar = $temp . '/clock-sync-test.jar';
    try {
        $output = [];
        $code = 0;
        exec(
            escapeshellarg($kotlinc) . ' ' .
            escapeshellarg($math) . ' ' .
            escapeshellarg($kotlinTest) .
            ' -include-runtime -d ' . escapeshellarg($jar) . ' 2>&1',
            $output,
            $code,
        );
        if ($code !== 0) {
            throw new RuntimeException("Clock math compilation failed\n" . implode("\n", $output));
        }
        $output = [];
        $code = 0;
        exec(
            escapeshellarg($java) . ' -jar ' . escapeshellarg($jar) . ' 2>&1',
            $output,
            $code,
        );
        if ($code !== 0 || trim(implode("\n", $output)) !== 'OK') {
            throw new RuntimeException("Clock math test failed\n" . implode("\n", $output));
        }
    } finally {
        if (is_file($jar)) unlink($jar);
        if (is_dir($temp)) rmdir($temp);
    }
}

echo "OK\n";
