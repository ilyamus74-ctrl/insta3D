#!/usr/bin/env bash
set -Eeuo pipefail
if [[ $# -ne 7 ]]; then echo "Usage: $0 <mesh_job_id> <parent_job_id> <mode> <poisson_depth> <target_faces> <min_input_vertices> <min_output_faces>" >&2; exit 1; fi
JOB_ID="$1"; PARENT_JOB_ID="$2"; MODE="$3"; DEPTH="$4"; TARGET_FACES="$5"; MIN_INPUT="$6"; MIN_FACES="$7"
BASE="${STATION_BASE:-/home/makler_storage}"; COLMAP_MODE="${COLMAP_MODE:-podman}"; COLMAP_BIN="${COLMAP_BIN:-colmap}"; COLMAP_IMAGE="${COLMAP_IMAGE:-docker.io/colmap/colmap:latest}"
INPUT_PLY="$BASE/output/job_${PARENT_JOB_ID}/merged/merged_fused.ply"; MESH_DIR="$BASE/output/job_${JOB_ID}/mesh"; LOG_DIR="$MESH_DIR/logs"; STATUS_FILE="$BASE/status/job_${JOB_ID}.json"
POISSON_PLY="$MESH_DIR/mesh_poisson.ply"; CLEANED_PLY="$MESH_DIR/mesh_cleaned.ply"; RESULT_JSON="$MESH_DIR/mesh_result.json"; START=$(date +%s)
mkdir -p "$BASE/status" "$MESH_DIR" "$LOG_DIR"
jqstr(){ python3 -c 'import json,sys;print(json.dumps(sys.stdin.read())[1:-1])'; }
status(){ local st="$1" pr="$2" msg; msg="$(printf '%s' "$3"|jqstr)"; cat > "$STATUS_FILE" <<JSON
{"job_id":"$JOB_ID","parent_job_id":"$PARENT_JOB_ID","status":"$st","progress_percent":$pr,"message":"$msg","updated_at":"$(date -Iseconds)"}
JSON
}
ply_header(){ python3 - "$1" <<'PY'
import re,sys,json,os
p=sys.argv[1]; d={'ok':False,'vertices':0,'faces':0,'normals':False,'message':''}
try:
 if os.path.getsize(p)<=100: d['message']='PLY too small'; print(json.dumps(d)); raise SystemExit
 with open(p,'rb') as f:
  if f.read(3)!=b'ply': d['message']='Invalid PLY magic'; print(json.dumps(d)); raise SystemExit
  f.seek(0); props=[]
  for raw in f:
   line=raw.decode('utf-8','replace').strip()
   m=re.match(r'element\s+vertex\s+(\d+)$',line);
   if m: d['vertices']=int(m.group(1))
   m=re.match(r'element\s+face\s+(\d+)$',line);
   if m: d['faces']=int(m.group(1))
   m=re.match(r'property\s+\S+\s+(nx|ny|nz)$',line)
   if m: props.append(m.group(1))
   if line=='end_header': d['ok']=True; break
  d['normals']=all(x in props for x in ('nx','ny','nz'))
except Exception as e: d['message']=str(e)
print(json.dumps(d))
PY
}
run_colmap(){ case "$COLMAP_MODE" in native) "$COLMAP_BIN" "$@";; podman) podman run --rm --security-opt=label=disable -v "$BASE:$BASE" "$COLMAP_IMAGE" colmap "$@";; *) echo "bad COLMAP_MODE" >&2; return 1;; esac; }
write_result(){ local st="$1" msg="$2" warn="$3"; local in_v mesh_v mesh_f dur; in_v=$(python3 -c "import json;print(json.load(open('$MESH_DIR/input_header.json')).get('vertices',0))" 2>/dev/null||echo 0); mesh_v=$(python3 -c "import json;print(json.load(open('$MESH_DIR/mesh_header.json')).get('vertices',0))" 2>/dev/null||echo 0); mesh_f=$(python3 -c "import json;print(json.load(open('$MESH_DIR/mesh_header.json')).get('faces',0))" 2>/dev/null||echo 0); dur=$(( $(date +%s)-START )); python3 - "$RESULT_JSON" <<PY
import json,datetime
warn = [] if '''$warn''' == '' else ['''$warn''']
json.dump({'status':'$st','job_id':'$JOB_ID','parent_reconstruction_job_id':'$PARENT_JOB_ID','mode':'$MODE','input_ply':'$INPUT_PLY','input_vertices':int('$in_v'),'poisson_depth':int('$DEPTH'),'target_faces':int('$TARGET_FACES'),'mesh_poisson_ply':'$POISSON_PLY','mesh_cleaned_ply':'$CLEANED_PLY','mesh_vertices':int('$mesh_v'),'mesh_faces':int('$mesh_f'),'simplification_applied':False,'warnings':warn,'message':'$msg','duration_sec':float('$dur'),'finished_at':datetime.datetime.now(datetime.timezone.utc).isoformat()}, open('$RESULT_JSON','w'), ensure_ascii=False, indent=2)
PY
}
status RUNNING 5 "Validating input PLY"
[[ -f "$INPUT_PLY" ]] || { status ERROR 5 "Input PLY not found"; write_result ERROR "Input PLY not found" ""; exit 0; }
ply_header "$INPUT_PLY" > "$MESH_DIR/input_header.json"; IN_V=$(python3 -c "import json;d=json.load(open('$MESH_DIR/input_header.json'));print(d.get('vertices',0))"); HAS_N=$(python3 -c "import json;print(1 if json.load(open('$MESH_DIR/input_header.json')).get('normals') else 0)")
if (( IN_V <= 0 || IN_V < MIN_INPUT )); then status ERROR 5 "Input point cloud has too few vertices: $IN_V"; write_result ERROR "too few vertices" ""; exit 0; fi
if (( HAS_N != 1 )); then status ERROR 5 "Input point cloud has no normals"; write_result ERROR "Input point cloud has no normals" ""; exit 0; fi
status RUNNING 20 "Poisson meshing"
HELP="$LOG_DIR/poisson_mesher_help.log"
set +e; run_colmap poisson_mesher -h > "$HELP" 2>&1; HELP_EC=$?; set -e
DEPTH_OPT="--PoissonMeshing.depth"
if [[ $HELP_EC -eq 0 ]]; then
  if grep -q -- "--PoissonMeshing.depth" "$HELP"; then DEPTH_OPT="--PoissonMeshing.depth";
  elif grep -q -- "--depth" "$HELP"; then DEPTH_OPT="--depth";
  else status ERROR 20 "COLMAP poisson_mesher depth option not found in runtime help"; write_result ERROR "depth option not found" ""; exit 0; fi
fi
set +e; run_colmap poisson_mesher --input_path "$INPUT_PLY" --output_path "$POISSON_PLY" "$DEPTH_OPT" "$DEPTH" > "$LOG_DIR/poisson_mesher.log" 2>&1; EC=$?; set -e
if [[ $EC -eq 137 ]]; then status ERROR 20 "Poisson meshing was killed by OOM. Reduce Poisson depth or input density."; write_result ERROR "Poisson meshing was killed by OOM. Reduce Poisson depth or input density." ""; exit 0; fi
if [[ $EC -ne 0 ]]; then status ERROR 20 "Poisson meshing failed with exit code $EC"; write_result ERROR "Poisson failed" ""; exit 0; fi
status RUNNING 65 "Validating mesh"
ply_header "$POISSON_PLY" > "$MESH_DIR/mesh_header.json"; MESH_V=$(python3 -c "import json;print(json.load(open('$MESH_DIR/mesh_header.json')).get('vertices',0))"); MESH_F=$(python3 -c "import json;print(json.load(open('$MESH_DIR/mesh_header.json')).get('faces',0))")
if (( MESH_V <= 0 || MESH_F < MIN_FACES )); then status ERROR_EMPTY_MESH 65 "Poisson produced empty mesh: vertices=$MESH_V faces=$MESH_F"; write_result ERROR_EMPTY_MESH "empty mesh" ""; exit 0; fi
status RUNNING 75 "Mesh cleanup"; cp -f "$POISSON_PLY" "$CLEANED_PLY"; echo "MVP cleanup: copied poisson mesh" > "$LOG_DIR/mesh_cleanup.log"
status RUNNING 90 "Simplification/export"; WARN=""; command -v meshlabserver >/dev/null 2>&1 || WARN="Mesh simplification skipped: no supported simplifier installed"
write_result DONE done "$WARN"; status DONE 100 "Mesh done: $MESH_F faces"