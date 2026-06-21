#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 10 ]]; then
  echo "Usage: $0 ./stations.conf <parent_job_id> <sparse_job_id> <model_id> <mode> <target_images_per_chunk> <max_images_per_chunk> <overlap_images> <ram_reserve_mb> <local_output_dir>" >&2
  exit 1
fi

CONFIG="$1"
PARENT_JOB_ID="$2"
SPARSE_JOB_ID="$3"
MODEL_ID="$4"
MODE="$5"
TARGET_IMAGES_PER_CHUNK="$6"
MAX_IMAGES_PER_CHUNK="$7"
OVERLAP_IMAGES="$8"
RAM_RESERVE_MB="$9"
LOCAL_OUTPUT_DIR="${10}"

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

if [[ "$COLMAP_MODE" == "podman" && -z "$COLMAP_IMAGE" ]]; then
  echo "ERROR: COLMAP_IMAGE required for podman mode" >&2
  exit 1
fi

SSH_OPTS=(-i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new)
SSH=(ssh "${SSH_OPTS[@]}" "${STATION_USER}@${STATION_HOST}")
PYTHON_BIN="$STATION_BASE/venv/bin/python"
PLANNER_SCRIPT="$STATION_BASE/scripts/plan_colmap_dense_chunks.py"
REMOTE_PARENT_DIR="$STATION_BASE/output/job_${PARENT_JOB_ID}"
REMOTE_SPARSE_MODEL_DIR="$STATION_BASE/output/job_${SPARSE_JOB_ID}/colmap/sparse/${MODEL_ID}"
REMOTE_PLAN="$REMOTE_PARENT_DIR/chunk_plan.json"
LOCAL_PARENT_DIR="$LOCAL_OUTPUT_DIR/job_${PARENT_JOB_ID}"

printf -v Q_PY '%q' "$PYTHON_BIN"
printf -v Q_SCRIPT '%q' "$PLANNER_SCRIPT"
printf -v Q_PARENT '%q' "$REMOTE_PARENT_DIR"
printf -v Q_SPARSE '%q' "$REMOTE_SPARSE_MODEL_DIR"
printf -v Q_PLAN '%q' "$REMOTE_PLAN"
printf -v Q_MODEL '%q' "$MODEL_ID"
printf -v Q_MODE '%q' "$MODE"
printf -v Q_TARGET '%q' "$TARGET_IMAGES_PER_CHUNK"
printf -v Q_MAX '%q' "$MAX_IMAGES_PER_CHUNK"
printf -v Q_OVERLAP '%q' "$OVERLAP_IMAGES"
printf -v Q_SPARSE_ID '%q' "$SPARSE_JOB_ID"
printf -v Q_RESERVE '%q' "$RAM_RESERVE_MB"
printf -v Q_COLMAP_MODE '%q' "$COLMAP_MODE"
printf -v Q_COLMAP_BIN '%q' "$COLMAP_BIN"
printf -v Q_COLMAP_IMAGE '%q' "$COLMAP_IMAGE"
printf -v Q_BASE '%q' "$STATION_BASE"

"${SSH[@]}" "
set -e
test -x $Q_PY
test -d $Q_SPARSE
mkdir -p $Q_PARENT

STATION_BASE=$Q_BASE \
COLMAP_MODE=$Q_COLMAP_MODE \
COLMAP_BIN=$Q_COLMAP_BIN \
COLMAP_IMAGE=$Q_COLMAP_IMAGE \
$Q_PY $Q_SCRIPT \
  --sparse-model-dir $Q_SPARSE \
  --model-id $Q_MODEL \
  --mode $Q_MODE \
  --output-plan $Q_PLAN \
  --target-images-per-chunk $Q_TARGET \
  --max-images-per-chunk $Q_MAX \
  --overlap-images $Q_OVERLAP \
  --sparse-job-id $Q_SPARSE_ID \
  --ram-reserve-mb $Q_RESERVE
"

mkdir -p "$LOCAL_PARENT_DIR"
if command -v rsync >/dev/null 2>&1; then
  rsync -az -e "ssh -i '$STATION_SSH_KEY' -o StrictHostKeyChecking=accept-new" "${STATION_USER}@${STATION_HOST}:$REMOTE_PARENT_DIR/" "$LOCAL_PARENT_DIR/"
else
  scp -i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new -r "${STATION_USER}@${STATION_HOST}:$REMOTE_PARENT_DIR/"* "$LOCAL_PARENT_DIR/"
fi
