#!/usr/bin/env bash
set -euo pipefail
if [[ $# -ne 4 ]]; then echo "Usage: $0 ./stations.conf <mesh_job_id> <reconstruction_parent_job_id> <preview|hq>" >&2; exit 1; fi
CONFIG="$1"; MESH_JOB_ID="$2"; PARENT_JOB_ID="$3"; MODE="$4"
[[ -f "$CONFIG" ]] || { echo "ERROR: config not found: $CONFIG" >&2; exit 1; }
# shellcheck source=/dev/null
source "$CONFIG"
: "${STATION_HOST:?}"; : "${STATION_USER:?}"; : "${STATION_SSH_KEY:?}"; : "${STATION_BASE:?}"
COLMAP_MODE="${COLMAP_MODE:-podman}"; COLMAP_BIN="${COLMAP_BIN:-colmap}"; COLMAP_IMAGE="${COLMAP_IMAGE:-docker.io/colmap/colmap:latest}"
MESH_ENGINE="${MESH_ENGINE:-auto}"; OPEN3D_PYTHON="${OPEN3D_PYTHON:-$STATION_BASE/open3d-venv/bin/python}"
if [[ "$MODE" == "preview" ]]; then DEPTH="${MESH_PREVIEW_POISSON_DEPTH:-7}"; TARGET="${MESH_PREVIEW_TARGET_FACES:-100000}"; else DEPTH="${MESH_HQ_POISSON_DEPTH:-9}"; TARGET="${MESH_HQ_TARGET_FACES:-500000}"; fi
MIN_IN="${MESH_MIN_INPUT_VERTICES:-500}"; MIN_FACES="${MESH_MIN_OUTPUT_FACES:-100}"
SSH=(ssh -i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new "${STATION_USER}@${STATION_HOST}")
printf -v B '%q' "$STATION_BASE"; printf -v CM '%q' "$COLMAP_MODE"; printf -v CB '%q' "$COLMAP_BIN"; printf -v CI '%q' "$COLMAP_IMAGE"; printf -v ME '%q' "$MESH_ENGINE"; printf -v OP '%q' "$OPEN3D_PYTHON"
printf -v A '%q ' "$MESH_JOB_ID" "$PARENT_JOB_ID" "$MODE" "$DEPTH" "$TARGET" "$MIN_IN" "$MIN_FACES"
"${SSH[@]}" "mkdir -p '$STATION_BASE/logs'; { echo '[launcher] mesh job $MESH_JOB_ID parent $PARENT_JOB_ID mode $MODE'; STATION_BASE=$B COLMAP_MODE=$CM COLMAP_BIN=$CB COLMAP_IMAGE=$CI MESH_ENGINE=$ME OPEN3D_PYTHON=$OP nohup $B/scripts/process_colmap_mesh.sh $A > '$STATION_BASE/logs/job_${MESH_JOB_ID}_mesh_launcher.log' 2>&1 & echo launched; }"
