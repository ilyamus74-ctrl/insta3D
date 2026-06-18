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

INSTALL_PACKAGES="${INSTALL_PACKAGES:-0}"
COLMAP_MODE="${COLMAP_MODE:-native}"
COLMAP_BIN="${COLMAP_BIN:-colmap}"
COLMAP_IMAGE="${COLMAP_IMAGE:-}"
REQUIRE_COLMAP="${REQUIRE_COLMAP:-0}"

SSH=(ssh -i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new "${STATION_USER}@${STATION_HOST}")
SCP=(scp -i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new)
LOCAL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

shopt -s nullglob
SCRIPTS=("$LOCAL_DIR"/scripts/*.sh)
if (( ${#SCRIPTS[@]} == 0 )); then
  echo "ERROR: no scripts found in $LOCAL_DIR/scripts" >&2
  exit 1
fi

echo "==> Installing station: $STATION_NAME at $STATION_HOST"

echo "==> Check SSH"
"${SSH[@]}" "hostname && whoami"

echo "==> Detect remote OS"
"${SSH[@]}" 'bash -s' <<'REMOTE_OS'
set -euo pipefail
if [[ ! -r /etc/os-release ]]; then
  echo "ERROR: /etc/os-release not found on remote station" >&2
  exit 1
fi
# shellcheck source=/dev/null
source /etc/os-release
echo "ID=${ID:-unknown}"
echo "VERSION_ID=${VERSION_ID:-unknown}"
echo "PRETTY_NAME=${PRETTY_NAME:-unknown}"
REMOTE_OS

echo "==> Install packages"
"${SSH[@]}" \
  "STATION_BASE=$(printf '%q' "$STATION_BASE") INSTALL_PACKAGES=$(printf '%q' "$INSTALL_PACKAGES") COLMAP_MODE=$(printf '%q' "$COLMAP_MODE") COLMAP_BIN=$(printf '%q' "$COLMAP_BIN") COLMAP_IMAGE=$(printf '%q' "$COLMAP_IMAGE") REQUIRE_COLMAP=$(printf '%q' "$REQUIRE_COLMAP") bash -s" <<'REMOTE_INSTALL'
set -euo pipefail

if [[ ! -r /etc/os-release ]]; then
  echo "ERROR: /etc/os-release not found on remote station" >&2
  exit 1
fi
# shellcheck source=/dev/null
source /etc/os-release
OS_ID="${ID:-}"

if [[ "$INSTALL_PACKAGES" != "0" && "$INSTALL_PACKAGES" != "1" ]]; then
  echo "ERROR: INSTALL_PACKAGES must be 1 or 0, got: $INSTALL_PACKAGES" >&2
  exit 1
fi
if [[ "$REQUIRE_COLMAP" != "0" && "$REQUIRE_COLMAP" != "1" ]]; then
  echo "ERROR: REQUIRE_COLMAP must be 1 or 0, got: $REQUIRE_COLMAP" >&2
  exit 1
fi
if [[ "$COLMAP_MODE" != "native" && "$COLMAP_MODE" != "podman" ]]; then
  echo "ERROR: COLMAP_MODE must be native or podman, got: $COLMAP_MODE" >&2
  exit 1
fi

run_root() {
  if [[ "${EUID}" -eq 0 ]]; then
    "$@"
  elif command -v sudo >/dev/null 2>&1; then
    sudo "$@"
  else
    echo "ERROR: package installation requires root or sudo" >&2
    exit 1
  fi
}

have() {
  command -v "$1" >/dev/null 2>&1
}

install_fedora() {
  if ! have dnf; then
    echo "ERROR: Fedora station does not have dnf" >&2
    exit 1
  fi
  run_root dnf install -y ffmpeg rsync python3

  if [[ "$COLMAP_MODE" == "podman" ]]; then
    if ! have podman; then
      run_root dnf install -y podman
    fi
  elif have "$COLMAP_BIN"; then
    echo "OK: colmap already installed -> $(command -v "$COLMAP_BIN")"
  elif [[ "$COLMAP_BIN" == "colmap" ]] && run_root dnf install -y colmap; then
    echo "OK: colmap installed -> $(command -v colmap)"
  else
    echo "WARN: COLMAP package not found via dnf. Install COLMAP manually or enable required repository." >&2
  fi
}

install_debian_like() {
  if ! have apt-get; then
    echo "ERROR: Debian/Ubuntu station does not have apt-get" >&2
    exit 1
  fi
  run_root apt-get update
  if [[ "$COLMAP_MODE" == "podman" ]]; then
    run_root env DEBIAN_FRONTEND=noninteractive apt-get install -y ffmpeg podman rsync python3
  else
    run_root env DEBIAN_FRONTEND=noninteractive apt-get install -y ffmpeg colmap rsync python3
  fi
}

if [[ "$INSTALL_PACKAGES" == "1" ]]; then
  case "$OS_ID" in
    fedora)
      install_fedora
      ;;
    debian|ubuntu)
      install_debian_like
      ;;
    *)
      echo "ERROR: unsupported remote OS: ${OS_ID:-unknown}. Supported: fedora, debian, ubuntu" >&2
      exit 1
      ;;
  esac
else
  echo "INSTALL_PACKAGES=0; skipping package installation and checking existing tools only."
fi

if ! have nvidia-smi; then
  echo "ERROR: required tool not found: nvidia-smi" >&2
  echo "ERROR: NVIDIA driver/CUDA toolkit is not installed automatically by this script; install the NVIDIA driver manually first." >&2
  exit 1
fi

colmap_package_name() {
  if command -v rpm >/dev/null 2>&1; then
    rpm -qf "$1" 2>/dev/null || true
  fi
}

validate_native_colmap() {
  if ! have "$COLMAP_BIN"; then
    if [[ "$REQUIRE_COLMAP" == "1" ]]; then
      echo "ERROR: required tool not found: $COLMAP_BIN" >&2
      exit 1
    fi
    echo "WARN: optional tool not found: $COLMAP_BIN" >&2
    return 0
  fi

  local colmap_path package_name
  colmap_path="$(command -v "$COLMAP_BIN")"
  package_name="$(colmap_package_name "$colmap_path")"
  if [[ "$package_name" =~ ^geomorph ]]; then
    echo "ERROR: invalid COLMAP binary: $colmap_path belongs to $package_name, not photogrammetry COLMAP" >&2
    if [[ "$REQUIRE_COLMAP" == "1" ]]; then
      exit 1
    fi
    return 0
  fi

  echo "OK: colmap path: $colmap_path"
  if ! "$COLMAP_BIN" feature_extractor -h >/dev/null || ! "$COLMAP_BIN" exhaustive_matcher -h >/dev/null || ! "$COLMAP_BIN" mapper -h >/dev/null; then
    echo "ERROR: native COLMAP command validation failed for $COLMAP_BIN" >&2
    if [[ "$REQUIRE_COLMAP" == "1" ]]; then
      exit 1
    fi
  fi
}

validate_podman_colmap() {
  if ! have podman; then
    echo "ERROR: required tool not found: podman" >&2
    if [[ "$REQUIRE_COLMAP" == "1" ]]; then
      exit 1
    fi
    return 0
  fi
  if [[ -z "$COLMAP_IMAGE" ]]; then
    echo "ERROR: COLMAP_IMAGE is required when COLMAP_MODE=podman" >&2
    if [[ "$REQUIRE_COLMAP" == "1" ]]; then
      exit 1
    fi
    return 0
  fi
  if ! have nvidia-smi; then
    echo "ERROR: required tool not found: nvidia-smi" >&2
    if [[ "$REQUIRE_COLMAP" == "1" ]]; then
      exit 1
    fi
    return 0
  fi

  if ! podman pull "$COLMAP_IMAGE"; then
    echo "ERROR: failed to pull COLMAP image: $COLMAP_IMAGE" >&2
    if [[ "$REQUIRE_COLMAP" == "1" ]]; then
      exit 1
    fi
    return 0
  fi
  if ! podman run --rm --device nvidia.com/gpu=all --security-opt=label=disable "$COLMAP_IMAGE" colmap help; then
    echo "ERROR: podman COLMAP validation failed for image: $COLMAP_IMAGE" >&2
    if [[ "$REQUIRE_COLMAP" == "1" ]]; then
      exit 1
    fi
  fi
}

if [[ "$COLMAP_MODE" == "podman" ]]; then
  validate_podman_colmap
else
  validate_native_colmap
fi
REMOTE_INSTALL

echo "==> Create station directories"
"${SSH[@]}" "STATION_BASE=$(printf '%q' "$STATION_BASE") bash -s" <<'REMOTE_DIRS'
set -euo pipefail
mkdir -p \
  "$STATION_BASE/incoming" \
  "$STATION_BASE/work" \
  "$STATION_BASE/output" \
  "$STATION_BASE/logs" \
  "$STATION_BASE/status" \
  "$STATION_BASE/scripts"
REMOTE_DIRS

echo "==> Upload station processing scripts"
"${SCP[@]}" "${SCRIPTS[@]}" "${STATION_USER}@${STATION_HOST}:$STATION_BASE/scripts/"
"${SSH[@]}" "STATION_BASE=$(printf '%q' "$STATION_BASE") bash -s" <<'REMOTE_CHMOD'
set -euo pipefail
chmod +x "$STATION_BASE"/scripts/*.sh
REMOTE_CHMOD

echo "==> Final health check"
"${SSH[@]}" "COLMAP_MODE=$(printf '%q' "$COLMAP_MODE") COLMAP_BIN=$(printf '%q' "$COLMAP_BIN") COLMAP_IMAGE=$(printf '%q' "$COLMAP_IMAGE") REQUIRE_COLMAP=$(printf '%q' "$REQUIRE_COLMAP") bash -s" <<'REMOTE_HEALTH'
set -euo pipefail

check_required() {
  local tool="$1"
  if command -v "$tool" >/dev/null 2>&1; then
    echo "$tool path: $(command -v "$tool")"
  else
    echo "ERROR: required tool not found: $tool" >&2
    exit 1
  fi
}

check_required ffmpeg
check_required ffprobe
check_required python3
check_required rsync
check_required nvidia-smi

if [[ "$COLMAP_MODE" == "podman" ]]; then
  check_required podman
  if [[ -z "$COLMAP_IMAGE" ]]; then
    echo "ERROR: COLMAP_IMAGE is required when COLMAP_MODE=podman" >&2
    exit 1
  fi
  if podman run --rm --device nvidia.com/gpu=all --security-opt=label=disable "$COLMAP_IMAGE" colmap help >/dev/null; then
    echo "colmap podman image: $COLMAP_IMAGE"
  elif [[ "$REQUIRE_COLMAP" == "1" ]]; then
    echo "ERROR: podman COLMAP validation failed for image: $COLMAP_IMAGE" >&2
    exit 1
  else
    echo "WARN: optional podman COLMAP validation failed for image: $COLMAP_IMAGE" >&2
  fi
else
  if command -v "$COLMAP_BIN" >/dev/null 2>&1; then
    COLMAP_PATH="$(command -v "$COLMAP_BIN")"
    echo "colmap path: $COLMAP_PATH"
    if command -v rpm >/dev/null 2>&1 && rpm -qf "$COLMAP_PATH" 2>/dev/null | grep -qi '^geomorph'; then
      echo "ERROR: invalid COLMAP binary: $COLMAP_PATH belongs to geomorph" >&2
      exit 1
    fi
  elif [[ "$REQUIRE_COLMAP" == "1" ]]; then
    echo "ERROR: required tool not found: $COLMAP_BIN" >&2
    exit 1
  else
    echo "WARN: optional tool not found: $COLMAP_BIN" >&2
  fi
fi

echo "GPU:"
nvidia-smi --query-gpu=name,memory.total --format=csv,noheader
REMOTE_HEALTH

echo "==> Done"
