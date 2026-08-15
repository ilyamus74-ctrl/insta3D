#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 3 || $# -gt 9 ]]; then
  echo "Usage: $0 ./stations.conf <job_id> <local_video_path> [fps] [max_frames] [scale_width] [jpeg_quality] [extract_json] [local_imu_jsonl]" >&2
  exit 1
fi

CONFIG="$1"
JOB_ID="$2"
LOCAL_VIDEO="$3"
EXTRACT_FPS="${4:-${EXTRACT_FPS:-}}"
EXTRACT_MAX_FRAMES="${5:-${EXTRACT_MAX_FRAMES:-}}"
EXTRACT_SCALE_WIDTH="${6:-${EXTRACT_SCALE_WIDTH:-}}"
EXTRACT_JPEG_QUALITY="${7:-${EXTRACT_JPEG_QUALITY:-}}"
EXTRACT_PARAMS_JSON="${8:-${EXTRACT_PARAMS_JSON:-}}"
LOCAL_IMU="${9:-}"
EXTRACT_FPS="${EXTRACT_FPS:-2}"; EXTRACT_MAX_FRAMES="${EXTRACT_MAX_FRAMES:-360}"; EXTRACT_SCALE_WIDTH="${EXTRACT_SCALE_WIDTH:-1920}"; EXTRACT_JPEG_QUALITY="${EXTRACT_JPEG_QUALITY:-2}"

if [[ ! -f "$CONFIG" ]]; then
  echo "ERROR: config not found: $CONFIG" >&2
  exit 1
fi

if [[ ! -f "$LOCAL_VIDEO" ]]; then
  echo "ERROR: local video not found: $LOCAL_VIDEO" >&2
  exit 1
fi

# shellcheck source=/dev/null
source "$CONFIG"

: "${STATION_HOST:?missing STATION_HOST}"
: "${STATION_USER:?missing STATION_USER}"
: "${STATION_SSH_KEY:?missing STATION_SSH_KEY}"
: "${STATION_BASE:?missing STATION_BASE}"

SSH_OPTS=(-i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new)
SSH=(ssh "${SSH_OPTS[@]}" "${STATION_USER}@${STATION_HOST}")

VIDEO_NAME="$(basename "$LOCAL_VIDEO")"
SAFE_VIDEO_NAME="$(echo "$VIDEO_NAME" | tr -c 'A-Za-z0-9._-' '_')"

REMOTE_INPUT="$STATION_BASE/incoming/job_${JOB_ID}_${SAFE_VIDEO_NAME}"
REMOTE_OUTPUT="$STATION_BASE/output/job_${JOB_ID}/frames"
REMOTE_LOG="$STATION_BASE/logs/job_${JOB_ID}.nohup.log"
REMOTE_IMU="$STATION_BASE/input/job_${JOB_ID}/scan_imu.jsonl"
REMOTE_CAMERA_INFO="$STATION_BASE/input/job_${JOB_ID}/camera_info.json"
REMOTE_MANIFEST="$STATION_BASE/input/job_${JOB_ID}/manifest.json"
REMOTE_FRAMES="$STATION_BASE/input/job_${JOB_ID}/frames.jsonl"
REMOTE_TOF_FRAMES="$STATION_BASE/input/job_${JOB_ID}/tof_frames.jsonl"
REMOTE_TOF_CALIBRATION="$STATION_BASE/input/job_${JOB_ID}/tof_calibration.json"
REMOTE_ENCODER_PTS="$STATION_BASE/input/job_${JOB_ID}/encoder_pts.jsonl"

LOCAL_CAMERA_INFO=""
LOCAL_MANIFEST=""
LOCAL_FRAMES=""
LOCAL_TOF_FRAMES=""
LOCAL_TOF_CALIBRATION=""
LOCAL_ENCODER_PTS=""
if [[ -n "$EXTRACT_PARAMS_JSON" ]]; then
  mapfile -t SOURCE_SIDECARS < <(
    python3 - "$EXTRACT_PARAMS_JSON" <<'PY'
import json
import sys

try:
    payload = json.loads(sys.argv[1])
except Exception:
    payload = {}

source = payload.get("source_video")
if not isinstance(source, dict):
    source = {}

print(source.get("camera_info_path") or "")
print(source.get("manifest_path") or "")
print(source.get("frames_path") or "")
print(source.get("tof_frames_path") or "")
print(source.get("tof_calibration_path") or "")
print(source.get("encoder_pts_path") or "")
PY
  )
  LOCAL_CAMERA_INFO="${SOURCE_SIDECARS[0]:-}"
  LOCAL_MANIFEST="${SOURCE_SIDECARS[1]:-}"
  LOCAL_FRAMES="${SOURCE_SIDECARS[2]:-}"
  LOCAL_TOF_FRAMES="${SOURCE_SIDECARS[3]:-}"
  LOCAL_TOF_CALIBRATION="${SOURCE_SIDECARS[4]:-}"
  LOCAL_ENCODER_PTS="${SOURCE_SIDECARS[5]:-}"
fi

# frames.jsonl is optional telemetry and older DB schemas do not have a
# dedicated path column. For PHONE_CAMERA uploads it is stored next to the
# camera-info/manifest sidecars using the same scan UUID prefix, so recover it
# from either known sidecar path when the worker did not provide frames_path.
if [[ -z "$LOCAL_FRAMES" || ! -f "$LOCAL_FRAMES" ]]; then
  if [[ "$LOCAL_CAMERA_INFO" == *_camera_info.json ]]; then
    candidate="${LOCAL_CAMERA_INFO%_camera_info.json}_frames.jsonl"
    [[ -f "$candidate" ]] && LOCAL_FRAMES="$candidate"
  fi
fi
if [[ -z "$LOCAL_FRAMES" || ! -f "$LOCAL_FRAMES" ]]; then
  if [[ "$LOCAL_MANIFEST" == *_manifest.json ]]; then
    candidate="${LOCAL_MANIFEST%_manifest.json}_frames.jsonl"
    [[ -f "$candidate" ]] && LOCAL_FRAMES="$candidate"
  fi
fi

recover_phone_sidecar() {
  local current="$1"
  local suffix="$2"
  local candidate=""
  if [[ -n "$current" && -f "$current" ]]; then
    printf '%s\n' "$current"
    return 0
  fi
  if [[ "$LOCAL_CAMERA_INFO" == *_camera_info.json ]]; then
    candidate="${LOCAL_CAMERA_INFO%_camera_info.json}${suffix}"
  elif [[ "$LOCAL_MANIFEST" == *_manifest.json ]]; then
    candidate="${LOCAL_MANIFEST%_manifest.json}${suffix}"
  fi
  if [[ -n "$candidate" && -f "$candidate" ]]; then
    printf '%s\n' "$candidate"
  fi
  return 0
}

LOCAL_TOF_FRAMES="$(recover_phone_sidecar "$LOCAL_TOF_FRAMES" "_tof_frames.jsonl")"
LOCAL_TOF_CALIBRATION="$(recover_phone_sidecar "$LOCAL_TOF_CALIBRATION" "_tof_calibration.json")"
LOCAL_ENCODER_PTS="$(recover_phone_sidecar "$LOCAL_ENCODER_PTS" "_encoder_pts.jsonl")"

upload_optional_sidecar() {
  local local_path="$1"
  local remote_path="$2"
  local label="$3"

  if [[ -z "$local_path" || ! -f "$local_path" ]]; then
    echo "==> ${label} sidecar is empty or file not found: ${local_path:-none}"
    return 0
  fi

  echo "==> Upload ${label} metadata to ${STATION_HOST}:${remote_path}"
  if command -v rsync >/dev/null 2>&1; then
    rsync -az -e "ssh -i '$STATION_SSH_KEY' -o StrictHostKeyChecking=accept-new" \
      "$local_path" "${STATION_USER}@${STATION_HOST}:$remote_path"
  else
    scp -i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new \
      "$local_path" "${STATION_USER}@${STATION_HOST}:$remote_path"
  fi

  "${SSH[@]}" "test -s '$remote_path'" || {
    echo "ERROR: ${label} upload failed or remote file is empty: $remote_path" >&2
    exit 23
  }
}

echo "==> Prepare station dirs"
####"${SSH[@]}" "mkdir -p '$STATION_BASE/incoming' '$STATION_BASE/output/job_${JOB_ID}' '$STATION_BASE/logs' '$STATION_BASE/status'"
"${SSH[@]}" "mkdir -p '$STATION_BASE/incoming' '$STATION_BASE/output/job_${JOB_ID}' '$STATION_BASE/input/job_${JOB_ID}' '$STATION_BASE/logs' '$STATION_BASE/status'"

echo "==> Upload input video to ${STATION_HOST}:${REMOTE_INPUT}"
if command -v rsync >/dev/null 2>&1; then
  rsync -az --progress -e "ssh -i '$STATION_SSH_KEY' -o StrictHostKeyChecking=accept-new" \
    "$LOCAL_VIDEO" "${STATION_USER}@${STATION_HOST}:$REMOTE_INPUT"
else
  scp -i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new \
    "$LOCAL_VIDEO" "${STATION_USER}@${STATION_HOST}:$REMOTE_INPUT"
fi

if [[ -n "$LOCAL_IMU" && -f "$LOCAL_IMU" ]]; then
  echo "==> Upload IMU metadata to ${STATION_HOST}:${REMOTE_IMU}"
  if command -v rsync >/dev/null 2>&1; then
    rsync -az -e "ssh -i '$STATION_SSH_KEY' -o StrictHostKeyChecking=accept-new" "$LOCAL_IMU" "${STATION_USER}@${STATION_HOST}:$REMOTE_IMU"
  else
    scp -i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new "$LOCAL_IMU" "${STATION_USER}@${STATION_HOST}:$REMOTE_IMU"
  fi

  "${SSH[@]}" "test -s '$REMOTE_IMU'" || {
    echo "ERROR: IMU upload failed or remote IMU is empty: $REMOTE_IMU" >&2
    exit 22
  }
else
  echo "==> LOCAL_IMU is empty or file not found: ${LOCAL_IMU:-none}"
fi

upload_optional_sidecar "$LOCAL_CAMERA_INFO" "$REMOTE_CAMERA_INFO" "camera_info"
upload_optional_sidecar "$LOCAL_MANIFEST" "$REMOTE_MANIFEST" "manifest"
upload_optional_sidecar "$LOCAL_FRAMES" "$REMOTE_FRAMES" "frames"
upload_optional_sidecar "$LOCAL_TOF_FRAMES" "$REMOTE_TOF_FRAMES" "tof_frames"
upload_optional_sidecar "$LOCAL_TOF_CALIBRATION" "$REMOTE_TOF_CALIBRATION" "tof_calibration"
upload_optional_sidecar "$LOCAL_ENCODER_PTS" "$REMOTE_ENCODER_PTS" "encoder_pts"

echo "==> Start extract frames job $JOB_ID"
printf -v Q_FPS '%q' "$EXTRACT_FPS"; printf -v Q_MAX '%q' "$EXTRACT_MAX_FRAMES"; printf -v Q_W '%q' "$EXTRACT_SCALE_WIDTH"; printf -v Q_Q '%q' "$EXTRACT_JPEG_QUALITY"
if [[ -n "$EXTRACT_PARAMS_JSON" ]]; then
  printf -v Q_JSON '%q' "$EXTRACT_PARAMS_JSON"
  "${SSH[@]}" "mkdir -p '$STATION_BASE/input/job_${JOB_ID}' && printf %s $Q_JSON > '$STATION_BASE/input/job_${JOB_ID}/parameters.json' && python3 - '$STATION_BASE/input/job_${JOB_ID}/parameters.json' '$REMOTE_IMU' '$REMOTE_CAMERA_INFO' '$REMOTE_MANIFEST' '$REMOTE_FRAMES' '$REMOTE_TOF_FRAMES' '$REMOTE_TOF_CALIBRATION' '$REMOTE_ENCODER_PTS' <<'PY'
import json
import os
import sys

parameter_path, imu_path, camera_info_path, manifest_path, frames_path, tof_frames_path, tof_calibration_path, encoder_pts_path = sys.argv[1:9]
with open(parameter_path, encoding='utf-8') as handle:
    payload = json.load(handle)

if imu_path and os.path.isfile(imu_path):
    payload['imu_jsonl_path'] = imu_path
else:
    payload.pop('imu_jsonl_path', None)

source = payload.get('source_video')
if not isinstance(source, dict):
    source = {}

for key, path in (
    ('camera_info_path', camera_info_path),
    ('manifest_path', manifest_path),
    ('frames_path', frames_path),
    ('tof_frames_path', tof_frames_path),
    ('tof_calibration_path', tof_calibration_path),
    ('encoder_pts_path', encoder_pts_path),
):
    if path and os.path.isfile(path):
        source[key] = path
    else:
        source.pop(key, None)

if source:
    payload['source_video'] = source
else:
    payload.pop('source_video', None)

with open(parameter_path, 'w', encoding='utf-8') as handle:
    json.dump(payload, handle, ensure_ascii=False)
PY
nohup '$STATION_BASE/scripts/process_extract_frames.sh' '$JOB_ID' '$REMOTE_INPUT' '$REMOTE_OUTPUT' > '$REMOTE_LOG' 2>&1 &"
else
  "${SSH[@]}" "mkdir -p '$STATION_BASE/input/job_${JOB_ID}' && printf '{\"extract\":{\"fps\":%s,\"max_frames\":%s,\"scale_width\":%s,\"jpeg_quality\":%s}}\n' $Q_FPS $Q_MAX $Q_W $Q_Q > '$STATION_BASE/input/job_${JOB_ID}/parameters.json' && EXTRACT_FPS=$Q_FPS EXTRACT_MAX_FRAMES=$Q_MAX EXTRACT_SCALE_WIDTH=$Q_W EXTRACT_JPEG_QUALITY=$Q_Q nohup '$STATION_BASE/scripts/process_extract_frames.sh' '$JOB_ID' '$REMOTE_INPUT' '$REMOTE_OUTPUT' > '$REMOTE_LOG' 2>&1 &"
fi

echo "==> Started"
echo "Status:"
echo "  ./get_station_status.sh $CONFIG $JOB_ID"
echo "Fetch result:"
echo "  ./fetch_job_result.sh $CONFIG $JOB_ID ./output"
