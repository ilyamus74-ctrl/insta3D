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
INSTALL_OPEN3D_DEPENDENCIES="${INSTALL_OPEN3D_DEPENDENCIES:-0}"

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

if [[ "$INSTALL_OPEN3D_DEPENDENCIES" == "1" ]]; then
  echo "==> Installing Open3D dependencies on $STATION_NAME"
  "${SSH[@]}" "set -e
    if ! [[ -x '$STATION_BASE/open3d-venv/bin/python' ]] || ! '$STATION_BASE/open3d-venv/bin/python' -c 'import open3d' >/dev/null 2>&1; then
      if command -v dnf >/dev/null 2>&1; then
        dnf install -y python3.12 python3.12-devel
        python3.12 -m venv '$STATION_BASE/open3d-venv'
      else
        echo 'ERROR: Open3D auto-install is only supported on Fedora/dnf stations' >&2
        exit 1
      fi
      '$STATION_BASE/open3d-venv/bin/pip' install --upgrade pip setuptools wheel
      '$STATION_BASE/open3d-venv/bin/pip' install open3d==0.19.0
    fi
  "
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
    '$STATION_BASE/venv/bin/pip' install numpy opencv-python-headless
  "
fi

echo "==> Deploying scripts to $STATION_NAME at $STATION_HOST"
"${SSH[@]}" "mkdir -p '$STATION_BASE/scripts'"
"${SCP[@]}" "${SCRIPT_FILES[@]}" "${STATION_USER}@${STATION_HOST}:$STATION_BASE/scripts/"
"${SCP[@]}" "$LOCAL_DIR"/*.sh "${STATION_USER}@${STATION_HOST}:$STATION_BASE/"
"${SSH[@]}" "
  set -e
  chmod +x '$STATION_BASE'/scripts/*.sh
  chmod +x '$STATION_BASE'/scripts/*.py
  chmod +x '$STATION_BASE'/*.sh 2>/dev/null || true
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
  '$STATION_BASE/venv/bin/python' -c 'import cv2, numpy; print(cv2.__version__, numpy.__version__)'
  test -f '$STATION_BASE/scripts/plan_colmap_dense_chunks.py'
  test -f '$STATION_BASE/scripts/merge_dense_chunks.py'
  test -x '$STATION_BASE/scripts/process_colmap_mesh.sh'
  test -x '$STATION_BASE/scripts/process_maklertour_synced_dense.sh'
  test -f '$STATION_BASE/scripts/dense_depth_from_synced_capture.py'
  test -x '$STATION_BASE/scripts/process_open3d_mesh.py'
  test -x '$STATION_BASE/open3d-venv/bin/python'
  '$STATION_BASE/open3d-venv/bin/python' -c 'import open3d; print(open3d.__version__)'
"; then
  if [[ "$INSTALL_STATION_DEPENDENCIES" != "1" ]]; then
    echo "ERROR: station Python dependency check failed. Re-run with INSTALL_STATION_DEPENDENCIES=1 in $CONFIG to install python3, pip, venv, numpy, and opencv-python-headless." >&2
  fi
  exit 1
fi

echo "==> Done"
