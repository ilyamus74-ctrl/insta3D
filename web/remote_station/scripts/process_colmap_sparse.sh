#!/usr/bin/env bash
set -Eeuo pipefail

if [[ $# -ne 3 ]]; then
  echo "Usage: $0 <job_id> <frames_dir> <output_dir>" >&2
  exit 1
fi

JOB_ID="$1"
FRAMES_DIR="$2"
OUTPUT_DIR="$3"

BASE="${STATION_BASE:-/home/makler_storage}"
STATUS_FILE="$BASE/status/job_${JOB_ID}.json"
LOG_FILE="$BASE/logs/job_${JOB_ID}.log"
DATABASE_PATH="$OUTPUT_DIR/database.db"
SPARSE_DIR="$OUTPUT_DIR/sparse"
COLMAP_LOG_DIR="$OUTPUT_DIR/logs"
COLMAP_MODE="${COLMAP_MODE:-native}"
COLMAP_BIN="${COLMAP_BIN:-colmap}"
COLMAP_IMAGE="${COLMAP_IMAGE:-}"
COLMAP_MATCHER="${COLMAP_MATCHER:-sequential}"
COLMAP_SEQUENTIAL_OVERLAP="${COLMAP_SEQUENTIAL_OVERLAP:-60}"
COLMAP_LOOP_DETECTION="${COLMAP_LOOP_DETECTION:-0}"
PARAMETERS_JSON_PATH="$BASE/input/job_${JOB_ID}/parameters.json"

mkdir -p "$BASE/status" "$BASE/logs"

json_escape() {
  python3 -c 'import json,sys; print(json.dumps(sys.stdin.read())[1:-1])'
}

write_status() {
  local status="$1"
  local progress="$2"
  local eta="$3"
  local message="$4"
  local escaped_message
  escaped_message="$(printf '%s' "$message" | json_escape)"

  cat > "$STATUS_FILE" <<JSON
{
  "job_id": "$JOB_ID",
  "status": "$status",
  "progress_percent": $progress,
  "eta_sec": $eta,
  "message": "$escaped_message",
  "updated_at": "$(date -Iseconds)"
}
JSON
}

on_error() {
  local exit_code=$?
  local line_no=${BASH_LINENO[0]:-unknown}
  write_status "ERROR" 0 -1 "COLMAP sparse reconstruction failed at line $line_no with exit code $exit_code. See $LOG_FILE"
  exit "$exit_code"
}
trap on_error ERR

run_colmap() {
  case "$COLMAP_MODE" in
    native)
      "$COLMAP_BIN" "$@"
      ;;
    podman)
      podman run --rm \
        --device nvidia.com/gpu=all \
        --security-opt=label=disable \
        -v "$BASE:$BASE" \
        "$COLMAP_IMAGE" \
        colmap "$@"
      ;;
    *)
      echo "ERROR: unsupported COLMAP_MODE: $COLMAP_MODE" >&2
      return 1
      ;;
  esac
}

resolve_sparse_settings() {
  python3 - "$PARAMETERS_JSON_PATH" "${COLMAP_SEQUENTIAL_OVERLAP:-}" "${COLMAP_LOOP_DETECTION:-}" <<'PYPARAM'
import json
import sys

path, env_overlap, env_loop = sys.argv[1], sys.argv[2], sys.argv[3]
SAFE_OVERLAP = 60
SAFE_LOOP = 0
warnings = []

try:
    with open(path, 'r', encoding='utf-8') as fh:
        data = json.load(fh)
except FileNotFoundError:
    data = {}
except Exception as exc:
    warnings.append(f"invalid parameters JSON {path}: {exc}; using environment/default sparse settings")
    data = {}

def dig(obj, parts):
    cur = obj
    for part in parts:
        if not isinstance(cur, dict) or part not in cur:
            return None
        cur = cur[part]
    return cur

def valid_overlap(raw, source):
    try:
        if isinstance(raw, bool):
            raise ValueError('boolean is not an integer overlap')
        value = int(str(raw).strip())
    except Exception:
        warnings.append(f"invalid sequential_overlap from {source}: {raw!r}; expected integer 1-200")
        return None
    if not 1 <= value <= 200:
        warnings.append(f"invalid sequential_overlap from {source}: {value}; expected range 1-200")
        return None
    return value

def valid_loop(raw, source):
    if isinstance(raw, bool):
        return 1 if raw else 0
    text = str(raw).strip().lower()
    if text in ('1', 'true', 'yes', 'on'):
        return 1
    if text in ('0', 'false', 'no', 'off', ''):
        return 0
    warnings.append(f"invalid loop_detection from {source}: {raw!r}; expected boolean/0/1")
    return None

candidates = [
    ('ui_snapshot', dig(data, ['settings', 'sparse', 'sequential_overlap'])),
    ('legacy', dig(data, ['sparse', 'sequential_overlap'])),
    ('env', env_overlap),
]
overlap = None
overlap_source = 'default'
for source, raw in candidates:
    if raw is None or raw == '':
        continue
    parsed = valid_overlap(raw, source)
    if parsed is not None:
        overlap = parsed
        overlap_source = source
        break
if overlap is None:
    overlap = SAFE_OVERLAP

loop_candidates = [
    ('ui_snapshot', dig(data, ['settings', 'sparse', 'loop_detection'])),
    ('legacy', dig(data, ['sparse', 'loop_detection'])),
    ('env', env_loop),
]
loop = None
for source, raw in loop_candidates:
    if raw is None or raw == '':
        continue
    parsed = valid_loop(raw, source)
    if parsed is not None:
        loop = parsed
        break
if loop is None:
    loop = SAFE_LOOP

print(overlap)
print(loop)
print(overlap_source)
for warning in warnings:
    print(warning)
PYPARAM
}

apply_sparse_parameters() {
  local resolved=()
  mapfile -t resolved < <(resolve_sparse_settings)
  COLMAP_SEQUENTIAL_OVERLAP="${resolved[0]:-60}"
  COLMAP_LOOP_DETECTION="${resolved[1]:-0}"
  COLMAP_SPARSE_SETTINGS_SOURCE="${resolved[2]:-default}"
  local warning
  for warning in "${resolved[@]:3}"; do
    [[ -n "$warning" ]] && echo "WARNING | SPARSE | $warning" >> "$LOG_FILE"
  done
}

validate_colmap_matcher() {
  case "$COLMAP_MATCHER" in
    sequential|exhaustive)
      ;;
    *)
      write_status "ERROR" 0 -1 "Unsupported COLMAP_MATCHER: $COLMAP_MATCHER"
      exit 1
      ;;
  esac
}

validate_colmap() {
  case "$COLMAP_MODE" in
    native)
      if ! command -v "$COLMAP_BIN" >/dev/null 2>&1; then
        write_status "ERROR" 0 -1 "COLMAP command not found: $COLMAP_BIN"
        echo "ERROR: COLMAP command not found: $COLMAP_BIN" >&2
        exit 1
      fi

      local colmap_path
      colmap_path="$(command -v "$COLMAP_BIN")"
      if command -v rpm >/dev/null 2>&1 && rpm -qf "$colmap_path" 2>/dev/null | grep -qi '^geomorph'; then
        write_status "ERROR" 0 -1 "Invalid COLMAP binary: $colmap_path belongs to geomorph"
        echo "ERROR: invalid COLMAP binary: $colmap_path belongs to geomorph, not photogrammetry COLMAP" >&2
        exit 1
      fi

      "$COLMAP_BIN" feature_extractor -h >/dev/null
      "$COLMAP_BIN" sequential_matcher -h >/dev/null
      "$COLMAP_BIN" exhaustive_matcher -h >/dev/null
      "$COLMAP_BIN" mapper -h >/dev/null
      ;;
    podman)
      if ! command -v podman >/dev/null 2>&1; then
        write_status "ERROR" 0 -1 "podman command not found"
        echo "ERROR: podman command not found" >&2
        exit 1
      fi
      if [[ -z "$COLMAP_IMAGE" ]]; then
        write_status "ERROR" 0 -1 "COLMAP_IMAGE is required when COLMAP_MODE=podman"
        echo "ERROR: COLMAP_IMAGE is required when COLMAP_MODE=podman" >&2
        exit 1
      fi

      podman run --rm --device nvidia.com/gpu=all --security-opt=label=disable "$COLMAP_IMAGE" colmap help >/dev/null
      podman run --rm --device nvidia.com/gpu=all --security-opt=label=disable "$COLMAP_IMAGE" colmap feature_extractor -h >/dev/null
      podman run --rm --device nvidia.com/gpu=all --security-opt=label=disable "$COLMAP_IMAGE" colmap sequential_matcher -h >/dev/null
      podman run --rm --device nvidia.com/gpu=all --security-opt=label=disable "$COLMAP_IMAGE" colmap exhaustive_matcher -h >/dev/null
      podman run --rm --device nvidia.com/gpu=all --security-opt=label=disable "$COLMAP_IMAGE" colmap mapper -h >/dev/null
      ;;
    *)
      write_status "ERROR" 0 -1 "Unsupported COLMAP_MODE: $COLMAP_MODE"
      echo "ERROR: unsupported COLMAP_MODE: $COLMAP_MODE" >&2
      exit 1
      ;;
  esac
}

exec 2>>"$LOG_FILE"

write_status "RUNNING" 0 -1 "Starting COLMAP sparse reconstruction"
apply_sparse_parameters
echo "INFO | SPARSE | Parameters JSON path=$PARAMETERS_JSON_PATH exists=$([[ -f "$PARAMETERS_JSON_PATH" ]] && echo 1 || echo 0)" >> "$LOG_FILE"
echo "INFO | SPARSE | Sparse settings effective: matcher=$COLMAP_MATCHER overlap=$COLMAP_SEQUENTIAL_OVERLAP loop_detection=$COLMAP_LOOP_DETECTION source=${COLMAP_SPARSE_SETTINGS_SOURCE:-default}" >> "$LOG_FILE"
validate_colmap_matcher
validate_colmap

if [[ ! -d "$FRAMES_DIR" ]]; then
  write_status "ERROR" 0 -1 "Frames directory not found: $FRAMES_DIR"
  echo "ERROR: frames directory not found: $FRAMES_DIR" >&2
  exit 1
fi

shopt -s nullglob
FRAME_FILES=("$FRAMES_DIR"/frame_*.jpg)
if (( ${#FRAME_FILES[@]} == 0 )); then
  write_status "ERROR" 0 -1 "No frame_*.jpg files found in: $FRAMES_DIR"
  echo "ERROR: no frame_*.jpg files found in: $FRAMES_DIR" >&2
  exit 1
fi
shopt -u nullglob

write_status "RUNNING" 5 -1 "Preparing workspace"
mkdir -p "$OUTPUT_DIR" "$SPARSE_DIR" "$COLMAP_LOG_DIR"

write_status "RUNNING" 15 -1 "COLMAP feature extraction"
run_colmap feature_extractor \
  --database_path "$DATABASE_PATH" \
  --image_path "$FRAMES_DIR" \
  --FeatureExtraction.use_gpu 1 \
  > "$COLMAP_LOG_DIR/feature_extractor.log" 2>&1

case "$COLMAP_MATCHER" in
  sequential)
    write_status "RUNNING" 45 -1 "COLMAP sequential feature matching"
    echo "INFO | SPARSE | Running COLMAP sequential matcher with --SequentialMatching.overlap $COLMAP_SEQUENTIAL_OVERLAP --SequentialMatching.loop_detection $COLMAP_LOOP_DETECTION" >> "$LOG_FILE"
    run_colmap sequential_matcher \
      --database_path "$DATABASE_PATH" \
      --FeatureMatching.use_gpu 1 \
      --SequentialMatching.overlap "$COLMAP_SEQUENTIAL_OVERLAP" \
      --SequentialMatching.loop_detection "$COLMAP_LOOP_DETECTION" \
      > "$COLMAP_LOG_DIR/sequential_matcher.log" 2>&1
    grep -E -- '--SequentialMatching\.(overlap|loop_detection) arg' "$COLMAP_LOG_DIR/sequential_matcher.log" >> "$LOG_FILE" || true
    parsed_overlap="$(sed -n 's/.*--SequentialMatching\.overlap arg (=\([^)]*\)).*/\1/p' "$COLMAP_LOG_DIR/sequential_matcher.log" | tail -n 1 || true)"
    if [[ -n "$parsed_overlap" && "$parsed_overlap" != "$COLMAP_SEQUENTIAL_OVERLAP" ]]; then
      echo "ERROR: COLMAP parsed overlap does not match effective settings: parsed=$parsed_overlap effective=$COLMAP_SEQUENTIAL_OVERLAP" >> "$LOG_FILE"
    fi
    ;;
  exhaustive)
    write_status "RUNNING" 45 -1 "COLMAP exhaustive feature matching"
    run_colmap exhaustive_matcher \
      --database_path "$DATABASE_PATH" \
      --FeatureMatching.use_gpu 1 \
      > "$COLMAP_LOG_DIR/exhaustive_matcher.log" 2>&1
    ;;
  *)
    write_status "ERROR" 0 -1 "Unsupported COLMAP_MATCHER: $COLMAP_MATCHER"
    exit 1
    ;;
esac

write_status "RUNNING" 70 -1 "COLMAP mapper"
run_colmap mapper \
  --database_path "$DATABASE_PATH" \
  --image_path "$FRAMES_DIR" \
  --output_path "$SPARSE_DIR" \
  > "$COLMAP_LOG_DIR/mapper.log" 2>&1

find_imu_jsonl() {
  local extract_job_id=""
  extract_job_id="$(basename "$(dirname "$FRAMES_DIR")" | sed 's/^job_//')"
  local candidates=()
  [[ -n "${IMU_JSONL_PATH:-}" ]] && candidates+=("$IMU_JSONL_PATH")
  candidates+=(
    "$(dirname "$FRAMES_DIR")/scan_imu.jsonl"
    "$OUTPUT_DIR/../scan_imu.jsonl"
    "$BASE/input/job_${JOB_ID}/scan_imu.jsonl"
  )
  [[ -n "$extract_job_id" ]] && candidates+=("$BASE/input/job_${extract_job_id}/scan_imu.jsonl")
  local seen=""
  local candidate
  for candidate in "${candidates[@]}"; do
    [[ -n "$candidate" ]] || continue
    case ":$seen:" in *":$candidate:"*) continue;; esac
    seen="$seen:$candidate"
    if [[ -f "$candidate" ]]; then
      echo "INFO | IMU | Using IMU JSONL: $candidate" >> "$LOG_FILE"
      printf '%s\n' "$candidate"
      return 0
    fi
  done
  echo "INFO | IMU | No scan_imu.jsonl found in sparse input paths" >> "$LOG_FILE"
  return 1
}


run_sparse_diagnostics() {
  local model_dir="$1"
  local out_json="$model_dir/sparse_diagnostics.json"
  local selected_json=""
  for candidate in \
    "$(dirname "$FRAMES_DIR")/quality/selected_frames.json" \
    "$OUTPUT_DIR/../quality/selected_frames.json" \
    "$BASE/output/job_${JOB_ID}/quality/selected_frames.json"; do
    if [[ -f "$candidate" ]]; then selected_json="$candidate"; break; fi
  done
  local imu_jsonl=""
  imu_jsonl="$(find_imu_jsonl || true)"
  local cmd=(python3 "$BASE/scripts/analyze_sparse_trajectory.py" --model-dir "$model_dir" --output-json "$out_json")
  [[ -n "$selected_json" ]] && cmd+=(--selected-frames-json "$selected_json")
  [[ -n "$imu_jsonl" ]] && cmd+=(--imu-jsonl "$imu_jsonl")
  if "${cmd[@]}" >> "$LOG_FILE" 2>&1; then
    python3 - "$out_json" <<'PYDIAG' >> "$LOG_FILE" 2>/dev/null || true
import json,sys
d=json.load(open(sys.argv[1])); r=d.get('registration_ratio'); rp=d.get('reprojection',{}); tr=d.get('trajectory',{}); imu=d.get('imu',{})
if r is not None: print(f"SPARSE_DIAGNOSTICS | Registration ratio={r*100:.1f}%")
print(f"SPARSE_DIAGNOSTICS | Reprojection median={rp.get('median_px',0):.2f}px p95={rp.get('p95_px',0):.2f}px")
print(f"SPARSE_DIAGNOSTICS | Position jumps={tr.get('position_jumps',0)} rotation jumps={tr.get('rotation_jumps',0)}")
print(f"SPARSE_DIAGNOSTICS | Pose clusters={tr.get('pose_clusters',0)} largest={tr.get('largest_cluster_images',0)} secondary={tr.get('secondary_cluster_images',0)}")
print(f"SPARSE_DIAGNOSTICS | IMU rotation mismatches={imu.get('rotation_mismatches',0)}")
for w in d.get('warnings',[]): print('SPARSE_DIAGNOSTICS | WARNING '+str(w.get('type','warning')).lower().replace('_',' '))
PYDIAG
  else
    echo "WARNING | SPARSE_DIAGNOSTICS | Diagnostics failed for $model_dir" >> "$LOG_FILE"
  fi
}

MODEL_COUNT=$(find "$SPARSE_DIR" -mindepth 1 -maxdepth 1 -type d | wc -l | tr -d ' ')
if [[ "$MODEL_COUNT" == "0" ]]; then
  write_status "ERROR" 0 -1 "COLMAP finished but produced zero sparse models"
  exit 1
fi

for model_dir in "$SPARSE_DIR"/*; do
  if [[ -d "$model_dir" ]]; then
    run_sparse_diagnostics "$model_dir"
    python3 "$BASE/scripts/build_camera_trajectory.py" --model-dir "$model_dir" --diagnostics-json "$model_dir/sparse_diagnostics.json" --output-json "$model_dir/camera_trajectory.json" >> "$LOG_FILE" 2>&1 || echo "WARNING | CAMERA_TRAJECTORY | Failed for $model_dir" >> "$LOG_FILE"
    imu_jsonl="$(find_imu_jsonl || true)"
    align_cmd=(python3 "$BASE/scripts/build_world_alignment.py" --model-dir "$model_dir" --camera-trajectory "$model_dir/camera_trajectory.json" --output-json "$model_dir/world_alignment.json")
    [[ -n "$imu_jsonl" ]] && align_cmd+=(--imu-jsonl "$imu_jsonl")
    "${align_cmd[@]}" >> "$LOG_FILE" 2>&1 || echo "WARNING | WORLD_ALIGNMENT | Failed for $model_dir" >> "$LOG_FILE"
  fi
done

cat > "$OUTPUT_DIR/result.json" <<JSON
{
  "job_id": "$JOB_ID",
  "status": "DONE",
  "frames_dir": "$FRAMES_DIR",
  "output_dir": "$OUTPUT_DIR",
  "database_path": "$DATABASE_PATH",
  "sparse_dir": "$SPARSE_DIR",
  "colmap_mode": "$COLMAP_MODE",
  "colmap_bin": "$COLMAP_BIN",
  "colmap_image": "$COLMAP_IMAGE",
  "colmap_matcher": "$COLMAP_MATCHER",
  "colmap_sequential_overlap": "$COLMAP_SEQUENTIAL_OVERLAP",
  "models": $MODEL_COUNT,
  "finished_at": "$(date -Iseconds)"
}
JSON

write_status "DONE" 100 -1 "COLMAP sparse reconstruction done"