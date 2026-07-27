#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 3 ]]; then
  echo "Usage: $0 ./stations.conf <job_id> <local_output_dir>" >&2
  exit 2
fi

CONFIG="$1"
JOB_ID="$2"
LOCAL_OUTPUT_DIR="$3"

if [[ ! -f "$CONFIG" ]]; then
  echo "ERROR: station config not found: $CONFIG" >&2
  exit 1
fi

if [[ ! "$JOB_ID" =~ ^[0-9]+$ ]] || (( JOB_ID <= 0 )); then
  echo "ERROR: job_id must be a positive integer" >&2
  exit 1
fi

# shellcheck source=/dev/null
source "$CONFIG"

: "${STATION_HOST:?missing STATION_HOST}"
: "${STATION_USER:?missing STATION_USER}"
: "${STATION_SSH_KEY:?missing STATION_SSH_KEY}"
: "${STATION_BASE:?missing STATION_BASE}"

LOCAL_JOB_DIR="${LOCAL_OUTPUT_DIR%/}/job_${JOB_ID}"
REMOTE_JOB_DIR="${STATION_BASE%/}/output/job_${JOB_ID}"

if [[ ! -d "$LOCAL_JOB_DIR" ]]; then
  echo "ERROR: local cached job directory not found: $LOCAL_JOB_DIR" >&2
  exit 1
fi

SSH_OPTS=(
  -i "$STATION_SSH_KEY"
  -o StrictHostKeyChecking=accept-new
  -o BatchMode=yes
)
SSH=(ssh "${SSH_OPTS[@]}" "${STATION_USER}@${STATION_HOST}")

printf -v Q_REMOTE_JOB_DIR '%q' "$REMOTE_JOB_DIR"

echo "==> Restore cached job_${JOB_ID} to ${STATION_HOST}:${REMOTE_JOB_DIR}/"
"${SSH[@]}" "mkdir -p $Q_REMOTE_JOB_DIR"

if command -v rsync >/dev/null 2>&1; then
  rsync -az --partial \
    -e "ssh -i '$STATION_SSH_KEY' -o StrictHostKeyChecking=accept-new -o BatchMode=yes" \
    "$LOCAL_JOB_DIR/" \
    "${STATION_USER}@${STATION_HOST}:${REMOTE_JOB_DIR}/"
else
  scp -i "$STATION_SSH_KEY" \
    -o StrictHostKeyChecking=accept-new \
    -o BatchMode=yes \
    -r "$LOCAL_JOB_DIR/." \
    "${STATION_USER}@${STATION_HOST}:${REMOTE_JOB_DIR}/"
fi

"${SSH[@]}" "test -d $Q_REMOTE_JOB_DIR"

echo "==> Restore complete"
echo "job_id=$JOB_ID"
echo "local_job_dir=$LOCAL_JOB_DIR"
echo "remote_job_dir=$REMOTE_JOB_DIR"
