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
write_status RUNNING 78 "Running metric stereo visual odometry"
"$PY" "$STATION_BASE/scripts/stereo_visual_odometry.py" "$OUTPUT_DIR/dense"   --orb-nfeatures 3500   --orb-fast-threshold 10   --match-ratio 0.75   --max-hamming-distance 64   --depth-search-radius 1   --min-correspondences 20   --min-inliers 15   --min-inlier-ratio 0.35   --ransac-reprojection-error-px 4.0   --max-median-reprojection-error-px 3.5   --max-translation-mm 1500   --max-rotation-deg 35   --min-positive-depth-ratio 0.90   --reference-window 3
write_status RUNNING 94 "Validating and packaging outputs"
read -r PAIR_CLOUD_COUNT TRAJECTORY_PAIR_COUNT TRAJECTORY_STATUS ACCEPTED_POSE_COUNT REJECTED_POSE_COUNT < <(
"$PY" - "$OUTPUT_DIR/dense/pair_cloud_manifest.json" "$OUTPUT_DIR/dense/stereo_trajectory.json" <<'PY'
import json,sys
with open(sys.argv[1],encoding='utf-8') as f:
    clouds=json.load(f)
with open(sys.argv[2],encoding='utf-8') as f:
    trajectory=json.load(f)
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
print(pair_cloud_count,trajectory_pair_count,status,accepted,rejected)
PY
)
cat > "$OUTPUT_DIR/result.json" <<JSON
{"job_id":$JOB_ID,"status":"DONE","job_type":"MAKLERTOUR_SYNCED_DENSE","dense_dir":"$OUTPUT_DIR/dense","contact_dense_depth":"$OUTPUT_DIR/dense/contact_dense_depth.jpg","dense_depth_debug":"$OUTPUT_DIR/dense/dense_depth_debug.json","dense_depth_summary":"$OUTPUT_DIR/dense/dense_depth_summary.csv","pair_cloud_manifest":"$OUTPUT_DIR/dense/pair_cloud_manifest.json","contact_pair_clouds":"$OUTPUT_DIR/dense/contact_pair_clouds.jpg","pair_cloud_count":$PAIR_CLOUD_COUNT,"stereo_trajectory":"$OUTPUT_DIR/dense/stereo_trajectory.json","stereo_odometry_debug":"$OUTPUT_DIR/dense/stereo_odometry_debug.json","trajectory_pair_count":$TRAJECTORY_PAIR_COUNT,"trajectory_status":"$TRAJECTORY_STATUS","accepted_pose_count":$ACCEPTED_POSE_COUNT,"rejected_pose_count":$REJECTED_POSE_COUNT,"global_fusion_complete":false,"finished_at":"$(date -Is)"}
JSON
write_status DONE 100 "Done: trajectory=$TRAJECTORY_STATUS accepted=$ACCEPTED_POSE_COUNT rejected=$REJECTED_POSE_COUNT"
