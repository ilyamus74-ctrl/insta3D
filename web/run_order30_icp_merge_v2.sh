#!/usr/bin/env bash
set -euo pipefail

cd /home/makler/web

CONFIG="/home/makler/web/remote_station/stations.conf"
ALIGN_SCRIPT="/home/makler/web/remote_station/scripts/align_dense_clouds_scale_search.py"
ANCHOR="/home/makler/web/remote_station/output/job_860990938/merged/merged_fused.ply"
SOURCE="/home/makler/web/remote_station/output/job_917339860/merged/merged_fused.ply"

EXPECTED_ANCHOR_MD5="fb8302edf71f1842ae89fa5a7f2709ca"
EXPECTED_SOURCE_MD5="eb8c1affe67328bae9a723059cebc19b"
EXPECTED_ANCHOR_POINTS=618736
EXPECTED_SOURCE_POINTS=376878
EXPECTED_TOTAL_POINTS=995614

[[ -f "$CONFIG" ]] || { echo "ERROR: config not found: $CONFIG" >&2; exit 1; }
# shellcheck source=/dev/null
source "$CONFIG"

: "${STATION_HOST:?missing STATION_HOST}"
: "${STATION_USER:?missing STATION_USER}"
: "${STATION_SSH_KEY:?missing STATION_SSH_KEY}"
: "${STATION_BASE:?missing STATION_BASE}"
OPEN3D_PYTHON="${OPEN3D_PYTHON:-$STATION_BASE/open3d-venv/bin/python}"

for file in "$ALIGN_SCRIPT" "$ANCHOR" "$SOURCE"; do
  [[ -s "$file" ]] || { echo "ERROR: missing or empty: $file" >&2; exit 1; }
done

ply_points() {
  sed -n 's/^element vertex \([0-9][0-9]*\)$/\1/p' "$1" | head -n1
}

ANCHOR_MD5="$(md5sum "$ANCHOR" | awk '{print $1}')"
SOURCE_MD5="$(md5sum "$SOURCE" | awk '{print $1}')"
ANCHOR_POINTS="$(ply_points "$ANCHOR")"
SOURCE_POINTS="$(ply_points "$SOURCE")"

echo "anchor points=$ANCHOR_POINTS md5=$ANCHOR_MD5"
echo "source points=$SOURCE_POINTS md5=$SOURCE_MD5"

[[ "$ANCHOR_MD5" == "$EXPECTED_ANCHOR_MD5" ]] || { echo "ERROR: anchor MD5 mismatch" >&2; exit 1; }
[[ "$SOURCE_MD5" == "$EXPECTED_SOURCE_MD5" ]] || { echo "ERROR: source MD5 mismatch" >&2; exit 1; }
[[ "$ANCHOR_POINTS" == "$EXPECTED_ANCHOR_POINTS" ]] || { echo "ERROR: anchor point count mismatch" >&2; exit 1; }
[[ "$SOURCE_POINTS" == "$EXPECTED_SOURCE_POINTS" ]] || { echo "ERROR: source point count mismatch" >&2; exit 1; }

SSH=(ssh -i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new "${STATION_USER}@${STATION_HOST}")
SCP=(scp -i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new)

RUN_ID="order30_scale_icp_$(date +%Y%m%d_%H%M%S)"
REMOTE_DIR="$STATION_BASE/manual_merges/$RUN_ID"
LOCAL_DIR="/home/makler/web/remote_station/output/merged_order_30_scale_icp_$RUN_ID"
mkdir -p "$LOCAL_DIR"

echo "Checking Open3D on ${STATION_USER}@${STATION_HOST}"
"${SSH[@]}" "test -x $(printf '%q' "$OPEN3D_PYTHON") && $(printf '%q' "$OPEN3D_PYTHON") -c 'import open3d; print(open3d.__version__)'"

echo "Creating remote work directory: $REMOTE_DIR"
"${SSH[@]}" "mkdir -p $(printf '%q' "$REMOTE_DIR")"

echo "Copying alignment script and ready-made PLY files"
"${SCP[@]}" "$ALIGN_SCRIPT" "${STATION_USER}@${STATION_HOST}:${REMOTE_DIR}/align_dense_clouds_scale_search.py"
"${SCP[@]}" "$ANCHOR" "${STATION_USER}@${STATION_HOST}:${REMOTE_DIR}/model0_anchor.ply"
"${SCP[@]}" "$SOURCE" "${STATION_USER}@${STATION_HOST}:${REMOTE_DIR}/model1_source.ply"

ARGS=(
  "$OPEN3D_PYTHON"
  "$REMOTE_DIR/align_dense_clouds_scale_search.py"
  --anchor "$REMOTE_DIR/model0_anchor.ply"
  --source "$REMOTE_DIR/model1_source.ply"
  --output-dir "$REMOTE_DIR/result"
  --anchor-model-id 0
  --source-model-id 1
  --anchor-db-job-id 654
  --source-db-job-id 655
  --anchor-remote-job-id 860990938
  --source-remote-job-id 917339860
  --voxel-divisors 100,150,220
  --max-feature-points 80000
  --ransac-iterations 150000
  --scale-bound-factor 10
  --seed 42
)
printf -v COMMAND '%q ' "${ARGS[@]}"
COMMAND+="2>&1 | tee $(printf '%q' "$REMOTE_DIR/run.log")"

echo "Running scale-search FPFH/RANSAC + ICP on GrafikStation"
set +e
"${SSH[@]}" "set -o pipefail; $COMMAND"
REMOTE_STATUS=$?
set -e

# Always copy diagnostics. The Python exit code is preserved despite tee.
"${SCP[@]}" "${STATION_USER}@${STATION_HOST}:${REMOTE_DIR}/run.log" "$LOCAL_DIR/" 2>/dev/null || true
"${SCP[@]}" "${STATION_USER}@${STATION_HOST}:${REMOTE_DIR}/result/merge_result.json" "$LOCAL_DIR/" 2>/dev/null || true

if [[ $REMOTE_STATUS -ne 0 ]]; then
  echo "ERROR: alignment failed with exit code $REMOTE_STATUS" >&2
  echo "Diagnostics:" >&2
  echo "  $LOCAL_DIR/run.log" >&2
  echo "  $LOCAL_DIR/merge_result.json" >&2
  exit "$REMOTE_STATUS"
fi

echo "Copying generated PLY files"
"${SCP[@]}" "${STATION_USER}@${STATION_HOST}:${REMOTE_DIR}/result/icp_merged_dense_cloud.ply" "$LOCAL_DIR/"
"${SCP[@]}" "${STATION_USER}@${STATION_HOST}:${REMOTE_DIR}/result/model1_aligned_to_model0.ply" "$LOCAL_DIR/"

MERGED="$LOCAL_DIR/icp_merged_dense_cloud.ply"
ALIGNED="$LOCAL_DIR/model1_aligned_to_model0.ply"
RESULT="$LOCAL_DIR/merge_result.json"

MERGED_POINTS="$(ply_points "$MERGED")"
ALIGNED_POINTS="$(ply_points "$ALIGNED")"
MERGED_MD5="$(md5sum "$MERGED" | awk '{print $1}')"
ALIGNED_MD5="$(md5sum "$ALIGNED" | awk '{print $1}')"

echo "local_dir=$LOCAL_DIR"
echo "aligned_points=$ALIGNED_POINTS"
echo "merged_points=$MERGED_POINTS"
echo "aligned_md5=$ALIGNED_MD5"
echo "merged_md5=$MERGED_MD5"

[[ "$ALIGNED_POINTS" == "$EXPECTED_SOURCE_POINTS" ]] || { echo "ERROR: aligned point count mismatch" >&2; exit 1; }
[[ "$MERGED_POINTS" == "$EXPECTED_TOTAL_POINTS" ]] || { echo "ERROR: merged point count mismatch" >&2; exit 1; }
[[ "$MERGED_MD5" != "$EXPECTED_ANCHOR_MD5" ]] || { echo "ERROR: merged file equals anchor" >&2; exit 1; }
[[ "$MERGED_MD5" != "$EXPECTED_SOURCE_MD5" ]] || { echo "ERROR: merged file equals source" >&2; exit 1; }

# Rewrite temporary GrafikStation paths to permanent web-server paths.
python3 - "$RESULT" "$ANCHOR" "$SOURCE" "$ALIGNED" "$MERGED" <<'PY'
import json
import os
import sys
from pathlib import Path

result_path = Path(sys.argv[1])
anchor = Path(sys.argv[2])
source = Path(sys.argv[3])
aligned = Path(sys.argv[4])
merged = Path(sys.argv[5])

payload = json.loads(result_path.read_text(encoding="utf-8"))
payload["anchor_ply"] = str(anchor)
payload["source_ply"] = str(source)
payload["aligned_source_ply"] = str(aligned)
payload["output_ply"] = str(merged)
payload["result_json"] = str(result_path)

inputs = payload.get("inputs", {})
if isinstance(inputs.get("anchor"), dict):
    inputs["anchor"]["path"] = str(anchor)
if isinstance(inputs.get("source"), dict):
    inputs["source"]["path"] = str(source)

for item in payload.get("included", []):
    item["path"] = str(anchor if int(item.get("model", -1)) == 0 else source)
for item in payload.get("source_jobs", []):
    item["path"] = str(anchor if int(item.get("model_id", -1)) == 0 else source)

files = payload.setdefault("files", {})
files.setdefault("aligned_source", {})["path"] = str(aligned)
files.setdefault("merged", {})["path"] = str(merged)

tmp = result_path.with_suffix(".json.tmp")
tmp.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
os.replace(tmp, result_path)
PY

echo
echo "Artifacts are ready for visual review:"
echo "  $MERGED"
echo "  $ALIGNED"
echo "  $RESULT"
echo "  $LOCAL_DIR/run.log"
echo
echo "No sfm_generated_model_merges row was created."
