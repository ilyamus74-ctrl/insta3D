#!/usr/bin/env bash
set -euo pipefail

cd /home/makler/web

CONFIG="/home/makler/web/remote_station/stations.conf"
ALIGN_SCRIPT="/home/makler/web/remote_station/scripts/align_dense_clouds_open3d.py"

ANCHOR="/home/makler/web/remote_station/output/job_860990938/merged/merged_fused.ply"
SOURCE="/home/makler/web/remote_station/output/job_917339860/merged/merged_fused.ply"

EXPECTED_ANCHOR_MD5="fb8302edf71f1842ae89fa5a7f2709ca"
EXPECTED_SOURCE_MD5="eb8c1affe67328bae9a723059cebc19b"
EXPECTED_ANCHOR_POINTS=618736
EXPECTED_SOURCE_POINTS=376878
EXPECTED_TOTAL_POINTS=995614

if [[ ! -f "$CONFIG" ]]; then
  echo "ERROR: station config not found: $CONFIG" >&2
  exit 1
fi

# shellcheck source=/dev/null
source "$CONFIG"

: "${STATION_HOST:?missing STATION_HOST}"
: "${STATION_USER:?missing STATION_USER}"
: "${STATION_SSH_KEY:?missing STATION_SSH_KEY}"
: "${STATION_BASE:?missing STATION_BASE}"

OPEN3D_PYTHON="${OPEN3D_PYTHON:-$STATION_BASE/open3d-venv/bin/python}"

for file in "$ALIGN_SCRIPT" "$ANCHOR" "$SOURCE"; do
  if [[ ! -s "$file" ]]; then
    echo "ERROR: required file is missing or empty: $file" >&2
    exit 1
  fi
done

python3 - "$ANCHOR" "$SOURCE" \
  "$EXPECTED_ANCHOR_MD5" "$EXPECTED_SOURCE_MD5" \
  "$EXPECTED_ANCHOR_POINTS" "$EXPECTED_SOURCE_POINTS" <<'PY'
import hashlib
import sys
from pathlib import Path


def md5(path: Path) -> str:
    h = hashlib.md5()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(8 * 1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def ply_points(path: Path) -> int:
    with path.open("rb") as f:
        while True:
            line = f.readline()
            if not line:
                raise RuntimeError(f"Invalid PLY header: {path}")
            text = line.decode("ascii", "replace").strip()
            if text.startswith("element vertex "):
                return int(text.split()[2])
            if text == "end_header":
                raise RuntimeError(f"PLY has no vertex count: {path}")


anchor = Path(sys.argv[1])
source = Path(sys.argv[2])
expected_anchor_md5 = sys.argv[3]
expected_source_md5 = sys.argv[4]
expected_anchor_points = int(sys.argv[5])
expected_source_points = int(sys.argv[6])

actual_anchor_md5 = md5(anchor)
actual_source_md5 = md5(source)
actual_anchor_points = ply_points(anchor)
actual_source_points = ply_points(source)

print(
    f"anchor points={actual_anchor_points} md5={actual_anchor_md5}\n"
    f"source points={actual_source_points} md5={actual_source_md5}"
)

if actual_anchor_md5 != expected_anchor_md5:
    raise SystemExit("ERROR: anchor MD5 does not match the expected model 0")
if actual_source_md5 != expected_source_md5:
    raise SystemExit("ERROR: source MD5 does not match the expected model 1")
if actual_anchor_points != expected_anchor_points:
    raise SystemExit("ERROR: anchor point count mismatch")
if actual_source_points != expected_source_points:
    raise SystemExit("ERROR: source point count mismatch")
PY

SSH=(
  ssh
  -i "$STATION_SSH_KEY"
  -o StrictHostKeyChecking=accept-new
  "${STATION_USER}@${STATION_HOST}"
)
SCP=(
  scp
  -i "$STATION_SSH_KEY"
  -o StrictHostKeyChecking=accept-new
)

RUN_ID="order30_icp_$(date +%Y%m%d_%H%M%S)"
REMOTE_DIR="$STATION_BASE/manual_merges/$RUN_ID"
LOCAL_DIR="/home/makler/web/remote_station/output/merged_order_30_icp_$RUN_ID"

mkdir -p "$LOCAL_DIR"

echo "Checking Open3D on ${STATION_USER}@${STATION_HOST}"
"${SSH[@]}" \
  "test -x $(printf '%q' "$OPEN3D_PYTHON") && $(printf '%q' "$OPEN3D_PYTHON") -c 'import open3d; print(open3d.__version__)'"

echo "Creating remote work directory: $REMOTE_DIR"
"${SSH[@]}" "mkdir -p $(printf '%q' "$REMOTE_DIR")"

echo "Copying alignment script and the two ready-made PLY files"
"${SCP[@]}" "$ALIGN_SCRIPT" \
  "${STATION_USER}@${STATION_HOST}:${REMOTE_DIR}/align_dense_clouds_open3d.py"
"${SCP[@]}" "$ANCHOR" \
  "${STATION_USER}@${STATION_HOST}:${REMOTE_DIR}/model0_anchor.ply"
"${SCP[@]}" "$SOURCE" \
  "${STATION_USER}@${STATION_HOST}:${REMOTE_DIR}/model1_source.ply"

remote_args=(
  "$OPEN3D_PYTHON"
  "$REMOTE_DIR/align_dense_clouds_open3d.py"
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

printf -v remote_command '%q ' "${remote_args[@]}"
remote_command+="2>&1 | tee $(printf '%q' "$REMOTE_DIR/run.log")"

echo "Running FPFH/RANSAC Sim(3) + ICP on GrafikStation"
"${SSH[@]}" "$remote_command"

echo "Copying generated artifacts back to the web server"
"${SCP[@]}" \
  "${STATION_USER}@${STATION_HOST}:${REMOTE_DIR}/result/icp_merged_dense_cloud.ply" \
  "$LOCAL_DIR/"
"${SCP[@]}" \
  "${STATION_USER}@${STATION_HOST}:${REMOTE_DIR}/result/model1_aligned_to_model0.ply" \
  "$LOCAL_DIR/"
"${SCP[@]}" \
  "${STATION_USER}@${STATION_HOST}:${REMOTE_DIR}/result/merge_result.json" \
  "$LOCAL_DIR/"
"${SCP[@]}" \
  "${STATION_USER}@${STATION_HOST}:${REMOTE_DIR}/run.log" \
  "$LOCAL_DIR/"

python3 - "$LOCAL_DIR" "$ANCHOR" "$SOURCE" \
  "$EXPECTED_TOTAL_POINTS" "$EXPECTED_ANCHOR_MD5" "$EXPECTED_SOURCE_MD5" <<'PY'
import hashlib
import json
import os
import sys
from pathlib import Path

local_dir = Path(sys.argv[1])
anchor = Path(sys.argv[2])
source = Path(sys.argv[3])
expected_total = int(sys.argv[4])
anchor_md5_expected = sys.argv[5]
source_md5_expected = sys.argv[6]

result_path = local_dir / "merge_result.json"
aligned_path = local_dir / "model1_aligned_to_model0.ply"
merged_path = local_dir / "icp_merged_dense_cloud.ply"


def md5(path: Path) -> str:
    h = hashlib.md5()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(8 * 1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def ply_points(path: Path) -> int:
    with path.open("rb") as f:
        while True:
            line = f.readline()
            if not line:
                raise RuntimeError(f"Invalid PLY header: {path}")
            text = line.decode("ascii", "replace").strip()
            if text.startswith("element vertex "):
                return int(text.split()[2])
            if text == "end_header":
                raise RuntimeError(f"PLY has no vertex count: {path}")


payload = json.loads(result_path.read_text(encoding="utf-8"))
if payload.get("status") != "DONE":
    raise SystemExit(
        "ERROR: alignment did not finish successfully; inspect merge_result.json and run.log"
    )

# Replace temporary GrafikStation paths with permanent web-server paths.
payload["anchor_ply"] = str(anchor)
payload["source_ply"] = str(source)
payload["aligned_source_ply"] = str(aligned_path)
payload["output_ply"] = str(merged_path)
payload["result_json"] = str(result_path)

inputs = payload.get("inputs", {})
if isinstance(inputs.get("anchor"), dict):
    inputs["anchor"]["path"] = str(anchor)
if isinstance(inputs.get("source"), dict):
    inputs["source"]["path"] = str(source)

for item in payload.get("included", []):
    if int(item.get("model", -1)) == 0:
        item["path"] = str(anchor)
    elif int(item.get("model", -1)) == 1:
        item["path"] = str(source)

for item in payload.get("source_jobs", []):
    if int(item.get("model_id", -1)) == 0:
        item["path"] = str(anchor)
    elif int(item.get("model_id", -1)) == 1:
        item["path"] = str(source)

files = payload.setdefault("files", {})
files.setdefault("aligned_source", {})["path"] = str(aligned_path)
files.setdefault("merged", {})["path"] = str(merged_path)

actual_total = ply_points(merged_path)
actual_aligned = ply_points(aligned_path)
merged_md5 = md5(merged_path)
aligned_md5 = md5(aligned_path)

payload["total_points"] = actual_total
payload["aligned_source_points"] = actual_aligned
payload["files"]["merged"]["size_bytes"] = merged_path.stat().st_size
payload["files"]["merged"]["md5"] = merged_md5
payload["files"]["aligned_source"]["size_bytes"] = aligned_path.stat().st_size
payload["files"]["aligned_source"]["md5"] = aligned_md5
payload["validation"]["point_count_is_exact_sum"] = actual_total == expected_total
payload["validation"]["merged_md5_differs_from_anchor"] = (
    merged_md5 != anchor_md5_expected
)
payload["validation"]["merged_md5_differs_from_source"] = (
    merged_md5 != source_md5_expected
)

tmp = result_path.with_suffix(".json.tmp")
tmp.write_text(
    json.dumps(payload, ensure_ascii=False, indent=2),
    encoding="utf-8",
)
os.replace(tmp, result_path)

print(f"local_dir={local_dir}")
print(f"aligned_points={actual_aligned}")
print(f"merged_points={actual_total}")
print(f"aligned_md5={aligned_md5}")
print(f"merged_md5={merged_md5}")
print(
    "scale=",
    payload.get("transform_source_to_anchor", {}).get("uniform_scale"),
)

if actual_aligned != 376878:
    raise SystemExit("ERROR: aligned source point count is not 376878")
if actual_total != expected_total:
    raise SystemExit(
        f"ERROR: merged point count is {actual_total}, expected {expected_total}"
    )
if merged_md5 in {anchor_md5_expected, source_md5_expected}:
    raise SystemExit("ERROR: merged file is identical to one of the source models")
PY

echo
echo "Artifacts are ready for visual review:"
echo "  $LOCAL_DIR/icp_merged_dense_cloud.ply"
echo "  $LOCAL_DIR/model1_aligned_to_model0.ply"
echo "  $LOCAL_DIR/merge_result.json"
echo "  $LOCAL_DIR/run.log"
echo
echo "No sfm_generated_model_merges row was created."
