#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 3 || $# -gt 8 ]]; then
  echo "Usage: $0 ./stations.conf <job_id> <local_video_path> [fps] [max_frames] [scale_width] [jpeg_quality] [extract_json]" >&2
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

echo "==> Prepare station dirs"
"${SSH[@]}" "mkdir -p '$STATION_BASE/incoming' '$STATION_BASE/output/job_${JOB_ID}' '$STATION_BASE/logs' '$STATION_BASE/status'"

echo "==> Upload input video to ${STATION_HOST}:${REMOTE_INPUT}"
if command -v rsync >/dev/null 2>&1; then
  rsync -az --progress -e "ssh -i '$STATION_SSH_KEY' -o StrictHostKeyChecking=accept-new" \
    "$LOCAL_VIDEO" "${STATION_USER}@${STATION_HOST}:$REMOTE_INPUT"
else
  scp -i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new \
    "$LOCAL_VIDEO" "${STATION_USER}@${STATION_HOST}:$REMOTE_INPUT"
fi

echo "==> Start extract frames job $JOB_ID"
printf -v Q_FPS '%q' "$EXTRACT_FPS"; printf -v Q_MAX '%q' "$EXTRACT_MAX_FRAMES"; printf -v Q_W '%q' "$EXTRACT_SCALE_WIDTH"; printf -v Q_Q '%q' "$EXTRACT_JPEG_QUALITY"
if [[ -n "$EXTRACT_PARAMS_JSON" ]]; then
  printf -v Q_JSON '%q' "$EXTRACT_PARAMS_JSON"
  "${SSH[@]}" "mkdir -p '$STATION_BASE/input/job_${JOB_ID}' && printf %s $Q_JSON > '$STATION_BASE/input/job_${JOB_ID}/parameters.json' && nohup '$STATION_BASE/scripts/process_extract_frames.sh' '$JOB_ID' '$REMOTE_INPUT' '$REMOTE_OUTPUT' > '$REMOTE_LOG' 2>&1 &"
else
  "${SSH[@]}" "mkdir -p '$STATION_BASE/input/job_${JOB_ID}' && printf '{\"extract\":{\"fps\":%s,\"max_frames\":%s,\"scale_width\":%s,\"jpeg_quality\":%s}}\n' $Q_FPS $Q_MAX $Q_W $Q_Q > '$STATION_BASE/input/job_${JOB_ID}/parameters.json' && EXTRACT_FPS=$Q_FPS EXTRACT_MAX_FRAMES=$Q_MAX EXTRACT_SCALE_WIDTH=$Q_W EXTRACT_JPEG_QUALITY=$Q_Q nohup '$STATION_BASE/scripts/process_extract_frames.sh' '$JOB_ID' '$REMOTE_INPUT' '$REMOTE_OUTPUT' > '$REMOTE_LOG' 2>&1 &"
fi

echo "==> Started"
echo "Status:"
echo "  ./get_station_status.sh $CONFIG $JOB_ID"
echo "Fetch result:"
echo "  ./fetch_job_result.sh $CONFIG $JOB_ID ./output"
