<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$base = $root . '/web/remote_station/dual_phone_host';
$required = [
    "$base/CMakeLists.txt",
    "$base/src/main.cpp",
    "$base/src/protocol.cpp",
    "$base/src/host_state.cpp",
    "$base/src/http_dashboard.cpp",
    "$base/web/index.html",
    "$base/tools/synthetic_camera_client.cpp",
    "$base/scripts/install_fedora41.sh",
    "$root/app/MaklerTour/docs/APP_DUAL_PHONE_LM02_7B_1_LAPTOP_HOST_CONTRACT.md",
    "$root/app/MaklerTour/docs/APP_DUAL_PHONE_ONDEVICE_BRANCH_CHECKPOINT.md",
];
foreach ($required as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "[FAIL] missing $path\n");
        exit(1);
    }
}
$main = file_get_contents("$base/src/main.cpp");
$protocol = file_get_contents("$base/src/protocol.cpp");
$state = file_get_contents("$base/src/host_state.cpp");
$dashboard = file_get_contents("$base/web/index.html");
$cmake = file_get_contents("$base/CMakeLists.txt");
$synthetic = file_get_contents("$base/tools/synthetic_camera_client.cpp");
$contract = file_get_contents(
    "$root/app/MaklerTour/docs/APP_DUAL_PHONE_LM02_7B_1_LAPTOP_HOST_CONTRACT.md"
);
$checks = [
    'two inbound slots' => str_contains(file_get_contents("$base/src/host_state.cpp"), 'CAMERA_A') && str_contains(file_get_contents("$base/src/host_state.cpp"), 'CAMERA_B'),
    'bounded protocol' => str_contains($protocol, 'kMaxPayloadBytes'),
    'crc validation' => str_contains($main, 'JPEG CRC32 mismatch'),
    'pair jsonl' => str_contains($state, 'pairs.jsonl'),
    'imu jsonl' => str_contains($state, 'imu_a.jsonl'),
    'c++ synthetic client' => str_contains($cmake, 'maklertour-dual-phone-synthetic-client') && str_contains($synthetic, 'SYNTHETIC_CPP'),
    'no python runtime' => !str_contains(file_get_contents("$base/scripts/install_fedora41.sh"), 'python3'),
    'browser gui' => str_contains($dashboard, '/camera/a.jpg') && str_contains($dashboard, '/api/status'),
    'phones initiate connections' => str_contains($contract, 'Both phones initiate outbound TCP'),
    'checkpoint documented' => str_contains(
        file_get_contents("$root/app/MaklerTour/docs/APP_DUAL_PHONE_ONDEVICE_BRANCH_CHECKPOINT.md"),
        'fd952471e0af8d7717a08729a0d2befab0e46fba'
    ),
];
foreach ($checks as $name => $ok) {
    if (!$ok) {
        fwrite(STDERR, "[FAIL] $name\n");
        exit(1);
    }
    echo "[OK] $name\n";
}
echo "Result: PASS\n";
