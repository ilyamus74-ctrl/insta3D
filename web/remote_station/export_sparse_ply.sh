#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 4 ]]; then
  echo "Usage: $0 ./stations.conf <colmap_job_id> <model_id> <local_output_dir>" >&2
  echo "Example: $0 ./stations.conf 8 0 ./output" >&2
  exit 1
fi

CONFIG="$1"
COLMAP_JOB_ID="$2"
MODEL_ID="$3"
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

COLMAP_MODE="${COLMAP_MODE:-podman}"
COLMAP_IMAGE="${COLMAP_IMAGE:-docker.io/colmap/colmap:latest}"

if [[ "$COLMAP_MODE" != "podman" ]]; then
  echo "WARN: COLMAP_MODE is '$COLMAP_MODE'. This script expects podman mode." >&2
fi

SSH_OPTS=(-i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new)
SSH=(ssh "${SSH_OPTS[@]}" "${STATION_USER}@${STATION_HOST}")

REMOTE_MODEL_DIR="$STATION_BASE/output/job_${COLMAP_JOB_ID}/colmap/sparse/${MODEL_ID}"
REMOTE_PLY="$REMOTE_MODEL_DIR/model.ply"
REMOTE_RESULT="$REMOTE_MODEL_DIR/export_ply_result.json"

LOCAL_DEST="$LOCAL_OUTPUT_DIR/job_${COLMAP_JOB_ID}/colmap/sparse/${MODEL_ID}"

printf -v Q_BASE '%q' "$STATION_BASE"
printf -v Q_IMAGE '%q' "$COLMAP_IMAGE"
printf -v Q_MODEL_DIR '%q' "$REMOTE_MODEL_DIR"
printf -v Q_REMOTE_PLY '%q' "$REMOTE_PLY"
printf -v Q_REMOTE_RESULT '%q' "$REMOTE_RESULT"

echo "==> Station: ${STATION_USER}@${STATION_HOST}"
echo "==> Remote model dir: $REMOTE_MODEL_DIR"
echo "==> Remote PLY: $REMOTE_PLY"

echo "==> Check remote model directory"
"${SSH[@]}" "test -d $Q_MODEL_DIR"

echo "==> Check required COLMAP sparse files"
"${SSH[@]}" "test -f $Q_MODEL_DIR/cameras.bin && test -f $Q_MODEL_DIR/images.bin && test -f $Q_MODEL_DIR/points3D.bin"

echo "==> Convert sparse model to PLY through Podman COLMAP"
"${SSH[@]}" "
set -euo pipefail

STARTED_AT=\$(date -Iseconds)
STARTED_EPOCH=\$(date +%s)

podman run --rm \
  --device nvidia.com/gpu=all \
  --security-opt=label=disable \
  -v $Q_BASE:$Q_BASE \
  $Q_IMAGE \
  colmap model_converter \
    --input_path $Q_MODEL_DIR \
    --output_path $Q_REMOTE_PLY \
    --output_type PLY

FINISHED_AT=\$(date -Iseconds)
FINISHED_EPOCH=\$(date +%s)
DURATION=\$((FINISHED_EPOCH - STARTED_EPOCH))
PLY_SIZE=\$(stat -c%s $Q_REMOTE_PLY)

cat > $Q_REMOTE_RESULT <<JSON
{
  \"status\": \"DONE\",
  \"colmap_job_id\": \"$COLMAP_JOB_ID\",
  \"model_id\": \"$MODEL_ID\",
  \"input_path\": \"$REMOTE_MODEL_DIR\",
  \"output_path\": \"$REMOTE_PLY\",
  \"output_type\": \"PLY\",
  \"colmap_mode\": \"$COLMAP_MODE\",
  \"colmap_image\": \"$COLMAP_IMAGE\",
  \"ply_size_bytes\": \$PLY_SIZE,
  \"started_at\": \"\$STARTED_AT\",
  \"finished_at\": \"\$FINISHED_AT\",
  \"duration_sec\": \$DURATION
}
JSON
"

echo "==> Fetch PLY result to local output"
mkdir -p "$LOCAL_DEST"

if command -v rsync >/dev/null 2>&1; then
  rsync -az -e "ssh -i '$STATION_SSH_KEY' -o StrictHostKeyChecking=accept-new" \
    "${STATION_USER}@${STATION_HOST}:$REMOTE_PLY" \
    "$LOCAL_DEST/model.ply"

  rsync -az -e "ssh -i '$STATION_SSH_KEY' -o StrictHostKeyChecking=accept-new" \
    "${STATION_USER}@${STATION_HOST}:$REMOTE_RESULT" \
    "$LOCAL_DEST/export_ply_result.json"
else
  scp -i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new \
    "${STATION_USER}@${STATION_HOST}:$REMOTE_PLY" \
    "$LOCAL_DEST/model.ply"

  scp -i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new \
    "${STATION_USER}@${STATION_HOST}:$REMOTE_RESULT" \
    "$LOCAL_DEST/export_ply_result.json"
fi

echo "==> Done"
echo "PLY:"
echo "  $LOCAL_DEST/model.ply"
echo "Result:"
echo "  $LOCAL_DEST/export_ply_result.json"