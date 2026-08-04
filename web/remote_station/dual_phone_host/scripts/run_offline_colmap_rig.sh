#!/usr/bin/env bash
set -Eeuo pipefail

usage() {
  cat <<'USAGE'
Usage:
  run_offline_colmap_rig.sh SESSION_DIR [options]

Options:
  --workspace DIR            output directory (default SESSION_DIR/offline_colmap_rig)
  --colmap BIN               COLMAP executable (default $COLMAP_BIN or colmap)
  --pair-step N              use every Nth archived synchronized pair (default 1)
  --max-pairs N              cap selected pairs; 0 means unlimited
  --overlap N                sequential matcher overlap (default 20)
  --loop-detection 0|1       sequential loop detection (default 1)
  --known-path-length-m M    physical path length for diagnostic comparison
  --copy-images              copy images instead of creating symlinks
  --dense                    run image undistortion, PatchMatch and stereo fusion
USAGE
}

[[ $# -ge 1 ]] || { usage >&2; exit 2; }
SESSION_DIR="$(realpath "$1")"
shift
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORKSPACE="$SESSION_DIR/offline_colmap_rig"
COLMAP_COMMAND="${COLMAP_BIN:-colmap}"
PAIR_STEP=1
MAX_PAIRS=0
OVERLAP=20
LOOP_DETECTION=1
KNOWN_PATH_LENGTH_M=0
COPY_IMAGES=0
RUN_DENSE=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --workspace) WORKSPACE="$2"; shift 2 ;;
    --colmap) COLMAP_COMMAND="$2"; shift 2 ;;
    --pair-step) PAIR_STEP="$2"; shift 2 ;;
    --max-pairs) MAX_PAIRS="$2"; shift 2 ;;
    --overlap) OVERLAP="$2"; shift 2 ;;
    --loop-detection) LOOP_DETECTION="$2"; shift 2 ;;
    --known-path-length-m) KNOWN_PATH_LENGTH_M="$2"; shift 2 ;;
    --copy-images) COPY_IMAGES=1; shift ;;
    --dense) RUN_DENSE=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "unknown argument: $1" >&2; usage >&2; exit 2 ;;
  esac
done

[[ -d "$SESSION_DIR" ]] || { echo "session not found: $SESSION_DIR" >&2; exit 1; }
[[ "$PAIR_STEP" =~ ^[1-9][0-9]*$ ]] || { echo "--pair-step must be >= 1" >&2; exit 2; }
[[ "$MAX_PAIRS" =~ ^[0-9]+$ ]] || { echo "--max-pairs must be >= 0" >&2; exit 2; }
[[ "$OVERLAP" =~ ^[1-9][0-9]*$ ]] || { echo "--overlap must be >= 1" >&2; exit 2; }
[[ "$LOOP_DETECTION" == 0 || "$LOOP_DETECTION" == 1 ]] || {
  echo "--loop-detection must be 0 or 1" >&2; exit 2;
}
command -v "$COLMAP_COMMAND" >/dev/null 2>&1 || {
  echo "COLMAP executable not found: $COLMAP_COMMAND" >&2; exit 1;
}
for command in feature_extractor rig_configurator sequential_matcher mapper model_converter; do
  "$COLMAP_COMMAND" "$command" -h >/dev/null 2>&1 || {
    echo "COLMAP command is unavailable: $command" >&2; exit 1;
  }
done

mkdir -p "$WORKSPACE"
WORKSPACE="$(realpath "$WORKSPACE")"
LOG_FILE="$WORKSPACE/offline_colmap_run.log"
STATUS_FILE="$WORKSPACE/offline_colmap_status.json"
exec > >(tee "$LOG_FILE") 2>&1

write_status() {
  local state="$1" stage="$2" message="$3"
  python3 - "$STATUS_FILE" "$state" "$stage" "$message" <<'PY'
import json,sys,datetime
path,state,stage,message=sys.argv[1:]
with open(path,'w',encoding='utf-8') as f:
    json.dump({
        'schema_version':1,
        'state':state,
        'stage':stage,
        'message':message,
        'updated_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),
    },f,indent=2)
    f.write('\n')
PY
}

on_error() {
  local code=$?
  write_status ERROR FAILED "offline COLMAP rig failed at line ${BASH_LINENO[0]:-unknown}, exit $code"
  exit "$code"
}
trap on_error ERR

write_status RUNNING PREPARE "preparing synchronized rig images"
PREPARE_ARGS=(
  "$SCRIPT_DIR/prepare_offline_colmap_rig.py"
  "$SESSION_DIR"
  "$WORKSPACE"
  --pair-step "$PAIR_STEP"
  --max-pairs "$MAX_PAIRS"
)
(( COPY_IMAGES > 0 )) && PREPARE_ARGS+=(--copy-images)
python3 "${PREPARE_ARGS[@]}"

DATABASE="$WORKSPACE/database.db"
SPARSE="$WORKSPACE/sparse"
SPARSE_TXT="$WORKSPACE/sparse_txt"
rm -f "$DATABASE"
rm -rf "$SPARSE" "$SPARSE_TXT"
mkdir -p "$SPARSE" "$SPARSE_TXT"

write_status RUNNING FEATURES "extracting calibrated features"
"$COLMAP_COMMAND" feature_extractor \
  --database_path "$DATABASE" \
  --image_path "$WORKSPACE/images" \
  --ImageReader.single_camera_per_folder 1 \
  --FeatureExtraction.use_gpu 0

write_status RUNNING RIG_CONFIG "applying fixed dual-phone rig calibration"
"$COLMAP_COMMAND" rig_configurator \
  --database_path "$DATABASE" \
  --rig_config_path "$WORKSPACE/rig_config.json"

write_status RUNNING MATCHING "matching sequential rig frames"
"$COLMAP_COMMAND" sequential_matcher \
  --database_path "$DATABASE" \
  --FeatureMatching.use_gpu 0 \
  --SequentialMatching.overlap "$OVERLAP" \
  --SequentialMatching.loop_detection "$LOOP_DETECTION"

write_status RUNNING MAPPER "running metric sparse reconstruction and bundle adjustment"
"$COLMAP_COMMAND" mapper \
  --database_path "$DATABASE" \
  --image_path "$WORKSPACE/images" \
  --output_path "$SPARSE" \
  --Mapper.ba_refine_sensor_from_rig 0 \
  --Mapper.ba_refine_focal_length 0 \
  --Mapper.ba_refine_principal_point 0 \
  --Mapper.ba_refine_extra_params 0

shopt -s nullglob
MODELS=("$SPARSE"/*)
shopt -u nullglob
(( ${#MODELS[@]} > 0 )) || { echo "COLMAP mapper produced no sparse model" >&2; exit 1; }
for model in "${MODELS[@]}"; do
  [[ -d "$model" ]] || continue
  model_id="$(basename "$model")"
  mkdir -p "$SPARSE_TXT/$model_id"
  "$COLMAP_COMMAND" model_converter \
    --input_path "$model" \
    --output_path "$SPARSE_TXT/$model_id" \
    --output_type TXT
done

write_status RUNNING TRAJECTORY "exporting metric CAMERA_A trajectory"
python3 "$SCRIPT_DIR/export_offline_colmap_trajectory.py" \
  "$WORKSPACE" "$SESSION_DIR" \
  --known-path-length-m "$KNOWN_PATH_LENGTH_M"

if (( RUN_DENSE > 0 )); then
  for command in image_undistorter patch_match_stereo stereo_fusion; do
    "$COLMAP_COMMAND" "$command" -h >/dev/null 2>&1 || {
      echo "COLMAP dense command is unavailable: $command" >&2; exit 1;
    }
  done
  BEST_MODEL="$(python3 - "$WORKSPACE/offline_colmap_summary.json" <<'PY'
import json,sys
print(json.load(open(sys.argv[1],encoding='utf-8'))['selected_model_binary_path'])
PY
)"
  DENSE="$WORKSPACE/dense"
  rm -rf "$DENSE"
  write_status RUNNING UNDISTORT "preparing dense workspace"
  "$COLMAP_COMMAND" image_undistorter \
    --image_path "$WORKSPACE/images" \
    --input_path "$BEST_MODEL" \
    --output_path "$DENSE" \
    --output_type COLMAP
  write_status RUNNING PATCH_MATCH "running COLMAP PatchMatch stereo"
  "$COLMAP_COMMAND" patch_match_stereo \
    --workspace_path "$DENSE" \
    --workspace_format COLMAP \
    --PatchMatchStereo.geom_consistency true
  write_status RUNNING FUSION "fusing dense metric cloud"
  "$COLMAP_COMMAND" stereo_fusion \
    --workspace_path "$DENSE" \
    --workspace_format COLMAP \
    --input_type geometric \
    --output_path "$DENSE/fused.ply"
fi

write_status READY COMPLETE "offline COLMAP rig reconstruction completed"
printf '\nResult summary: %s\n' "$WORKSPACE/offline_colmap_summary.json"
printf 'Trajectory PLY: %s\n' "$WORKSPACE/offline_colmap_trajectory.ply"
(( RUN_DENSE > 0 )) && printf 'Dense cloud: %s\n' "$WORKSPACE/dense/fused.ply"
