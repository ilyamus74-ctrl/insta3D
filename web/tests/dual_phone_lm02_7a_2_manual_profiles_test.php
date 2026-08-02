<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$clockPath = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneClockSyncController.kt';
$performancePath = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneDepthPerformanceController.kt';
$selectionPath = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneDepthProfileSelection.kt';
$selectorPath = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/session/DualPhoneDepthProfileModeSelector.kt';
$contourPath = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/session/DualPhoneContourFirstViewport.kt';
$contractPath = $root . '/app/MaklerTour/docs/APP_DUAL_PHONE_LM02_7A_2_MANUAL_DEPTH_CONTRACT.md';

foreach ([$clockPath, $performancePath, $selectionPath, $selectorPath, $contourPath, $contractPath] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "[FAIL] missing {$path}\n");
        exit(1);
    }
}

$clock = file_get_contents($clockPath);
$performance = file_get_contents($performancePath);
$selection = file_get_contents($selectionPath);
$selector = file_get_contents($selectorPath);
$contour = file_get_contents($contourPath);
$contract = file_get_contents($contractPath);

$checks = [
    'clock watchdog keeps bounded holdover' =>
        str_contains($clock, 'MAX_HOLDOVER_AGE_NS = 300_000_000_000L') &&
        str_contains($clock, 'Clock holdover active; watchdog fast resync'),
    'clock watchdog retries quickly and refreshes UDP socket' =>
        str_contains($clock, 'RECOVERY_RETRY_INTERVAL_MS = 750L') &&
        str_contains($clock, 'WATCHDOG_RECONNECT_ROUNDS = 3') &&
        str_contains($clock, 'socket.disconnect()') &&
        str_contains($clock, 'socket.connect(InetSocketAddress(address, port))'),
    'watchdog status is forwarded without control re-pairing' =>
        str_contains($clock, 'onStatusForSlave(statusPayload(next))') &&
        !str_contains($clock, 'startMaster(' . "\n" . '                        peerHost'),
    'manual profile modes are defined' =>
        str_contains($selection, 'MANUAL_ULTRA_960') &&
        str_contains($selection, 'MANUAL_HIGH_640') &&
        str_contains($selection, 'MANUAL_QUALITY_480') &&
        str_contains($selection, 'MANUAL_BALANCED_320'),
    'manual modes bypass timing downgrade while thermal floors remain' =>
        str_contains($performance, 'selectionMode != DualPhoneDepthProfileMode.AUTO') &&
        str_contains($performance, 'val level = maxOf(thermalFloor, requestedLevel)'),
    'profile selector exposes AUTO and manual controls' =>
        str_contains($selector, 'DualPhoneDepthProfileMode.values().forEach') &&
        str_contains($selector, 'DualPhoneDepthProfileSelection.select(mode)'),
    'overlay waits for matching active profile dimensions' =>
        str_contains($contour, 'overlayMatchesActiveMap') &&
        str_contains($selection, 'OVERLAY_TRANSITION_HOLD_MS = 1_200L') &&
        str_contains($selection, 'expectedWorkEnvelope'),
    'contract schedules CPU laptop work after phone probe' =>
        str_contains($contract, 'LM02.7B') &&
        str_contains($contract, 'CPU laptop'),
];

$failed = false;
foreach ($checks as $label => $ok) {
    if ($ok) {
        fwrite(STDOUT, "[OK] {$label}\n");
    } else {
        fwrite(STDERR, "[FAIL] {$label}\n");
        $failed = true;
    }
}

exit($failed ? 1 : 0);
