<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$roadmap = $root . '/docs/llm/tasks/APP-DUAL-PHONE-STEREO-ROADMAP.md';
$architecture = $root . '/docs/llm/tasks/APP-DUAL-PHONE-METRIC-MODEL-ARCHITECTURE.md';
$status = $root . '/docs/llm/tasks/APP-STEREO-CURRENT-STATUS-AND-NEXT-STEPS.md';

foreach ([$roadmap, $architecture, $status] as $path) {
    if (!is_file($path) || filesize($path) <= 0) {
        throw new RuntimeException('Missing stereo architecture document: ' . $path);
    }
}

$roadmapText = (string) file_get_contents($roadmap);
foreach ([
    'SYNC_VIDEO',
    'LIVE_METRIC',
    'HYBRID',
    'PHONE_USB_STEREO',
    'DUAL_PHONE_STEREO',
    'DP04.2',
    'cam0_frames.jsonl',
    'cam1_frames.jsonl',
    'encoder_mapping_status',
    'K0, D0',
    'K1, D1',
    'stereo_R / R',
    'stereo_T / T',
    'MM04 — Mesh',
    'MM05 — Textures',
    'textured_model.glb',
] as $token) {
    if (!str_contains($roadmapText, $token)) {
        throw new RuntimeException('Dual-phone roadmap contract missing: ' . $token);
    }
}

$architectureText = (string) file_get_contents($architecture);
foreach ([
    'PRIMARY TARGET: METRIC ROOM MODEL WITH TEXTURED TRIANGLE MESH',
    'TSDF or equivalent surface fusion',
    'optimized mesh',
    'original cam0/cam1 frames',
    'Camera2 + MediaCodec',
    'shared acceptance gates',
] as $token) {
    if (!str_contains(strtolower($architectureText), strtolower($token))) {
        throw new RuntimeException('Metric-model architecture contract missing: ' . $token);
    }
}

$statusText = (string) file_get_contents($status);
foreach ([
    'DUAL-PHONE CONTROL, CLOCK SYNC AND REAL FHD RECORDING VERIFIED',
    'per-frame camera metadata sidecars',
    'dual-phone intrinsics/distortion and stereo R/T calibration',
    'texture projection from original frames',
] as $token) {
    if (!str_contains($statusText, $token)) {
        throw new RuntimeException('Stereo status contract missing: ' . $token);
    }
}

echo "OK\n";
