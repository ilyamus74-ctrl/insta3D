#!/usr/bin/env bash
set -euo pipefail
if [[ $# -ne 3 ]]; then echo "Usage: $0 stations.conf remote_job_id parent_remote_job_id" >&2; exit 2; fi
CONFIG="$1"; RID="$2"; PARENT="$3"; source "$CONFIG"
: "${STATION_HOST:?}"; : "${STATION_USER:?}"; : "${STATION_SSH_KEY:?}"; : "${STATION_BASE:?}"
SSH=(ssh -i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new "${STATION_USER}@${STATION_HOST}")
"${SSH[@]}" bash -s -- "$RID" "$PARENT" "$STATION_BASE" <<'REMOTE'
set -euo pipefail
rid="$1"; parent="$2"; base="$3"
status_file="$base/status/job_${rid}.json"
log="$base/output/job_${parent}/chunks"/*/logs/patch_match_stereo.log
now=$(date +%s); mtime=0; [[ -f "$status_file" ]] && mtime=$(stat -c %Y "$status_file" 2>/dev/null || echo 0)
proc=0; pgrep -af "process_colmap_dense_chunk\.sh ${rid}( |$)" >/dev/null 2>&1 && proc=1
container=0; if command -v podman >/dev/null 2>&1; then podman ps -a --format '{{.Names}} {{.Command}}' 2>/dev/null | grep -Eq "makler_job_${rid}|job_${parent}/" && container=1 || true; fi
sigabrt=0; grep -Eiq 'SIGABRT|terminate called|Check failed' $log 2>/dev/null && sigabrt=1 || true
python3 - <<PY
import json
print(json.dumps({'status_mtime': int('$mtime'), 'status_age_seconds': max(0, int('$now')-int('$mtime')), 'process_present': bool(int('$proc')), 'container_present': bool(int('$container')), 'log_has_sigabrt': bool(int('$sigabrt'))}))
PY
REMOTE