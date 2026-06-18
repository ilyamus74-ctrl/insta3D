#!/usr/bin/env bash
set -euo pipefail

JOB_ID="$1"
INPUT_VIDEO="$2"
OUTPUT_DIR="$3"

BASE="/home/makler_storage"
STATUS_FILE="$BASE/status/job_${JOB_ID}.json"
LOG_FILE="$BASE/logs/job_${JOB_ID}.log"

mkdir -p "$OUTPUT_DIR" "$BASE/status" "$BASE/logs"

write_status() {
  local status="$1"
  local progress="$2"
  local eta="$3"
  local message="$4"

  cat > "$STATUS_FILE" <<EOF
{
  "job_id": $JOB_ID,
  "status": "$status",
  "progress_percent": $progress,
  "eta_sec": $eta,
  "message": "$message",
  "updated_at": "$(date -Iseconds)"
}
EOF
}

write_status "RUNNING" 0 -1 "Starting"

DURATION_SEC=$(ffprobe -v error -show_entries format=duration \
  -of default=noprint_wrappers=1:nokey=1 "$INPUT_VIDEO" | awk '{print int($1)}')

if [ -z "$DURATION_SEC" ] || [ "$DURATION_SEC" -le 0 ]; then
  DURATION_SEC=1
fi

write_status "RUNNING" 1 -1 "Extracting frames"

ffmpeg -y \
  -i "$INPUT_VIDEO" \
  -vf "fps=2,scale=1920:-1" \
  -progress pipe:1 \
  "$OUTPUT_DIR/frame_%06d.jpg" 2>>"$LOG_FILE" | while read -r line; do
    if [[ "$line" == out_time_ms=* ]]; then
      OUT_MS="${line#out_time_ms=}"
      OUT_SEC=$((OUT_MS / 1000000))
      PROGRESS=$((OUT_SEC * 100 / DURATION_SEC))
      if [ "$PROGRESS" -gt 99 ]; then PROGRESS=99; fi
      ETA=$((DURATION_SEC - OUT_SEC))
      if [ "$ETA" -lt 0 ]; then ETA=0; fi
      write_status "RUNNING" "$PROGRESS" "$ETA" "Extracting frames"
    fi
  done

FRAME_COUNT=$(find "$OUTPUT_DIR" -type f -name 'frame_*.jpg' | wc -l)

write_status "DONE" 100 0 "Done. Frames: $FRAME_COUNT"

cat > "$OUTPUT_DIR/result.json" <<EOF
{
  "job_id": $JOB_ID,
  "status": "DONE",
  "frames": $FRAME_COUNT,
  "output_dir": "$OUTPUT_DIR",
  "finished_at": "$(date -Iseconds)"
}
EOF