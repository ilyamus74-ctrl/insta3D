#!/usr/bin/env bash
set -euo pipefail

cd /home/makler/web

SCRIPT="/home/makler/web/remote_station/scripts/align_dense_clouds_from_bridge_poses.py"

SPARSE_JOB_DIR="/home/makler/web/remote_station/output/job_972009591"
ANCHOR="/home/makler/web/remote_station/output/job_860990938/merged/merged_fused.ply"
SOURCE="/home/makler/web/remote_station/output/job_917339860/merged/merged_fused.ply"

EXPECTED_ANCHOR_MD5="fb8302edf71f1842ae89fa5a7f2709ca"
EXPECTED_SOURCE_MD5="eb8c1affe67328bae9a723059cebc19b"
EXPECTED_ANCHOR_POINTS=618736
EXPECTED_SOURCE_POINTS=376878
EXPECTED_TOTAL_POINTS=995614

for file in \
  "$SCRIPT" \
  "$SPARSE_JOB_DIR/colmap/database.db" \
  "$SPARSE_JOB_DIR/colmap/sparse/0/txt/images.txt" \
  "$SPARSE_JOB_DIR/colmap/sparse/0/txt/points3D.txt" \
  "$SPARSE_JOB_DIR/colmap/sparse/1/txt/images.txt" \
  "$SPARSE_JOB_DIR/colmap/sparse/1/txt/points3D.txt" \
  "$ANCHOR" \
  "$SOURCE"
do
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
    digest = hashlib.md5()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(8 * 1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def points(path: Path) -> int:
    with path.open("rb") as handle:
        while True:
            line = handle.readline()
            if not line:
                raise RuntimeError(f"Invalid PLY: {path}")
            text = line.decode("ascii", "replace").strip()
            if text.startswith("element vertex "):
                return int(text.split()[2])
            if text == "end_header":
                raise RuntimeError(f"No vertex count: {path}")


anchor = Path(sys.argv[1])
source = Path(sys.argv[2])

actual_anchor_md5 = md5(anchor)
actual_source_md5 = md5(source)
actual_anchor_points = points(anchor)
actual_source_points = points(source)

print(
    f"anchor points={actual_anchor_points} md5={actual_anchor_md5}\n"
    f"source points={actual_source_points} md5={actual_source_md5}"
)

if actual_anchor_md5 != sys.argv[3]:
    raise SystemExit("ERROR: anchor MD5 mismatch")
if actual_source_md5 != sys.argv[4]:
    raise SystemExit("ERROR: source MD5 mismatch")
if actual_anchor_points != int(sys.argv[5]):
    raise SystemExit("ERROR: anchor point count mismatch")
if actual_source_points != int(sys.argv[6]):
    raise SystemExit("ERROR: source point count mismatch")
PY

RUN_ID="order30_bridge_$(date +%Y%m%d_%H%M%S)"
OUTPUT_DIR="/home/makler/web/remote_station/output/merged_order_30_bridge_poses_${RUN_ID}"

mkdir -p "$OUTPUT_DIR"

echo "Running bridge-frame camera-pose alignment"
echo "Output: $OUTPUT_DIR"

set +e
set -o pipefail

python3 "$SCRIPT" \
  --sparse-job-dir "$SPARSE_JOB_DIR" \
  --anchor-ply "$ANCHOR" \
  --source-ply "$SOURCE" \
  --output-dir "$OUTPUT_DIR" \
  --iterations-per-offset 30000 \
  --minimum-image-pairs 6 \
  --minimum-camera-inliers 5 \
  --minimum-camera-inlier-ratio 0.45 \
  --minimum-point-inliers 8 \
  --scale-factor 3 \
  --seed 42 \
  2>&1 | tee "$OUTPUT_DIR/run.log"

STATUS=${PIPESTATUS[0]}
set +o pipefail
set -e

if [[ $STATUS -ne 0 ]]; then
  echo "ERROR: alignment failed with exit code $STATUS" >&2
  echo "Diagnostics:" >&2
  echo "  $OUTPUT_DIR/run.log" >&2
  echo "  $OUTPUT_DIR/merge_result.json" >&2
  exit "$STATUS"
fi

python3 - "$OUTPUT_DIR" \
  "$EXPECTED_SOURCE_POINTS" "$EXPECTED_TOTAL_POINTS" \
  "$EXPECTED_ANCHOR_MD5" "$EXPECTED_SOURCE_MD5" <<'PY'
import hashlib
import json
import sys
from pathlib import Path

directory = Path(sys.argv[1])
expected_aligned = int(sys.argv[2])
expected_total = int(sys.argv[3])
anchor_md5 = sys.argv[4]
source_md5 = sys.argv[5]

aligned = directory / "model1_aligned_to_model0_bridge_poses.ply"
merged = directory / "bridge_pose_merged_dense_cloud.ply"
result_path = directory / "merge_result.json"


def md5(path: Path) -> str:
    digest = hashlib.md5()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(8 * 1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def points(path: Path) -> int:
    with path.open("rb") as handle:
        while True:
            line = handle.readline()
            if not line:
                raise RuntimeError(f"Invalid PLY: {path}")
            text = line.decode("ascii", "replace").strip()
            if text.startswith("element vertex "):
                return int(text.split()[2])
            if text == "end_header":
                raise RuntimeError(f"No vertex count: {path}")


payload = json.loads(result_path.read_text(encoding="utf-8"))

if payload.get("status") != "DONE":
    raise SystemExit("ERROR: merge_result status is not DONE")

aligned_points = points(aligned)
merged_points = points(merged)
aligned_md5 = md5(aligned)
merged_md5 = md5(merged)

candidate = payload["selected_candidate"]

print(f"selected_offset={payload['selected_offset']}")
print(f"scale={payload['transform_source_to_anchor']['uniform_scale']}")
print(
    f"camera_inliers={candidate['camera_inlier_count']}/"
    f"{candidate['image_pair_count']}"
)
print(
    f"point_inliers={candidate['point_inlier_count']}/"
    f"{candidate['point_pair_count']}"
)
print(
    f"orientation_median_degrees="
    f"{candidate['orientation_median_degrees']}"
)
print(f"aligned_points={aligned_points}")
print(f"merged_points={merged_points}")
print(f"aligned_md5={aligned_md5}")
print(f"merged_md5={merged_md5}")

if aligned_points != expected_aligned:
    raise SystemExit(
        f"ERROR: aligned points {aligned_points}, expected {expected_aligned}"
    )
if merged_points != expected_total:
    raise SystemExit(
        f"ERROR: merged points {merged_points}, expected {expected_total}"
    )
if merged_md5 in {anchor_md5, source_md5}:
    raise SystemExit(
        "ERROR: merged file is identical to a source"
    )
PY

echo
echo "Artifacts ready for visual review:"
echo "  $OUTPUT_DIR/bridge_pose_merged_dense_cloud.ply"
echo "  $OUTPUT_DIR/model1_aligned_to_model0_bridge_poses.ply"
echo "  $OUTPUT_DIR/merge_result.json"
echo "  $OUTPUT_DIR/camera_pair_inliers.csv"
echo "  $OUTPUT_DIR/run.log"
echo
echo "No sfm_generated_model_merges row was created."
