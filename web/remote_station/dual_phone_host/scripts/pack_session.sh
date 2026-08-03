#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage:
  pack_session.sh SESSION_DIR [--sample-every N] [--output-dir DIR]

Creates a .tar.zst diagnostic package outside the Git repository.
By default only JSON/JSONL diagnostics are included. --sample-every N adds
JPEG + sidecar JSON for every Nth frame from CAMERA_A and CAMERA_B.
USAGE
}

[[ $# -ge 1 ]] || { usage >&2; exit 2; }
SESSION_DIR="$(realpath "$1")"
shift
SAMPLE_EVERY=0
OUTPUT_DIR="${MAKLER_ARCHIVE_DIR:-$HOME/MaklerTourData/archives}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --sample-every)
      [[ $# -ge 2 ]] || { echo "missing value for --sample-every" >&2; exit 2; }
      SAMPLE_EVERY="$2"
      shift 2
      ;;
    --output-dir)
      [[ $# -ge 2 ]] || { echo "missing value for --output-dir" >&2; exit 2; }
      OUTPUT_DIR="$2"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "unknown argument: $1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

[[ -d "$SESSION_DIR" ]] || { echo "session directory not found: $SESSION_DIR" >&2; exit 1; }
[[ "$SAMPLE_EVERY" =~ ^[0-9]+$ ]] || { echo "--sample-every must be an integer >= 0" >&2; exit 2; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FUSION_TOOL="$SCRIPT_DIR/../tools/fuse_room_geometry.py"
if [[ -x "$FUSION_TOOL" && -d "$SESSION_DIR/keyframes" ]]; then
  echo "[FUSION] Filtering local stereo clouds and fusing room planes..."
  if ! python3 "$FUSION_TOOL" "$SESSION_DIR" >"$SESSION_DIR/room_fusion_console.json"; then
    echo "[FUSION] Warning: robust room fusion failed; diagnostics archive will still be created." >&2
  fi
fi

LOCAL_STEREO_TOOL="$SCRIPT_DIR/../tools/analyze_local_stereo_geometry.py"
if [[ -x "$LOCAL_STEREO_TOOL" && -d "$SESSION_DIR/keyframes" ]]; then
  echo "[LOCAL-STEREO] Validating local clouds, world transforms and tripod pose..."
  if ! python3 "$LOCAL_STEREO_TOOL" "$SESSION_DIR" >"$SESSION_DIR/local_stereo_validation_console.json"; then
    echo "[LOCAL-STEREO] Warning: validation failed; diagnostics archive will still be created." >&2
  fi
fi

mkdir -p "$OUTPUT_DIR"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT
PACKAGE_ROOT="$WORK_DIR/$(basename "$SESSION_DIR")"
mkdir -p "$PACKAGE_ROOT"

for name in \
  session.json \
  events.jsonl \
  pairs.jsonl \
  stereo_preview.jsonl \
  stereo_preview_status.json \
  live_preview.jsonl \
  live_preview_status.json \
  room_geometry.jsonl \
  room_geometry_status.json \
  accumulated_map.jsonl \
  accumulated_map_status.json \
  pose_validation.jsonl \
  apriltag_observations.jsonl \
  apriltag_stereo_observations.jsonl \
  apriltag_constraints.jsonl \
  apriltag_map.json \
  apriltag_map.ply \
  apriltag_relations.json \
  apriltag_tag_graph.json \
  apriltag_status.json \
  apriltag_latest.jpg \
  apriltag_latest_a.jpg \
  apriltag_latest_b.jpg \
  point_cloud_accumulated.ply \
  point_cloud_accumulated_raw.ply \
  point_cloud_accumulated_multiview.ply \
  point_cloud_accumulated_temporal_strict_raw.ply \
  point_cloud_accumulated_temporal_strict_multiview.ply \
  point_cloud_accumulated_confirmed.ply \
  point_cloud_accumulated_strict.ply \
  point_cloud_accumulated_keyframe_colors.ply \
  point_cloud_accumulated_filtered_raw.ply \
  point_cloud_accumulated_filtered.ply \
  room_planes_accumulated.json \
  room_edges_accumulated.json \
  room_skeleton_accumulated.ply \
  room_fusion_status.json \
  room_fusion_diagnostics.json \
  room_fusion_console.json \
  local_stereo_validation.json \
  local_stereo_validation.txt \
  local_stereo_validation_console.json \
  accumulated_diagnostics.json \
  accumulated_diagnostics.txt \
  camera_trajectory.json \
  camera_trajectory.ply \
  point_cloud_latest.ply \
  room_skeleton_latest.ply \
  room_planes_latest.json \
  room_edges_latest.json \
  imu_a.jsonl \
  imu_b.jsonl \
  camera_a_hello.json \
  camera_b_hello.json \
  stereo_calibration.json \
  raw_a_latest.jpg \
  raw_b_latest.jpg \
  rectified_a_latest.jpg \
  rectified_b_latest.jpg \
  disparity_latest.jpg \
  depth_raw_latest.jpg \
  depth_filtered_latest.jpg \
  depth_strict_latest.jpg \
  confidence_latest.jpg \
  selected_preview_latest.jpg
do
  [[ -f "$SESSION_DIR/$name" ]] && cp -a "$SESSION_DIR/$name" "$PACKAGE_ROOT/"
done

if [[ -d "$SESSION_DIR/keyframes" ]]; then
  mkdir -p "$PACKAGE_ROOT/keyframes"
  cp -a "$SESSION_DIR/keyframes/." "$PACKAGE_ROOT/keyframes/"
fi

if (( SAMPLE_EVERY > 0 )); then
  for camera in camera_a camera_b; do
    source_dir="$SESSION_DIR/$camera"
    [[ -d "$source_dir" ]] || continue
    target_dir="$PACKAGE_ROOT/$camera"
    mkdir -p "$target_dir"
    index=0
    while IFS= read -r -d '' image; do
      if (( index % SAMPLE_EVERY == 0 )); then
        cp -a "$image" "$target_dir/"
        sidecar="${image%.jpg}.json"
        [[ -f "$sidecar" ]] && cp -a "$sidecar" "$target_dir/"
      fi
      index=$((index + 1))
    done < <(find "$source_dir" -maxdepth 1 -type f -name '*.jpg' -print0 | sort -z)
  done
fi

(
  cd "$PACKAGE_ROOT"
  find . -type f ! -name MANIFEST.sha256 -print0 | \
    sort -z | xargs -0 sha256sum > MANIFEST.sha256
)

stamp="$(date -u +%Y%m%dT%H%M%SZ)"
archive="$OUTPUT_DIR/$(basename "$SESSION_DIR")-diagnostics-$stamp.tar.zst"
tar --zstd -cf "$archive" -C "$WORK_DIR" "$(basename "$SESSION_DIR")"
sha256sum "$archive" > "$archive.sha256"

printf 'Archive: %s\n' "$archive"
printf 'Checksum: %s\n' "$archive.sha256"
printf 'Sample every: %s\n' "$SAMPLE_EVERY"
