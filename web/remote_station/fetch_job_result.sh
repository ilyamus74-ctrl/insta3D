#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 3 ]]; then
  echo "Usage: $0 ./stations.conf <job_id> <local_output_dir>" >&2
  exit 1
fi

CONFIG="$1"
JOB_ID="$2"
LOCAL_OUTPUT_DIR="$3"

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

SSH_OPTS=(-i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new)
SSH=(ssh "${SSH_OPTS[@]}" "${STATION_USER}@${STATION_HOST}")
DEST="$LOCAL_OUTPUT_DIR/job_${JOB_ID}"
REMOTE_JOB_DIR="$STATION_BASE/output/job_${JOB_ID}/"

mkdir -p "$DEST"

echo "==> Fetch output from ${STATION_HOST}:${REMOTE_JOB_DIR} to $DEST/"
if command -v rsync >/dev/null 2>&1; then
  rsync -az -e "ssh -i '$STATION_SSH_KEY' -o StrictHostKeyChecking=accept-new" "${STATION_USER}@${STATION_HOST}:$REMOTE_JOB_DIR" "$DEST/"
else
  scp -i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new -r "${STATION_USER}@${STATION_HOST}:$REMOTE_JOB_DIR"* "$DEST/"
fi

echo "==> Fetch status and logs"
"${SSH[@]}" "cat '$STATION_BASE/status/job_${JOB_ID}.json' 2>/dev/null || true" > "$DEST/job_${JOB_ID}.json"
"${SSH[@]}" "cat '$STATION_BASE/logs/job_${JOB_ID}.log' 2>/dev/null || true" > "$DEST/job_${JOB_ID}.log"
"${SSH[@]}" "cat '$STATION_BASE/logs/job_${JOB_ID}.nohup.log' 2>/dev/null || true" > "$DEST/job_${JOB_ID}.nohup.log"

echo "==> Done: $DEST"