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
INSTALL_STATION_DEPENDENCIES="${INSTALL_STATION_DEPENDENCIES:-0}"

SSH=(ssh -i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new "${STATION_USER}@${STATION_HOST}")
SCP=(scp -i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new)
LOCAL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

shopt -s nullglob
SCRIPT_FILES=(
  "$LOCAL_DIR"/scripts/*.sh
  "$LOCAL_DIR"/scripts/*.py
)
if (( ${#SCRIPT_FILES[@]} == 0 )); then
  echo "ERROR: no scripts found in $LOCAL_DIR/scripts" >&2
  exit 1
fi

if [[ "$INSTALL_STATION_DEPENDENCIES" == "1" ]]; then
  echo "==> Installing station Python dependencies on $STATION_NAME"
  "${SSH[@]}" "set -e
    if command -v dnf >/dev/null 2>&1; then
      dnf install -y python3 python3-pip python3-numpy
    elif command -v apt-get >/dev/null 2>&1; then
      apt-get update
      DEBIAN_FRONTEND=noninteractive apt-get install -y python3 python3-pip python3-venv python3-numpy
    else
      echo 'ERROR: no supported package manager found for Python dependencies' >&2
      exit 1
    fi
    python3 -m venv '$STATION_BASE/venv'
    '$STATION_BASE/venv/bin/pip' install --upgrade pip
    '$STATION_BASE/venv/bin/pip' install numpy
  "
fi

echo "==> Deploying scripts to $STATION_NAME at $STATION_HOST"
"${SSH[@]}" "mkdir -p '$STATION_BASE/scripts'"
"${SCP[@]}" "${SCRIPT_FILES[@]}" "${STATION_USER}@${STATION_HOST}:$STATION_BASE/scripts/"
"${SSH[@]}" "
  set -e
  chmod +x '$STATION_BASE'/scripts/*.sh
  chmod +x '$STATION_BASE'/scripts/*.py
"

echo "==> Checking station Python dependencies"
if ! "${SSH[@]}" "
  set -e
  command -v python3
  python3 --version
  python3 -c 'import json, pathlib'
  python3 -c 'import numpy; print(numpy.__version__)'
  if [[ ! -x '$STATION_BASE/venv/bin/python' ]]; then
    echo 'ERROR: station venv python not found: $STATION_BASE/venv/bin/python' >&2
    exit 1
  fi
  '$STATION_BASE/venv/bin/python' -c 'import numpy; print(numpy.__version__)'
  test -f '$STATION_BASE/scripts/plan_colmap_dense_chunks.py'
  test -f '$STATION_BASE/scripts/merge_dense_chunks.py'
  test -x '$STATION_BASE/scripts/process_colmap_mesh.sh'
"; then
  if [[ "$INSTALL_STATION_DEPENDENCIES" != "1" ]]; then
    echo "ERROR: station Python dependency check failed. Re-run with INSTALL_STATION_DEPENDENCIES=1 in $CONFIG to install python3, pip, venv, and numpy." >&2
  fi
  exit 1
fi

echo "==> Done"
