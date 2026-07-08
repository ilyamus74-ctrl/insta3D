#!/bin/bash


SER=192.168.2.217:5555
PKG=com.maklertour
TS=$(date +%Y%m%d_%H%M%S)
OUT="calib_debug_$TS"

mkdir -p "$OUT"

adb -s "$SER" logcat -d -v time > "$OUT/logcat_full.txt"

adb -s "$SER" logcat -d -v time | grep -Ei \
"PhoneCameraVideoRecorder|CalibrationCapture|StereoCalibration|CalibrationResult|stereo_rms|corner_order|checkerboard|cam0 calibration|cam1|UVC|TurboJPEG|OpenCV|FATAL|Exception|error|failed|distortion_model|per_pair|epipolar|worst" \
> "$OUT/logcat_filtered.txt" || true

adb -s "$SER" exec-out run-as "$PKG" sh -c '
cd files || exit 1
LATEST=$(ls -td calibration_sessions/* 2>/dev/null | head -1)
tar -czf - "$LATEST"
' > "$OUT/calibration_session_latest.tgz"

adb -s "$SER" exec-out run-as "$PKG" sh -c '
cd files || exit 1
LATEST=$(ls -td calibration_sessions/* 2>/dev/null | head -1)
echo "LATEST=$LATEST"
find "$LATEST" -maxdepth 3 -type f | sort
echo
echo "--- cam0_intrinsics ---"
cat "$LATEST/cam0_intrinsics.json" 2>/dev/null || true
echo
echo "--- cam1_intrinsics ---"
cat "$LATEST/cam1_intrinsics.json" 2>/dev/null || true
echo
echo "--- stereo_extrinsics ---"
cat "$LATEST/stereo_extrinsics.json" 2>/dev/null || true
echo
echo "--- pairs_manifest ---"
cat "$LATEST/pairs_manifest.json" 2>/dev/null || true
' > "$OUT/calibration_text_dump.txt"

tar -czf "$OUT.tgz" "$OUT"
ls -lh "$OUT.tgz"