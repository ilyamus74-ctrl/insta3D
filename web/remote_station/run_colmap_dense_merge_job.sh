#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 4 ]]; then
  echo "Usage: $0 ./stations.conf <parent_job_id> <mode> <local_output_dir>" >&2
  exit 1
fi

CONFIG="$1"
PARENT_JOB_ID="$2"
MODE="$3"
LOCAL_OUTPUT_DIR="$4"

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
PYTHON_BIN="$STATION_BASE/venv/bin/python"
REMOTE_PARENT_DIR="$STATION_BASE/output/job_${PARENT_JOB_ID}"
REMOTE_OUTPUT_PLY="$REMOTE_PARENT_DIR/merged/merged_fused.ply"
LOCAL_PARENT_DIR="$LOCAL_OUTPUT_DIR/job_${PARENT_JOB_ID}"

printf -v Q_PY '%q' "$PYTHON_BIN"
printf -v Q_PARENT '%q' "$REMOTE_PARENT_DIR"
printf -v Q_MODE '%q' "$MODE"
printf -v Q_OUT '%q' "$REMOTE_OUTPUT_PLY"

"${SSH[@]}" "set -e; test -x $Q_PY; $Q_PY '$STATION_BASE/scripts/merge_dense_chunks.py' --parent-output-dir $Q_PARENT --mode $Q_MODE --output-ply $Q_OUT"

mkdir -p "$LOCAL_PARENT_DIR"
if command -v rsync >/dev/null 2>&1; then
  rsync -az -e "ssh -i '$STATION_SSH_KEY' -o StrictHostKeyChecking=accept-new" "${STATION_USER}@${STATION_HOST}:$REMOTE_PARENT_DIR/" "$LOCAL_PARENT_DIR/"
else
  scp -i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new -r "${STATION_USER}@${STATION_HOST}:$REMOTE_PARENT_DIR/"* "$LOCAL_PARENT_DIR/"
fi