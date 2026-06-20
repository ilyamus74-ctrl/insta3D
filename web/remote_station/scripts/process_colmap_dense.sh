#!/usr/bin/env bash
set -Eeuo pipefail

if [[ $# -ne 3 ]]; then
  echo "Usage: $0 <job_id> <sparse_job_id> <model_id>" >&2
  exit 1
fi

JOB_ID="$1"
SPARSE_JOB_ID="$2"
MODEL_ID="$3"
BASE="${STATION_BASE:-/home/makler_storage}"
SPARSE_JOB_DIR="$BASE/output/job_${SPARSE_JOB_ID}/colmap"
SPARSE_RESULT="$SPARSE_JOB_DIR/result.json"
SPARSE_MODEL_DIR="$SPARSE_JOB_DIR/sparse/${MODEL_ID}"
DENSE_DIR="$BASE/output/job_${JOB_ID}/dense"
UNDISTORTED_DIR="$DENSE_DIR/undistorted"
FUSED_PLY="$DENSE_DIR/fused.ply"
STATUS_FILE="$BASE/status/job_${JOB_ID}.json"
LOG_FILE="$BASE/logs/job_${JOB_ID}.log"
DENSE_LOG_DIR="$DENSE_DIR/logs"
COLMAP_MODE="${COLMAP_MODE:-native}"
COLMAP_BIN="${COLMAP_BIN:-colmap}"
COLMAP_IMAGE="${COLMAP_IMAGE:-}"

mkdir -p "$BASE/status" "$BASE/logs"

json_escape() { python3 -c 'import json,sys; print(json.dumps(sys.stdin.read())[1:-1])'; }
write_status() {
  local status="$1" progress="$2" eta="$3" message="$4" escaped_message
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
  local exit_code=$? line_no=${BASH_LINENO[0]:-unknown}
  write_status "ERROR" 0 -1 "COLMAP dense reconstruction failed at line $line_no with exit code $exit_code. See $LOG_FILE"
  exit "$exit_code"
}
trap on_error ERR

run_colmap() {
  case "$COLMAP_MODE" in
    native) "$COLMAP_BIN" "$@" ;;
    podman)
      podman run --rm --device nvidia.com/gpu=all --security-opt=label=disable -v "$BASE:$BASE" "$COLMAP_IMAGE" colmap "$@"
      ;;
    *) echo "ERROR: unsupported COLMAP_MODE: $COLMAP_MODE" >&2; return 1 ;;
  esac
}

validate_colmap() {
  case "$COLMAP_MODE" in
    native)
      if ! command -v "$COLMAP_BIN" >/dev/null 2>&1; then write_status "ERROR" 0 -1 "COLMAP command not found: $COLMAP_BIN"; exit 1; fi
      "$COLMAP_BIN" image_undistorter -h >/dev/null
      "$COLMAP_BIN" patch_match_stereo -h >/dev/null
      "$COLMAP_BIN" stereo_fusion -h >/dev/null
      ;;
    podman)
      if ! command -v podman >/dev/null 2>&1; then write_status "ERROR" 0 -1 "podman command not found"; exit 1; fi
      if [[ -z "$COLMAP_IMAGE" ]]; then write_status "ERROR" 0 -1 "COLMAP_IMAGE is required when COLMAP_MODE=podman"; exit 1; fi
      podman run --rm --device nvidia.com/gpu=all --security-opt=label=disable "$COLMAP_IMAGE" colmap image_undistorter -h >/dev/null
      podman run --rm --device nvidia.com/gpu=all --security-opt=label=disable "$COLMAP_IMAGE" colmap patch_match_stereo -h >/dev/null
      podman run --rm --device nvidia.com/gpu=all --security-opt=label=disable "$COLMAP_IMAGE" colmap stereo_fusion -h >/dev/null
      ;;
    *) write_status "ERROR" 0 -1 "Unsupported COLMAP_MODE: $COLMAP_MODE"; exit 1 ;;
  esac
}

exec 2>>"$LOG_FILE"
write_status "RUNNING" 10 -1 "Preparing dense workspace"
validate_colmap

if [[ ! -f "$SPARSE_RESULT" ]]; then write_status "ERROR" 0 -1 "Sparse result.json not found: $SPARSE_RESULT"; exit 1; fi
FRAMES_DIR="$(python3 - "$SPARSE_RESULT" <<'PY'
import json,sys
try:
    data=json.load(open(sys.argv[1]))
except Exception:
    sys.exit(2)
print(data.get('frames_dir') or '')
PY
)"
if [[ -z "$FRAMES_DIR" ]]; then write_status "ERROR" 0 -1 "frames_dir missing in sparse result.json: $SPARSE_RESULT"; exit 1; fi
if [[ ! -d "$FRAMES_DIR" ]]; then write_status "ERROR" 0 -1 "Frames directory not found: $FRAMES_DIR"; exit 1; fi
for f in cameras.bin images.bin points3D.bin; do [[ -f "$SPARSE_MODEL_DIR/$f" ]] || { write_status "ERROR" 0 -1 "Sparse model file missing: $SPARSE_MODEL_DIR/$f"; exit 1; }; done
mkdir -p "$DENSE_DIR" "$DENSE_LOG_DIR"

run_colmap image_undistorter --image_path "$FRAMES_DIR" --input_path "$SPARSE_MODEL_DIR" --output_path "$UNDISTORTED_DIR" --output_type COLMAP > "$DENSE_LOG_DIR/image_undistorter.log" 2>&1

write_status "RUNNING" 40 -1 "Patch match stereo"
run_colmap patch_match_stereo --workspace_path "$UNDISTORTED_DIR" --workspace_format COLMAP --PatchMatchStereo.geom_consistency true > "$DENSE_LOG_DIR/patch_match_stereo.log" 2>&1

write_status "RUNNING" 80 -1 "Stereo fusion"
run_colmap stereo_fusion --workspace_path "$UNDISTORTED_DIR" --workspace_format COLMAP --input_type geometric --output_path "$FUSED_PLY" > "$DENSE_LOG_DIR/stereo_fusion.log" 2>&1

if [[ ! -s "$FUSED_PLY" ]] || [[ $(stat -c '%s' "$FUSED_PLY") -lt 128 ]]; then write_status "ERROR" 0 -1 "fused.ply missing or too small: $FUSED_PLY"; exit 1; fi
SIZE=$(stat -c '%s' "$FUSED_PLY")
cat > "$DENSE_DIR/result.json" <<JSON
{
  "job_id": "$JOB_ID",
  "status": "DONE",
  "sparse_job_id": "$SPARSE_JOB_ID",
  "model_id": $MODEL_ID,
  "frames_dir": "$FRAMES_DIR",
  "sparse_model_dir": "$SPARSE_MODEL_DIR",
  "dense_dir": "$DENSE_DIR",
  "fused_ply": "$FUSED_PLY",
  "fused_ply_size_bytes": $SIZE,
  "finished_at": "$(date -Iseconds)"
}
JSON
write_status "DONE" 100 -1 "COLMAP dense reconstruction done"