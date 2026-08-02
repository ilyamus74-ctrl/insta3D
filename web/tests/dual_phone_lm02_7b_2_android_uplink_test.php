<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$android = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour';
$host = $root . '/web/remote_station/dual_phone_host';

$runtime = (string) file_get_contents(
    $android . '/data/dualphone/DualPhoneLaptopUplinkRuntime.kt',
);
$settings = (string) file_get_contents(
    $android . '/data/dualphone/DualPhoneLaptopUplinkSettings.kt',
);
$card = (string) file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/settings/' .
    'DualPhoneLaptopUplinkCard.kt',
);
$settingsCard = (string) file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/settings/' .
    'DualPhoneControlSettingsCard.kt',
);
$main = (string) file_get_contents($host . '/src/main.cpp');
$stateHpp = (string) file_get_contents($host . '/src/host_state.hpp');
$stateCpp = (string) file_get_contents($host . '/src/host_state.cpp');
$run = (string) file_get_contents($host . '/scripts/run.sh');

$checks = [
    'android laptop slot settings' =>
        str_contains($settings, 'CAMERA_A') &&
        str_contains($settings, 'CAMERA_B'),
    'android bounded latest frame queue' =>
        str_contains($runtime, 'AtomicReference<DualPhoneReducedFrame?>') &&
        str_contains($runtime, 'framesReplacedBeforeSend'),
    'android hello and reconnect' =>
        str_contains($runtime, 'ANDROID_CAMERAX') &&
        str_contains($runtime, 'RECONNECTING'),
    'android host clock probes' =>
        str_contains($runtime, '"clock_probe"') &&
        str_contains($runtime, 'host_aligned_timestamp_ns'),
    'android imu stream' =>
        str_contains($runtime, 'TYPE_ACCELEROMETER') &&
        str_contains($runtime, 'TYPE_GYROSCOPE') &&
        str_contains($runtime, '"imu"'),
    'settings UI exposes laptop uplink' =>
        str_contains($card, 'Connect phone to laptop') &&
        str_contains($settingsCard, 'DualPhoneLaptopUplinkCard'),
    'host clock probe acknowledgement' =>
        str_contains($main, 'clock_probe_ack') &&
        str_contains($main, 'server_receive_ns'),
    'dashboard remains local by default' =>
        str_contains($run, 'MAKLER_HTTP_BIND:-127.0.0.1'),
    'bounded host pair queues' =>
        str_contains($stateHpp, 'pair_queue') &&
        str_contains($stateCpp, 'kPairQueueCapacity'),
    'nearest unused pair matching' =>
        str_contains($stateCpp, 'best_delta_ns') &&
        str_contains($stateCpp, 'queue_a.pop_front()') &&
        str_contains($stateCpp, 'queue_b.pop_front()'),
];

$failed = false;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    $failed = $failed || !$ok;
}

echo 'Result: ' . ($failed ? 'FAIL' : 'PASS') . PHP_EOL;
exit($failed ? 1 : 0);
