#!/usr/bin/env bash
set -euo pipefail
if [[ $# -ne 3 ]]; then echo "Usage: $0 stations.conf remote_job_id parent_remote_job_id" >&2; exit 2; fi
CONFIG="$1"; REMOTE_JOB_ID="$2"; PARENT_REMOTE_JOB_ID="$3"
[[ "$REMOTE_JOB_ID" =~ ^[0-9]+$ && "$REMOTE_JOB_ID" -gt 0 ]] || { echo "bad remote_job_id" >&2; exit 2; }
[[ "$PARENT_REMOTE_JOB_ID" =~ ^[0-9]+$ && "$PARENT_REMOTE_JOB_ID" -gt 0 ]] || { echo "bad parent_remote_job_id" >&2; exit 2; }
source "$CONFIG"
: "${STATION_HOST:?}"; : "${STATION_USER:?}"; : "${STATION_SSH_KEY:?}"; : "${STATION_BASE:?}"
SSH=(ssh -i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new "${STATION_USER}@${STATION_HOST}")
"${SSH[@]}" bash -s -- "$REMOTE_JOB_ID" "$PARENT_REMOTE_JOB_ID" "$STATION_BASE" <<'REMOTE'
set -euo pipefail
rid="$1"; parent="$2"; base="$3"
json_array(){ python3 - "$@" <<'PY'
import json,sys
print(json.dumps(sys.argv[1:]))
PY
}
patterns=(
  "process_extract_frames\.sh ${rid}([[:space:]]|$)"
  "process_colmap_sparse\.sh ${rid}([[:space:]]|$)"
  "process_colmap_dense_chunk\.sh ${rid}([[:space:]]|$)"
  "process_colmap_mesh\.sh ${rid}([[:space:]]|$)"
  "build_clean_mesh\.py .*job_${rid}(/|[[:space:]]|$)"
)
mapfile -t pids < <(
  { for pat in "${patterns[@]}"; do pgrep -af "$pat" || true; done; } |
  awk '{print $1}' |
  sort -u
)
containers=()
if command -v podman >/dev/null 2>&1; then
  if podman container exists "makler_job_${rid}" 2>/dev/null; then
    containers+=("makler_job_${rid}")
  fi
  for c in "${containers[@]}"; do
    podman rm -f "$c" >/dev/null 2>&1 || true
  done
fi
for p in "${pids[@]}"; do kill -TERM "$p" >/dev/null 2>&1 || true; done
sleep 2
for p in "${pids[@]}"; do kill -KILL "$p" >/dev/null 2>&1 || true; done
mapfile -t remaining < <(
  {
    for pat in "${patterns[@]}"; do pgrep -af "$pat" || true; done
    if command -v podman >/dev/null 2>&1; then
      podman ps -a --format '{{.Names}}' 2>/dev/null |
        grep -Fx "makler_job_${rid}" || true
    fi
  } |
  sed '/^$/d'
)
export PIDS_JSON=$(json_array "${pids[@]}")
export CONTAINERS_JSON=$(json_array "${containers[@]}")
export REMAINING_JSON=$(json_array "${remaining[@]}")
python3 - <<PY
import json, os
pids=json.loads(os.environ['PIDS_JSON'])
containers=json.loads(os.environ['CONTAINERS_JSON'])
remaining=json.loads(os.environ['REMAINING_JSON'])
print(json.dumps({
    'cancelled': len(remaining)==0,
    'pids': pids,
    'containers': containers,
    'remaining_processes': remaining,
}, ensure_ascii=False))
PY
REMOTE
