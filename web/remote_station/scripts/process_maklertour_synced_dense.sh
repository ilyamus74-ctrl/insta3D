#!/usr/bin/env bash
set -Eeuo pipefail
if [[ $# -ne 6 ]]; then echo "Usage: $0 <job_id> <input_tgz> <output_dir> <max_pairs> <num_disparities> <block_size>" >&2; exit 2; fi
JOB_ID="$1"; INPUT_TGZ="$2"; OUTPUT_DIR="$3"; MAX_PAIRS="$4"; NUM_DISPARITIES="$5"; BLOCK_SIZE="$6"
STATION_BASE="${STATION_BASE:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
STATUS_DIR="$STATION_BASE/status"; LOG_DIR="$STATION_BASE/logs"; WORK_DIR="$STATION_BASE/work/job_${JOB_ID}"; PACKAGE_DIR="$WORK_DIR/package"
LOG_FILE="$LOG_DIR/job_${JOB_ID}.log"
mkdir -p "$STATUS_DIR" "$LOG_DIR" "$WORK_DIR" "$OUTPUT_DIR/dense"
exec > >(tee -a "$LOG_FILE") 2>&1
json_escape(){ python3 -c 'import json,sys; print(json.dumps(sys.stdin.read())[1:-1])'; }
write_status(){ local st="$1" prog="$2" msg="$3"; local esc; esc=$(printf '%s' "$msg" | json_escape); cat > "$STATUS_DIR/job_${JOB_ID}.json" <<JSON
{"job_id":$JOB_ID,"job_type":"MAKLERTOUR_SYNCED_DENSE","status":"$st","progress_percent":$prog,"message":"$esc","updated_at":"$(date -Is)"}
JSON
cp "$STATUS_DIR/job_${JOB_ID}.json" "$OUTPUT_DIR/status.json" 2>/dev/null || true; }
on_error(){ local code=$?; write_status ERROR 0 "${BASH_COMMAND} failed with exit $code"; echo "ERROR: ${BASH_COMMAND} failed with exit $code"; exit $code; }
trap on_error ERR
write_status RUNNING 0 "Starting"
[[ -f "$INPUT_TGZ" ]] || { write_status ERROR 0 "Input tgz not found: $INPUT_TGZ"; exit 1; }
write_status RUNNING 10 "Unpacking bundle"
rm -rf "$PACKAGE_DIR"; mkdir -p "$PACKAGE_DIR"; tar -xzf "$INPUT_TGZ" -C "$PACKAGE_DIR"
# If tgz contains a single top-level directory, use it as package root.
ROOT="$PACKAGE_DIR"
if [[ ! -f "$ROOT/bundle_manifest.json" ]]; then
  first_dir=$(find "$PACKAGE_DIR" -mindepth 1 -maxdepth 1 -type d | head -n 1 || true)
  if [[ -n "$first_dir" && -f "$first_dir/bundle_manifest.json" ]]; then ROOT="$first_dir"; fi
fi
write_status RUNNING 25 "Validating bundle"
[[ -f "$ROOT/bundle_manifest.json" ]] || { write_status ERROR 25 "Missing package/bundle_manifest.json"; exit 3; }
[[ -f "$ROOT/capture/synced_depth_manifest.json" ]] || { write_status ERROR 25 "Missing package/capture/synced_depth_manifest.json"; exit 3; }
[[ -d "$ROOT/capture/pairs" ]] || { write_status ERROR 25 "Missing package/capture/pairs"; exit 3; }
[[ -f "$ROOT/calibration/stereo_extrinsics.json" ]] || { write_status ERROR 25 "Missing package/calibration/stereo_extrinsics.json"; exit 3; }
write_status RUNNING 40 "Running synced dense"
PY="$STATION_BASE/venv/bin/python"
if [[ ! -x "$PY" ]]; then PY="python3"; fi
"$PY" "$STATION_BASE/scripts/dense_depth_from_synced_capture.py" "$ROOT/calibration/stereo_extrinsics.json" "$ROOT/capture" "$OUTPUT_DIR/dense" --max-pairs "$MAX_PAIRS" --num-disparities "$NUM_DISPARITIES" --block-size "$BLOCK_SIZE" --min-depth-mm 200 --max-depth-mm 10000 --cloud-stride 2 --cloud-max-points 250000
write_status RUNNING 74 "Running metric stereo visual odometry"
"$PY" "$STATION_BASE/scripts/stereo_visual_odometry.py" "$OUTPUT_DIR/dense"   --orb-nfeatures 3500   --orb-fast-threshold 10   --match-ratio 0.75   --max-hamming-distance 64   --depth-search-radius 1   --min-correspondences 20   --min-inliers 15   --min-inlier-ratio 0.35   --ransac-reprojection-error-px 4.0   --max-median-reprojection-error-px 3.5   --max-translation-mm 1500   --max-rotation-deg 35   --min-positive-depth-ratio 0.90   --reference-window 3
write_status RUNNING 88 "Running initial global cloud fusion"
"$PY" "$STATION_BASE/scripts/stereo_global_fusion.py" "$OUTPUT_DIR/dense" --voxel-size-mm 20
write_status RUNNING 96 "Validating and packaging outputs"
read -r PAIR_CLOUD_COUNT TRAJECTORY_PAIR_COUNT TRAJECTORY_STATUS ACCEPTED_POSE_COUNT REJECTED_POSE_COUNT FUSION_STAGE INCLUDED_CLOUD_COUNT EXCLUDED_CLOUD_COUNT FUSED_POINTS_BEFORE_VOXEL FUSED_POINTS_AFTER_VOXEL VOXEL_SIZE_MM FUSED_GLOBAL_PLY_REL < <(
"$PY" - "$OUTPUT_DIR/dense" "$OUTPUT_DIR/dense/pair_cloud_manifest.json" "$OUTPUT_DIR/dense/stereo_trajectory.json" "$OUTPUT_DIR/dense/global_fusion/global_fusion_manifest.json" <<'PY'
import json,os,sys
dense_dir=os.path.realpath(sys.argv[1])
with open(sys.argv[2],encoding='utf-8') as f:
    clouds=json.load(f)
with open(sys.argv[3],encoding='utf-8') as f:
    trajectory=json.load(f)
with open(sys.argv[4],encoding='utf-8') as f:
    fusion=json.load(f)
pair_cloud_count=int(clouds.get('pair_cloud_count',0))
trajectory_pair_count=int(trajectory.get('pair_count',0))
accepted=int(trajectory.get('accepted_pose_count',0))
rejected=int(trajectory.get('rejected_pose_count',0))
status=str(trajectory.get('trajectory_status',''))
allowed={'origin_only','partial','complete_pair_sequence'}
if trajectory.get('global_fusion_complete') is not False:
    raise SystemExit('trajectory must keep global_fusion_complete=false')
if status not in allowed:
    raise SystemExit(f'invalid trajectory_status: {status}')
if pair_cloud_count != trajectory_pair_count:
    raise SystemExit(f'pair count mismatch: clouds={pair_cloud_count} trajectory={trajectory_pair_count}')
if accepted + rejected != trajectory_pair_count:
    raise SystemExit(f'pose count mismatch: accepted={accepted} rejected={rejected} pairs={trajectory_pair_count}')
fusion_stage=str(fusion.get('fusion_stage',''))
if fusion_stage != 'initial_no_icp':
    raise SystemExit(f'invalid fusion_stage: {fusion_stage}')
if fusion.get('icp_applied') is not False:
    raise SystemExit('initial fusion must keep icp_applied=false')
if fusion.get('global_fusion_complete') is not False:
    raise SystemExit('initial fusion must keep global_fusion_complete=false')
included=int(fusion.get('included_cloud_count',0))
excluded=int(fusion.get('excluded_cloud_count',0))
before=int(fusion.get('fused_points_before_voxel',0))
after=int(fusion.get('fused_points_after_voxel',0))
voxel=float(fusion.get('voxel_size_mm',0))
if included < 1:
    raise SystemExit('global fusion included no clouds')
if included > accepted:
    raise SystemExit(f'included cloud count {included} exceeds accepted poses {accepted}')
if before < 1 or after < 1 or after > before:
    raise SystemExit(f'invalid fused point counts: before={before} after={after}')
if voxel <= 0:
    raise SystemExit(f'invalid voxel_size_mm: {voxel}')
output_rel=str(fusion.get('output_ply',''))
if not output_rel or os.path.isabs(output_rel):
    raise SystemExit(f'invalid relative output_ply: {output_rel}')
output_path=os.path.realpath(os.path.join(dense_dir,output_rel))
if os.path.commonpath([dense_dir,output_path]) != dense_dir:
    raise SystemExit(f'output_ply escapes dense_dir: {output_rel}')
if not os.path.isfile(output_path) or os.path.getsize(output_path) <= 100:
    raise SystemExit(f'fused global PLY missing or too small: {output_path}')
print(pair_cloud_count,trajectory_pair_count,status,accepted,rejected,fusion_stage,included,excluded,before,after,voxel,output_rel)
PY
)
FUSED_GLOBAL_PLY="$OUTPUT_DIR/dense/$FUSED_GLOBAL_PLY_REL"
cat > "$OUTPUT_DIR/result.json" <<JSON
{"job_id":$JOB_ID,"status":"DONE","job_type":"MAKLERTOUR_SYNCED_DENSE","dense_dir":"$OUTPUT_DIR/dense","contact_dense_depth":"$OUTPUT_DIR/dense/contact_dense_depth.jpg","dense_depth_debug":"$OUTPUT_DIR/dense/dense_depth_debug.json","dense_depth_summary":"$OUTPUT_DIR/dense/dense_depth_summary.csv","pair_cloud_manifest":"$OUTPUT_DIR/dense/pair_cloud_manifest.json","contact_pair_clouds":"$OUTPUT_DIR/dense/contact_pair_clouds.jpg","pair_cloud_count":$PAIR_CLOUD_COUNT,"stereo_trajectory":"$OUTPUT_DIR/dense/stereo_trajectory.json","stereo_odometry_debug":"$OUTPUT_DIR/dense/stereo_odometry_debug.json","trajectory_pair_count":$TRAJECTORY_PAIR_COUNT,"trajectory_status":"$TRAJECTORY_STATUS","accepted_pose_count":$ACCEPTED_POSE_COUNT,"rejected_pose_count":$REJECTED_POSE_COUNT,"global_fusion_manifest":"$OUTPUT_DIR/dense/global_fusion/global_fusion_manifest.json","fused_global_no_icp":"$FUSED_GLOBAL_PLY","fusion_stage":"$FUSION_STAGE","included_cloud_count":$INCLUDED_CLOUD_COUNT,"excluded_cloud_count":$EXCLUDED_CLOUD_COUNT,"fused_points_before_voxel":$FUSED_POINTS_BEFORE_VOXEL,"fused_points_after_voxel":$FUSED_POINTS_AFTER_VOXEL,"voxel_size_mm":$VOXEL_SIZE_MM,"icp_applied":false,"global_fusion_complete":false,"finished_at":"$(date -Is)"}
JSON
write_status DONE 100 "Done: trajectory=$TRAJECTORY_STATUS fusion=$FUSION_STAGE clouds=$INCLUDED_CLOUD_COUNT points=$FUSED_POINTS_AFTER_VOXEL"
