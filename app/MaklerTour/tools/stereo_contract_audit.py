#!/usr/bin/env python3
from __future__ import annotations

import re
import sys
from pathlib import Path


HERE = Path(__file__).resolve().parent
ROOT = HERE if (HERE / "app").exists() else HERE.parent

MAIN = ROOT / "app/src/main/java/com/example/maklertour/MainActivity.kt"
PHONE_RECORDER = ROOT / "app/src/main/java/com/example/maklertour/data/phonecamera/PhoneCameraVideoRecorder.kt"
STEREO_PROCESSOR = ROOT / "app/src/main/java/com/example/maklertour/data/calibration/StereoCalibrationProcessor.kt"
STEREO_CAPTURE = ROOT / "app/src/main/java/com/example/maklertour/data/phonecamera/StereoCaptureExperimental.kt"
NATIVE_UVC = ROOT / "app/src/main/cpp/cam1_uvc.cpp"
DEVICE_ORIENTATION = ROOT / "app/src/main/java/com/example/maklertour/data/phonecamera/DeviceOrientationTracker.kt"
DENSE_DEPTH = ROOT / "tools/dense_depth_from_synced_capture.py"


class Audit:
    def __init__(self) -> None:
        self.ok: list[str] = []
        self.warns: list[str] = []
        self.fails: list[str] = []

    def ok_(self, msg: str) -> None:
        self.ok.append(msg)

    def warn(self, msg: str) -> None:
        self.warns.append(msg)

    def fail(self, msg: str) -> None:
        self.fails.append(msg)

    def require(self, condition: bool, msg: str) -> None:
        if condition:
            self.ok_(msg)
        else:
            self.fail(msg)

    def report(self) -> int:
        print("=== MaklerTour stereo contract audit ===\n")

        if self.ok:
            print("OK:")
            for item in self.ok:
                print(f"  [OK] {item}")
            print()

        if self.warns:
            print("WARNINGS:")
            for item in self.warns:
                print(f"  [WARN] {item}")
            print()

        if self.fails:
            print("ERRORS:")
            for item in self.fails:
                print(f"  [FAIL] {item}")
            print("\nResult: FAIL")
            return 1

        print("Result: PASS")
        return 0


def read(path: Path, audit: Audit) -> str:
    if not path.exists():
        audit.fail(f"missing file: {path.relative_to(ROOT)}")
        return ""
    return path.read_text(encoding="utf-8", errors="replace")


def balanced_block(text: str, anchor: str) -> str:
    start = text.find(anchor)
    if start < 0:
        return ""

    brace = text.find("{", start)
    if brace < 0:
        return text[start : start + 4000]

    depth = 0
    for i in range(brace, len(text)):
        if text[i] == "{":
            depth += 1
        elif text[i] == "}":
            depth -= 1
            if depth == 0:
                return text[start : i + 1]

    return text[start : start + 8000]


def line_hits(text: str, pattern: str) -> list[str]:
    rx = re.compile(pattern)
    out: list[str] = []
    for n, line in enumerate(text.splitlines(), 1):
        if rx.search(line):
            out.append(f"{n}: {line}")
    return out


def audit_main(main: str, audit: Audit) -> None:
    screen = balanced_block(main, "private fun StereoCaptureExperimentalScreen(")
    if not screen:
        audit.fail("StereoCaptureExperimentalScreen not found")
        return

    audit.require("FrameLayout" in screen, "cam1 live preview uses FrameLayout wrapper")
    audit.require("clipChildren = true" in screen, "cam1 FrameLayout has clipChildren=true")
    audit.require("clipToPadding = true" in screen, "cam1 FrameLayout has clipToPadding=true")
    audit.require("TextureView" in screen, "cam1 live preview uses TextureView")
    audit.require("layoutCam1Preview" in screen, "cam1 live preview has manual layout helper")
    audit.require("rotation = CAM1_PREVIEW_ROTATION_DEGREES" in screen or "textureView.rotation = CAM1_PREVIEW_ROTATION_DEGREES" in screen, "cam1 TextureView is rotated as Android View")

    audit.require(".graphicsLayer { rotationZ = CAM1_PREVIEW_ROTATION_DEGREES }" not in screen, "cam1 live preview does not use Compose graphicsLayer rotation")
    audit.require(".setTransform(" not in screen and "setTransform(" not in screen, "cam1 live preview does not use TextureView.setTransform")
    audit.require("cropScale" not in screen, "cam1 live preview does not use cropScale")
    audit.require("maxOf(width / rotatedWidth" not in screen, "cam1 live preview does not use maxOf crop/fill zoom")

    # Do not relayout on every frame.
    updated = balanced_block(screen, "override fun onSurfaceTextureUpdated")
    if updated:
        audit.require("layoutCam1Preview(parent" not in updated, "cam1 layout is not recalculated on every preview frame")
        audit.require("manager.onCam1PreviewFrameRendered()" in updated, "cam1 preview still reports rendered frames")

    audit.require("PreviewView.ScaleType.FIT_CENTER" in screen, "cam0 PreviewView uses FIT_CENTER")

    audit.require("Probe phone dual camera" not in screen, "main UI does not show Probe phone dual camera")
    audit.require("Show phone dual camera probe JSON path" not in screen, "main UI does not show probe JSON path button")
    audit.require("Probe JSON:" not in screen, "main UI does not show Probe JSON output")

    for label in [
        "Record stereo video (legacy)",
        "Record synced depth frames",
        "Calibration",
        "Open settings",
        "Show diagnostics",
        "Refresh lenses",
    ]:
        audit.require(label in screen, f"main UI contains: {label}")


def audit_calibration_ui(main: str, audit: Audit) -> None:
    fn_idx = main.find("private fun PreviewBitmapPanel(")
    if fn_idx < 0:
        audit.fail("Calibration PreviewBitmapPanel exists")
        return

    # Include possible annotation lines directly above the function.
    prefix_start = max(0, fn_idx - 300)
    prefix = main[prefix_start:fn_idx]
    annotation_ok = "@Composable" in prefix.splitlines()[-5:]

    panel = balanced_block(main, "private fun PreviewBitmapPanel(")

    audit.require(bool(panel), "Calibration PreviewBitmapPanel exists")
    if not panel:
        return

    audit.require(annotation_ok, "PreviewBitmapPanel is @Composable")
    audit.require("rotateBitmapForCalibrationDisplayOnly" in main, "calibration display rotation helper exists")
    audit.require("makeCalibrationOverlayBitmapForDisplayOnly" in main, "calibration overlay bitmap helper exists")
    audit.require("displayBitmap" in panel, "PreviewBitmapPanel uses displayBitmap")
    audit.require("overlayBitmap" in panel, "PreviewBitmapPanel uses overlayBitmap")
    audit.require("graphicsLayer { rotationZ = rotationDegrees }" not in panel, "PreviewBitmapPanel does not rotate via graphicsLayer")



def audit_calibration_capture_ux(main: str, audit: Audit) -> None:
    dialog = balanced_block(main, "private fun CalibrationCaptureDialog(")
    if not dialog:
        audit.fail("CalibrationCaptureDialog not found")
        return

    audit.require("fun CalibrationInfoCard" in dialog, "Calibration dialog has collapsible CalibrationInfoCard")
    audit.require("fun CapturePreview" in dialog, "Calibration dialog has CapturePreview helper")
    audit.require("fun CaptureControls" in dialog, "Calibration dialog has CaptureControls helper")

    audit.require("if (isCapturing)" in dialog, "Calibration dialog has dedicated active capture layout")
    audit.require("Box(modifier = Modifier.fillMaxSize().background(Color.Black))" in dialog.replace("\n", " "), "Active capture layout is full-screen black Box")
    audit.require("overlay = true" in dialog, "Capture mode uses translucent info overlay")
    audit.require("Color.Black.copy(alpha = 0.65f)" in dialog, "Capture controls use translucent black overlay")
    audit.require("Color(0xFF1E2A3A).copy(alpha = 0.70f)" in dialog, "Capture info uses translucent blue overlay")

    audit.require(".clickable { calibrationInfoExpanded = !calibrationInfoExpanded }" in dialog, "Calibration info card is clickable/collapsible")
    audit.require("showFullCalibrationInfo" in dialog, "Calibration dialog forces full info for result states")

    controls = balanced_block(dialog, "fun CaptureControls")
    audit.require(bool(controls), "CaptureControls block exists")
    if controls:
        audit.require("autoCapture = !autoCapture" in controls, "CaptureControls has Auto toggle")
        audit.require("capturePair(requireValidDetection = true)" in controls, "CaptureControls has manual capture button")
        audit.require("Auto stereo" in controls, "CaptureControls labels stereo auto mode")
        audit.require("modifier = Modifier.weight(1f)" in controls, "Auto and Capture buttons are placed side-by-side")
        audit.require("onDismiss" in controls and 'Text("Cancel")' in controls, "CaptureControls has Cancel button")

    # Service/debug buttons should be guarded by IDLE branch, not shown during capture overlay.
    idle_idx = dialog.find("wizardState == CalibrationWizardState.IDLE")
    clear_idx = dialog.find("Clear old calibration sessions")
    diag_idx = dialog.find("Write diagnostics JSON")
    audit.require(clear_idx >= 0, "Calibration dialog still has Clear old calibration sessions button")
    audit.require(diag_idx >= 0, "Calibration dialog still has Write diagnostics JSON button")
    if clear_idx >= 0 and idle_idx >= 0:
        audit.require(clear_idx > idle_idx, "Clear old calibration sessions appears under IDLE branch")
    if diag_idx >= 0 and idle_idx >= 0:
        audit.require(diag_idx > idle_idx, "Write diagnostics JSON appears under IDLE branch")

    preview = balanced_block(main, "private fun PreviewBitmapPanel(")
    audit.require("Image(" in preview, "PreviewBitmapPanel uses Image for in-memory bitmaps")
    audit.require(".asImageBitmap()" in preview, "PreviewBitmapPanel uses asImageBitmap")
    audit.require("AsyncImage(" not in preview, "PreviewBitmapPanel does not use AsyncImage")
    audit.require("ContentScale.Crop" not in preview, "PreviewBitmapPanel does not use ContentScale.Crop")
    audit.require("ContentScale.FillBounds" not in preview, "PreviewBitmapPanel does not use ContentScale.FillBounds")
    audit.require("contentScale = ContentScale.Fit" in preview or "contentScale: ContentScale = ContentScale.Fit" in preview, "PreviewBitmapPanel default contentScale is Fit")

    audit.require("aspectRatio(4f / 3f)" not in dialog, "Calibration dialog no longer forces 4:3 preview aspect")

def audit_stereo(main: str, processor: str, capture: str, audit: Audit) -> None:
    audit.require("getNearestStereoCalibrationFrames(30)" in main, "Step 3 uses nearest ring-buffer stereo pair with 30 ms")
    audit.require("nearest_ring_buffer" in main, "manifest records nearest_ring_buffer")
    audit.require("stereoMaxDeltaMs" in main and "30L" in main, "manifest records stereoMaxDeltaMs=30L")

    audit.require("fun getNearestStereoCalibrationFrames" in capture, "StereoCaptureExperimental has getNearestStereoCalibrationFrames")
    audit.require("maxDeltaMs" in capture, "nearest stereo pair function accepts maxDeltaMs")
    audit.require("delta_exceeds" in capture or "best_delta_ms" in capture, "nearest stereo pair function logs/rejects bad deltas")

    audit.require("STEREO_MANUAL_MIN_COMMON_CHARUCO_IDS" in main, "MainActivity has manual stereo common-ID threshold")
    audit.require("STEREO_AUTO_MIN_COMMON_CHARUCO_IDS" in main, "MainActivity has auto stereo common-ID threshold")
    audit.require("stereoCommonCharucoIds" in main, "Step 3 computes common ChArUco IDs")
    audit.require("commonIds >= STEREO_MANUAL_MIN_COMMON_CHARUCO_IDS" in main or ">= STEREO_MANUAL_MIN_COMMON_CHARUCO_IDS" in main, "manual capture gate checks common IDs")
    audit.require("commonIds" in main and "STEREO_AUTO_MIN_COMMON_CHARUCO_IDS" in main and "autoCaptureReady" in main, "auto stereo gate checks common IDs")
    audit.require("common ids:" in main or "stereo quality:" in main, "Step 3 UI shows common IDs / stereo quality")

    audit.require("detectCharucoCorners" in processor, "StereoCalibrationProcessor detects ChArUco")
    audit.require("commonIds" in processor, "StereoCalibrationProcessor uses common ChArUco IDs")
    audit.require(".intersect(" in processor, "StereoCalibrationProcessor intersects cam0/cam1 IDs")
    audit.require("charucoCommonIdsPerPair" in processor, "StereoCalibrationProcessor reports common IDs per pair")
    audit.require("STEREO_PROCESSOR_MIN_COMMON_CHARUCO_IDS" in processor, "processor has common-ID filter threshold")
    audit.require("STEREO_OUTLIER_HARD_MAX_ERROR_PX" in processor, "processor has hard outlier error threshold")
    audit.require("STEREO_OUTLIER_MAX_ITERATIONS" in processor, "processor has iterative outlier maximum iteration count")
    audit.require("STEREO_MIN_FILTERED_PAIRS" in processor, "processor has minimum filtered pair count")
    audit.require("rejectedPairIndexes" in processor and "rejected_pair_indexes" in processor, "processor populates rejected_pair_indexes")
    audit.require("common_id_and_iterative_epipolar_error_filter" in processor, "outlier_rejection_mode uses iterative epipolar filter")
    audit.require("computePerPairEpipolarErrors(refitCam0Points, refitCam1Points, refitF, refitPairIndexes)" in processor, "processor computes final per-pair errors after refit")
    audit.require("iterative epipolar error" in processor and "%.2f" in processor and "px >" in processor, "rejectedPairReasons include epipolar error values")
    audit.require("outlierIterations" in processor and "outlier_iterations" in processor, "outlier_iterations is written to result JSON")
    audit.require(processor.count("stereoCalibrate(") >= 2, "final stereoCalibrate can run after filtering")
    audit.require("pairsCandidatesAfterCommonIdFilter" in processor and "pairsUsed = finalPairsUsed" in processor, "pairs_used can be lower than pairs_total")
    audit.require("CALIB_FIX_INTRINSIC" in processor, "stereoCalibrate uses CALIB_FIX_INTRINSIC")


def audit_saved_frames(recorder: str, audit: Audit) -> None:
    audit.require("imageProxyRotationDegrees" in recorder, "PhoneCameraVideoRecorder keeps imageProxy rotation as metadata/log")
    audit.require("val rotationDegrees = 0" in recorder or "val rotationDegrees = 0f" in recorder, "cam0 saved frames force rotationDegrees=0")

    forbidden = [
        "rotateBitmap(rawBitmap, imageProxyRotationDegrees)",
        "rawBitmap.rotate(imageProxyRotationDegrees)",
        "rotationDegrees = imageProxy.imageInfo.rotationDegrees",
    ]
    for item in forbidden:
        audit.require(item not in recorder, f"cam0 saved frames do not apply CameraX rotation: {item}")



def audit_native_uvc_shutdown(native: str, audit: Audit) -> None:
    if not native:
        return

    audit.require("g_uvc_stopping" in native and "g_uvc_alive" in native and "std::atomic<bool>" in native, "native UVC has atomic stopping/alive flags")
    audit.require("g_lifecycle_lock" in native and "std::lock_guard<std::mutex> lifecycle(g_lifecycle_lock)" in native, "native UVC stop/release paths are serialized by lifecycle mutex")
    audit.require("g_accept_frames=false" in native and "g_active_callback_state.store(nullptr" in native, "native UVC disables frame callback delivery during stop")
    audit.require("state->stopping.load" in native and "state->alive.load" in native and "g_uvc_stopping.load" in native, "native UVC callback checks alive/stopping before using shared state")

    done = native[native.find("done:") : native.find('ALOGI("streamingThread stop', native.find("done:"))]
    stop_idx = done.find("uvc_stop_streaming")
    close_idx = done.find("uvc_close")
    exit_idx = done.find("uvc_exit")
    destroy_idx = done.find("native_uvc_stop resources_destroyed")
    audit.require(stop_idx >= 0, "native UVC stop path calls uvc_stop_streaming")
    audit.require(stop_idx >= 0 and close_idx > stop_idx and exit_idx > close_idx, "native UVC stop path calls uvc_stop_streaming before uvc_close/uvc_exit")
    audit.require("g_callbacks_in_flight.load()>0" in done and (close_idx > done.find("g_callbacks_in_flight.load()>0")), "native UVC drains callbacks before resource destruction")
    audit.require(destroy_idx > exit_idx >= 0, "native UVC logs resources_destroyed after uvc_exit")

    joiner = balanced_block(native, "bool joinStreamThreadLocked")
    audit.require("g_thread.join()" in joiner, "native UVC event/stream thread is joined")
    audit.require("native_uvc_stop begin" in joiner, "native UVC stop logs begin")
    audit.require("native_uvc_stop event_thread_joined" in joiner, "native UVC stop logs event_thread_joined")
    audit.require("native_uvc_stop end" in joiner, "native UVC stop logs end")
    audit.require("timeout_warning" in native, "native UVC stop has timeout warning logs for slow drains/joins")

def audit_imports(main: str, audit: Audit) -> None:
    android_matrix = "import android.graphics.Matrix" in main
    compose_matrix = "import androidx.compose.ui.graphics.Matrix" in main

    if android_matrix and compose_matrix:
        audit.fail("Matrix import conflict: both android.graphics.Matrix and androidx.compose.ui.graphics.Matrix")
    else:
        audit.ok_("no Matrix import conflict")

    # android.graphics.Matrix may be used by calibration display bitmap helper; warn only.
    if "android.graphics.Matrix" in main:
        audit.warn("android.graphics.Matrix is used somewhere; OK for calibration bitmap helper, not OK for cam1 TextureView.setTransform")




def audit_orientation_metadata(main: str, docs: str, tracker: str, audit: Audit) -> None:
    combined = main + "\n" + tracker
    audit.require("DeviceOrientationTracker" in combined or "Sensor.TYPE_GRAVITY" in combined or "Sensor.TYPE_ACCELEROMETER" in combined, "IMU physical orientation tracker exists")
    audit.require("Sensor.TYPE_GRAVITY" in combined and "Sensor.TYPE_ACCELEROMETER" in combined, "orientation tracker prefers gravity and falls back to accelerometer")
    audit.require("nearestSample(timestampNs" in tracker or "fun nearestSample(timestampNs" in tracker, "orientation tracker exposes nearestSample(timestampNs)")
    audit.require("event.timestamp" in tracker and "timestampNs" in tracker, "orientation tracker stores SensorEvent.timestamp in samples")
    audit.require("pair_orientation_timestamp_ns" in main and "(pair.cam0.timestampNs + pair.cam1.timestampNs) / 2L" in main, "syncedDepthPairMeta writes pair_orientation_timestamp_ns from cam0/cam1 midpoint")
    audit.require("physical_orientation" in main and "syncedDepthPairMeta" in main, "syncedDepthPairMeta writes physical_orientation")
    audit.require("imu_sample_timestamp_ns" in main, "metadata writes imu_sample_timestamp_ns")
    audit.require("imu_sample_delta_ms" in main, "metadata writes imu_sample_delta_ms")
    audit.require("imu_gravity_x" in main and "imu_gravity_y" in main and "imu_gravity_z" in main, "metadata writes imu_gravity_x/y/z")
    audit.require("physical_orientation_source" in main and "physical_orientation_confidence" in main and "imu_orientation_stale" in main, "metadata writes physical orientation source/confidence/stale")
    audit.require("config_orientation" in main and "display_rotation_degrees" in main, "metadata writes config_orientation and display_rotation_degrees")
    audit.require("readJsonObjectOrNull" in main, "readJsonObjectOrNull exists")
    audit.require("appendSyncedDepthManifestPair" in main and "appendSyncedDepthManifestPair" in main[main.find("readJsonObjectOrNull"):] or "readJsonObjectOrNull(manifestFile" in balanced_block(main, "private fun appendSyncedDepthManifestPair"), "appendSyncedDepthManifestPair uses readJsonObjectOrNull")
    audit.require("readJsonObjectOrNull" in balanced_block(main, "private fun writeSyncedDepthManifest"), "writeSyncedDepthManifest uses readJsonObjectOrNull")
    audit.require("writeJsonObjectAtomic" in main and ".tmp" in main and "renameTo" in main, "manifests use atomic tmp+rename JSON writes")
    audit.require("first_pair_physical_orientation" in main and "last_pair_physical_orientation" in main and "physical_orientation_counts" in main, "root synced depth manifest records first/last/count physical orientation")
    audit.require("config_orientation_counts" in main, "root synced depth manifest records config_orientation_counts")
    audit.require("orientation_transition_count" in main, "root synced depth manifest records orientation_transition_count")
    audit.require("IMU physical orientation" in docs and "diagnostics" in docs and "must not rotate" in docs, "docs mention IMU orientation diagnostics only")

def audit_depth_axis_contract(audit: Audit) -> None:
    rectify = read(ROOT / "tools/rectify_synced_depth_capture.py", audit)
    dense = read(DENSE_DEPTH, audit)
    docs = read(ROOT / "docs/APP_CAMERA_STEREO_CONTRACT.md", audit)
    root_audit = read(ROOT / "stereo_contract_audit.py", audit)

    if rectify:
        audit.require("rectified_baseline_axis" in rectify or "disparity_axis" in rectify, "rectify/depth script records rectified_baseline_axis or disparity_axis")
        audit.require("p2_tx" in rectify and "p2_ty" in rectify and "abs(p2_tx) >= abs(p2_ty)" in rectify, "rectify/depth script detects axis from P2 tx/ty")
        audit.require("rectified_baseline_axis == 'vertical'" in rectify, "rectify/depth script has vertical baseline branch")
        audit.require("rotate_90_ccw" in rectify and "rotate_90_cw" in rectify, "rectify/depth script rotates vertical baseline inputs both directions")
        audit.require("q_valid_for_rotated_disparity" in rectify and "vertical_rotated_manual_z" in rectify, "rectify/depth script does not fake Q depth for rotated vertical disparity")

    if dense:
        audit.require("cv2.StereoSGBM_create" in dense, "dense script uses StereoSGBM")
        audit.require("p2_tx" in dense and "p2_ty" in dense and "abs(p2_tx) >= abs(p2_ty)" in dense, "dense script detects axis via P2[0,3]/P2[1,3]")
        audit.require("baseline_axis=='vertical'" in dense or "baseline_axis == 'vertical'" in dense or "rectified_baseline_axis == 'vertical'" in dense, "dense script has vertical branch")
        audit.require("rotate_90_ccw" in dense and "rotate_90_cw" in dense, "dense script has rotate_90_ccw / rotate_90_cw")
        audit.require("q_valid_for_rotated_disparity" in dense, "dense script writes q_valid_for_rotated_disparity")
        audit.require("vertical_rotated_manual_z" in dense, "dense script writes vertical_rotated_manual_z")
        audit.require("focal_for_depth*baseline_magnitude" in dense.replace(" ", "") or "f * B / disparity" in dense, "dense script computes Z = f * B / disparity or equivalent")
        audit.require("dense_depth_debug.json" in dense, "dense script writes dense_depth_debug.json")

    if docs:
        audit.require(("vertical baseline" in docs or "vertical rectified baseline" in docs) and ("P2[0,3]" in docs or "P2[0, 3]" in docs) and ("P2[1,3]" in docs or "P2[1, 3]" in docs), "docs describe P2/T baseline-axis detection")
        audit.require("must not be reused blindly" in docs, "docs warn against blindly reusing Q after rotation")
        audit.require("raw saved frames remain unrotated" in docs.lower() or "remain unrotated raw frames" in docs.lower(), "docs preserve unrotated saved-frame contract")

    if root_audit:
        audit.require("tools" in root_audit and "stereo_contract_audit.py" in root_audit, "root stereo_contract_audit.py delegates to tools audit")

def main() -> int:
    audit = Audit()

    main_text = read(MAIN, audit)
    recorder_text = read(PHONE_RECORDER, audit)
    processor_text = read(STEREO_PROCESSOR, audit)
    capture_text = read(STEREO_CAPTURE, audit)
    native_uvc_text = read(NATIVE_UVC, audit)
    tracker_text = read(DEVICE_ORIENTATION, audit)
    docs_text = read(ROOT / "docs/APP_CAMERA_STEREO_CONTRACT.md", audit)

    if main_text:
        audit_imports(main_text, audit)
        audit_main(main_text, audit)
        audit_calibration_ui(main_text, audit)
        audit_calibration_capture_ux(main_text, audit)
        audit_orientation_metadata(main_text, docs_text, tracker_text, audit)

    if main_text and processor_text and capture_text:
        audit_stereo(main_text, processor_text, capture_text, audit)

    audit_native_uvc_shutdown(native_uvc_text, audit)

    if recorder_text:
        audit_saved_frames(recorder_text, audit)

    audit_depth_axis_contract(audit)

    return audit.report()


if __name__ == "__main__":
    raise SystemExit(main())

