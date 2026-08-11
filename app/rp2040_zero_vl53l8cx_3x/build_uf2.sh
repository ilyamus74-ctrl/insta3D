#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
THIRD="$ROOT/third_party"
VENDOR="$ROOT/vendor"
PICO_DIR="$THIRD/pico-sdk"
VL_DIR="$VENDOR/VL53L8CX"

mkdir -p "$THIRD" "$VENDOR"

need() {
  command -v "$1" >/dev/null 2>&1 || {
    echo "[ERROR] missing: $1"
    exit 1
  }
}

need git
need cmake
need python3
need arm-none-eabi-gcc

if [[ ! -d "$PICO_DIR/.git" ]]; then
  echo "[INFO] clone pico-sdk 2.3.0"
  git clone --depth 1 --branch 2.3.0 \
    https://github.com/raspberrypi/pico-sdk.git "$PICO_DIR"
  git -C "$PICO_DIR" submodule update --init --recursive
fi

VL_COMMIT="a93a9d6796f2a74835a4088f225daec343153c62"
if [[ ! -d "$VL_DIR/.git" ]]; then
  echo "[INFO] clone ST VL53L8CX library"
  git clone https://github.com/stm32duino/VL53L8CX.git "$VL_DIR"
fi
git -C "$VL_DIR" fetch --depth 1 origin "$VL_COMMIT"
git -C "$VL_DIR" checkout --detach "$VL_COMMIT"

export PICO_SDK_PATH="$PICO_DIR"

rm -rf "$ROOT/build"
cmake -S "$ROOT" -B "$ROOT/build" \
  -DPICO_BOARD=waveshare_rp2040_zero \
  -DCMAKE_BUILD_TYPE=Release
cmake --build "$ROOT/build" -j"$(nproc)"

echo
echo "[OK] UF2:"
ls -lh "$ROOT/build/tof_rig.uf2"
sha256sum "$ROOT/build/tof_rig.uf2"
