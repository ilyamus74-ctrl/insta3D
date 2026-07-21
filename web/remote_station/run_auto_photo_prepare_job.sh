#!/usr/bin/env bash
set -euo pipefail
[[ $# -eq 4 ]] || { echo "Usage: $0 stations.conf job_id photos_dir manifest.json" >&2; exit 2; }
CONFIG=$1 JOB_ID=$2 PHOTOS=$3 MANIFEST=$4
[[ "$JOB_ID" =~ ^[1-9][0-9]*$ && -d "$PHOTOS" && -f "$MANIFEST" ]] || { echo bad_prepare_arguments >&2; exit 2; }
source "$CONFIG"; : "${STATION_HOST:?}" "${STATION_USER:?}" "${STATION_SSH_KEY:?}" "${STATION_BASE:?}"
SSH=(ssh -i "$STATION_SSH_KEY" -o BatchMode=yes -o StrictHostKeyChecking=accept-new "${STATION_USER}@${STATION_HOST}")
STAGE="$STATION_BASE/incoming/auto_photo_prepare_${JOB_ID}"; OUT="$STATION_BASE/output/job_${JOB_ID}"; LOG="$STATION_BASE/logs/job_${JOB_ID}.nohup.log"
q(){ printf %q "$1"; }
"${SSH[@]}" "rm -rf -- $(q "$STAGE"); rm -f -- $(q "$STATION_BASE/status/job_${JOB_ID}.json"); mkdir -p -- $(q "$STAGE/frames") $(q "$STAGE/sidecars") $(q "$STATION_BASE/status") $(q "$STATION_BASE/logs")"
cleanup(){ "${SSH[@]}" "rm -rf -- $(q "$STAGE")" || true; }
trap cleanup EXIT INT TERM
copy(){ rsync -a --protect-args -e "ssh -i '$STATION_SSH_KEY' -o BatchMode=yes -o StrictHostKeyChecking=accept-new" "$1" "${STATION_USER}@${STATION_HOST}:$2"; }
copy "$MANIFEST" "$STAGE/transfer_manifest.json"
copy "$(dirname "$0")/scripts/process_auto_photo_prepare.py" "$STAGE/processor.py"
while IFS=$'\t' read -r kind name size sha source; do
 [[ "$name" =~ ^(frame_[0-9]{6}\.jpe?g|camera_metadata\.json|scan_imu\.jsonl|photos_metadata\.jsonl|manifest\.json|bundle_manifest\.json)$ && "$size" =~ ^[1-9][0-9]*$ && "$sha" =~ ^[a-f0-9]{64}$ && -f "$source" && ! -L "$source" ]] || exit 2
 [[ "$kind" == frame ]] && copy "$source" "$STAGE/frames/$name" || copy "$source" "$STAGE/sidecars/$name"
done < <(php -r '$m=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); foreach($m["frames"] as $x)echo "frame\t{$x["filename"]}\t{$x["size_bytes"]}\t{$x["sha256"]}\t{$x["source"]}\n"; foreach($m["sidecars"] as $x)echo "sidecar\t{$x["filename"]}\t{$x["size_bytes"]}\t{$x["sha256"]}\t{$x["source"]}\n";' "$MANIFEST")
"${SSH[@]}" "nohup sh -c 'python3 \"\$1\" \"\$2\" \"\$3\" \"\$4\" \"\$5\"; rc=\$?; if [ \$rc -ne 0 ] && [ ! -e \"\$5\" ]; then t=\"\$5.tmp.\$$\"; printf \"{\\\"job_id\\\":%s,\\\"job_type\\\":\\\"MAKLERTOUR_AUTO_PHOTO_PREPARE\\\",\\\"status\\\":\\\"ERROR\\\",\\\"progress_percent\\\":0,\\\"message\\\":\\\"auto_photo_prepare_processor_start_failed\\\"}\\n\" \"\$2\" > \"\$t\" && mv -f \"\$t\" \"\$5\"; fi; rm -rf -- \"\$3\"; exit \$rc' sh $(q "$STAGE/processor.py") $(q "$JOB_ID") $(q "$STAGE") $(q "$OUT") $(q "$STATION_BASE/status/job_${JOB_ID}.json") > $(q "$LOG") 2>&1 &"
trap - EXIT INT TERM
