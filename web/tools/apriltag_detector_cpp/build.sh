#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUILD_DIR="${PROJECT_DIR}/build"

echo "=== AprilTag detector build ==="
echo "Project dir: ${PROJECT_DIR}"
echo "Build dir:   ${BUILD_DIR}"

echo
echo "=== Checking dependencies ==="

command -v cmake >/dev/null || { echo "ERROR: cmake not found"; exit 1; }
command -v make >/dev/null || { echo "ERROR: make not found"; exit 1; }
command -v c++ >/dev/null || { echo "ERROR: c++ compiler not found"; exit 1; }
command -v pkg-config >/dev/null || { echo "ERROR: pkg-config not found"; exit 1; }

echo "--- apriltag pkg-config ---"
pkg-config --modversion apriltag
pkg-config --cflags apriltag
pkg-config --libs apriltag

echo
echo "--- OpenCV pkg-config, optional ---"
pkg-config --modversion opencv4 || true

echo
echo "=== Cleaning build dir ==="
rm -rf "${BUILD_DIR}"
mkdir -p "${BUILD_DIR}"

echo
echo "=== Running CMake ==="
cd "${BUILD_DIR}"
cmake .. -DCMAKE_BUILD_TYPE=Release 2>&1 | tee cmake.log

echo
echo "=== Building ==="
make -j"$(nproc)" 2>&1 | tee build.log

echo
echo "=== Binary check ==="
ls -lh "${BUILD_DIR}/detect_markers"
file "${BUILD_DIR}/detect_markers"

echo
echo "=== Shared libraries check ==="
ldd "${BUILD_DIR}/detect_markers" | grep -E "opencv|apriltag|not found" || true

echo
echo "=== Empty JSON smoke test ==="
cat > /tmp/input_media_empty.json <<'JSON'
{
  "session_id": 0,
  "items": []
}
JSON

"${BUILD_DIR}/detect_markers" \
  --input-list /tmp/input_media_empty.json \
  --output /tmp/detections_empty.json \
  --tag-family tag36h11 \
  --valid-ids 1-30 \
  --marker-size-m 0.160

echo
echo "=== Detector output ==="
cat /tmp/detections_empty.json
echo

echo "=== Build OK ==="
