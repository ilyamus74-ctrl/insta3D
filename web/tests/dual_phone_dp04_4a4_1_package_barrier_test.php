<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$manager = file_get_contents($root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneControlManager.kt');
$bundle = file_get_contents($root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneBundleTransfer.kt');
$collector = file_get_contents($root . '/collect_insta3d_dual_adb_diagnostics.sh');

$required = [
    'pendingSlaveTransferOffer',
    'tryStartAggregateTransfer()',
    'WAITING_FOR_MASTER_PACKAGE',
    'TRANSFER_BARRIER_READY',
    'aggregateTransferCaptureId',
];
foreach ($required as $token) {
    if (!str_contains($manager, $token)) {
        fwrite(STDERR, "Missing manager contract token: {$token}\n");
        exit(1);
    }
}
if (!str_contains($bundle, 'role package server ended before successful transfer')) {
    fwrite(STDERR, "Bundle server timeout hardening is missing\n");
    exit(1);
}
foreach (['stop_background_process', 'kill -KILL', 'logcat_stream.log', 'timeout --signal=TERM', 'COLLECT_PIDS'] as $token) {
    if (!str_contains($collector, $token)) {
        fwrite(STDERR, "Missing collector hardening token: {$token}\n");
        exit(1);
    }
}
echo "OK\n";
