#!/usr/bin/env python3
from future import annotations

import re
import sys
from pathlib import Path

ROOT = Path(file).resolve().parents[1]

MAIN = ROOT / "app/src/main/java/com/example/maklertour/MainActivity.kt"
PHONE_RECORDER = ROOT / "app/src/main/java/com/example/maklertour/data/phonecamera/PhoneCameraVideoRecorder.kt"
STEREO_PROCESSOR = ROOT / "app/src/main/java/com/example/maklertour/data/calibration/StereoCalibrationProcessor.kt"
STEREO_CAPTURE = ROOT / "app/src/main/java/com/example/maklertour/data/phonecamera/StereoCaptureExperimental.kt"

class Audit:
def init(self) -> None:
self.errors: list[str] = []
self.warnings: list[str] = []
self.ok: list[str] = []

def pass_(self, msg: str) -> None:
    self.ok.append(msg)

def warn(self, msg: str) -> None:
    self.warnings.append(msg)

def fail(self, msg: str) -> None:
    self.errors.append(msg)

def require(self, cond: bool, msg: str) -> None:
    if cond:
        self.pass_(msg)
    else:
        self.fail(msg)

def report(self) -> int:
    print("=== Stereo contract audit ===")
    print()

    if self.ok:
        print("OK:")
        for item in self.ok:
            print(f"  [OK] {item}")
        print()

    if self.warnings:
        print("WARNINGS:")
        for item in self.warnings:
            print(f"  [WARN] {item}")
        print()

    if self.errors:
        print("ERRORS:")
        for item in self.errors:
            print(f"  [FAIL] {item}")
        print()
        print("Result: FAIL")
        return 1

    print("Result: PASS")
    return 0

def read_file(path: Path, audit: Audit) -> str:
if not path.exists():
audit.fail(f"Missing file: {path.relative_to(ROOT)}")
return ""
return path.read_text(encoding="utf-8", errors="replace")

def extract_function_or_block(text: str, anchor: str) -> str:
idx = text.find(anchor)
if idx < 0:
return ""

# Try to find nearest opening brace after anchor and return balanced block.
brace = text.find("{", idx)
if brace < 0:
    return text[idx: idx + 3000]

depth = 0
for pos in range(brace, len(text)):
    ch = text[pos]
    if ch == "{":
        depth += 1
    elif ch == "}":
        depth -= 1
        if depth == 0:
            return text[idx: pos + 1]

return text[idx: idx + 5000]

def extract_between(text: str, start_anchor: str, end_anchor: str | None = None) -> str:
start = text.find(start_anchor)
if start < 0:
return ""
if end_anchor is None:
return text[start:]
end = text.find(end_anchor, start + len(start_anchor))
if end < 0:
return text[start:]
return text[start:end]

def grep_context(text: str, pattern: str, before: int = 2, after: int = 2) -> list[str]:
lines = text.splitlines()
hits: list[str] = []
rx = re.compile(pattern)
for i, line in enumerate(lines):
if rx.search(line):
a = max(0, i - before)
b = min(len(lines), i + after + 1)
hits.append("\n".join(f"{n+1}: {lines[n]}" for n in range(a, b)))
return hits

def audit_main_screen(main: str, audit: Audit) -> None:
screen = extract_function_or_block(main, "private fun StereoCaptureExperimentalScreen(")
if not screen:
audit.fail("MainActivity: StereoCaptureExperimentalScreen not found")
return

audit.require("FrameLayout" in screen, "cam1 live preview uses FrameLayout wrapper")
audit.require("clipChildren = true" in screen, "cam1 FrameLayout has clipChildren=true")
audit.require("clipToPadding = true" in screen, "cam1 FrameLayout has clipToPadding=true")
audit.require("TextureView" in screen, "cam1 live preview uses TextureView")
audit.require("textureView.rotation = CAM1_PREVIEW_ROTATION_DEGREES" in screen or "rotation = CAM1_PREVIEW_ROTATION_DEGREES" in screen, "cam1 TextureView is rotated as Android View")
audit.require("layoutCam1Preview" in screen, "cam1 TextureView layout helper exists")

forbidden = [
    ("graphicsLayer { rotationZ = CAM1_PREVIEW_ROTATION_DEGREES }", "cam1 live preview must not use Compose graphicsLayer rotation"),
    ("setTransform(", "cam1 live preview must not use TextureView.setTransform"),
    ("cropScale", "cam1 live preview must not use cropScale"),
    ("maxOf(width / rotatedWidth", "cam1 live preview must not use maxOf crop/fill scale"),
]
for needle, msg in forbidden:
    audit.require(needle not in screen, msg)

if "onSurfaceTextureUpdated" in screen:
    updated_blocks = grep_context(screen, r"onSurfaceTextureUpdated", before=0, after=6)
    combined = "\n".join(updated_blocks)
    audit.require("layoutCam1Preview(parent" not in combined, "cam1 layout is not recalculated on every preview frame")
    audit.require("manager.onCam1PreviewFrameRendered()" in combined, "cam1 onSurfaceTextureUpdated still reports rendered frames")

audit.require("PreviewView.ScaleType.FIT_CENTER" in screen, "cam0 PreviewView uses FIT_CENTER")
audit.require("height(360.dp)" in screen or "height(350.dp)" in screen or "height(330.dp)" in screen or re.search(r"height\(\s*3[3-9]\d\.dp\s*\)", screen) is not None, "main live preview row is enlarged")

# Probe buttons should not be visible on the main screen.
audit.require("Probe phone dual camera" not in screen, "main UI does not show Probe phone dual camera button")
audit.require("Show phone dual camera probe JSON path" not in screen, "main UI does not show probe JSON path button")
audit.require("Probe JSON:" not in screen, "main UI does not show probe JSON output line")

# Main button labels should still exist.
for label in [
    "Record stereo video (legacy)",
    "Record synced depth frames",
    "Calibration",
    "Open settings",
    "Show diagnostics",
    "Refresh lenses",
]:
    audit.require(label in screen, f"main UI has button/text: {label}")

def audit_calibration_ui(main: str, audit: Audit) -> None:
panel = extract_function_or_block(main, "private fun PreviewBitmapPanel(")
if not panel:
panel = extract_function_or_block(main, "@Composable\nprivate fun PreviewBitmapPanel(")

audit.require(bool(panel), "Calibration PreviewBitmapPanel exists")
if not panel:
    return

audit.require("@Composable" in panel[:120], "PreviewBitmapPanel is @Composable")
audit.require("rotateBitmapForCalibrationDisplayOnly" in main, "calibration display bitmap rotation helper exists")
audit.require("makeCalibrationOverlayBitmapForDisplayOnly" in main, "calibration overlay bitmap helper exists")
audit.require("model = displayBitmap" in panel, "PreviewBitmapPanel displays rotated displayBitmap")
audit.require("overlayBitmap" in panel, "PreviewBitmapPanel displays rotated overlay bitmap")
audit.require("graphicsLayer { rotationZ = rotationDegrees }" not in panel, "PreviewBitmapPanel does not rotate bitmap via graphicsLayer")
audit.require("Canvas(modifier = Modifier.fillMaxSize())" not in panel, "PreviewBitmapPanel no longer uses unrotated Canvas overlay")

def audit_stereo_flow(main: str, processor: str, capture: str, audit: Audit) -> None:
audit.require("getNearestStereoCalibrationFrames(30)" in main, "Step 3 stereo calibration uses nearest ring-buffer with 30ms")
audit.require("stereoPairSelection" in main and "nearest_ring_buffer" in main, "manifest records nearest_ring_buffer for stereo pairs")
audit.require("stereoMaxDeltaMs" in main and "30L" in main, "manifest records stereoMaxDeltaMs=30L")

audit.require("fun getNearestStereoCalibrationFrames" in capture, "StereoCaptureExperimental has getNearestStereoCalibrationFrames")
audit.require("maxDeltaMs" in capture, "nearest pair function enforces maxDeltaMs")
audit.require("delta_exceeds" in capture or "best_delta_ms" in capture, "nearest pair function logs/rejects bad deltas")

audit.require("commonIds" in processor, "StereoCalibrationProcessor uses common ChArUco IDs")
audit.require(".intersect(" in processor, "StereoCalibrationProcessor intersects cam0/cam1 ChArUco IDs")
audit.require("charucoCommonIdsPerPair" in processor, "StereoCalibrationProcessor reports common IDs per pair")
audit.require("CALIB_FIX_INTRINSIC" in processor, "stereoCalibrate uses CALIB_FIX_INTRINSIC")
audit.require("detectCharucoCorners" in processor, "StereoCalibrationProcessor detects ChArUco corners")

def audit_saved_frames(recorder: str, audit: Audit) -> None:
if not recorder:
return

audit.require("imageProxyRotationDegrees" in recorder, "PhoneCameraVideoRecorder logs/keeps imageProxy rotation separately")
audit.require("val rotationDegrees = 0" in recorder or "val rotationDegrees = 0f" in recorder, "cam0 saved frames force rotationDegrees=0")

suspicious = [
    "rotateBitmap(rawBitmap, imageProxyRotationDegrees)",
    "rawBitmap.rotate(imageProxyRotationDegrees)",
    "rotationDegrees = imageProxy.imageInfo.rotationDegrees",
]
for needle in suspicious:
    audit.require(needle not in recorder, f"cam0 saved frames do not apply CameraX rotation: {needle}")

def audit_imports(main: str, audit: Audit) -> None:
android_matrix = "import android.graphics.Matrix" in main
compose_matrix = "import androidx.compose.ui.graphics.Matrix" in main
if android_matrix and compose_matrix:
audit.fail("MainActivity imports both android.graphics.Matrix and androidx.compose.ui.graphics.Matrix")
else:
audit.pass_("No conflicting Matrix imports")

if "android.graphics.Matrix().apply" in main:
    audit.warn("android.graphics.Matrix usage exists. Ensure it is not used for cam1 live TextureView transform.")
else:
    audit.pass_("No android.graphics.Matrix transform usage in MainActivity")

def main() -> int:
audit = Audit()

main_text = read_file(MAIN, audit)
recorder_text = read_file(PHONE_RECORDER, audit)
processor_text = read_file(STEREO_PROCESSOR, audit)
capture_text = read_file(STEREO_CAPTURE, audit)

if main_text:
    audit_imports(main_text, audit)
    audit_main_screen(main_text, audit)
    audit_calibration_ui(main_text, audit)

if processor_text or capture_text:
    audit_stereo_flow(main_text, processor_text, capture_text, audit)

if recorder_text:
    audit_saved_frames(recorder_text, audit)

return audit.report()

if name == "main":
sys.exit(main())
