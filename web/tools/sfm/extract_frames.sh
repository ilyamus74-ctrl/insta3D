#!/usr/bin/env bash
set -euo pipefail

VIDEO_PATH="$1"
FRAMES_PATH="$2"
FPS="${3:-2}"
WIDTH="${4:-1920}"

mkdir -p "$FRAMES_PATH"
rm -f "$FRAMES_PATH"/frame_*.jpg

ffmpeg -y \
  -i "$VIDEO_PATH" \
  -vf "fps=${FPS},scale=${WIDTH}:-1" \
  -q:v 2 \
  "$FRAMES_PATH/frame_%06d.jpg"

COUNT=$(find "$FRAMES_PATH" -maxdepth 1 -type f -name 'frame_*.jpg' | wc -l)

echo "frames_created=$COUNT"