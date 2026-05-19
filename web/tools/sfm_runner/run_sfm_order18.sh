#!/usr/bin/env bash
set -euo pipefail

source /home/makler/web/tools/sfm_runner/sfm_env_order18.sh

mkdir -p \
  "$SFM_VIDEO_DIR" \
  "$SFM_FRAMES_DIR" \
  "$SFM_KEYFRAMES_DIR" \
  "$SFM_COLMAP_DIR" \
  "$SFM_MARKERS_DIR" \
  "$SFM_TRAJECTORY_DIR" \
  "$SFM_LOG_DIR"

RUN_ID="$(date +%Y%m%d_%H%M%S)"
LOG_FILE="${SFM_LOG_DIR}/sfm_pipeline_${RUN_ID}.log"

log() {
  echo "[$(date '+%F %T')] $*" | tee -a "$LOG_FILE"
}

run_step() {
  local name="$1"
  shift

  log "===== START: ${name} ====="
  local start_ts
  start_ts="$(date +%s)"

  "$@" 2>&1 | tee -a "$LOG_FILE"

  local end_ts
  end_ts="$(date +%s)"
  local elapsed=$((end_ts - start_ts))

  log "===== DONE: ${name}, elapsed=${elapsed}s ====="
}

log "SFM pipeline started"
log "ORDER_ID=${ORDER_ID}"
log "SESSION_PATH=${SESSION_PATH}"
log "VIDEO_PATH=${VIDEO_PATH}"
log "SFM_BASE=${SFM_BASE}"

if [ ! -f "$VIDEO_PATH" ]; then
  log "ERROR: video file not found: $VIDEO_PATH"
  exit 1
fi

if [ ! -x "$SFM_TOOL" ]; then
  log "ERROR: sfm_tool not executable: $SFM_TOOL"
  exit 1
fi

if [ ! -x "$COLMAP_BIN" ]; then
  log "ERROR: colmap not executable: $COLMAP_BIN"
  exit 1
fi

log "Video info:"
ffprobe -hide_banner "$VIDEO_PATH" 2>&1 | tee -a "$LOG_FILE" || true

# 1. Copy/link video into sfm/video for stable layout.
run_step "prepare_video" bash -c "
  ln -sf '$VIDEO_PATH' '$SFM_VIDEO_DIR/scan.mp4'
  ls -lh '$SFM_VIDEO_DIR/scan.mp4'
"

# 2. Extract SfM frames.
run_step "extract_sfm_frames" bash -c "
  rm -rf '$SFM_FRAMES_DIR'
  mkdir -p '$SFM_FRAMES_DIR'

  ffmpeg -y \
    -i '$SFM_VIDEO_DIR/scan.mp4' \
    -vf 'fps=${SFM_FPS},scale=${FRAME_WIDTH}:-1' \
    -q:v 2 \
    '$SFM_FRAMES_DIR/frame_%06d.jpg'

  echo 'frames_count='\"\$(find '$SFM_FRAMES_DIR' -type f -name 'frame_*.jpg' | wc -l)\"
  du -sh '$SFM_FRAMES_DIR'
"

# 3. Extract project keyframes.
run_step "extract_project_keyframes" bash -c "
  rm -rf '$SFM_KEYFRAMES_DIR'
  mkdir -p '$SFM_KEYFRAMES_DIR'

  ffmpeg -y \
    -i '$SFM_VIDEO_DIR/scan.mp4' \
    -vf 'fps=${KEYFRAME_FPS},scale=${FRAME_WIDTH}:-1' \
    -q:v 2 \
    '$SFM_KEYFRAMES_DIR/keyframe_%06d.jpg'

  echo 'keyframes_count='\"\$(find '$SFM_KEYFRAMES_DIR' -type f -name 'keyframe_*.jpg' | wc -l)\"
  du -sh '$SFM_KEYFRAMES_DIR'
"

# 4. Camera profile. Temporary rough profile for 1920x1920 fisheye stream.
run_step "write_camera_profile" bash -c "
  cat > '$SFM_BASE/camera_profile.json' <<CAMERAEOF
{
  \"name\": \"insta360_video_test_1920\",
  \"image_width\": 1920,
  \"image_height\": 1920,
  \"camera_model\": \"OPENCV\",
  \"fx\": 960.0,
  \"fy\": 960.0,
  \"cx\": 960.0,
  \"cy\": 960.0,
  \"dist\": [0.0, 0.0, 0.0, 0.0, 0.0]
}
CAMERAEOF

  cat '$SFM_BASE/camera_profile.json'
"

# 5. Detect AprilTags first.
run_step "detect_apriltags" "$SFM_TOOL" detect-apriltag-frames \
  --frames "$SFM_FRAMES_DIR" \
  --camera-profile "$SFM_BASE/camera_profile.json" \
  --marker-size-m "$MARKER_SIZE_M" \
  --family "$MARKER_FAMILY" \
  --out "$SFM_MARKERS_DIR/marker_observations.json"

run_step "marker_stats" bash -c "
  jq '.ok, .count' '$SFM_MARKERS_DIR/marker_observations.json'
  echo 'Markers by ID:'
  jq -r '.observations[] | \"\(.marker_id)\"' '$SFM_MARKERS_DIR/marker_observations.json' | sort | uniq -c | sort -nr | head -50
"

# 6. COLMAP sparse.
run_step "colmap_feature_extractor" "$COLMAP_BIN" feature_extractor \
  --database_path "$SFM_COLMAP_DIR/database.db" \
  --image_path "$SFM_FRAMES_DIR" \
  --ImageReader.single_camera 1

run_step "colmap_sequential_matcher" "$COLMAP_BIN" sequential_matcher \
  --database_path "$SFM_COLMAP_DIR/database.db" \
  --SequentialMatching.overlap 20

run_step "colmap_mapper" bash -c "
  rm -rf '$SFM_COLMAP_DIR/sparse'
  mkdir -p '$SFM_COLMAP_DIR/sparse'

  '$COLMAP_BIN' mapper \
    --database_path '$SFM_COLMAP_DIR/database.db' \
    --image_path '$SFM_FRAMES_DIR' \
    --output_path '$SFM_COLMAP_DIR/sparse'
"

# 7. Convert model.
run_step "colmap_model_converter" bash -c "
  rm -rf '$SFM_COLMAP_DIR/sparse/0_txt'
  mkdir -p '$SFM_COLMAP_DIR/sparse/0_txt'

  '$COLMAP_BIN' model_converter \
    --input_path '$SFM_COLMAP_DIR/sparse/0' \
    --output_path '$SFM_COLMAP_DIR/sparse/0_txt' \
    --output_type TXT

  ls -lh '$SFM_COLMAP_DIR/sparse/0_txt'
"

# 8. Parse poses.
run_step "parse_colmap_images" "$SFM_TOOL" parse-colmap-images \
  --images "$SFM_COLMAP_DIR/sparse/0_txt/images.txt" \
  --out "$SFM_TRAJECTORY_DIR/camera_poses.json"

run_step "pose_stats" bash -c "
  jq '.ok, .count' '$SFM_TRAJECTORY_DIR/camera_poses.json'
"

# 9. Rough scale.
run_step "rough_scale" "$SFM_TOOL" rough-scale \
  --poses "$SFM_TRAJECTORY_DIR/camera_poses.json" \
  --markers "$SFM_MARKERS_DIR/marker_observations.json" \
  --out "$SFM_TRAJECTORY_DIR/trajectory_scaled.json" || true

run_step "scale_stats" bash -c "
  jq '.ok, .scale_factor, .samples_count, .error' '$SFM_TRAJECTORY_DIR/trajectory_scaled.json' || true
"

# 10. Intersection diagnostics.
run_step "diagnostics_pose_marker_intersection" bash -c "
  jq -r '.poses[].image_name' '$SFM_TRAJECTORY_DIR/camera_poses.json' | sort > '$SFM_LOG_DIR/pose_frames_${RUN_ID}.txt'
  jq -r '.observations[].image_name' '$SFM_MARKERS_DIR/marker_observations.json' | sort -u > '$SFM_LOG_DIR/marker_frames_${RUN_ID}.txt'

  echo 'Pose frames count:'
  wc -l '$SFM_LOG_DIR/pose_frames_${RUN_ID}.txt'

  echo 'Marker frames count:'
  wc -l '$SFM_LOG_DIR/marker_frames_${RUN_ID}.txt'

  echo 'Intersection count:'
  comm -12 '$SFM_LOG_DIR/pose_frames_${RUN_ID}.txt' '$SFM_LOG_DIR/marker_frames_${RUN_ID}.txt' | tee '$SFM_LOG_DIR/intersection_${RUN_ID}.txt' | wc -l

  echo 'Intersection frames:'
  cat '$SFM_LOG_DIR/intersection_${RUN_ID}.txt'
"

log "SFM pipeline finished"
log "LOG_FILE=${LOG_FILE}"
