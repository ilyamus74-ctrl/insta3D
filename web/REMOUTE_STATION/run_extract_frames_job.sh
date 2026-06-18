#!/usr/bin/env bash
set -euo pipefail

CONFIG="${1:-./stations.conf}"

if [[ ! -f "$CONFIG" ]]; then
  echo "ERROR: config not found: $CONFIG"
  echo "Usage: $0 ./stations.conf"
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

echo "==> Installing station: $STATION_NAME at $STATION_HOST"

echo "==> Check SSH"
"${SSH[@]}" "hostname && whoami"

echo "==> Create directories"
"${SSH[@]}" "mkdir -p \
  '$STATION_BASE/incoming' \
  '$STATION_BASE/work' \
  '$STATION_BASE/output' \
  '$STATION_BASE/logs' \
  '$STATION_BASE/status' \
  '$STATION_BASE/scripts'"

echo "==> Upload scripts"
"${SCP[@]}" "$LOCAL_DIR/scripts/process_extract_frames.sh" "${STATION_USER}@${STATION_HOST}:$STATION_BASE/scripts/process_extract_frames.sh"

echo "==> chmod scripts"
"${SSH[@]}" "chmod +x '$STATION_BASE/scripts/process_extract_frames.sh'"

echo "==> Check tools"
"${SSH[@]}" "command -v ffmpeg && command -v ffprobe && command -v nvidia-smi && nvidia-smi --query-gpu=name,memory.total --format=csv,noheader"

echo "==> Done"
