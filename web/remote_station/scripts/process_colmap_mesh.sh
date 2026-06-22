#!/usr/bin/env bash
set -Eeuo pipefail
if [[ $# -ne 7 ]]; then echo "Usage: $0 <mesh_job_id> <parent_job_id> <mode> <poisson_depth> <target_faces> <min_input_vertices> <min_output_faces>" >&2; exit 1; fi
JOB_ID="$1"; PARENT_JOB_ID="$2"; MODE="$3"; DEPTH="$4"; TARGET_FACES="$5"; MIN_INPUT="$6"; MIN_FACES="$7"
BASE="${STATION_BASE:-/home/makler_storage}"; COLMAP_MODE="${COLMAP_MODE:-podman}"; COLMAP_BIN="${COLMAP_BIN:-colmap}"; COLMAP_IMAGE="${COLMAP_IMAGE:-docker.io/colmap/colmap:latest}"
OPEN3D_PYTHON="${OPEN3D_PYTHON:-$BASE/open3d-venv/bin/python}"; OPEN3D_MESH_SCRIPT="${OPEN3D_MESH_SCRIPT:-$BASE/scripts/process_open3d_mesh.py}"; MESH_ENGINE="${MESH_ENGINE:-auto}"
if [[ "$MODE" == "hq" ]]; then O3D_DEPTH="${OPEN3D_HQ_DEPTH:-9}"; O3D_TARGET="${OPEN3D_HQ_TARGET_FACES:-500000}"; O3D_DQ="${OPEN3D_HQ_DENSITY_QUANTILE:-0.01}"; else O3D_DEPTH="${OPEN3D_PREVIEW_DEPTH:-7}"; O3D_TARGET="${OPEN3D_PREVIEW_TARGET_FACES:-100000}"; O3D_DQ="${OPEN3D_PREVIEW_DENSITY_QUANTILE:-0.03}"; fi
INPUT_PLY="$BASE/output/job_${PARENT_JOB_ID}/merged/merged_fused.ply"; MESH_DIR="$BASE/output/job_${JOB_ID}/mesh"; LOG_DIR="$MESH_DIR/logs"; STATUS_FILE="$BASE/status/job_${JOB_ID}.json"
COLMAP_PLY="$MESH_DIR/mesh_colmap.ply"; OPEN3D_PLY="$MESH_DIR/mesh_open3d.ply"; FINAL_PLY="$MESH_DIR/mesh_final.ply"; RESULT_JSON="$MESH_DIR/mesh_result.json"; START=$(date +%s)
mkdir -p "$BASE/status" "$MESH_DIR" "$LOG_DIR"
jqstr(){ python3 -c 'import json,sys;print(json.dumps(sys.stdin.read())[1:-1])'; }
status(){ local st="$1" pr="$2" msg; msg="$(printf '%s' "$3"|jqstr)"; cat > "$STATUS_FILE" <<JSON
{"job_id":"$JOB_ID","parent_job_id":"$PARENT_JOB_ID","status":"$st","progress_percent":$pr,"message":"$msg","updated_at":"$(date -Iseconds)"}
JSON
}
ply_header(){ python3 - "$1" <<'PY'
import re,sys,json,os
p=sys.argv[1]; d={'ok':False,'vertices':0,'faces':0,'message':''}
try:
 if os.path.getsize(p)<=100: d['message']='PLY too small'; print(json.dumps(d)); raise SystemExit
 with open(p,'rb') as f:
  if f.read(3)!=b'ply': d['message']='Invalid PLY magic'; print(json.dumps(d)); raise SystemExit
  f.seek(0)
  for raw in f:
   line=raw.decode('utf-8','replace').strip()
   m=re.match(r'element\s+vertex\s+(\d+)$',line); d['vertices']=int(m.group(1)) if m else d['vertices']
   m=re.match(r'element\s+face\s+(\d+)$',line); d['faces']=int(m.group(1)) if m else d['faces']
   if line=='end_header': d['ok']=True; break
except Exception as e: d['message']=str(e)
print(json.dumps(d))
PY
}
run_colmap(){ case "$COLMAP_MODE" in native) "$COLMAP_BIN" "$@";; podman) podman run --rm --security-opt=label=disable -v "$BASE:$BASE" "$COLMAP_IMAGE" colmap "$@";; *) echo "bad COLMAP_MODE" >&2; return 1;; esac; }
finish_result(){ local engine="$1" src="$2" msg="$3"; cp -f "$src" "$FINAL_PLY"; ply_header "$FINAL_PLY" > "$MESH_DIR/mesh_header.json"; local v f dur; v=$(python3 -c "import json;print(json.load(open('$MESH_DIR/mesh_header.json')).get('vertices',0))"); f=$(python3 -c "import json;print(json.load(open('$MESH_DIR/mesh_header.json')).get('faces',0))"); dur=$(( $(date +%s)-START )); python3 - "$RESULT_JSON" <<PY
import json,datetime,os
extra={}
p='$RESULT_JSON.open3d'
if os.path.isfile(p):
 data=json.load(open(p)); extra=data if data.get('status')=='DONE' else {}
result={'status':'DONE','engine':'$engine','mode':'$MODE','job_id':'$JOB_ID','parent_reconstruction_job_id':'$PARENT_JOB_ID','input_ply':'$INPUT_PLY','output_ply':'$FINAL_PLY','mesh_final_ply':'$FINAL_PLY','mesh_colmap_ply':'$COLMAP_PLY','mesh_open3d_ply':'$OPEN3D_PLY','vertices':int('$v'),'faces':int('$f'),'fallback_used':('$engine'=='open3d' and '$MESH_ENGINE'=='auto'),'message':'$msg','duration_sec':float('$dur'),'finished_at':datetime.datetime.now(datetime.timezone.utc).isoformat()}
result.update({k:v for k,v in extra.items() if k not in ('status','output_ply')}); result['status']='DONE'; result['engine']='$engine'; result['output_ply']='$FINAL_PLY'; result['vertices']=int('$v'); result['faces']=int('$f')
json.dump(result, open('$RESULT_JSON','w'), ensure_ascii=False, indent=2)
PY
status DONE 100 "$msg: $v vertices, $f faces"; }
run_open3d(){ status RUNNING 10 "Validating input point cloud"; set +e; "$OPEN3D_PYTHON" "$OPEN3D_MESH_SCRIPT" --input-ply "$INPUT_PLY" --output-ply "$OPEN3D_PLY" --result-json "$RESULT_JSON.open3d" --mode "$MODE" --depth "$O3D_DEPTH" --target-faces "$O3D_TARGET" --density-quantile "$O3D_DQ" > "$LOG_DIR/open3d_mesh.log" 2>&1; local ec=$?; set -e; if [[ $ec -eq 0 ]]; then finish_result open3d "$OPEN3D_PLY" "Mesh generated with Open3D"; return 0; fi; local m; m=$(python3 -c "import json;print(json.load(open('$RESULT_JSON.open3d')).get('message','Open3D failed'))" 2>/dev/null || echo Open3D failed); status ERROR 65 "Open3D mesh generation failed: $m"; cp -f "$RESULT_JSON.open3d" "$RESULT_JSON" 2>/dev/null || true; return 1; }
status RUNNING 5 "Validating input PLY"; [[ -f "$INPUT_PLY" ]] || { status ERROR 5 "Input PLY not found"; exit 0; }
ply_header "$INPUT_PLY" > "$MESH_DIR/input_header.json"; IN_V=$(python3 -c "import json;print(json.load(open('$MESH_DIR/input_header.json')).get('vertices',0))")
if (( IN_V <= 0 || IN_V < MIN_INPUT )); then status ERROR 5 "Input point cloud has too few vertices: $IN_V"; exit 0; fi
case "$MESH_ENGINE" in open3d) run_open3d || exit 0; exit 0;; colmap|auto) ;; *) status ERROR 5 "Unsupported MESH_ENGINE: $MESH_ENGINE"; exit 0;; esac
status RUNNING 20 "Poisson meshing"
HELP="$LOG_DIR/poisson_mesher_help.log"; set +e; run_colmap poisson_mesher -h > "$HELP" 2>&1; HELP_EC=$?; set -e; DEPTH_OPT="--PoissonMeshing.depth"; if [[ $HELP_EC -eq 0 ]]; then if grep -q -- "--PoissonMeshing.depth" "$HELP"; then DEPTH_OPT="--PoissonMeshing.depth"; elif grep -q -- "--depth" "$HELP"; then DEPTH_OPT="--depth"; fi; fi
set +e; run_colmap poisson_mesher --input_path "$INPUT_PLY" --output_path "$COLMAP_PLY" "$DEPTH_OPT" "$DEPTH" > "$LOG_DIR/colmap_poisson.log" 2>&1; EC=$?; set -e
status RUNNING 65 "Validating mesh"; MESH_V=0; MESH_F=0; if [[ -f "$COLMAP_PLY" ]]; then ply_header "$COLMAP_PLY" > "$MESH_DIR/mesh_colmap_header.json"; MESH_V=$(python3 -c "import json;print(json.load(open('$MESH_DIR/mesh_colmap_header.json')).get('vertices',0))"); MESH_F=$(python3 -c "import json;print(json.load(open('$MESH_DIR/mesh_colmap_header.json')).get('faces',0))"); fi
if [[ $EC -eq 0 && -f "$COLMAP_PLY" && $MESH_V -gt 0 && $MESH_F -ge $MIN_FACES ]]; then finish_result colmap "$COLMAP_PLY" "Mesh generated with COLMAP"; exit 0; fi
if [[ "$MESH_ENGINE" == "auto" ]]; then echo "COLMAP mesh failed/empty (exit=$EC vertices=$MESH_V faces=$MESH_F), trying Open3D" >> "$LOG_DIR/colmap_poisson.log"; run_open3d || true; exit 0; fi
status ERROR_EMPTY_MESH 65 "Poisson produced empty mesh: vertices=$MESH_V faces=$MESH_F exit=$EC"
