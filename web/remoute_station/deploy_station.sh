#!/usr/bin/env bash
set -euo pipefail

CONFIG="${1:-./stations.conf}"

if [[ ! -f "$CONFIG" ]]; then
  echo "ERROR: config not found: $CONFIG" >&2
  echo "Usage: $0 ./stations.conf" >&2
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

shopt -s nullglob
SCRIPTS=("$LOCAL_DIR"/scripts/*.sh)
if (( ${#SCRIPTS[@]} == 0 )); then
  echo "ERROR: no scripts found in $LOCAL_DIR/scripts" >&2
  exit 1
fi

echo "==> Deploying scripts to $STATION_NAME at $STATION_HOST"
"${SSH[@]}" "mkdir -p '$STATION_BASE/scripts'"
"${SCP[@]}" "${SCRIPTS[@]}" "${STATION_USER}@${STATION_HOST}:$STATION_BASE/scripts/"
"${SSH[@]}" "chmod +x '$STATION_BASE'/scripts/*.sh"
echo "==> Done"
