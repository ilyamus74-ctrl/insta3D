#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
source = root / "app/src/main/java/com/example/maklertour"

required = {
    source / "data/dualphone/DualPhoneCaptureRuntime.kt": [
        "interface DualPhoneCaptureEndpoint",
        "scheduledElapsedRealtimeNs",
        "startLatenessNs",
    ],
    source / "data/dualphone/DualPhoneControlProtocol.kt": [
        'CAPTURE_STARTED = "CAPTURE_STARTED"',
    ],
    source / "data/dualphone/DualPhoneControlManager.kt": [
        "DualPhoneCaptureRuntime.requireEndpoint()",
        "scheduleLocalCaptureStart",
        "CAPTURE_STARTED",
        "stopLocalCapture",
    ],
    source / "data/phonecamera/PhoneCameraVideoRecorder.kt": [
        "PhoneVideoRecordingStart",
        "VideoRecordEvent.Start",
        "cameraXStartElapsedNs",
        "getRecordingReadiness",
        "ensureRecordingReady",
        "withContext(Dispatchers.Main.immediate)",
        "preparedVideoCapture",
    ],
    source / "data/phonecamera/PhoneCameraScanProvider.kt": [
        "DualPhoneCaptureEndpoint",
        "dual_phone_captures",
        "dual_capture_manifest.json",
        "override suspend fun arm",
        "videoRecorder.ensureRecordingReady",
        "override suspend fun start",
        "override suspend fun stop",
    ],
}

for path, tokens in required.items():
    if not path.is_file():
        raise RuntimeError(f"missing file: {path}")
    text = path.read_text(encoding="utf-8")
    for token in tokens:
        if token not in text:
            raise RuntimeError(f"{path}: missing token: {token}")

print("OK")
