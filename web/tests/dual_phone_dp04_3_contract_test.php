<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneBundleTransfer.kt',
    'app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneControlManager.kt',
    'app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneControlProtocol.kt',
    'app/MaklerTour/app/src/main/java/com/example/maklertour/state/AppStateViewModel.kt',
    'docs/llm/tasks/APP-DUAL-PHONE-DP04-3-AUTOMATIC-BUNDLE-TRANSFER.md',
];
foreach ($files as $relative) {
    if (!is_file($root . '/' . $relative)) {
        fwrite(STDERR, "Missing $relative\n");
        exit(1);
    }
}
$joined = implode("\n", array_map(static fn(string $f): string => file_get_contents($root . '/' . $f), $files));
$required = [
    'DualPhoneBundleCoordinator',
    'role_package_transfer_token',
    'PACKAGE_RECEIVED',
    'dual_phone_stereo_video',
    'roles/master.tgz',
    'roles/slave.tgz',
    'DualPhoneAggregateUploadRuntime',
    'enqueueCaptureBundle',
    'READY_NOT_QUEUED',
    'bundleTransferPort',
];
foreach ($required as $token) {
    if (!str_contains($joined, $token)) {
        fwrite(STDERR, "Missing contract token: $token\n");
        exit(1);
    }
}
echo "OK\n";
