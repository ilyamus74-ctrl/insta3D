<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$manager = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneControlManager.kt';
$protocol = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneControlProtocol.kt';
$settings = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneStereoSettings.kt';
$ui = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/settings/DualPhoneControlSettingsCard.kt';
$main = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/MainActivity.kt';

foreach ([$manager, $protocol, $settings, $ui, $main] as $path) {
    if (!is_file($path)) {
        throw new RuntimeException('Missing DP02 source: ' . $path);
    }
}

$managerText = (string) file_get_contents($manager);
$protocolText = (string) file_get_contents($protocol);
$settingsText = (string) file_get_contents($settings);
$uiText = (string) file_get_contents($ui);
$mainText = (string) file_get_contents($main);

$requiredProtocol = [
    'HELLO',
    'WELCOME',
    'CAPABILITIES',
    'PING',
    'PONG',
    'ARM',
    'ARM_ACK',
    'START_AT',
    'START_ACK',
    'STOP',
    'STOP_ACK',
];

foreach ($requiredProtocol as $token) {
    if (!str_contains($protocolText, '"' . $token . '"')) {
        throw new RuntimeException('Protocol token missing: ' . $token);
    }
}

$managerChecks = [
    'ServerSocket()',
    'Socket()',
    'DualPhoneControlProtocol.pairingCode()',
    'DualPhoneControlProtocol.dualCaptureId()',
    'HEARTBEAT_INTERVAL_MS',
    'HEARTBEAT_TIMEOUT_MS',
    'capabilityProbe.buildReport(settings)',
    'allowedPhases',
    'reportCommandError',
    'lastError',
    'is not allowed in',
];

foreach ($managerChecks as $token) {
    if (!str_contains($managerText, $token)) {
        throw new RuntimeException('Manager contract missing: ' . $token);
    }
}

if (!str_contains($settingsText, 'val masterHost: String? = null')) {
    throw new RuntimeException('Persistent Master host is missing');
}
if (!str_contains($uiText, 'START +3s')) {
    throw new RuntimeException('DP02 control UI is missing');
}
foreach (['enabled = canArm', 'enabled = canStart', 'enabled = canStop'] as $token) {
    if (!str_contains($uiText, $token)) {
        throw new RuntimeException('DP02 state-gated control missing: ' . $token);
    }
}
if (!str_contains($mainText, 'DualPhoneControlSettingsCard(')) {
    throw new RuntimeException('DP02 control UI is not connected to SettingsScreen');
}
if (!str_contains($mainText, 'DualPhoneControlManager.get(context.applicationContext)')) {
    throw new RuntimeException('DP02 control runtime is not connected');
}

echo "OK\n";
