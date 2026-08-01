<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'card' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/session/DualPhoneLiveStreamSessionCard.kt',
    'slave' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/session/DualPhoneSlaveWorkScreen.kt',
    'workspace' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/session/DualPhoneFullScreenScanWorkspace.kt',
    'depth' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneLiveDepthProcessor.kt',
    'filter' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneFilteredDepthEngine.kt',
    'producer' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneReducedFrameProducer.kt',
    'runtime' => $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneApplicationRuntime.kt',
    'contract' => $root . '/app/MaklerTour/docs/APP_DUAL_PHONE_LIVE_STREAM_CONTRACT.md',
];

foreach ($files as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "[FAIL] missing {$name}: {$path}\n");
        exit(1);
    }
}

$card = file_get_contents($files['card']);
$slave = file_get_contents($files['slave']);
$workspace = file_get_contents($files['workspace']);
$depth = file_get_contents($files['depth']);
$filter = file_get_contents($files['filter']);
$producer = file_get_contents($files['producer']);
$runtime = file_get_contents($files['runtime']);
$contract = file_get_contents($files['contract']);

$checks = [
    'LIVE opens full-screen workspace' =>
        str_contains($card, 'showScanWorkspace = true') &&
        str_contains($card, 'DualPhoneMasterScanDialog'),
    'workspace disables platform default width' =>
        str_contains($workspace, 'usePlatformDefaultWidth = false'),
    'MASTER overlay exposes all views' =>
        str_contains($workspace, 'MASTER,') &&
        str_contains($workspace, 'SLAVE,') &&
        str_contains($workspace, 'SPLIT("SPLIT")') &&
        str_contains($workspace, 'DEPTH("RAW")') &&
        str_contains($workspace, 'FILTERED("FILTERED")') &&
        str_contains($workspace, 'CONFIDENCE("CONF")'),
    'SLAVE uses full-screen scan workspace' =>
        str_contains($slave, 'DualPhoneSlaveScanWorkspace'),
    'SLAVE keeps emergency action' =>
        str_contains($workspace, 'Аварийно отключить SLAVE'),
    'MASTER publishes authoritative stream identity' =>
        str_contains($runtime, '"stream_id",') &&
        str_contains($runtime, 'owner?.streamId ?: JSONObject.NULL'),
    'SLAVE adopts MASTER stream identity' =>
        str_contains($runtime, 'payload.optString("stream_id")') &&
        str_contains($runtime, 'streamId = masterStreamId') &&
        str_contains($runtime, 'MASTER_STREAM_ID_MISSING'),
    'depth keeps strict stream identity gate' =>
        str_contains($depth, 'masterFrame.streamId != slaveFrame.streamId') &&
        str_contains($depth, 'MASTER and SLAVE stream_id do not match'),
    'pairing consumes clock offset' =>
        str_contains($depth, 'clockSync.offsetNs') &&
        str_contains($depth, 'slaveToMasterTime'),
    'capture timestamp is sampled before JPEG encoding' =>
        strpos($producer, 'analysisReceivedElapsedRealtimeNs') <
            strpos($producer, 'val encoded = encodeJpeg(image)') &&
        str_contains(
            $producer,
            'captureElapsedRealtimeNs = analysisReceivedElapsedRealtimeNs',
        ),
    'pair histories remain bounded' =>
        str_contains($depth, 'MAX_HISTORY_FRAMES = 8'),
    'pair gates are explicit' =>
        str_contains($depth, 'READY_PAIR_DELTA_NS = 35_000_000L') &&
        str_contains($depth, 'MAX_PAIR_DELTA_NS = 120_000_000L'),
    'real calibration rectification is used' =>
        str_contains($depth, 'Calib3d.stereoRectify') &&
        str_contains($depth, 'Calib3d.initUndistortRectifyMap'),
    'filtered SGBM disparity is computed' =>
        str_contains($filter, 'StereoSGBM.create') &&
        str_contains($filter, 'StereoSGBM.MODE_SGBM_3WAY') &&
        str_contains($filter, 'stereo.compute'),
    'spatial quality gates are explicit' =>
        str_contains($filter, 'MIN_TEXTURE_GRADIENT') &&
        str_contains($filter, 'Imgproc.MORPH_OPEN') &&
        str_contains($filter, 'Imgproc.MORPH_CLOSE'),
    'temporal history is bounded and resettable' =>
        str_contains($filter, 'TEMPORAL_WINDOW_FRAMES = 5') &&
        str_contains($filter, 'MIN_TEMPORAL_VOTES = 3') &&
        str_contains($filter, 'temporalDisparities.removeFirst().release()') &&
        str_contains($depth, 'filteredDepthEngine.reset()'),
    'confidence categories are published' =>
        str_contains($filter, 'HIGH_CONFIDENCE_BGR') &&
        str_contains($filter, 'MEDIUM_CONFIDENCE_BGR') &&
        str_contains($filter, 'LOW_CONFIDENCE_BGR') &&
        str_contains($depth, 'confidencePreviewJpeg'),
    'MASTER exposes raw filtered and confidence diagnostics' =>
        str_contains($workspace, 'rawValidDisparityPercent') &&
        str_contains($workspace, 'filteredValidDisparityPercent') &&
        str_contains($workspace, 'stableCoveragePercent') &&
        str_contains($workspace, 'depthJitterMeters'),
    'contract rejects premature room-model claims' =>
        str_contains($contract, 'diagnostic') &&
        str_contains($contract, 'must not claim'),
];

$failed = false;
foreach ($checks as $label => $ok) {
    if ($ok) {
        echo "[OK] {$label}\n";
    } else {
        fwrite(STDERR, "[FAIL] {$label}\n");
        $failed = true;
    }
}

exit($failed ? 1 : 0);
