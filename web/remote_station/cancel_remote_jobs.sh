#!/usr/bin/env bash
set -euo pipefail
if [[ $# -lt 2 ]]; then echo "Usage: $0 stations.conf remote_job_id [remote_job_id ...]" >&2; exit 2; fi
CONFIG="$1"; shift
[[ -f "$CONFIG" ]] || { echo "config not found" >&2; exit 2; }
for id in "$@"; do [[ "$id" =~ ^[0-9]+$ && "$id" -gt 0 ]] || { echo "bad remote_job_id: $id" >&2; exit 2; }; done
# shellcheck source=/dev/null
source "$CONFIG"
: "${STATION_HOST:?missing STATION_HOST}"; : "${STATION_USER:?missing STATION_USER}"; : "${STATION_SSH_KEY:?missing STATION_SSH_KEY}"
SSH=(ssh -i "$STATION_SSH_KEY" -o BatchMode=yes -o ConnectTimeout=8 -o StrictHostKeyChecking=accept-new "${STATION_USER}@${STATION_HOST}")
remote_script='set -euo pipefail
for id in "$@"; do
  [[ "$id" =~ ^[0-9]+$ && "$id" -gt 0 ]] || exit 2
  pkill -TERM -f "job_${id}\b|remote_job_id=${id}\b|/${id}( |$)" 2>/dev/null || true
done'
"${SSH[@]}" bash -s -- "$@" <<< "$remote_script"