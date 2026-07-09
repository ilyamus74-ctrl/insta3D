#!/bin/bash
set -euo pipefail

SER="${SER:-192.168.2.217:5555}"
PKG="${PKG:-com.maklertour}"
TS=$(date +%Y%m%d_%H%M%S)
OUT="video_debug_$TS"

mkdir -p "$OUT"

echo "== Device =="
adb -s "$SER" get-state
adb -s "$SER" shell date | tee "$OUT/device_date.txt"

echo "== Logcat =="
adb -s "$SER" logcat -d -v time > "$OUT/logcat_full.txt"

adb -s "$SER" logcat -d -v time | grep -Ei \
"StereoCapture|synced|depth|disparity|rectif|rectify|Record synced|recording|frame|pair|delta|timestamp|pts|cam0|cam1|UVC|MJPEG|YUYV|TurboJPEG|OpenCV|Calibration|stereo_rms|epipolar|per_pair|worst|PhoneCameraVideoRecorder|orientation|imu|gravity|physical_orientation|rotation|ActivityInfo|display|portrait|landscape|FATAL|Exception|error|failed|pthread_mutex|destroyed mutex|libuvc|libusb" \
> "$OUT/logcat_filtered.txt" || true

echo "== App files tree =="
adb -s "$SER" exec-out run-as "$PKG" sh -c '
cd files || exit 1
echo "PWD=$(pwd)"
echo
find . -maxdepth 6 -type f | sort | while read f; do
  ls -lah "$f"
done
' > "$OUT/app_files_tree.txt" || true

echo "== Latest calibration =="
adb -s "$SER" exec-out run-as "$PKG" sh -c '
cd files || exit 1
LATEST_CALIB=$(ls -td calibration_sessions/* 2>/dev/null | head -1 || true)
echo "LATEST_CALIB=$LATEST_CALIB"

if [ -n "$LATEST_CALIB" ] && [ -d "$LATEST_CALIB" ]; then
  echo
  find "$LATEST_CALIB" -maxdepth 4 -type f | sort

  for f in \
    calibration_input.json \
    cam0_intrinsics.json \
    cam1_intrinsics.json \
    stereo_extrinsics.json \
    calibration_result.json \
    pairs_manifest.json
  do
    echo
    echo "===== $LATEST_CALIB/$f ====="
    cat "$LATEST_CALIB/$f" 2>/dev/null || true
  done
fi
' > "$OUT/calibration_text_dump.txt" || true

adb -s "$SER" exec-out run-as "$PKG" sh -c '
cd files || exit 1
LATEST_CALIB=$(ls -td calibration_sessions/* 2>/dev/null | head -1 || true)
if [ -n "$LATEST_CALIB" ] && [ -d "$LATEST_CALIB" ]; then
  tar -czf - "$LATEST_CALIB"
fi
' > "$OUT/calibration_session_latest.tgz" || true

echo "== Latest synced/depth/video recording =="
adb -s "$SER" exec-out run-as "$PKG" sh -c '
cd files || exit 1

# Find latest directory that contains stereo pair images.
PAIR_FILE=$(
  find . -maxdepth 7 -type f 2>/dev/null \
    | grep -Ei "pair_.*cam0.*\.(jpg|jpeg|png)$|cam0.*\.(jpg|jpeg|png)$" \
    | xargs -r ls -t 2>/dev/null \
    | head -1
)

if [ -n "$PAIR_FILE" ]; then
  PAIR_DIR=$(dirname "$PAIR_FILE")

  # If latest target is ".../pairs", archive its parent session.
  if [ "$(basename "$PAIR_DIR")" = "pairs" ]; then
    LATEST_RECORDING=$(dirname "$PAIR_DIR")
  else
    LATEST_RECORDING="$PAIR_DIR"
  fi
else
  LATEST_RECORDING=$(
    find . -maxdepth 6 -type d 2>/dev/null \
      | grep -Ei "depth|synced|video|record|capture|validation|stereo" \
      | grep -Ev "/pairs$" \
      | xargs -r ls -td 2>/dev/null \
      | head -1
  )
fi

echo "PAIR_FILE=$PAIR_FILE"
echo "LATEST_RECORDING=$LATEST_RECORDING"

if [ -n "$LATEST_RECORDING" ] && [ -d "$LATEST_RECORDING" ]; then
  echo
  echo "--- recording files ---"
  find "$LATEST_RECORDING" -maxdepth 6 -type f | sort | while read f; do
    ls -lah "$f"
  done

  echo
  echo "--- JSON/TXT/CSV/LOG dump ---"
  find "$LATEST_RECORDING" -maxdepth 6 -type f \
    \( -name "*.json" -o -name "*.txt" -o -name "*.csv" -o -name "*.log" \) \
    | sort \
    | while read f; do
        echo
        echo "===== $f ====="
        cat "$f" 2>/dev/null || true
      done
fi
' > "$OUT/recording_text_dump.txt" || true

adb -s "$SER" exec-out run-as "$PKG" sh -c '
cd files || exit 1

PAIR_FILE=$(
  find . -maxdepth 7 -type f 2>/dev/null \
    | grep -Ei "pair_.*cam0.*\.(jpg|jpeg|png)$|cam0.*\.(jpg|jpeg|png)$" \
    | xargs -r ls -t 2>/dev/null \
    | head -1
)

if [ -n "$PAIR_FILE" ]; then
  PAIR_DIR=$(dirname "$PAIR_FILE")
  if [ "$(basename "$PAIR_DIR")" = "pairs" ]; then
    LATEST_RECORDING=$(dirname "$PAIR_DIR")
  else
    LATEST_RECORDING="$PAIR_DIR"
  fi
else
  LATEST_RECORDING=$(
    find . -maxdepth 6 -type d 2>/dev/null \
      | grep -Ei "depth|synced|video|record|capture|validation|stereo" \
      | grep -Ev "/pairs$" \
      | xargs -r ls -td 2>/dev/null \
      | head -1
  )
fi

if [ -n "$LATEST_RECORDING" ] && [ -d "$LATEST_RECORDING" ]; then
  tar -czf - "$LATEST_RECORDING"
fi
' > "$OUT/recording_latest_full.tgz" || true

echo "== Profiles/configs =="
adb -s "$SER" exec-out run-as "$PKG" sh -c '
cd files || exit 1

echo "--- all profile/config candidates ---"
find . -maxdepth 6 -type f \
  \( -name "*profile*.json" -o -name "*rig*.json" -o -name "*calib*.json" -o -name "*depth*.json" -o -name "*diagnostic*.json" -o -name "*settings*.json" -o -name "*config*.json" \) \
  | sort \
  | while read f; do
      echo
      echo "===== $f ====="
      cat "$f" 2>/dev/null || true
    done
' > "$OUT/profile_and_config_dump.txt" || true

echo "== Package archive =="
tar -czf "$OUT.tgz" "$OUT"

ls -lh "$OUT.tgz"
echo "DONE: $OUT.tgz"