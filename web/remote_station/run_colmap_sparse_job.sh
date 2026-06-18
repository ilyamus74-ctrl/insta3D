#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 3 ]]; then
  echo "Usage: $0 ./stations.conf <job_id> <remote_frames_dir>" >&2
  exit 1
fi

CONFIG="$1"
JOB_ID="$2"
REMOTE_FRAMES_DIR="$3"

if [[ ! -f "$CONFIG" ]]; then
  echo "ERROR: config not found: $CONFIG" >&2
  exit 1
fi

# shellcheck source=/dev/null
source "$CONFIG"

: "${STATION_HOST:?missing STATION_HOST}"
: "${STATION_USER:?missing STATION_USER}"
: "${STATION_SSH_KEY:?missing STATION_SSH_KEY}"
: "${STATION_BASE:?missing STATION_BASE}"
COLMAP_MODE="${COLMAP_MODE:-native}"
COLMAP_BIN="${COLMAP_BIN:-colmap}"
COLMAP_IMAGE="${COLMAP_IMAGE:-}"
COLMAP_MATCHER="${COLMAP_MATCHER:-sequential}"
COLMAP_SEQUENTIAL_OVERLAP="${COLMAP_SEQUENTIAL_OVERLAP:-10}"

SSH_OPTS=(-i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new)
SSH=(ssh "${SSH_OPTS[@]}" "${STATION_USER}@${STATION_HOST}")

REMOTE_OUTPUT="$STATION_BASE/output/job_${JOB_ID}/colmap"
REMOTE_LOG="$STATION_BASE/logs/job_${JOB_ID}.nohup.log"

printf -v Q_FRAMES '%q' "$REMOTE_FRAMES_DIR"
printf -v Q_BASE '%q' "$STATION_BASE"
printf -v Q_JOB '%q' "$JOB_ID"
printf -v Q_OUTPUT '%q' "$REMOTE_OUTPUT"
printf -v Q_LOG '%q' "$REMOTE_LOG"
printf -v Q_STATION_BASE '%q' "$STATION_BASE"
printf -v Q_COLMAP_MODE '%q' "$COLMAP_MODE"
printf -v Q_COLMAP_BIN '%q' "$COLMAP_BIN"
printf -v Q_COLMAP_IMAGE '%q' "$COLMAP_IMAGE"
printf -v Q_COLMAP_MATCHER '%q' "$COLMAP_MATCHER"
printf -v Q_COLMAP_SEQUENTIAL_OVERLAP '%q' "$COLMAP_SEQUENTIAL_OVERLAP"
REMOTE_CMD="test -d $Q_FRAMES"

echo "==> Check remote frames directory"
if ! "${SSH[@]}" "$REMOTE_CMD"; then
  echo "ERROR: remote frames directory not found: $REMOTE_FRAMES_DIR" >&2
  exit 1
fi

echo "==> Prepare station dirs"
"${SSH[@]}" "mkdir -p $Q_OUTPUT $Q_BASE/logs $Q_BASE/status"

echo "==> Start COLMAP sparse reconstruction job $JOB_ID"
"${SSH[@]}" "STATION_BASE=$Q_STATION_BASE COLMAP_MODE=$Q_COLMAP_MODE COLMAP_BIN=$Q_COLMAP_BIN COLMAP_IMAGE=$Q_COLMAP_IMAGE COLMAP_MATCHER=$Q_COLMAP_MATCHER COLMAP_SEQUENTIAL_OVERLAP=$Q_COLMAP_SEQUENTIAL_OVERLAP nohup $Q_BASE/scripts/process_colmap_sparse.sh $Q_JOB $Q_FRAMES $Q_OUTPUT > $Q_LOG 2>&1 &"

echo "==> Started"
echo "Status:"
echo "  ./get_station_status.sh $CONFIG $JOB_ID"
echo "Fetch result:"
echo "  ./fetch_job_result.sh $CONFIG $JOB_ID ./output"
