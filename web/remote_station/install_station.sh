#!/usr/bin/env bash
set -euo pipefail

CONFIG="${1:-./stations.conf}"

usage() {
  echo "Usage: $0 ./stations.conf" >&2
}

if [[ ! -f "$CONFIG" ]]; then
  echo "ERROR: config not found: $CONFIG" >&2
  usage
  exit 1
fi

# shellcheck source=/dev/null
source "$CONFIG"

: "${STATION_NAME:?missing STATION_NAME}"
: "${STATION_HOST:?missing STATION_HOST}"
: "${STATION_USER:?missing STATION_USER}"
: "${STATION_SSH_KEY:?missing STATION_SSH_KEY}"
: "${STATION_BASE:?missing STATION_BASE}"

SSH=(ssh -i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new "${STATION_USER}@${STATION_HOST}")
SCP=(scp -i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new)
LOCAL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [[ ! -f "$LOCAL_DIR/scripts/process_extract_frames.sh" ]]; then
  echo "ERROR: local script not found: $LOCAL_DIR/scripts/process_extract_frames.sh" >&2
  exit 1
fi

echo "==> Installing station: $STATION_NAME at $STATION_HOST"

echo "==> Check SSH"
"${SSH[@]}" "hostname && whoami"
echo "==> Create station directories under $STATION_BASE"
"${SSH[@]}" "mkdir -p \
  '$STATION_BASE/incoming' \
  '$STATION_BASE/work' \
  '$STATION_BASE/output' \
  '$STATION_BASE/logs' \
  '$STATION_BASE/status' \
  '$STATION_BASE/scripts'"
echo "==> Upload process_extract_frames.sh"
"${SCP[@]}" "$LOCAL_DIR/scripts/process_extract_frames.sh" "${STATION_USER}@${STATION_HOST}:$STATION_BASE/scripts/process_extract_frames.sh"

echo "==> chmod scripts"
"${SSH[@]}" "chmod +x '$STATION_BASE/scripts/process_extract_frames.sh'"
echo "==> Check station tools"
"${SSH[@]}" 'set -u
for tool in ffmpeg ffprobe nvidia-smi; do
  if command -v "$tool" >/dev/null 2>&1; then
    echo "OK: $tool -> $(command -v "$tool")"
  else
    echo "ERROR: required tool not found: $tool" >&2
    exit 1
  fi
done
if command -v colmap >/dev/null 2>&1; then
  echo "OK: colmap -> $(command -v colmap)"
else
  echo "WARN: optional tool not found: colmap"
fi
nvidia-smi --query-gpu=name,memory.total --format=csv,noheader || true'

echo "==> Done"