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

source "$CONFIG"

: "${STATION_HOST:?missing STATION_HOST}"
: "${STATION_USER:?missing STATION_USER}"
: "${STATION_SSH_KEY:?missing STATION_SSH_KEY}"
: "${STATION_BASE:?missing STATION_BASE}"

OPEN3D_PYTHON="${OPEN3D_PYTHON:-$STATION_BASE/open3d-venv/bin/python}"

for file in "$ALIGN_SCRIPT" "$ANCHOR" "$SOURCE"; do
  [[ -s "$file" ]] || { echo "ERROR: missing file: $file" >&2; exit 1; }
done

python3 - "$ANCHOR" "$SOURCE" \
  "$EXPECTED_ANCHOR_MD5" "$EXPECTED_SOURCE_MD5" \
  "$EXPECTED_ANCHOR_POINTS" "$EXPECTED_SOURCE_POINTS" <<'PY'
import hashlib
import sys
from pathlib import Path


def md5(path):
    h = hashlib.md5()
    with Path(path).open("rb") as f:
        for chunk in iter(lambda: f.read(8 * 1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def points(path):
    with Path(path).open("rb") as f:
        for line in f:
            text = line.decode("ascii", "replace").strip()
            if text.startswith("element vertex "):
                return int(text.split()[2])
            if text == "end_header":
                break
    raise RuntimeError(f"Invalid PLY header: {path}")


anchor, source = sys.argv[1], sys.argv[2]
amd5, smd5 = sys.argv[3], sys.argv[4]
apoints, spoints = int(sys.argv[5]), int(sys.argv[6])

actual = {
    "anchor_md5": md5(anchor),
    "source_md5": md5(source),
    "anchor_points": points(anchor),
    "source_points": points(source),
}
print(
    f"anchor points={actual['anchor_points']} md5={actual['anchor_md5']}\n"
    f"source points={actual['source_points']} md5={actual['source_md5']}"
)
if actual["anchor_md5"] != amd5 or actual["source_md5"] != smd5:
    raise SystemExit("ERROR: source MD5 check failed")
if actual["anchor_points"] != apoints or actual["source_points"] != spoints:
    raise SystemExit("ERROR: source point-count check failed")
PY

SSH=(ssh -i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new "${STATION_USER}@${STATION_HOST}")
SCP=(scp -i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new)

RUN_ID="order30_icp_v2_$(date +%Y%m%d_%H%M%S)"
REMOTE_DIR="$STATION_BASE/manual_merges/$RUN_ID"
LOCAL_DIR="/home/makler/web/remote_station/output/merged_order_30_icp_$RUN_ID"
mkdir -p "$LOCAL_DIR"

echo "Checking Open3D on ${STATION_USER}@${STATION_HOST}"
"${SSH[@]}" \
  "test -x $(printf '%q' "$OPEN3D_PYTHON") && $(printf '%q' "$OPEN3D_PYTHON") -c 'import open3d; print(open3d.__version__)'"

echo "Creating remote work directory: $REMOTE_DIR"
"${SSH[@]}" "mkdir -p $(printf '%q' "$REMOTE_DIR")"

echo "Copying the alignment script and two existing PLY files"
"${SCP[@]}" "$ALIGN_SCRIPT" \
  "${STATION_USER}@${STATION_HOST}:${REMOTE_DIR}/align.py"
"${SCP[@]}" "$ANCHOR" \
  "${STATION_USER}@${STATION_HOST}:${REMOTE_DIR}/model0_anchor.ply"
"${SCP[@]}" "$SOURCE" \
  "${STATION_USER}@${STATION_HOST}:${REMOTE_DIR}/model1_source.ply"

ARGS=(
  "$OPEN3D_PYTHON"
  "$REMOTE_DIR/align.py"
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

# Always return diagnostics first. A failed Python process must not be hidden by tee.
"${SCP[@]}" \
  "${STATION_USER}@${STATION_HOST}:${REMOTE_DIR}/run.log" \
  "$LOCAL_DIR/" 2>/dev/null || true
"${SCP[@]}" \
  "${STATION_USER}@${STATION_HOST}:${REMOTE_DIR}/result/merge_result.json" \
  "$LOCAL_DIR/" 2>/dev/null || true

if [[ $REMOTE_STATUS -ne 0 ]]; then
  echo "ERROR: alignment failed with exit code $REMOTE_STATUS" >&2
  echo "Diagnostics:" >&2
  echo "  $LOCAL_DIR/run.log" >&2
  echo "  $LOCAL_DIR/merge_result.json" >&2
  exit "$REMOTE_STATUS"
fi

echo "Copying generated PLY files"
"${SCP[@]}" \
  "${STATION_USER}@${STATION_HOST}:${REMOTE_DIR}/result/icp_merged_dense_cloud.ply" \
  "$LOCAL_DIR/"
"${SCP[@]}" \
  "${STATION_USER}@${STATION_HOST}:${REMOTE_DIR}/result/model1_aligned_to_model0.ply" \
  "$LOCAL_DIR/"

python3 - "$LOCAL_DIR" "$ANCHOR" "$SOURCE" \
  "$EXPECTED_TOTAL_POINTS" "$EXPECTED_ANCHOR_MD5" "$EXPECTED_SOURCE_MD5" <<'PY'
import hashlib
import json
import os
import sys
from pathlib import Path

directory = Path(sys.argv[1])
anchor = Path(sys.argv[2])
source = Path(sys.argv[3])
expected_total = int(sys.argv[4])
anchor_md5 = sys.argv[5]
source_md5 = sys.argv[6]

result_path = directory / "merge_result.json"
aligned_path = directory / "model1_aligned_to_model0.ply"
merged_path = directory / "icp_merged_dense_cloud.ply"


def md5(path):
    h = hashlib.md5()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(8 * 1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def points(path):
    with path.open("rb") as f:
        for line in f:
            text = line.decode("ascii", "replace").strip()
            if text.startswith("element vertex "):
                return int(text.split()[2])
            if text == "end_header":
                break
    raise RuntimeError(f"Invalid PLY header: {path}")


for path in (result_path, aligned_path, merged_path):
    if not path.is_file() or path.stat().st_size <= 0:
        raise SystemExit(f"ERROR: missing result file: {path}")

payload = json.loads(result_path.read_text(encoding="utf-8"))
if payload.get("status") != "DONE":
    raise SystemExit("ERROR: merge_result.json is not DONE")

actual_total = points(merged_path)
actual_aligned = points(aligned_path)
merged_md5 = md5(merged_path)

if actual_aligned != 376878:
    raise SystemExit(f"ERROR: aligned points={actual_aligned}, expected 376878")
if actual_total != expected_total:
    raise SystemExit(
        f"ERROR: merged points={actual_total}, expected {expected_total}"
    )
if merged_md5 in {anchor_md5, source_md5}:
    raise SystemExit("ERROR: merged MD5 equals an input MD5")

# Rewrite temporary GrafikStation paths to permanent web paths.
payload["anchor_ply"] = str(anchor)
payload["source_ply"] = str(source)
payload["aligned_source_ply"] = str(aligned_path)
payload["output_ply"] = str(merged_path)
payload["result_json"] = str(result_path)
for key, path in (("aligned_source", aligned_path), ("merged", merged_path)):
    payload.setdefault("files", {}).setdefault(key, {})["path"] = str(path)

tmp = result_path.with_suffix(".json.tmp")
tmp.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
os.replace(tmp, result_path)

print(f"local_dir={directory}")
print(f"aligned_points={actual_aligned}")
print(f"merged_points={actual_total}")
print(f"merged_md5={merged_md5}")
print(
    "scale=",
    payload.get("transform_source_to_anchor", {}).get("uniform_scale"),
)
PY

echo
echo "Artifacts ready for visual review:"
echo "  $LOCAL_DIR/icp_merged_dense_cloud.ply"
echo "  $LOCAL_DIR/model1_aligned_to_model0.ply"
echo "  $LOCAL_DIR/merge_result.json"
echo "  $LOCAL_DIR/run.log"
echo
echo "No sfm_generated_model_merges row was created."
