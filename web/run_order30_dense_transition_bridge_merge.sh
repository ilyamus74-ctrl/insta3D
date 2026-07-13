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

# Dense bridge settings. These values are based on the measured break:
#   current component 0 ends at 56.6729 sec (frame_000286.jpg)
#   current component 1 starts at 57.7334 sec (frame_000291.jpg)
#
# Override example:
#   TRANSITION_START=54 TRANSITION_DURATION=7 DENSE_FPS=30 \
#   ./run_order30_dense_transition_bridge_merge.sh
SOURCE_VIDEO="${SOURCE_VIDEO:-/home/storage/orders/30/sessions/031321af-f41d-46c1-842f-db5f0c0b27e0_30/videos/c2e71381-0cab-4418-9c2d-3fd07433bee8_video.mp4}"
TRANSITION_START="${TRANSITION_START:-54.5}"
TRANSITION_DURATION="${TRANSITION_DURATION:-5.5}"
DENSE_FPS="${DENSE_FPS:-30}"

# Exact selected-image names retained on both sides so the bridge component
# has shared camera poses with current sparse models 0 and 1.
ANCHOR_START="${ANCHOR_START:-250}"
ANCHOR_END="${ANCHOR_END:-286}"
SOURCE_START="${SOURCE_START:-291}"
SOURCE_END="${SOURCE_END:-330}"

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

# These are normally defined in stations.conf. The standard
# run_colmap_sparse_job.sh forwards them to GrafikStation; this manual runner
# must do the same.
COLMAP_MODE="${COLMAP_MODE:-native}"
COLMAP_BIN="${COLMAP_BIN:-colmap}"
COLMAP_IMAGE="${COLMAP_IMAGE:-}"
COLMAP_CAMERA_MODEL_AUTO_FROM_METADATA="${COLMAP_CAMERA_MODEL_AUTO_FROM_METADATA:-0}"

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

LOCAL_DENSE_FRAMES="$LOCAL_DIR/dense_transition_frames"

mkdir -p "$LOCAL_BRIDGE" "$GRAPH_DIR" "$LOCAL_DENSE_FRAMES"
printf '%s\n' "$BRIDGE_JOB_ID" > "$LOCAL_DIR/bridge_job_id.txt"

[[ -s "$SOURCE_VIDEO" ]] || {
  echo "ERROR: source video is missing or empty: $SOURCE_VIDEO" >&2
  exit 1
}

command -v ffmpeg >/dev/null 2>&1 || {
  echo "ERROR: ffmpeg is not installed on the web server" >&2
  exit 1
}

command -v ffprobe >/dev/null 2>&1 || {
  echo "ERROR: ffprobe is not installed on the web server" >&2
  exit 1
}

SOURCE_FPS="$(
  ffprobe -v error     -select_streams v:0     -show_entries stream=avg_frame_rate     -of default=noprint_wrappers=1:nokey=1     "$SOURCE_VIDEO" |
  python3 -c '
import sys
raw=sys.stdin.read().strip()
try:
    if "/" in raw:
        a,b=raw.split("/",1)
        value=float(a)/float(b)
    else:
        value=float(raw)
except Exception:
    value=0.0
print(f"{value:.6f}")
'
)"

EFFECTIVE_DENSE_FPS="$(
  python3 - "$SOURCE_FPS" "$DENSE_FPS" <<'PYFPS'
import sys
source=float(sys.argv[1])
requested=float(sys.argv[2])
if requested <= 0:
    raise SystemExit("DENSE_FPS must be positive")
value=requested if source <= 0 else min(source, requested)
print(f"{value:.6f}".rstrip("0").rstrip("."))
PYFPS
)"

rm -f "$LOCAL_DENSE_FRAMES"/frame_dense_*.jpg

echo "Extracting dense transition frames from the original video"
echo "Source video: $SOURCE_VIDEO"
echo "Source FPS: $SOURCE_FPS"
echo "Transition: start=$TRANSITION_START duration=$TRANSITION_DURATION"
echo "Dense bridge FPS: $EFFECTIVE_DENSE_FPS"

ffmpeg   -hide_banner   -loglevel warning   -y   -ss "$TRANSITION_START"   -t "$TRANSITION_DURATION"   -i "$SOURCE_VIDEO"   -vf "fps=${EFFECTIVE_DENSE_FPS}"   -q:v 2   "$LOCAL_DENSE_FRAMES/frame_dense_%06d.jpg"

DENSE_FRAME_COUNT="$(
  find "$LOCAL_DENSE_FRAMES"     -maxdepth 1     -type f     -name 'frame_dense_*.jpg' |
  wc -l |
  tr -d ' '
)"

if (( DENSE_FRAME_COUNT < 30 )); then
  echo "ERROR: only $DENSE_FRAME_COUNT dense transition frames were extracted" >&2
  exit 1
fi

echo "Dense transition frames: $DENSE_FRAME_COUNT"

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
  "$ANCHOR_START" \
  "$ANCHOR_END" \
  "$SOURCE_START" \
  "$SOURCE_END"

echo "Preparing remote bridge workspace"
echo "Selected anchors: $ANCHOR_START..$ANCHOR_END and $SOURCE_START..$SOURCE_END"
echo "Remote output: $REMOTE_COLMAP"
echo "COLMAP mode: $COLMAP_MODE"
echo "COLMAP bin: $COLMAP_BIN"
echo "COLMAP image: $COLMAP_IMAGE"

"${SSH[@]}" "$REMOTE_PREP_COMMAND" <<'REMOTE_PREP'
set -Eeuo pipefail

REMOTE_ROOT="$1"
REMOTE_FRAMES="$2"
REMOTE_COLMAP="$3"
SOURCE_FRAMES="$4"
STATION_BASE="$5"
ANCHOR_START="$6"
ANCHOR_END="$7"
SOURCE_START="$8"
SOURCE_END="$9"

rm -rf "$REMOTE_ROOT"
mkdir -p "$REMOTE_FRAMES" "$REMOTE_COLMAP"

for range in \
  "$ANCHOR_START $ANCHOR_END" \
  "$SOURCE_START $SOURCE_END"
do
  read -r start end <<< "$range"

  for number in $(seq "$start" "$end"); do
    filename="$(printf 'frame_%06d.jpg' "$number")"
    source="$SOURCE_FRAMES/$filename"

    if [[ -s "$source" ]]; then
      ln -s "$source" "$REMOTE_FRAMES/$filename"
    fi
  done
done

metadata_source="$STATION_BASE/output/job_74380741/camera_metadata.json"
if [[ -s "$metadata_source" ]]; then
  ln -s "$metadata_source" "$REMOTE_ROOT/camera_metadata.json"
fi

imu_source="$STATION_BASE/output/job_74380741/scan_imu.jsonl"
if [[ -s "$imu_source" ]]; then
  ln -s "$imu_source" "$REMOTE_ROOT/scan_imu.jsonl"
fi

selected_count="$(
  find "$REMOTE_FRAMES" \
    -maxdepth 1 \
    -type l \
    -name 'frame_*.jpg' |
  wc -l |
  tr -d ' '
)"

echo "Selected anchor images prepared: $selected_count"

if (( selected_count < 20 )); then
  echo "ERROR: too few selected anchor images: $selected_count" >&2
  exit 2
fi
REMOTE_PREP

echo "Uploading dense transition frames to GrafikStation"
"${SCP[@]}" \
  "$LOCAL_DENSE_FRAMES"/frame_dense_*.jpg \
  "${STATION_USER}@${STATION_HOST}:${REMOTE_FRAMES}/"

printf -v REMOTE_RUN_COMMAND '%q ' bash -s -- \
  "$REMOTE_ROOT" \
  "$REMOTE_FRAMES" \
  "$REMOTE_COLMAP" \
  "$STATION_BASE" \
  "$REMOTE_SPARSE_SCRIPT" \
  "$BRIDGE_JOB_ID" \
  "$REMOTE_RUN_LOG" \
  "$COLMAP_MODE" \
  "$COLMAP_BIN" \
  "$COLMAP_IMAGE" \
  "$COLMAP_CAMERA_MODEL_AUTO_FROM_METADATA"

echo "Running sparse-only dense-transition bridge reconstruction"

set +e
"${SSH[@]}" "$REMOTE_RUN_COMMAND" <<'REMOTE_RUN'
set -Eeuo pipefail

REMOTE_ROOT="$1"
REMOTE_FRAMES="$2"
REMOTE_COLMAP="$3"
STATION_BASE="$4"
SPARSE_SCRIPT="$5"
JOB_ID="$6"
RUN_LOG="$7"
COLMAP_MODE="$8"
COLMAP_BIN="$9"
COLMAP_IMAGE="${10}"
COLMAP_CAMERA_MODEL_AUTO_FROM_METADATA="${11}"

STATUS_FILE="$STATION_BASE/status/job_${JOB_ID}.json"
JOB_LOG="$STATION_BASE/logs/job_${JOB_ID}.log"

mkdir -p "$STATION_BASE/input/job_${JOB_ID}"

selected_count="$(
  find "$REMOTE_FRAMES" \
    -maxdepth 1 \
    -type l \
    -name 'frame_*.jpg' |
  wc -l |
  tr -d ' '
)"

dense_count="$(
  find "$REMOTE_FRAMES" \
    -maxdepth 1 \
    -type f \
    -name 'frame_dense_*.jpg' |
  wc -l |
  tr -d ' '
)"

total_count=$((selected_count + dense_count))

{
  echo "Bridge job id: $JOB_ID"
  echo "Bridge selected anchors: $selected_count"
  echo "Bridge dense transition frames: $dense_count"
  echo "Bridge total images: $total_count"
  echo "Bridge COLMAP output: $REMOTE_COLMAP"
  echo "COLMAP mode: $COLMAP_MODE"
  echo "COLMAP bin: $COLMAP_BIN"
  echo "COLMAP image: $COLMAP_IMAGE"
} > "$RUN_LOG"

if (( dense_count < 30 || total_count < 50 )); then
  echo "ERROR: incomplete dense bridge input" | tee -a "$RUN_LOG" >&2
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

set +e
STATION_BASE="$STATION_BASE" \
COLMAP_MODE="$COLMAP_MODE" \
COLMAP_BIN="$COLMAP_BIN" \
COLMAP_IMAGE="$COLMAP_IMAGE" \
COLMAP_MATCHER=exhaustive \
COLMAP_SEQUENTIAL_OVERLAP=200 \
COLMAP_LOOP_DETECTION=0 \
COLMAP_CAMERA_MODEL_AUTO_FROM_METADATA="$COLMAP_CAMERA_MODEL_AUTO_FROM_METADATA" \
"$SPARSE_SCRIPT" "$JOB_ID" "$REMOTE_FRAMES" "$REMOTE_COLMAP"
SPARSE_STATUS=$?
set -e

{
  echo
  echo "===== PROCESS STATUS ====="
  echo "exit_code=$SPARSE_STATUS"

  if [[ -f "$STATUS_FILE" ]]; then
    echo
    echo "===== STATUS JSON ====="
    cat "$STATUS_FILE"
  fi

  if [[ -f "$JOB_LOG" ]]; then
    echo
    echo "===== PROCESS LOG (LAST 400 LINES) ====="
    tail -n 400 "$JOB_LOG"
  fi

  if [[ -f "$REMOTE_COLMAP/sparse_components.json" ]]; then
    echo
    echo "===== SPARSE COMPONENTS ====="
    cat "$REMOTE_COLMAP/sparse_components.json"
  fi

  for colmap_log in "$REMOTE_COLMAP"/logs/*.log; do
    [[ -f "$colmap_log" ]] || continue
    echo
    echo "===== $(basename "$colmap_log") (LAST 120 LINES) ====="
    tail -n 120 "$colmap_log"
  done
} >> "$RUN_LOG" 2>&1

cat "$RUN_LOG"

if (( SPARSE_STATUS != 0 )); then
  exit "$SPARSE_STATUS"
fi

model_count="$(
  find "$REMOTE_COLMAP/sparse" \
    -mindepth 1 \
    -maxdepth 1 \
    -type d \
    -printf '.' |
  wc -c
)"

echo "Bridge sparse components: $model_count" | tee -a "$RUN_LOG"

if (( model_count < 1 )); then
  echo "ERROR: bridge sparse produced no registered component" | tee -a "$RUN_LOG" >&2
  exit 3
fi
REMOTE_RUN
REMOTE_STATUS=$?
set -e

# Always return diagnostics when possible.
"${SCP[@]}" \
  "${STATION_USER}@${STATION_HOST}:${REMOTE_RUN_LOG}" \
  "$LOCAL_DIR/" 2>/dev/null || true

"${SCP[@]}" \
  "${STATION_USER}@${STATION_HOST}:${STATION_BASE}/logs/job_${BRIDGE_JOB_ID}.log" \
  "$LOCAL_DIR/process_colmap_sparse.log" 2>/dev/null || true

"${SCP[@]}" \
  "${STATION_USER}@${STATION_HOST}:${STATION_BASE}/status/job_${BRIDGE_JOB_ID}.json" \
  "$LOCAL_DIR/process_colmap_sparse_status.json" 2>/dev/null || true

if [[ $REMOTE_STATUS -ne 0 ]]; then
  echo "ERROR: bridge sparse failed with exit code $REMOTE_STATUS" >&2
  echo "Diagnostics:" >&2
  echo "  $LOCAL_DIR/bridge_sparse_console.log" >&2
  echo "  $LOCAL_DIR/process_colmap_sparse.log" >&2
  echo "  $LOCAL_DIR/process_colmap_sparse_status.json" >&2
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
echo "Dense-transition sparse bridge merge completed."
echo "Artifacts:"
echo "  $OUTPUT_PLY"
echo "  $RESULT_JSON"
echo "  $POSES_JSON"
echo "  $LOCAL_DIR/bridge_sparse_console.log"
echo "  $LOCAL_BRIDGE/"
echo
echo "Only a small sparse bridge was reconstructed; dense PLY generation was not rerun."
echo "No sfm_generated_model_merges row was created."
