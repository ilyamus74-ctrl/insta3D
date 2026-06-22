#!/usr/bin/env bash
set -Eeuo pipefail
if [[ $# -ne 7 ]]; then echo "Usage: $0 <job_id> <parent_job_id> <sparse_job_id> <model_id> <chunk_id> <image_list_path> <mode>" >&2; exit 1; fi
JOB_ID="$1"; PARENT_JOB_ID="$2"; SPARSE_JOB_ID="$3"; MODEL_ID="$4"; CHUNK_ID="$5"; IMAGE_LIST_PATH="$6"; MODE="$7"
BASE="${STATION_BASE:-/home/makler_storage}"; COLMAP_MODE="${COLMAP_MODE:-native}"; COLMAP_BIN="${COLMAP_BIN:-colmap}"; COLMAP_IMAGE="${COLMAP_IMAGE:-}"
PARENT_DIR="$BASE/output/job_${PARENT_JOB_ID}"; SPARSE_JOB_DIR="$BASE/output/job_${SPARSE_JOB_ID}/colmap"; SPARSE_MODEL_DIR="$SPARSE_JOB_DIR/sparse/${MODEL_ID}"
CHUNK_DIR="$PARENT_DIR/chunks/chunk_${CHUNK_ID}"; UNDISTORTED_DIR="$CHUNK_DIR/undistorted"; LOG_DIR="$CHUNK_DIR/logs"; FUSED_PLY="$CHUNK_DIR/fused.ply"; STATUS_FILE="$BASE/status/job_${JOB_ID}.json"
mkdir -p "$BASE/status" "$BASE/logs" "$CHUNK_DIR" "$LOG_DIR"

TARGET_IMAGE_LIST="$CHUNK_DIR/image_list.txt"

if [[ ! -f "$IMAGE_LIST_PATH" ]]; then
    echo "image list not found: $IMAGE_LIST_PATH" >&2
    exit 1
fi

if [[ "$(realpath "$IMAGE_LIST_PATH")" != "$(realpath -m "$TARGET_IMAGE_LIST")" ]]; then
    cp "$IMAGE_LIST_PATH" "$TARGET_IMAGE_LIST"
fi
if [[ "$MODE" == "preview" ]]; then MAX_IMAGE_SIZE="${COLMAP_PREVIEW_MAX_IMAGE_SIZE:-640}"; SRC="${COLMAP_PREVIEW_NUM_SRC_IMAGES:-6}"; PMC="${COLMAP_PREVIEW_PATCHMATCH_CACHE_SIZE:-2}"; FC="${COLMAP_PREVIEW_FUSION_CACHE_SIZE:-2}"; else MAX_IMAGE_SIZE="${COLMAP_HQ_MAX_IMAGE_SIZE:-1600}"; SRC="${COLMAP_HQ_NUM_SRC_IMAGES:-8}"; PMC="${COLMAP_HQ_PATCHMATCH_CACHE_SIZE:-4}"; FC="${COLMAP_HQ_FUSION_CACHE_SIZE:-4}"; fi
avail_mb(){ awk '/MemAvailable:/ {print int($2/1024)}' /proc/meminfo; }
jqstr(){ python3 -c 'import json,sys;print(json.dumps(sys.stdin.read())[1:-1])'; }
status(){ local st="$1" pr="$2" msg; msg="$(printf '%s' "$3"|jqstr)"; cat > "$STATUS_FILE" <<JSON
{"job_id":"$JOB_ID","parent_job_id":"$PARENT_JOB_ID","status":"$st","progress_percent":$pr,"message":"$msg","updated_at":"$(date -Iseconds)"}
JSON
}
ply_vertices(){ python3 - "$FUSED_PLY" <<'PYV'
import re,sys
p=sys.argv[1]
n=0
try:
    with open(p,'rb') as f:
        for raw in f:
            line=raw.decode('utf-8','replace').strip()
            m=re.match(r'element\s+vertex\s+(\d+)$', line)
            if m: n=int(m.group(1))
            if line=='end_header': break
except FileNotFoundError:
    pass
print(n)
PYV
}
result(){ local st="$1" code="$2" msg="$3" count size vertices; count=$(wc -l < "$CHUNK_DIR/image_list.txt"|tr -d ' '); size=0; [[ -f "$FUSED_PLY" ]] && size=$(stat -c '%s' "$FUSED_PLY"); vertices=$(ply_vertices); cat > "$CHUNK_DIR/result.json" <<JSON
{"job_id":"$JOB_ID","parent_job_id":"$PARENT_JOB_ID","sparse_job_id":"$SPARSE_JOB_ID","model_id":$MODEL_ID,"chunk_id":$CHUNK_ID,"status":"$st","exit_code":$code,"message":"$msg","images_count":$count,"max_image_size":$MAX_IMAGE_SIZE,"patchmatch_cache_size":$PMC,"fusion_cache_size":$FC,"available_ram_before_start_mb":$AVAIL_BEFORE,"fused_ply":"$FUSED_PLY","fused_ply_size_bytes":$size,"fused_vertices":$vertices,"finished_at":"$(date -Iseconds)"}
JSON
}
run_colmap(){ case "$COLMAP_MODE" in native) "$COLMAP_BIN" "$@";; podman) podman run --rm --device nvidia.com/gpu=all --security-opt=label=disable -v "$BASE:$BASE" "$COLMAP_IMAGE" colmap "$@";; *) echo "bad COLMAP_MODE" >&2; return 1;; esac; }
trap 'ec=$?; st=ERROR; [[ $ec -eq 137 ]] && st=ERROR_OOM; status "$st" 0 "Dense chunk $CHUNK_ID failed exit $ec"; result "$st" "$ec" "failed"; exit $ec' ERR
AVAIL_BEFORE=$(avail_mb); status RUNNING 5 "Preparing dense chunk $CHUNK_ID"
FRAMES_DIR=$(python3 - "$SPARSE_JOB_DIR/result.json" <<'PY'
import json,sys; print(json.load(open(sys.argv[1])).get('frames_dir',''))
PY
)
[[ -d "$FRAMES_DIR" ]] || { status ERROR 0 "frames_dir missing"; exit 1; }
run_colmap image_undistorter --image_path "$FRAMES_DIR" --input_path "$SPARSE_MODEL_DIR" --output_path "$UNDISTORTED_DIR" --output_type COLMAP --image_list_path "$CHUNK_DIR/image_list.txt" --max_image_size "$MAX_IMAGE_SIZE" --num_patch_match_src_images "$SRC" > "$LOG_DIR/image_undistorter.log" 2>&1
status RUNNING 45 "PatchMatch chunk $CHUNK_ID"
run_colmap patch_match_stereo \
  --workspace_path "$UNDISTORTED_DIR" \
  --workspace_format COLMAP \
  --PatchMatchStereo.geom_consistency true \
  --PatchMatchStereo.allow_missing_files true \
  --PatchMatchStereo.cache_size "$PMC" \
  > "$LOG_DIR/patch_match_stereo.log" 2>&1
status RUNNING 85 "Fusion chunk $CHUNK_ID"
run_colmap stereo_fusion --workspace_path "$UNDISTORTED_DIR" --workspace_format COLMAP --input_type geometric --output_path "$FUSED_PLY" --StereoFusion.cache_size "$FC" > "$LOG_DIR/stereo_fusion.log" 2>&1
[[ -s "$FUSED_PLY" ]] || { status ERROR 0 "fused.ply missing"; exit 1; }
FUSED_VERTICES=$(ply_vertices)
if [[ "$FUSED_VERTICES" -eq 0 ]]; then
  result ERROR_EMPTY 0 "Dense fusion produced zero vertices"
  status ERROR_EMPTY 100 "Dense fusion produced zero vertices"
  exit 0
fi
result DONE 0 done; status DONE 100 "Dense chunk $CHUNK_ID done"