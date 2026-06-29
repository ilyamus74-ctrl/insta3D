#!/usr/bin/env bash
set -Eeuo pipefail

if [[ $# -ne 3 ]]; then echo "Usage: $0 <job_id> <input_video> <output_dir>" >&2; exit 1; fi
JOB_ID="$1"; INPUT_VIDEO="$2"; OUTPUT_DIR="$3"
STATION_BASE="${STATION_BASE:-/home/makler_storage}"; BASE="$STATION_BASE"; STATUS_FILE="$BASE/status/job_${JOB_ID}.json"; LOG_FILE="$BASE/logs/job_${JOB_ID}.log"; PARAM_FILE="$BASE/input/job_${JOB_ID}/parameters.json"
JOB_ROOT="$(dirname "$OUTPUT_DIR")"; [[ "$(basename "$OUTPUT_DIR")" == "frames" ]] || JOB_ROOT="$OUTPUT_DIR"
mkdir -p "$JOB_ROOT" "$JOB_ROOT/frames" "$JOB_ROOT/quality" "$BASE/status" "$BASE/logs"
json_escape(){ python3 -c 'import json,sys; print(json.dumps(sys.stdin.read())[1:-1])'; }
write_status(){ local s="$1" p="$2" eta="$3" m="$4"; local e; e="$(printf '%s' "$m"|json_escape)"; cat > "$STATUS_FILE" <<JSON
{"job_id":"$JOB_ID","status":"$s","progress_percent":$p,"eta_sec":$eta,"message":"$e","updated_at":"$(date -Iseconds)"}
JSON
}
on_error(){ local c=$? l=${BASH_LINENO[0]:-unknown}; write_status ERROR 0 -1 "Job failed at line $l with exit code $c. See $LOG_FILE"; exit "$c"; }
trap on_error ERR
exec 2>>"$LOG_FILE"
write_status RUNNING 0 -1 "Starting"
[[ -f "$INPUT_VIDEO" ]] || { write_status ERROR 0 -1 "Input video not found: $INPUT_VIDEO"; exit 1; }
[[ -f "$PARAM_FILE" ]] || mkdir -p "$(dirname "$PARAM_FILE")" && [[ -f "$PARAM_FILE" ]] || printf '{"extract":{}}\n' > "$PARAM_FILE"
read_param(){ python3 - "$PARAM_FILE" "$1" "$2" <<'PY'
import json,sys
p=sys.argv[2].split('.'); d=json.load(open(sys.argv[1])); v=d
for k in p: v=v.get(k,{}) if isinstance(v,dict) else {}
print(sys.argv[3] if v=={} else str(v).lower() if isinstance(v,bool) else v)
PY
}
DURATION_RAW=$(ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 "$INPUT_VIDEO" || echo 0)
DURATION_SEC=$(awk -v d="$DURATION_RAW" 'BEGIN{if(d<=0)d=1; printf "%.4f", d}')
SOURCE_FPS=$(ffprobe -v error -select_streams v:0 -show_entries stream=avg_frame_rate -of default=noprint_wrappers=1:nokey=1 "$INPUT_VIDEO" | awk -F/ 'NF==2&&$2>0{printf "%.4f",$1/$2; exit} {print 0}')
IMU_JSONL="$(read_param imu_jsonl_path "")"; IMU_SETTINGS="$(read_param imu_frame_selection "{}")"
if [[ -n "$IMU_JSONL" && -f "$IMU_JSONL" ]]; then
  cp "$IMU_JSONL" "$JOB_ROOT/scan_imu.jsonl"
  echo "INFO | IMU | Copied sidecar to: $JOB_ROOT/scan_imu.jsonl"
else
  echo "INFO | IMU | No source IMU sidecar found for video $(basename "$INPUT_VIDEO")"
fi
for sidecar_pair in "source_video.camera_info_path:$JOB_ROOT/camera_info.json:camera_info" "source_video.manifest_path:$JOB_ROOT/manifest.json:manifest"; do
  key="${sidecar_pair%%:*}"; rest="${sidecar_pair#*:}"; dest="${rest%%:*}"; label="${rest##*:}"
  src="$(read_param "$key" "")"
  if [[ -n "$src" && -f "$src" ]]; then
    cp "$src" "$dest"
    echo "INFO | CAMERA_METADATA | $label copied"
  fi
done

CAMERA_METADATA_JSON="$JOB_ROOT/camera_metadata.json"
python3 "$STATION_BASE/scripts/camera_metadata.py" --camera-info "$JOB_ROOT/camera_info.json" --manifest "$JOB_ROOT/manifest.json" --output-json "$CAMERA_METADATA_JSON" --print-log >> "$LOG_FILE" 2>&1 || echo "WARNING | CAMERA_METADATA | Failed to parse camera metadata" >> "$LOG_FILE"
SAMPLING_MODE="$(read_param extract.sampling_mode auto_quality)"; TARGET="$(read_param extract.target_frames 400)"; CAND_MULT="$(read_param extract.candidate_multiplier 1.5)"; MIN_FPS="$(read_param extract.minimum_sampling_fps 0.25)"; MAX_FPS="$(read_param extract.maximum_sampling_fps 10)"; SCALE="$(read_param extract.scale_width 1920)"; JPEG="$(read_param extract.jpeg_quality 2)"; KEEP="$(read_param extract.keep_candidate_frames false)"; ALLOW="$(read_param extract.allow_upscale false)"
if [[ "$SAMPLING_MODE" == "manual" || ( "$SAMPLING_MODE" == "{}" && -n "$(read_param extract.fps '')" ) ]]; then
  FPS="$(read_param extract.fps ${EXTRACT_FPS:-2})"; MAX_FRAMES="$(read_param extract.max_frames ${EXTRACT_MAX_FRAMES:-360})"
  rm -f "$JOB_ROOT/frames"/frame_*.jpg; write_status RUNNING 1 -1 "Extracting frames (manual)"
  ffmpeg -y -i "$INPUT_VIDEO" -vf "fps=${FPS},scale='if(gte(iw,ih),min(iw,${SCALE}),-2)':'if(gte(iw,ih),-2,min(ih,${SCALE}))'" -frames:v "$MAX_FRAMES" -q:v "$JPEG" -progress pipe:1 "$JOB_ROOT/frames/frame_%06d.jpg" | while IFS= read -r line; do
    [[ "$line" == out_time_ms=* ]] || continue; OUT_MS="${line#out_time_ms=}"; [[ "$OUT_MS" =~ ^[0-9]+$ ]] || continue; OUT_SEC=$((OUT_MS/1000000)); PROGRESS=$((OUT_SEC*100/${DURATION_SEC%.*})); ((PROGRESS>99))&&PROGRESS=99; write_status RUNNING "$PROGRESS" -1 "Extracting frames (manual)"; done
  FRAME_COUNT=$(find "$JOB_ROOT/frames" -type f -name 'frame_*.jpg' | wc -l | tr -d ' ')
  [[ "$FRAME_COUNT" -gt 0 ]] || { write_status ERROR 0 -1 "No frames extracted"; exit 2; }
  cat > "$JOB_ROOT/result.json" <<JSON
{"job_id":"$JOB_ID","status":"DONE","frames":$FRAME_COUNT,"sampling_mode":"manual","fps":$FPS,"max_frames":$MAX_FRAMES,"scale_width":$SCALE,"output_dir":"$JOB_ROOT/frames","finished_at":"$(date -Iseconds)"}
JSON
python3 "$STATION_BASE/scripts/camera_metadata.py" --camera-info "$JOB_ROOT/camera_info.json" --manifest "$JOB_ROOT/manifest.json" --merge-json "$JOB_ROOT/result.json" >> "$LOG_FILE" 2>&1 || true
else
  CANDIDATES=$(python3 - <<PY
print(max(1, round(float('$TARGET')*float('$CAND_MULT'))))
PY
)
  echo "INFO | EXTRACT_FRAMES | Video duration=$DURATION_SEC source_fps=$SOURCE_FPS"
  echo "INFO | EXTRACT_FRAMES | Sampling mode=$SAMPLING_MODE Target frames=$TARGET Candidate frames=$CANDIDATES"
  write_status RUNNING 5 -1 "Selecting quality frames"
  KEEP_ARG=(); [[ "$KEEP" == "true" || "$KEEP" == "1" ]] && KEEP_ARG=(--keep-candidates)
  UPSCALE_ARG=(); [[ "$ALLOW" == "true" || "$ALLOW" == "1" ]] && UPSCALE_ARG=(--allow-upscale)
  IMU_ARG=(); [[ -n "$IMU_JSONL" && -f "$IMU_JSONL" ]] && IMU_ARG=(--imu-jsonl "$IMU_JSONL" --imu-settings "$IMU_SETTINGS")
  SUMMARY=$(python3 "$STATION_BASE/scripts/select_quality_frames.py" --video "$INPUT_VIDEO" --output-dir "$JOB_ROOT" --sampling-mode "$SAMPLING_MODE" --target-frames "$TARGET" --candidate-multiplier "$CAND_MULT" --min-fps "$MIN_FPS" --max-fps "$MAX_FPS" --scale-width "$SCALE" --jpeg-quality "$JPEG" "${KEEP_ARG[@]}" "${UPSCALE_ARG[@]}" "${IMU_ARG[@]}")
  FRAME_COUNT=$(find "$JOB_ROOT/frames" -type f -name 'frame_*.jpg' | wc -l | tr -d ' ')
  [[ "$FRAME_COUNT" -gt 0 ]] || { write_status ERROR 0 -1 "No frames selected"; exit 2; }
  python3 - "$JOB_ROOT" "$JOB_ID" "$SUMMARY" <<'PY'
import json,sys,datetime,os
root,job,summary=sys.argv[1:4]; s=json.loads(summary)
s.update({'job_id':job,'frames':s.get('selected_frames',0),'output_dir':os.path.join(root,'frames'),'quality_dir':os.path.join(root,'quality'),'finished_at':datetime.datetime.now(datetime.timezone.utc).isoformat()})
s['camera_metadata']=json.load(open(os.path.join(root,'camera_metadata.json'))) if os.path.isfile(os.path.join(root,'camera_metadata.json')) else {}
open(os.path.join(root,'result.json'),'w').write(json.dumps(s,indent=2))
PY
  echo "INFO | EXTRACT_FRAMES | Candidate extraction completed frames=$CANDIDATES"
  python3 - "$JOB_ROOT/quality/quality_summary.json" <<'PY'
import json,sys
s=json.load(open(sys.argv[1])); c=s.get('coverage',{})
print(f"INFO | FRAME_QUALITY | Blur rejected={s.get('rejected_blur',0)} dark={s.get('rejected_dark',0)} overexposed={s.get('rejected_overexposed',0)} duplicates={s.get('rejected_duplicate',0)}")
print(f"INFO | FRAME_SELECTION | Selected={s.get('selected_frames',0)} coverage={c.get('coverage_percent',0)}% max_gap={c.get('maximum_gap_sec',0)} sec")
imu=s.get("imu",{})
print(f"INFO | IMU | Parsed gyro={imu.get('counts',{}).get('gyro',0)} accel={imu.get('counts',{}).get('accel',0)} gravity={imu.get('counts',{}).get('gravity',0)} rotation_vector={imu.get('counts',{}).get('rotation_vector',0)}")
print(f"INFO | IMU | Sync method={imu.get('sync_method','unavailable')} quality={imu.get('sync_quality','unavailable')} offset={imu.get('offset_sec','')}")
print(f"INFO | IMU | Coverage={s.get('coverage',{}).get('coverage_percent',0)}%")
print(f"INFO | FRAME_QUALITY | IMU soft penalized={s.get('imu_soft_penalized',0)}")
print(f"INFO | FRAME_QUALITY | IMU hard rejected={s.get('imu_hard_rejected',0)}")
print(f"INFO | FRAME_QUALITY | IMU coverage fallback={s.get('fallback_frames',0)}")
print(f"INFO | FRAME_SELECTION | First timestamp={c.get('first_timestamp_sec',0)} last timestamp={c.get('last_timestamp_sec',0)}")
PY
fi
write_status DONE 100 0 "Done. Frames: $FRAME_COUNT"
