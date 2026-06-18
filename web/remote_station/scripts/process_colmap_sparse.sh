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
  --SiftExtraction.use_gpu 1 \
  > "$COLMAP_LOG_DIR/feature_extractor.log" 2>&1

write_status "RUNNING" 45 -1 "COLMAP feature matching"
run_colmap exhaustive_matcher \
  --database_path "$DATABASE_PATH" \
  --SiftMatching.use_gpu 1 \
  > "$COLMAP_LOG_DIR/exhaustive_matcher.log" 2>&1

write_status "RUNNING" 70 -1 "COLMAP mapper"
run_colmap mapper \
  --database_path "$DATABASE_PATH" \
  --image_path "$FRAMES_DIR" \
  --output_path "$SPARSE_DIR" \
  > "$COLMAP_LOG_DIR/mapper.log" 2>&1

MODEL_COUNT=$(find "$SPARSE_DIR" -mindepth 1 -maxdepth 1 -type d | wc -l | tr -d ' ')
if [[ "$MODEL_COUNT" == "0" ]]; then
  write_status "ERROR" 0 -1 "COLMAP finished but produced zero sparse models"
  exit 1
fi

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
  "models": $MODEL_COUNT,
  "finished_at": "$(date -Iseconds)"
}
JSON

write_status "DONE" 100 -1 "COLMAP sparse reconstruction done"