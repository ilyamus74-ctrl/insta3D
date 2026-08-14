<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$android = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour';
$producer = (string) file_get_contents(
    $android . '/data/dualphone/DualPhoneReducedFrameProducer.kt',
);
$frame = (string) file_get_contents(
    $android . '/data/dualphone/DualPhoneReducedFrameTransport.kt',
);
$uplink = (string) file_get_contents(
    $android . '/data/dualphone/DualPhoneLaptopUplinkRuntime.kt',
);
$tofRuntime = (string) file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/' .
    'data/tof/TofRegisteredRgbAnchorRuntime.kt',
);
$host = (string) file_get_contents(
    $root . '/web/remote_station/dual_phone_host/src/host_state.cpp',
);
$web = (string) file_get_contents(
    $root . '/web/remote_station/dual_phone_host/web/index.html',
);

$checks = [
    'same frame object can carry process-local ToF snapshot' =>
        str_contains($frame, 'registeredTofSnapshot: TofRegisteredRgbSnapshot?'),
    'laptop CAMERA_A explicitly enables ToF registration' =>
        str_contains($producer, 'owner.localRole == "LAPTOP_CAMERA_A"') &&
        str_contains($uplink, 'producer.startLaptop(owner)'),
    'CAMERA_B does not implicitly register ToF in laptop mode' =>
        str_contains($producer, 'if (registerTofForCameraA)') &&
        !str_contains($producer, 'owner.localRole == "LAPTOP_CAMERA_B"'),
    'uplink keeps ToF metadata on the exact JPEG frame header' =>
        str_contains($uplink, 'header.put("tof_registered"') &&
        str_contains($uplink, 'frame.registeredTofSnapshot'),
    'multi-slot payload remains explicit' =>
        str_contains($uplink, '"slots"') &&
        str_contains($uplink, '"slot", slot.tofSlot') &&
        str_contains($tofRuntime, 'tofWidth: Int = 0') &&
        str_contains($tofRuntime, 'tofHeight: Int = 0'),
    'host exposes ToF metadata through existing status JSON' =>
        str_contains($host, 'value["tof_registered"]') &&
        str_contains($host, 'frame.header.at("tof_registered")'),
    'web overlays raw CAMERA_A rather than recomputing geometry' =>
        str_contains($web, 'id="tofOverlayA"') &&
        str_contains($web, 'latest.tof_registered') &&
        str_contains($web, 'tofOverlayA.style.transform = cameraA.style.transform'),
    'web draws zone grid and metric distance labels' =>
        str_contains($web, 'zone + width') &&
        str_contains($web, 'anchor.distance_mm') &&
        str_contains($web, 'TOF → CAMERA_A'),
];

$failed = false;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    $failed = $failed || !$ok;
}

echo 'Result: ' . ($failed ? 'FAIL' : 'PASS') . PHP_EOL;
exit($failed ? 1 : 0);
