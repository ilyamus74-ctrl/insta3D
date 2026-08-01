<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$transportPath = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneReducedFrameTransport.kt';
$producerPath = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneReducedFrameProducer.kt';
$runtimePath = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneApplicationRuntime.kt';
$previewPath = $root . '/app/MaklerTour/app/src/main/java/com/example/maklertour/ui/session/DualPhoneReducedFramePreview.kt';
$appContractPath = $root . '/app/MaklerTour/docs/APP_DUAL_PHONE_LIVE_STREAM_CONTRACT.md';

foreach ([$transportPath, $producerPath, $runtimePath, $previewPath, $appContractPath] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing LM01B file: {$path}\n");
        exit(1);
    }
}

$transport = file_get_contents($transportPath);
$producer = file_get_contents($producerPath);
$runtime = file_get_contents($runtimePath);
$preview = file_get_contents($previewPath);
$contract = file_get_contents($appContractPath);

$requiredTransport = [
    'const val DEFAULT_PORT = 45_832',
    'const val MAX_WIDTH = 640',
    'const val MAX_HEIGHT = 360',
    'const val MAX_PAYLOAD_BYTES = 256 * 1024',
    'AtomicReference<DualPhoneReducedFrame?>',
    'framesReplacedBeforeSend',
    'pendingFrame.getAndSet(frame)',
    'rotationAppliedDegrees == 0',
    'payload_crc32',
    'sender_frames_replaced_before_send',
    'generation.get() == token',
    'config.owner.peerIdentity',
    'FRAME_MAGIC',
];

$requiredProducer = [
    'ImageAnalysis.STRATEGY_KEEP_ONLY_LATEST',
    'private const val TARGET_FPS = 10L',
    'private const val JPEG_QUALITY = 65',
    'rotationAppliedDegrees = 0',
    'STREAM_UNAVAILABLE',
    'selectedOrDefault().first.cameraId',
];

$requiredRuntime = [
    'private val mediaTransport = DualPhoneReducedFrameTransport()',
    'private val frameProducer = DualPhoneReducedFrameProducer(appContext)',
    'mediaTransport.state.collect',
    'frameProducer.state.collect',
    'stopReducedFramePipeline()',
    'mediaTransport.offerFrame(frame)',
];

$requiredPreview = [
    'MASTER · локальная камера',
    'SLAVE · TCP/',
    'SLAVE CAMERA · STREAMING TO MASTER',
    'без rectification, depth и метрической геометрии',
];

foreach ([
    'transport' => [$transport, $requiredTransport],
    'producer' => [$producer, $requiredProducer],
    'runtime' => [$runtime, $requiredRuntime],
    'preview' => [$preview, $requiredPreview],
] as $name => [$text, $tokens]) {
    foreach ($tokens as $token) {
        if (strpos($text, $token) === false) {
            fwrite(STDERR, "LM01B {$name} contract token is missing: {$token}\n");
            exit(1);
        }
    }
}

if (strpos($transport, 'DualPhoneControlManager') !== false) {
    fwrite(STDERR, "Reduced frame media was embedded in control manager\n");
    exit(1);
}

if (strpos($contract, 'JPEG payloads are forbidden on the command socket') === false) {
    fwrite(STDERR, "APP live-stream channel separation contract is missing\n");
    exit(1);
}

foreach (['metric depth', 'room skeleton', 'mesh'] as $claim) {
    if (strpos($preview, 'Text("' . $claim) !== false) {
        fwrite(STDERR, "LM01B UI makes a forbidden geometry claim: {$claim}\n");
        exit(1);
    }
}

echo "OK: LM01B real reduced-frame stream is bounded, separated and diagnostic-only\n";
