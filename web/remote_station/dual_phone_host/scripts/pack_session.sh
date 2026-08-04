#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage:
  pack_session.sh SESSION_DIR [--sample-every N] [--output-dir DIR]
                  [--include-intermediate-models]

Creates a .tar.zst diagnostic package outside the Git repository.
The default archive contains diagnostics plus a small curated `models/` set.
Raw, duplicate and per-keyframe PLY models are omitted. Use
--include-intermediate-models only for a full geometry-debug package.
--sample-every N adds JPEG + sidecar JSON for every Nth frame.
USAGE
}

[[ $# -ge 1 ]] || { usage >&2; exit 2; }
SESSION_DIR="$(realpath "$1")"
shift
SAMPLE_EVERY=0
INCLUDE_INTERMEDIATE_MODELS=0
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
    --include-intermediate-models)
      INCLUDE_INTERMEDIATE_MODELS=1
      shift
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

MULTI_PLANE_TOOL="$SCRIPT_DIR/../tools/analyze_multi_plane_corners.py"
if [[ -x "$MULTI_PLANE_TOOL" && -f "$SESSION_DIR/room_planes_accumulated.json" ]]; then
  echo "[MULTI-PLANE] Diagnosing wall, ceiling and Manhattan corner candidates..."
  if ! python3 "$MULTI_PLANE_TOOL" "$SESSION_DIR" >"$SESSION_DIR/room_multi_plane_console.json"; then
    echo "[MULTI-PLANE] Warning: candidate diagnostics failed; archive creation will continue." >&2
  fi
fi

MANHATTAN_FUSION_TOOL="$SCRIPT_DIR/../tools/fuse_manhattan_room.py"
if [[ -x "$MANHATTAN_FUSION_TOOL" && \
      -f "$SESSION_DIR/room_plane_candidates_accumulated.json" && \
      -f "$SESSION_DIR/room_corner_hypotheses_accumulated.json" ]]; then
  echo "[MANHATTAN-FUSION] Merging fragmented walls and confirming supported room corners..."
  if ! python3 "$MANHATTAN_FUSION_TOOL" "$SESSION_DIR" >"$SESSION_DIR/room_manhattan_fusion_console.json"; then
    echo "[MANHATTAN-FUSION] Warning: conservative Manhattan fusion failed; archive creation will continue." >&2
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
  apriltag_relations.json \
  apriltag_tag_graph.json \
  apriltag_status.json \
  apriltag_latest.jpg \
  apriltag_latest_a.jpg \
  apriltag_latest_b.jpg \
  room_planes_accumulated.json \
  room_edges_accumulated.json \
  room_fusion_status.json \
  room_fusion_diagnostics.json \
  room_fusion_console.json \
  room_plane_candidates_accumulated.json \
  room_corner_hypotheses_accumulated.json \
  room_multi_plane_status.json \
  room_multi_plane_console.json \
  room_planes_manhattan_accumulated.json \
  room_edges_manhattan_accumulated.json \
  room_manhattan_fusion_status.json \
  room_manhattan_fusion_console.json \
  local_stereo_validation.json \
  local_stereo_validation.txt \
  local_stereo_validation_console.json \
  accumulated_diagnostics.json \
  accumulated_diagnostics.txt \
  camera_trajectory.json \
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

MODEL_DIR="$PACKAGE_ROOT/models"
mkdir -p "$MODEL_DIR"

copy_model() {
  local source_name="$1"
  local target_name="$2"
  [[ -f "$SESSION_DIR/$source_name" ]] || return 1
  cp -a "$SESSION_DIR/$source_name" "$MODEL_DIR/$target_name"
}

if ! copy_model point_cloud_accumulated_filtered.ply 01_cloud_filtered_multiview.ply; then
  copy_model point_cloud_accumulated_multiview.ply 01_cloud_filtered_multiview.ply || true
fi
copy_model point_cloud_accumulated_temporal_strict_multiview.ply \
  02_cloud_temporal_strict_multiview.ply || true
if ! copy_model room_skeleton_manhattan_accumulated.ply \
  03_room_manhattan_skeleton.ply; then
  copy_model room_skeleton_accumulated.ply 03_room_manhattan_skeleton.ply || true
fi
copy_model camera_trajectory.ply 04_camera_trajectory.ply || true
copy_model apriltag_map.ply 05_apriltag_map.ply || true

cat >"$MODEL_DIR/README.txt" <<'README'
Curated model set
=================
01_cloud_filtered_multiview.ply       final filtered multi-view point cloud
02_cloud_temporal_strict_multiview.ply strict temporal-overlap cloud
03_room_manhattan_skeleton.ply        final Manhattan room planes and edges
04_camera_trajectory.ply              constrained camera trajectory
05_apriltag_map.ply                   mapped AprilTag anchors

Raw clouds, duplicate live models and per-keyframe PLY files are intentionally
excluded from the default archive. Re-run pack_session.sh with
--include-intermediate-models when those files are required for deep debugging.
README

if (( INCLUDE_INTERMEDIATE_MODELS > 0 )); then
  INTERMEDIATE_DIR="$MODEL_DIR/intermediate"
  mkdir -p "$INTERMEDIATE_DIR"
  while IFS= read -r -d '' model; do
    cp -a "$model" "$INTERMEDIATE_DIR/"
  done < <(find "$SESSION_DIR" -maxdepth 1 -type f -name '*.ply' -print0 | sort -z)
  if [[ -d "$SESSION_DIR/keyframes" ]]; then
    mkdir -p "$INTERMEDIATE_DIR/keyframes"
    cp -a "$SESSION_DIR/keyframes/." "$INTERMEDIATE_DIR/keyframes/"
  fi
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
printf 'Intermediate models: %s\n' "$INCLUDE_INTERMEDIATE_MODELS"
