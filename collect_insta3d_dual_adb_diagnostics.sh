#!/usr/bin/env bash
set -Eeuo pipefail

PKG="${PKG:-com.maklertour}"
OUT_ROOT="${OUT_ROOT:-$HOME/logs/insta3d}"
MASTER_SERIAL="${MASTER_SERIAL:-}"
SLAVE_SERIAL="${SLAVE_SERIAL:-}"
SINGLE_SERIAL="${SERIAL:-}"
BOTH=0
FULL=0
RESTART_APP=1
LOG_PIDS=()

usage() {
    cat <<'USAGE'
Usage:
  ./collect_insta3d_dual_adb_diagnostics.sh --both [options]
  ./collect_insta3d_dual_adb_diagnostics.sh --serial SERIAL [options]

Options:
      --both               Capture Master and Slave in parallel.
      --master SERIAL      Master ADB serial for --both mode.
      --slave SERIAL       Slave ADB serial for --both mode.
  -s, --serial SERIAL      Capture one device only.
  -p, --package PACKAGE   Android package (default: com.maklertour).
  -o, --output DIR        Output root (default: ~/logs/insta3d).
      --no-restart        Keep the current application processes.
      --full              Also save full system logcat and complete capture tar with MP4.
  -h, --help              Show help.

Default mode is lightweight: application log, filtered camera/control log, dumpsys,
screenshot and latest text telemetry. MP4 and full system logcat are excluded.
USAGE
}

die() {
    echo "[ERROR] $*" >&2
    exit 1
}

log() {
    echo "[INFO] $*"
}

cleanup() {
    local pid
    for pid in "${LOG_PIDS[@]:-}"; do
        kill "$pid" 2>/dev/null || true
        wait "$pid" 2>/dev/null || true
    done
}
trap cleanup EXIT INT TERM

while (($#)); do
    case "$1" in
        --both)
            BOTH=1
            shift
            ;;
        --master)
            [[ $# -ge 2 ]] || die "Missing value for $1"
            MASTER_SERIAL="$2"
            shift 2
            ;;
        --slave)
            [[ $# -ge 2 ]] || die "Missing value for $1"
            SLAVE_SERIAL="$2"
            shift 2
            ;;
        -s|--serial)
            [[ $# -ge 2 ]] || die "Missing value for $1"
            SINGLE_SERIAL="$2"
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
        --full)
            FULL=1
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
command -v sha256sum >/dev/null 2>&1 || die "sha256sum is not installed"
adb start-server >/dev/null

mapfile -t DEVICES < <(
    adb devices -l | awk 'NR > 1 && $2 == "device" {
        model="unknown"; product="unknown"
        for (i=3; i<=NF; i++) {
            if ($i ~ /^model:/) { sub(/^model:/, "", $i); model=$i }
            if ($i ~ /^product:/) { sub(/^product:/, "", $i); product=$i }
        }
        print $1 "|" model "|" product
    }'
)
((${#DEVICES[@]} > 0)) || die "No authorized ADB devices found"

show_devices() {
    echo "Available ADB devices:"
    local i serial model product
    for i in "${!DEVICES[@]}"; do
        IFS='|' read -r serial model product <<<"${DEVICES[$i]}"
        printf '  %d) %-20s model=%-24s product=%s\n' \
            "$((i + 1))" "$serial" "$model" "$product"
    done
}

select_device() {
    local prompt="$1"
    local excluded="${2:-}"
    local choice selected
    while true; do
        read -r -p "$prompt [1-${#DEVICES[@]}]: " choice
        [[ "$choice" =~ ^[0-9]+$ ]] || continue
        ((choice >= 1 && choice <= ${#DEVICES[@]})) || continue
        selected="${DEVICES[$((choice - 1))]%%|*}"
        [[ -z "$excluded" || "$selected" != "$excluded" ]] || {
            echo "Select a different device." >&2
            continue
        }
        printf '%s' "$selected"
        return
    done
}

if ((BOTH == 1)); then
    ((${#DEVICES[@]} >= 2)) || die "--both requires at least two ADB devices"
    if [[ -z "$MASTER_SERIAL" || -z "$SLAVE_SERIAL" ]]; then
        show_devices
        [[ -n "$MASTER_SERIAL" ]] || MASTER_SERIAL="$(select_device 'Select Master')"
        [[ -n "$SLAVE_SERIAL" ]] || SLAVE_SERIAL="$(select_device 'Select Slave' "$MASTER_SERIAL")"
    fi
    [[ "$MASTER_SERIAL" != "$SLAVE_SERIAL" ]] || die "Master and Slave serials are identical"
    ROLES=("master:$MASTER_SERIAL" "slave:$SLAVE_SERIAL")
else
    if [[ -z "$SINGLE_SERIAL" ]]; then
        if ((${#DEVICES[@]} == 1)); then
            SINGLE_SERIAL="${DEVICES[0]%%|*}"
        else
            show_devices
            SINGLE_SERIAL="$(select_device 'Select device')"
        fi
    fi
    ROLES=("device:$SINGLE_SERIAL")
fi

for entry in "${ROLES[@]}"; do
    serial="${entry#*:}"
    adb -s "$serial" get-state >/dev/null 2>&1 || die "Device $serial is unavailable"
    adb -s "$serial" shell pm path "$PKG" >/dev/null 2>&1 ||
        die "Package $PKG is not installed on $serial"
done

TS="$(date +%Y%m%d_%H%M%S)"
RUN_NAME="$([[ $BOTH -eq 1 ]] && echo dual || echo single)_${TS}"
RUN_DIR="$OUT_ROOT/$RUN_NAME"
mkdir -p "$RUN_DIR"

FILTER_REGEX='com\.maklertour|PhoneCameraVideoRecorder|PhoneCameraScanProvider|DualPhoneControlManager|DualPhoneBundle|DualPhoneCapture|DualPhoneLocalTimeline|VideoRecordEvent|CameraX|Recorder|VideoEncoder|MediaCodec|MediaMuxer|Camera2|CameraManager|CameraDevice|CameraCaptureSession|ProcessCameraProvider|PreviewView|StreamState|AndroidRuntime|FATAL EXCEPTION|ANR|RECORDER_ATTEMPT|CAPTURE_WINDOW|PHYSICAL_RECORDING|role package|aggregate bundle'

prepare_device() {
    local role="$1" serial="$2" dir="$3"
    mkdir -p "$dir"
    local model android sdk
    model="$(adb -s "$serial" shell getprop ro.product.model | tr -d '\r')"
    android="$(adb -s "$serial" shell getprop ro.build.version.release | tr -d '\r')"
    sdk="$(adb -s "$serial" shell getprop ro.build.version.sdk | tr -d '\r')"
    {
        echo "timestamp_local=$(date --iso-8601=seconds)"
        echo "role=$role"
        echo "serial=$serial"
        echo "model=$model"
        echo "android=$android"
        echo "sdk=$sdk"
        echo "package=$PKG"
        echo "collector_mode=$([[ $FULL -eq 1 ]] && echo full || echo lightweight)"
        echo
        adb -s "$serial" shell dumpsys package "$PKG" |
            grep -E 'versionName=|versionCode=|firstInstallTime=|lastUpdateTime=' || true
    } >"$dir/metadata.txt" 2>&1

    if ((RESTART_APP == 1)); then
        adb -s "$serial" shell am force-stop "$PKG" || true
        sleep 1
        adb -s "$serial" shell monkey -p "$PKG" 1 >"$dir/app_launch.txt" 2>&1 || true
    fi
}

start_logs() {
    local serial="$1" dir="$2"
    adb -s "$serial" logcat -c || true
    local pid
    pid="$(adb -s "$serial" shell pidof -s "$PKG" | tr -d '\r' || true)"
    if [[ -n "$pid" ]]; then
        adb -s "$serial" logcat --pid="$pid" -v threadtime >"$dir/logcat_app.log" 2>&1 &
        LOG_PIDS+=("$!")
    else
        echo "Application PID was unavailable at collector start" >"$dir/logcat_app.log"
    fi
    adb -s "$serial" logcat -b all -v threadtime 2>&1 |
        grep --line-buffered -Ei "$FILTER_REGEX" >"$dir/logcat_filtered.log" &
    LOG_PIDS+=("$!")
    if ((FULL == 1)); then
        adb -s "$serial" logcat -b all -v threadtime >"$dir/logcat_full.log" 2>&1 &
        LOG_PIDS+=("$!")
    fi
}

for entry in "${ROLES[@]}"; do
    role="${entry%%:*}"
    serial="${entry#*:}"
    safe_serial="${serial//[^A-Za-z0-9._-]/_}"
    dir="$RUN_DIR/${role}_${safe_serial}"
    prepare_device "$role" "$serial" "$dir"
done

sleep 3
for entry in "${ROLES[@]}"; do
    role="${entry%%:*}"
    serial="${entry#*:}"
    safe_serial="${serial//[^A-Za-z0-9._-]/_}"
    start_logs "$serial" "$RUN_DIR/${role}_${safe_serial}"
done

cat <<'INSTRUCTIONS'

============================================================
Run one complete test now:
  1. Open the dual-phone screen on both phones.
  2. CONNECT.
  3. ARM and verify ● REC on both phones.
  4. MARK START.
  5. Record for 15-30 seconds.
  6. STOP.
  7. Wait until ● REC disappears and package/transfer status settles.
  8. Wait another 5 seconds, then return here.
============================================================
INSTRUCTIONS
read -r -p "Press Enter after the complete test... " _
cleanup
LOG_PIDS=()

copy_private_file() {
    local serial="$1" remote="$2" local_path="$3"
    adb -s "$serial" exec-out run-as "$PKG" sh -c "cat '$remote'" \
        >"$local_path" 2>/dev/null || rm -f "$local_path"
    [[ -s "$local_path" ]] || rm -f "$local_path"
}

collect_private_telemetry() {
    local serial="$1" dir="$2"
    if ! adb -s "$serial" shell run-as "$PKG" id >/dev/null 2>&1; then
        echo "run-as unavailable" >"$dir/run_as_error.txt"
        return
    fi
    local latest
    latest="$(adb -s "$serial" shell run-as "$PKG" sh -c \
        'cd files 2>/dev/null && ls -td dual_phone_captures/*/* 2>/dev/null | head -1' |
        tr -d '\r')"
    echo "latest_role_dir=${latest:-not_found}" >"$dir/latest_capture.txt"
    adb -s "$serial" exec-out run-as "$PKG" sh -c \
        'cd files 2>/dev/null && find dual_phone_captures dual_phone_transfer upload_packages -maxdepth 6 -type f -exec ls -ln {} \; 2>/dev/null' \
        >"$dir/app_file_list.txt" 2>&1 || true
    [[ -n "$latest" ]] || return
    mkdir -p "$dir/latest_capture"
    local name
    for name in \
        dual_capture_manifest.json \
        local_timeline_report.json \
        frame_encoder_map.jsonl \
        frames.jsonl \
        encoder_pts.jsonl \
        camera_info.json \
        clock_sync.json \
        capture_events.jsonl \
        clock_sync_history.jsonl \
        imu.jsonl; do
        copy_private_file "$serial" "files/$latest/$name" "$dir/latest_capture/$name"
    done
    if ((FULL == 1)); then
        adb -s "$serial" exec-out run-as "$PKG" sh -c \
            'cd files 2>/dev/null && tar cf - dual_phone_captures dual_phone_transfer upload_packages 2>/dev/null' \
            >"$dir/app_private_full.tar" 2>"$dir/app_private_full_error.txt" || true
        [[ -s "$dir/app_private_full.tar" ]] || rm -f "$dir/app_private_full.tar"
    fi
}

collect_device() {
    local role="$1" serial="$2" dir="$3"
    adb -s "$serial" shell dumpsys media.camera >"$dir/dumpsys_media_camera.txt" 2>&1 || true
    adb -s "$serial" shell dumpsys activity processes >"$dir/dumpsys_activity_processes.txt" 2>&1 || true
    adb -s "$serial" exec-out screencap -p >"$dir/screenshot.png" 2>/dev/null || true
    collect_private_telemetry "$serial" "$dir"
    {
        echo "===== role ====="
        echo "$role $serial"
        echo
        echo "===== recorder/control highlights ====="
        grep -Eai \
            'RECORDER_ATTEMPT|CAPTURE_WINDOW|PHYSICAL_RECORDING|valid encoded|finalize|frame_encoder|local_timeline|role package|aggregate bundle|TRANSFERRED|ERROR|Exception' \
            "$dir/logcat_app.log" "$dir/logcat_filtered.log" 2>/dev/null || true
        echo
        echo "===== collected files ====="
        find "$dir" -type f -printf '%P|%s bytes\n' | sort
    } >"$dir/summary.txt"
}

for entry in "${ROLES[@]}"; do
    role="${entry%%:*}"
    serial="${entry#*:}"
    safe_serial="${serial//[^A-Za-z0-9._-]/_}"
    collect_device "$role" "$serial" "$RUN_DIR/${role}_${safe_serial}"
done

{
    echo "timestamp_local=$(date --iso-8601=seconds)"
    echo "package=$PKG"
    echo "both=$BOTH"
    echo "full=$FULL"
    printf 'devices=%s\n' "${ROLES[*]}"
    echo
    find "$RUN_DIR" -type f -printf '%P|%s bytes\n' | sort
} >"$RUN_DIR/collector_summary.txt"

ARCHIVE="${RUN_DIR}.tar.gz"
tar -C "$(dirname "$RUN_DIR")" -czf "$ARCHIVE" "$(basename "$RUN_DIR")"
SHA256="$(sha256sum "$ARCHIVE" | awk '{print $1}')"

echo
echo "Done."
echo "Archive: $ARCHIVE"
echo "SHA-256: $SHA256"
echo "Upload this archive for analysis."
