<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$main = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/MainActivity.kt';
$runtime = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneApplicationRuntime.kt';
$card = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/session/DualPhoneLiveStreamSessionCard.kt';
$screen = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/session/DualPhoneSlaveWorkScreen.kt';
$contracts = $root . '/docs/llm/04_CONTRACTS.md';
$appContract = $root . '/app/MaklerTour/docs/APP_CAMERA_STEREO_CONTRACT.md';
$task = $root . '/docs/llm/tasks/APP-DUAL-PHONE-LM01A-4.2-MASTER-NAVIGATION-OWNERSHIP.md';

foreach ([$main, $runtime, $card, $screen, $contracts, $appContract, $task] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing required file: {$path}\n");
        exit(1);
    }
}

$mainText = file_get_contents($main);
$runtimeText = file_get_contents($runtime);
$cardText = file_get_contents($card);
$screenText = file_get_contents($screen);
$contractsText = file_get_contents($contracts);
$appContractText = file_get_contents($appContract);
$taskText = file_get_contents($task);

foreach ([
    'route == AppTab.Settings.route',
    'dualPhoneRuntime.enterManagedWorkSurface()',
    'dualPhoneRuntime.exitWorkMode()',
    'dualPhoneRuntimeState.controlConnected',
] as $token) {
    if (strpos($mainText, $token) === false) {
        fwrite(STDERR, "Missing root-navigation ownership token: {$token}\n");
        exit(1);
    }
}

if (strpos($mainText, 'currentRoute != AppTab.Camera.route') !== false) {
    fwrite(STDERR, "Camera-only SLAVE ownership regression is present\n");
    exit(1);
}

foreach ([
    'fun enterManagedWorkSurface(forcePassive: Boolean = false)',
    'DualPhoneApplicationMode.WORK_APP',
    'current.applicationMode.working && !forcePassive',
    'Waiting for SLAVE managed application screen',
    'SLAVE application is controlled by MASTER',
] as $token) {
    if (strpos($runtimeText, $token) === false) {
        fwrite(STDERR, "Missing application-runtime ownership token: {$token}\n");
        exit(1);
    }
}

if (strpos($cardText, 'enterCameraWorkSurface()') !== false ||
    strpos($cardText, 'applicationRuntime::exitWorkMode') !== false ||
    strpos($cardText, 'forcePassive = true') === false
) {
    fwrite(STDERR, "Camera card may stop LIVE but must not release SLAVE ownership\n");
    exit(1);
}

foreach ([
    'SLAVE · УПРАВЛЯЕТСЯ MASTER',
    'Только раздел «Настройки» возвращает локальное управление.',
] as $token) {
    if (strpos($screenText, $token) === false) {
        fwrite(STDERR, "Missing SLAVE ownership UI token: {$token}\n");
        exit(1);
    }
}

if (strpos($contractsText, 'C30 — Android dual-phone MASTER ↔ SLAVE application ownership') === false ||
    strpos($appContractText, 'Dual-phone application ownership contract (2026-08-01)') === false ||
    strpos($taskText, 'MASTER navigation ownership') === false
) {
    fwrite(STDERR, "LM01A navigation ownership is not documented\n");
    exit(1);
}

echo "OK: MASTER owns SLAVE across all work tabs and Settings is the only normal release tab\n";
