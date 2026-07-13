#!/usr/bin/env bash
set -Eeuo pipefail

cd /home/makler/web

CONFIG="/home/makler/web/remote_station/stations.conf"
POSE_EXPORT="/home/makler/web/remote_station/scripts/sparse_component_pose_export.py"

CURRENT_SPARSE="/home/makler/web/remote_station/output/job_972009591/colmap/sparse"
ANCHOR_PLY="/home/makler/web/remote_station/output/job_860990938/merged/merged_fused.ply"
SOURCE_PLY="/home/makler/web/remote_station/output/job_917339860/merged/merged_fused.ply"

EXPECTED_ANCHOR_POINTS=618736
EXPECTED_SOURCE_POINTS=376878
EXPECTED_TOTAL_POINTS=995614
EXPECTED_ANCHOR_MD5="fb8302edf71f1842ae89fa5a7f2709ca"
EXPECTED_SOURCE_MD5="eb8c1affe67328bae9a723059cebc19b"

# Can be overridden:
#   BRIDGE_START=200 BRIDGE_END=380 ./run_order30_sparse_bridge_merge.sh
BRIDGE_START="${BRIDGE_START:-220}"
BRIDGE_END="${BRIDGE_END:-360}"

[[ -f "$CONFIG" ]] || {
  echo "ERROR: station config not found: $CONFIG" >&2
  exit 1
}

# shellcheck source=/dev/null
source "$CONFIG"

: "${STATION_HOST:?missing STATION_HOST}"
: "${STATION_USER:?missing STATION_USER}"
: "${STATION_SSH_KEY:?missing STATION_SSH_KEY}"
: "${STATION_BASE:?missing STATION_BASE}"

REMOTE_SOURCE_FRAMES="$STATION_BASE/output/job_74380741/frames"
REMOTE_SPARSE_SCRIPT="$STATION_BASE/scripts/process_colmap_sparse.sh"

for required in \
  "$POSE_EXPORT" \
  "$CURRENT_SPARSE/0/images.bin" \
  "$CURRENT_SPARSE/1/images.bin" \
  "$ANCHOR_PLY" \
  "$SOURCE_PLY"
do
  [[ -s "$required" ]] || {
    echo "ERROR: required local file is missing or empty: $required" >&2
    exit 1
  }
done

python3 - "$ANCHOR_PLY" "$SOURCE_PLY" \
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
actual_anchor_points = ply_points(anchor)
actual_source_points = ply_points(source)

print(f"anchor points={actual_anchor_points} md5={actual_anchor_md5}")
print(f"source points={actual_source_points} md5={actual_source_md5}")

if actual_anchor_md5 != sys.argv[3]:
    raise SystemExit("ERROR: anchor MD5 mismatch")
if actual_source_md5 != sys.argv[4]:
    raise SystemExit("ERROR: source MD5 mismatch")
if actual_anchor_points != int(sys.argv[5]):
    raise SystemExit("ERROR: anchor point count mismatch")
if actual_source_points != int(sys.argv[6]):
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

RUN_ID="order30_sparse_bridge_$(date +%Y%m%d_%H%M%S)"
# Not a DB job; only a unique local status/log namespace for process_colmap_sparse.sh.
BRIDGE_JOB_ID="93$(date +%s)"

REMOTE_ROOT="$STATION_BASE/manual_bridges/$RUN_ID"
REMOTE_FRAMES="$REMOTE_ROOT/frames"
REMOTE_COLMAP="$REMOTE_ROOT/colmap"
REMOTE_RUN_LOG="$REMOTE_ROOT/bridge_sparse_console.log"

LOCAL_DIR="/home/makler/web/remote_station/output/merged_order_30_sparse_bridge_${RUN_ID}"
LOCAL_BRIDGE="$LOCAL_DIR/bridge_colmap"
GRAPH_DIR="$LOCAL_DIR/alignment_graph_sparse"
POSES_JSON="$LOCAL_DIR/alignment_graph_poses.json"
MERGE_SPEC="$LOCAL_DIR/merge_spec.json"
RESULT_JSON="$LOCAL_DIR/merge_result.json"
OUTPUT_PLY="$LOCAL_DIR/bridge_merged_dense_cloud.ply"

mkdir -p "$LOCAL_BRIDGE" "$GRAPH_DIR"

echo "Checking bridge source frames on ${STATION_USER}@${STATION_HOST}"
"${SSH[@]}" \
  "test -d $(printf '%q' "$REMOTE_SOURCE_FRAMES") && \
   test -x $(printf '%q' "$REMOTE_SPARSE_SCRIPT")"

printf -v REMOTE_PREP_COMMAND '%q ' bash -s -- \
  "$REMOTE_ROOT" \
  "$REMOTE_FRAMES" \
  "$REMOTE_COLMAP" \
  "$REMOTE_SOURCE_FRAMES" \
  "$STATION_BASE" \
  "$REMOTE_SPARSE_SCRIPT" \
  "$BRIDGE_JOB_ID" \
  "$BRIDGE_START" \
  "$BRIDGE_END" \
  "$REMOTE_RUN_LOG"

echo "Running sparse-only bridge reconstruction"
echo "Frame range: $BRIDGE_START..$BRIDGE_END"
echo "Remote output: $REMOTE_COLMAP"

set +e
"${SSH[@]}" "$REMOTE_PREP_COMMAND" <<'REMOTE_SCRIPT'
set -Eeuo pipefail

REMOTE_ROOT="$1"
REMOTE_FRAMES="$2"
REMOTE_COLMAP="$3"
SOURCE_FRAMES="$4"
STATION_BASE="$5"
SPARSE_SCRIPT="$6"
JOB_ID="$7"
START_FRAME="$8"
END_FRAME="$9"
RUN_LOG="${10}"

rm -rf "$REMOTE_ROOT"
mkdir -p "$REMOTE_FRAMES" "$REMOTE_COLMAP"
mkdir -p "$STATION_BASE/input/job_${JOB_ID}"

for number in $(seq "$START_FRAME" "$END_FRAME"); do
  filename="$(printf 'frame_%06d.jpg' "$number")"
  source="$SOURCE_FRAMES/$filename"

  if [[ -s "$source" ]]; then
    ln -s "$source" "$REMOTE_FRAMES/$filename"
  fi
done

frame_count="$(find "$REMOTE_FRAMES" -maxdepth 1 -type l -name 'frame_*.jpg' | wc -l | tr -d ' ')"
echo "Bridge input frames: $frame_count"

if (( frame_count < 20 )); then
  echo "ERROR: bridge frame subset contains only $frame_count images" >&2
  exit 2
fi

cat > "$STATION_BASE/input/job_${JOB_ID}/parameters.json" <<JSON
{
  "settings": {
    "sparse": {
      "matcher": "exhaustive",
      "sequential_overlap": 200,
      "loop_detection": false
    }
  },
  "sparse": {
    "sequential_overlap": 200,
    "loop_detection": false
  }
}
JSON

set -o pipefail
STATION_BASE="$STATION_BASE" \
COLMAP_MATCHER=exhaustive \
COLMAP_SEQUENTIAL_OVERLAP=200 \
COLMAP_LOOP_DETECTION=0 \
"$SPARSE_SCRIPT" "$JOB_ID" "$REMOTE_FRAMES" "$REMOTE_COLMAP" \
  2>&1 | tee "$RUN_LOG"

model_count="$(find "$REMOTE_COLMAP/sparse" -mindepth 1 -maxdepth 1 -type d -printf '.' | wc -c)"
echo "Bridge sparse components: $model_count"

if (( model_count < 1 )); then
  echo "ERROR: bridge sparse produced no registered component" >&2
  exit 3
fi
REMOTE_SCRIPT
REMOTE_STATUS=$?
set -e

# Always return diagnostics when possible.
"${SCP[@]}" \
  "${STATION_USER}@${STATION_HOST}:${REMOTE_RUN_LOG}" \
  "$LOCAL_DIR/" 2>/dev/null || true

if [[ $REMOTE_STATUS -ne 0 ]]; then
  echo "ERROR: bridge sparse failed with exit code $REMOTE_STATUS" >&2
  echo "Diagnostic log: $LOCAL_DIR/bridge_sparse_console.log" >&2
  exit "$REMOTE_STATUS"
fi

echo "Copying bridge sparse artifacts to web server"
"${SCP[@]}" -r \
  "${STATION_USER}@${STATION_HOST}:${REMOTE_COLMAP}/sparse" \
  "$LOCAL_BRIDGE/"

"${SCP[@]}" \
  "${STATION_USER}@${STATION_HOST}:${REMOTE_COLMAP}/database.db" \
  "$LOCAL_BRIDGE/" 2>/dev/null || true

"${SCP[@]}" -r \
  "${STATION_USER}@${STATION_HOST}:${REMOTE_COLMAP}/logs" \
  "$LOCAL_BRIDGE/" 2>/dev/null || true

# Build a synthetic sparse graph:
#   model 0   = current large component
#   model 1   = current isolated component
#   model 100+ = sparse-only bridge components
ln -s "$CURRENT_SPARSE/0" "$GRAPH_DIR/0"
ln -s "$CURRENT_SPARSE/1" "$GRAPH_DIR/1"

next_id=100
bridge_component_count=0

for bridge_model in "$LOCAL_BRIDGE/sparse"/*; do
  [[ -d "$bridge_model" ]] || continue
  [[ -s "$bridge_model/images.bin" || -s "$bridge_model/images.txt" ]] || continue

  ln -s "$bridge_model" "$GRAPH_DIR/$next_id"
  bridge_component_count=$((bridge_component_count + 1))
  next_id=$((next_id + 1))
done

if (( bridge_component_count < 1 )); then
  echo "ERROR: copied bridge sparse contains no usable components" >&2
  exit 4
fi

echo "Bridge graph components added: $bridge_component_count"

python3 "$POSE_EXPORT" \
  --sparse-dir "$GRAPH_DIR" \
  --output-json "$POSES_JSON"

python3 - "$POSES_JSON" <<'PY'
import json
import sys
from pathlib import Path

poses_path = Path(sys.argv[1])
payload = json.loads(poses_path.read_text(encoding="utf-8"))
models = {
    int(model["model_id"]): model
    for model in payload.get("models", [])
}


def names(model_id: int) -> set[str]:
    model = models.get(model_id, {})
    return {
        str(image.get("image_name", ""))
        for image in model.get("images", [])
        if image.get("image_name")
    }


current0 = names(0)
current1 = names(1)

print(
    f"Current model 0 images={len(current0)}; "
    f"current model 1 images={len(current1)}"
)

usable = []

for model_id in sorted(mid for mid in models if mid >= 100):
    bridge = names(model_id)
    shared0 = sorted(current0 & bridge)
    shared1 = sorted(current1 & bridge)

    print(
        f"Bridge model {model_id}: registered={len(bridge)} "
        f"shared_with_0={len(shared0)} "
        f"shared_with_1={len(shared1)}"
    )

    if shared0:
        print(f"  model0 examples={shared0[:8]}")
    if shared1:
        print(f"  model1 examples={shared1[:8]}")

    if len(shared0) >= 3 and len(shared1) >= 3:
        usable.append(model_id)

if not usable:
    raise SystemExit(
        "ERROR: no single bridge sparse component has at least "
        "3 exact shared image names with both current models"
    )

print("Usable bridge components:", usable)
PY

python3 - "$MERGE_SPEC" "$RESULT_JSON" "$ANCHOR_PLY" "$SOURCE_PLY" <<'PY'
import json
import sys
from pathlib import Path

spec_path = Path(sys.argv[1])
result_path = Path(sys.argv[2])
anchor_path = Path(sys.argv[3])
source_path = Path(sys.argv[4])

spec = {
    "sources": [
        {
            "db_job_id": 654,
            "remote_job_id": 860990938,
            "model_id": 0,
            "mode": "preview",
            "points": 618736,
            "path": str(anchor_path),
            "capture_session_id": 63,
            "sparse_remote_job_id": 972009591,
        },
        {
            "db_job_id": 655,
            "remote_job_id": 917339860,
            "model_id": 1,
            "mode": "preview",
            "points": 376878,
            "path": str(source_path),
            "capture_session_id": 63,
            "sparse_remote_job_id": 972009591,
        },
    ],
    "result_json": str(result_path),
}

spec_path.write_text(
    json.dumps(spec, ensure_ascii=False, indent=2),
    encoding="utf-8",
)
PY

echo "Running the existing shared-image pose graph merge"

python3 "$POSE_EXPORT" \
  --sparse-dir "$GRAPH_DIR" \
  --output-json "$POSES_JSON" \
  --merge-spec-json "$MERGE_SPEC" \
  --output-ply "$OUTPUT_PLY" \
  --anchor-model-id 0

python3 - "$RESULT_JSON" "$OUTPUT_PLY" \
  "$EXPECTED_TOTAL_POINTS" \
  "$EXPECTED_ANCHOR_MD5" "$EXPECTED_SOURCE_MD5" <<'PY'
import hashlib
import json
import sys
from pathlib import Path

result_path = Path(sys.argv[1])
output_path = Path(sys.argv[2])
expected_total = int(sys.argv[3])
anchor_md5 = sys.argv[4]
source_md5 = sys.argv[5]

payload = json.loads(result_path.read_text(encoding="utf-8"))


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
                raise RuntimeError(f"Invalid PLY: {path}")
            text = line.decode("ascii", "replace").strip()
            if text.startswith("element vertex "):
                return int(text.split()[2])
            if text == "end_header":
                raise RuntimeError(f"No vertex count: {path}")


points = ply_points(output_path)
output_md5 = md5(output_path)

included = payload.get("included", [])
excluded = payload.get("excluded", [])
source_jobs = payload.get("source_jobs", [])

included_models = sorted(
    int(item.get("model", -1))
    for item in included
)
excluded_models = sorted(
    int(item.get("model", -1))
    for item in excluded
)

print(f"included_models={included_models}")
print(f"excluded_models={excluded_models}")
print(f"merged_points={points}")
print(f"merged_md5={output_md5}")

for item in source_jobs:
    model_id = int(item.get("model_id", -1))
    transform = item.get("transform_to_anchor", {})
    print(
        f"model={model_id} "
        f"status={item.get('alignment_status')} "
        f"path={item.get('shared_path_to_anchor')} "
        f"scale={transform.get('scale')}"
    )

edges = payload.get("edges", [])
for edge in edges:
    from_id = int(edge.get("from_model_id", -1))
    to_id = int(edge.get("to_model_id", -1))

    if (
        from_id in (0, 1)
        or to_id in (0, 1)
        or from_id >= 100
        or to_id >= 100
    ):
        print(
            f"edge {from_id}->{to_id} "
            f"shared={edge.get('shared_images_count')} "
            f"rms_after={edge.get('rms_error_after')} "
            f"scale={edge.get('scale')}"
        )

if included_models != [0, 1]:
    raise SystemExit(
        f"ERROR: expected included models [0, 1], got {included_models}"
    )
if excluded_models:
    raise SystemExit(
        f"ERROR: source models were excluded: {excluded_models}"
    )
if len(source_jobs) != 2:
    raise SystemExit(
        f"ERROR: expected 2 merged source jobs, got {len(source_jobs)}"
    )
if points != expected_total:
    raise SystemExit(
        f"ERROR: merged points={points}, expected={expected_total}"
    )
if output_md5 in {anchor_md5, source_md5}:
    raise SystemExit(
        "ERROR: merged PLY is identical to one of the sources"
    )
PY

echo
echo "Sparse bridge merge completed."
echo "Artifacts:"
echo "  $OUTPUT_PLY"
echo "  $RESULT_JSON"
echo "  $POSES_JSON"
echo "  $LOCAL_DIR/bridge_sparse_console.log"
echo "  $LOCAL_BRIDGE/"
echo
echo "No dense reconstruction was rerun."
echo "No sfm_generated_model_merges row was created."
