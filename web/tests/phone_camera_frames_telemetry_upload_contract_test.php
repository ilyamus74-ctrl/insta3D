<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$provider = (string) file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/PhoneCameraScanProvider.kt'
);
$manifest = (string) file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/PhoneScanManifestWriter.kt'
);
$uploadApi = (string) file_get_contents(
    $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/auth/MobileUploadApi.kt'
);
$mobilePhp = (string) file_get_contents($root . '/web/www/api/mobile.php');
$extractRunner = (string) file_get_contents(
    $root . '/web/remote_station/run_extract_frames_job.sh'
);
$extractProcessor = (string) file_get_contents(
    $root . '/web/remote_station/scripts/process_extract_frames.sh'
);

$checks = [
    'regular PHONE_CAMERA stop passes runtime frames telemetry into manifest' =>
        str_contains($provider, 'video.frameTelemetrySummary?.path?.let(::File)') &&
        str_contains($provider, 'framesFile ='),

    'phone scan manifest declares frames.jsonl when present' =>
        str_contains($manifest, 'framesFile: File? = null') &&
        str_contains($manifest, '.put("frames"') &&
        str_contains($manifest, 'files.put(JSONObject().put("name", framesFile.name)'),

    'Android multipart upload attaches frames telemetry' =>
        str_contains($uploadApi, 'frames = File(dir, "frames.jsonl")') &&
        str_contains(
            $uploadApi,
            'metadata.frames, "frames", "application/x-ndjson"'
        ),

    'mobile API stores uploaded frames sidecar next to video metadata' =>
        str_contains($mobilePhp, "\$metadataPaths['frames']") &&
        str_contains($mobilePhp, "api_store_optional_video_metadata('frames'") &&
        str_contains($mobilePhp, "'_frames.jsonl'"),

    'extract runner discovers and transfers frames telemetry to station' =>
        str_contains($extractRunner, 'REMOTE_FRAMES=') &&
        str_contains($extractRunner, 'source.get("frames_path")') &&
        str_contains($extractRunner, '_camera_info.json}_frames.jsonl') &&
        str_contains($extractRunner, 'upload_optional_sidecar "$LOCAL_FRAMES"') &&
        str_contains($extractRunner, "('frames_path', frames_path)"),

    'extract processor preserves frames telemetry in extract job root' =>
        str_contains($extractProcessor, 'source_video.frames_path') &&
        str_contains($extractProcessor, '$JOB_ROOT/frames.jsonl:frames'),
];

$failed = false;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    $failed = $failed || !$ok;
}

echo 'Result: ' . ($failed ? 'FAIL' : 'PASS') . PHP_EOL;
exit($failed ? 1 : 0);
