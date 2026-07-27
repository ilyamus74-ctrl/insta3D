#!/usr/bin/env bash
set -euo pipefail
if [[ $# -ne 3 && $# -ne 4 ]]; then
  echo "Usage: $0 stations.conf remote_job_id parent_remote_job_id [chunk_index]" >&2
  exit 2
fi
CONFIG="$1"; RID="$2"; PARENT="$3"; CHUNK="${4:-}"; source "$CONFIG"
: "${STATION_HOST:?}"; : "${STATION_USER:?}"; : "${STATION_SSH_KEY:?}"; : "${STATION_BASE:?}"
SSH=(ssh -i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new "${STATION_USER}@${STATION_HOST}")
"${SSH[@]}" bash -s -- "$RID" "$PARENT" "$CHUNK" "$STATION_BASE" <<'REMOTE'
set -euo pipefail
rid="$1"; parent="$2"; chunk="$3"; base="$4"
status_file="$base/status/job_${rid}.json"
log=""
if [[ "$chunk" =~ ^[0-9]+$ ]]; then
  log="$base/output/job_${parent}/chunks/chunk_${chunk}/logs/patch_match_stereo.log"
fi
now=$(date +%s)
mtime=0
status_exists=0
if [[ -f "$status_file" ]]; then
  status_exists=1
  mtime=$(stat -c %Y "$status_file" 2>/dev/null || echo 0)
fi
proc=0
pgrep -af "process_colmap_dense_chunk\.sh ${rid}( |$)" >/dev/null 2>&1 && proc=1
container=0
container_running=0
if command -v podman >/dev/null 2>&1; then
  if podman container exists "makler_job_${rid}" 2>/dev/null; then
    container=1
    running=$(podman inspect "makler_job_${rid}" --format '{{.State.Running}}' 2>/dev/null || echo false)
    [[ "$running" == "true" ]] && container_running=1
  fi
fi
sigabrt=0
if [[ -n "$log" && -f "$log" ]]; then
  grep -Eiq 'SIGABRT|terminate called|Check failed|AggregateException' "$log" \
    && sigabrt=1 || true
fi
python3 - <<PY
import json
print(json.dumps({
    'status_exists': bool(int('$status_exists')),
    'status_mtime': int('$mtime'),
    'status_age_seconds': max(0, int('$now')-int('$mtime')) if int('$mtime') else None,
    'process_present': bool(int('$proc')),
    'container_present': bool(int('$container')),
    'container_running': bool(int('$container_running')),
    'log_has_sigabrt': bool(int('$sigabrt')),
    'chunk_index': int('$chunk') if '$chunk'.isdigit() else None,
}))
PY
REMOTE