#!/usr/bin/env bash
set -Eeuo pipefail

if [[ $# -ne 3 ]]; then
  echo "Usage: $0 <job_id> <input_video> <output_dir>" >&2
  exit 1
fi

JOB_ID="$1"
INPUT_VIDEO="$2"
OUTPUT_DIR="$3"

BASE="${STATION_BASE:-/home/makler_storage}"
STATUS_FILE="$BASE/status/job_${JOB_ID}.json"
LOG_FILE="$BASE/logs/job_${JOB_ID}.log"

mkdir -p "$OUTPUT_DIR" "$BASE/status" "$BASE/logs"

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
  write_status "ERROR" 0 -1 "Job failed at line $line_no with exit code $exit_code. See $LOG_FILE"
  exit "$exit_code"
}
trap on_error ERR

exec 2>>"$LOG_FILE"


write_status "RUNNING" 0 -1 "Starting"

if [[ ! -f "$INPUT_VIDEO" ]]; then
  write_status "ERROR" 0 -1 "Input video not found: $INPUT_VIDEO"
  echo "ERROR: input video not found: $INPUT_VIDEO" >&2
  exit 1
fi

DURATION_SEC=$(ffprobe -v error -show_entries format=duration \
  -of default=noprint_wrappers=1:nokey=1 "$INPUT_VIDEO" | awk '{duration=int($1); if (duration < 1) duration=1; print duration}')

if [[ -z "$DURATION_SEC" || "$DURATION_SEC" -le 0 ]]; then
  DURATION_SEC=1
fi

FPS="${EXTRACT_FPS:-2}"; MAX_FRAMES="${EXTRACT_MAX_FRAMES:-360}"; SCALE_WIDTH="${EXTRACT_SCALE_WIDTH:-1920}"; JPEG_QUALITY="${EXTRACT_JPEG_QUALITY:-2}"
rm -f "$OUTPUT_DIR"/frame_*.jpg
write_status "RUNNING" 1 -1 "Extracting frames"

ffmpeg -y \
  -i "$INPUT_VIDEO" \
  -vf "fps=${FPS},scale=${SCALE_WIDTH}:-1" \
  -frames:v "$MAX_FRAMES" \
  -q:v "$JPEG_QUALITY" \
  -progress pipe:1 \
  "$OUTPUT_DIR/frame_%06d.jpg" | while IFS= read -r line; do
    if [[ "$line" == out_time_ms=* ]]; then
      OUT_MS="${line#out_time_ms=}"
      OUT_SEC=$((OUT_MS / 1000000))
      PROGRESS=$((OUT_SEC * 100 / DURATION_SEC))
      if (( PROGRESS > 99 )); then PROGRESS=99; fi
      ETA=$((DURATION_SEC - OUT_SEC))
      if (( ETA < 0 )); then ETA=0; fi
      write_status "RUNNING" "$PROGRESS" "$ETA" "Extracting frames"
    fi
  done

FRAME_COUNT=$(find "$OUTPUT_DIR" -type f -name 'frame_*.jpg' | wc -l | tr -d ' ')

write_status "DONE" 100 0 "Done. Frames: $FRAME_COUNT"

cat > "$OUTPUT_DIR/result.json" <<JSON
{
  "job_id": "$JOB_ID",
  "status": "DONE",
  "frames": $FRAME_COUNT,
  "fps": $FPS,
  "max_frames": $MAX_FRAMES,
  "scale_width": $SCALE_WIDTH,
  "output_dir": "$OUTPUT_DIR",
  "finished_at": "$(date -Iseconds)"
}
JSON
