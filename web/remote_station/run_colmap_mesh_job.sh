#!/usr/bin/env bash
set -euo pipefail
if [[ $# -lt 4 || $# -gt 9 ]]; then echo "Usage: $0 ./stations.conf <mesh_job_id> <reconstruction_parent_job_id> <preview|hq> [engine] [depth] [target_faces] [density_quantile] [mesh_json]" >&2; exit 1; fi
CONFIG="$1"; MESH_JOB_ID="$2"; PARENT_JOB_ID="$3"; MODE="$4"; REQ_ENGINE="${5:-}"; REQ_DEPTH="${6:-}"; REQ_TARGET="${7:-}"; REQ_DQ="${8:-}"; REQ_MESH_JSON="${9:-}"
[[ -f "$CONFIG" ]] || { echo "ERROR: config not found: $CONFIG" >&2; exit 1; }
# shellcheck source=/dev/null
source "$CONFIG"
: "${STATION_HOST:?}"; : "${STATION_USER:?}"; : "${STATION_SSH_KEY:?}"; : "${STATION_BASE:?}"
COLMAP_MODE="${COLMAP_MODE:-podman}"; COLMAP_BIN="${COLMAP_BIN:-colmap}"; COLMAP_IMAGE="${COLMAP_IMAGE:-docker.io/colmap/colmap:latest}"
MESH_ENGINE="${REQ_ENGINE:-${MESH_ENGINE:-auto}}"; OPEN3D_PYTHON="${OPEN3D_PYTHON:-$STATION_BASE/open3d-venv/bin/python}"
if [[ "$MODE" == "preview" ]]; then DEPTH="${MESH_PREVIEW_POISSON_DEPTH:-7}"; TARGET="${MESH_PREVIEW_TARGET_FACES:-100000}"; else DEPTH="${MESH_HQ_POISSON_DEPTH:-9}"; TARGET="${MESH_HQ_TARGET_FACES:-500000}"; fi
[[ -n "$REQ_DEPTH" ]] && DEPTH="$REQ_DEPTH"
[[ -n "$REQ_TARGET" ]] && TARGET="$REQ_TARGET"
DENSITY_QUANTILE="${REQ_DQ:-${MESH_DENSITY_QUANTILE:-0.12}}"
read_mesh_json(){ python3 - "$REQ_MESH_JSON" "$1" "$2" <<'PY'
import json,sys
try: d=json.loads(sys.argv[1] or "{}")
except Exception: d={}
print(d.get(sys.argv[2], sys.argv[3]))
PY
}
STAT_NB=$(read_mesh_json statistical_nb_neighbors 24); STAT_STD=$(read_mesh_json statistical_std_ratio 2.0); RADIUS_NB=$(read_mesh_json radius_nb_points 6); RADIUS_MULT=$(read_mesh_json radius_multiplier 3.0); CROP_LOW=$(read_mesh_json crop_low_percentile 0.01); CROP_HIGH=$(read_mesh_json crop_high_percentile 0.99); MIN_COMP=$(read_mesh_json minimum_component_ratio 0.01); MAX_EDGE=$(read_mesh_json maximum_triangle_edge_multiplier 8.0)
MIN_IN="${MESH_MIN_INPUT_VERTICES:-500}"; MIN_FACES="${MESH_MIN_OUTPUT_FACES:-100}"
SSH=(ssh -i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new "${STATION_USER}@${STATION_HOST}")
printf -v B '%q' "$STATION_BASE"; printf -v DQ '%q' "$DENSITY_QUANTILE"; printf -v SN '%q' "$STAT_NB"; printf -v SS '%q' "$STAT_STD"; printf -v RN '%q' "$RADIUS_NB"; printf -v RM '%q' "$RADIUS_MULT"; printf -v CL '%q' "$CROP_LOW"; printf -v CH '%q' "$CROP_HIGH"; printf -v MC '%q' "$MIN_COMP"; printf -v MX '%q' "$MAX_EDGE"; printf -v CM '%q' "$COLMAP_MODE"; printf -v CB '%q' "$COLMAP_BIN"; printf -v CI '%q' "$COLMAP_IMAGE"; printf -v ME '%q' "$MESH_ENGINE"; printf -v OP '%q' "$OPEN3D_PYTHON"
printf -v A '%q ' "$MESH_JOB_ID" "$PARENT_JOB_ID" "$MODE" "$DEPTH" "$TARGET" "$MIN_IN" "$MIN_FACES"
"${SSH[@]}" "
mkdir -p '$STATION_BASE/logs'

echo '[launcher] mesh job $MESH_JOB_ID parent $PARENT_JOB_ID mode $MODE'

mkdir -p $B/input/job_${MESH_JOB_ID} && printf '{\"mesh\":{\"engine\":\"%s\",\"depth\":%s,\"target_faces\":%s,\"density_quantile\":%s}}\n' $ME "$DEPTH" "$TARGET" ${DQ:-0.07} > $B/input/job_${MESH_JOB_ID}/parameters.json

setsid -f env \
  STATION_BASE=$B \
  COLMAP_MODE=$CM \
  COLMAP_BIN=$CB \
  COLMAP_IMAGE=$CI \
  MESH_ENGINE=$ME \
  OPEN3D_PYTHON=$OP \
  MESH_DENSITY_QUANTILE=$DQ \
  OPEN3D_STANDARD_STATISTICAL_NB_NEIGHBORS=$SN OPEN3D_PREVIEW_STATISTICAL_NB_NEIGHBORS=$SN OPEN3D_HQ_STATISTICAL_NB_NEIGHBORS=$SN OPEN3D_FULLHD_STATISTICAL_NB_NEIGHBORS=$SN \
  OPEN3D_STANDARD_STATISTICAL_STD_RATIO=$SS OPEN3D_PREVIEW_STATISTICAL_STD_RATIO=$SS OPEN3D_HQ_STATISTICAL_STD_RATIO=$SS OPEN3D_FULLHD_STATISTICAL_STD_RATIO=$SS \
  OPEN3D_STANDARD_RADIUS_NB_POINTS=$RN OPEN3D_PREVIEW_RADIUS_NB_POINTS=$RN OPEN3D_HQ_RADIUS_NB_POINTS=$RN OPEN3D_FULLHD_RADIUS_NB_POINTS=$RN \
  OPEN3D_RADIUS_MULTIPLIER=$RM OPEN3D_CROP_LOW_PERCENTILE=$CL OPEN3D_CROP_HIGH_PERCENTILE=$CH OPEN3D_MINIMUM_COMPONENT_RATIO=$MC OPEN3D_MAXIMUM_TRIANGLE_EDGE_MULTIPLIER=$MX \
  $B/scripts/process_colmap_mesh.sh $A \
  > '$STATION_BASE/logs/job_${MESH_JOB_ID}_mesh_launcher.log' \
  2>&1 < /dev/null

echo launched
"
