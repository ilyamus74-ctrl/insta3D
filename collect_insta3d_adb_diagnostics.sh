#!/usr/bin/env bash
set -Eeuo pipefail

PKG="${PKG:-com.maklertour}"
OUT_ROOT="${OUT_ROOT:-$HOME/logs/insta3d}"
SERIAL="${SERIAL:-}"
RESTART_APP=1

usage() {
    cat <<'EOF'
Usage:
  ./collect_insta3d_adb_diagnostics.sh [options]

Options:
  -s, --serial SERIAL     ADB serial. If omitted, script shows an interactive list.
  -p, --package PACKAGE   Android package (default: com.maklertour).
  -o, --output DIR        Output root (default: ~/logs/insta3d).
      --no-restart        Do not force-stop/relaunch the application.
  -h, --help              Show help.

Workflow:
  1. Select the Slave phone.
  2. Script optionally restarts the app and clears logcat.
  3. Reproduce CONNECT -> ARM once.
  4. Return to the terminal and press Enter.
  5. Script collects logs, dumpsys, screenshot and app capture files.
  6. Upload the resulting .tar.gz archive for analysis.
EOF
}

die() {
    echo "[ERROR] $*" >&2
    exit 1
}

log() {
    echo "[INFO] $*"
}

cleanup() {
    if [[ -n "${LOGCAT_PID:-}" ]]; then
        kill "$LOGCAT_PID" 2>/dev/null || true
        wait "$LOGCAT_PID" 2>/dev/null || true
    fi
}
trap cleanup EXIT INT TERM

while (($#)); do
    case "$1" in
        -s|--serial)
            [[ $# -ge 2 ]] || die "Missing value for $1"
            SERIAL="$2"
            shift 2
            ;;
        -p|--package)
            [[ $# -ge 2 ]] || die "Missing value for $1"
            PKG="$2"
            shift 2
            ;;
        -o|--output)
            [[ $# -ge 2 ]] || die "Missing value for $1"
            OUT_ROOT="$2"
            shift 2
            ;;
        --no-restart)
            RESTART_APP=0
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            die "Unknown option: $1"
            ;;
    esac
done

command -v adb >/dev/null 2>&1 || die "adb is not installed or not in PATH"
command -v tar >/dev/null 2>&1 || die "tar is not installed"

adb start-server >/dev/null

mapfile -t DEVICES < <(
    adb devices -l |
        awk 'NR > 1 && $2 == "device" {
            model="unknown"
            product="unknown"
            for (i=3; i<=NF; i++) {
                if ($i ~ /^model:/) { sub(/^model:/, "", $i); model=$i }
                if ($i ~ /^product:/) { sub(/^product:/, "", $i); product=$i }
            }
            print $1 "|" model "|" product
        }'
)

((${#DEVICES[@]} > 0)) || die "No authorized ADB devices found"

if [[ -z "$SERIAL" ]]; then
    if ((${#DEVICES[@]} == 1)); then
        SERIAL="${DEVICES[0]%%|*}"
    else
        echo "Available ADB devices:"
        for i in "${!DEVICES[@]}"; do
            IFS='|' read -r serial model product <<<"${DEVICES[$i]}"
            printf '  %d) %-20s model=%-24s product=%s\n' \
                "$((i + 1))" "$serial" "$model" "$product"
        done
        read -r -p "Select Slave device [1-${#DEVICES[@]}]: " CHOICE
        [[ "$CHOICE" =~ ^[0-9]+$ ]] || die "Invalid selection"
        ((CHOICE >= 1 && CHOICE <= ${#DEVICES[@]})) || die "Selection out of range"
        SERIAL="${DEVICES[$((CHOICE - 1))]%%|*}"
    fi
fi

adb -s "$SERIAL" get-state >/dev/null 2>&1 ||
    die "Device $SERIAL is not available"

MODEL="$(adb -s "$SERIAL" shell getprop ro.product.model | tr -d '\r')"
ANDROID="$(adb -s "$SERIAL" shell getprop ro.build.version.release | tr -d '\r')"
SDK="$(adb -s "$SERIAL" shell getprop ro.build.version.sdk | tr -d '\r')"

if ! adb -s "$SERIAL" shell pm path "$PKG" >/dev/null 2>&1; then
    log "Package $PKG was not found. Searching likely package names..."
    mapfile -t CANDIDATES < <(
        adb -s "$SERIAL" shell pm list packages |
            tr -d '\r' |
            sed 's/^package://' |
            grep -Ei 'makler|insta3d|insta' || true
    )
    if ((${#CANDIDATES[@]} == 1)); then
        PKG="${CANDIDATES[0]}"
        log "Using detected package: $PKG"
    elif ((${#CANDIDATES[@]} > 1)); then
        echo "Possible packages:"
        printf '  %s\n' "${CANDIDATES[@]}"
        read -r -p "Enter package name: " PKG
    else
        die "Package $PKG is not installed and no likely package was found"
    fi
fi

TS="$(date +%Y%m%d_%H%M%S)"
SAFE_SERIAL="${SERIAL//[^A-Za-z0-9._-]/_}"
RUN_DIR="$OUT_ROOT/slave_${SAFE_SERIAL}_${TS}"
mkdir -p "$RUN_DIR"

META="$RUN_DIR/metadata.txt"
{
    echo "timestamp_local=$(date --iso-8601=seconds)"
    echo "serial=$SERIAL"
    echo "model=$MODEL"
    echo "android=$ANDROID"
    echo "sdk=$SDK"
    echo "package=$PKG"
    echo
    echo "===== adb version ====="
    adb version
    echo
    echo "===== adb devices -l ====="
    adb devices -l
    echo
    echo "===== package version ====="
    adb -s "$SERIAL" shell dumpsys package "$PKG" |
        grep -E 'versionName=|versionCode=|firstInstallTime=|lastUpdateTime=' || true
    echo
    echo "===== selected properties ====="
    adb -s "$SERIAL" shell getprop |
        grep -E '\[(ro.product|ro.build|ro.hardware|ro.boot|persist.camera|vendor.camera)' || true
} >"$META" 2>&1

log "Selected Slave: serial=$SERIAL model=$MODEL Android=$ANDROID SDK=$SDK"
log "Package: $PKG"
log "Output directory: $RUN_DIR"

if ((RESTART_APP == 1)); then
    log "Force-stopping and launching the application..."
    adb -s "$SERIAL" shell am force-stop "$PKG" || true
    sleep 1
    adb -s "$SERIAL" shell monkey -p "$PKG" 1 \
        >"$RUN_DIR/app_launch.txt" 2>&1 || true
    sleep 3
fi

adb -s "$SERIAL" logcat -c
log "Starting full logcat capture..."
adb -s "$SERIAL" logcat -b all -v threadtime \
    >"$RUN_DIR/logcat_full.log" 2>&1 &
LOGCAT_PID=$!

cat <<EOF

============================================================
On the Slave phone:
  1. Open the dual-phone settings screen.
  2. Verify that the preview is visible.
  3. CONNECT to the Master.
  4. Press ARM on the Master exactly once.
  5. Wait for success or error and another 5 seconds.

Then return here and press Enter.
============================================================
EOF

read -r -p "Press Enter after the ARM test is complete... " _

kill "$LOGCAT_PID" 2>/dev/null || true
wait "$LOGCAT_PID" 2>/dev/null || true
unset LOGCAT_PID

log "Building filtered application/camera log..."
grep -Ei \
'com\.maklertour|PhoneCameraVideoRecorder|PhoneCameraScanProvider|DualPhoneControlManager|DualPhoneRecorderPreviewRegistry|DualPhoneCapture|VideoRecordEvent|CameraX|Recorder|VideoEncoder|AudioEncoder|MediaCodec|MediaMuxer|Camera2|CameraManager|CameraDevice|CameraCaptureSession|ProcessCameraProvider|PreviewView|StreamState|AndroidRuntime|FATAL EXCEPTION|ANR' \
"$RUN_DIR/logcat_full.log" \
    >"$RUN_DIR/logcat_filtered.log" || true

log "Collecting dumpsys and screenshots..."
adb -s "$SERIAL" shell dumpsys media.camera \
    >"$RUN_DIR/dumpsys_media_camera.txt" 2>&1 || true
adb -s "$SERIAL" shell dumpsys activity activities \
    >"$RUN_DIR/dumpsys_activity_activities.txt" 2>&1 || true
adb -s "$SERIAL" shell dumpsys activity processes \
    >"$RUN_DIR/dumpsys_activity_processes.txt" 2>&1 || true
adb -s "$SERIAL" shell dumpsys gfxinfo "$PKG" \
    >"$RUN_DIR/dumpsys_gfxinfo.txt" 2>&1 || true
adb -s "$SERIAL" shell dumpsys SurfaceFlinger --list \
    >"$RUN_DIR/surfaceflinger_layers.txt" 2>&1 || true
adb -s "$SERIAL" exec-out screencap -p \
    >"$RUN_DIR/screenshot.png" 2>/dev/null || true

PID="$(adb -s "$SERIAL" shell pidof -s "$PKG" | tr -d '\r' || true)"
{
    echo "pid=${PID:-not_running}"
    adb -s "$SERIAL" shell ps -A | grep -F "$PKG" || true
} >"$RUN_DIR/process_snapshot.txt" 2>&1

log "Collecting application-private dual-phone captures with run-as..."
if adb -s "$SERIAL" shell run-as "$PKG" id >/dev/null 2>&1; then
    adb -s "$SERIAL" exec-out run-as "$PKG" sh -c \
        'cd files && find dual_phone_captures -maxdepth 5 -type f -exec ls -ln {} \; 2>/dev/null' \
        >"$RUN_DIR/app_capture_file_list.txt" 2>&1 || true

    adb -s "$SERIAL" exec-out run-as "$PKG" sh -c \
        'cd files && tar cf - dual_phone_captures 2>/dev/null' \
        >"$RUN_DIR/dual_phone_captures.tar" 2>"$RUN_DIR/run_as_capture_error.txt" || true

    if [[ ! -s "$RUN_DIR/dual_phone_captures.tar" ]]; then
        rm -f "$RUN_DIR/dual_phone_captures.tar"
    fi
else
    echo "run-as is unavailable; APK may not be debuggable" \
        >"$RUN_DIR/run_as_capture_error.txt"
fi

log "Generating quick summary..."
{
    echo "===== recorder-related lines ====="
    grep -Ei \
'RECORDER_ATTEMPT|ARM_RECORDING|CAPTURE_ABORTED|RECORDER_STATE_RESET|valid encoded|No valid encoded|recording finalize failed|ERROR_NO_VALID_DATA|ERROR_SOURCE_INACTIVE|ERROR_ENCODING_FAILED|PreviewView|STREAMING|stream_state|Another dual-phone capture' \
        "$RUN_DIR/logcat_filtered.log" || true
    echo
    echo "===== files ====="
    find "$RUN_DIR" -maxdepth 1 -type f -printf '%f|%s bytes\n' | sort
} >"$RUN_DIR/summary.txt"

ARCHIVE="${RUN_DIR}.tar.gz"
tar -C "$(dirname "$RUN_DIR")" -czf "$ARCHIVE" "$(basename "$RUN_DIR")"

SHA256="$(sha256sum "$ARCHIVE" | awk '{print $1}')"

echo
echo "Done."
echo "Archive: $ARCHIVE"
echo "SHA-256: $SHA256"
echo
echo "Upload this file for analysis:"
echo "$ARCHIVE"
