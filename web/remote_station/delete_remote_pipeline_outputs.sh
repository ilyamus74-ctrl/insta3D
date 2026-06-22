#!/usr/bin/env bash
set -euo pipefail
if [[ $# -lt 2 ]]; then echo "Usage: $0 stations.conf pipeline_run_id [remote_job_id ...]" >&2; exit 2; fi
CONFIG="$1"; PIPELINE_ID="$2"; shift 2
[[ -f "$CONFIG" ]] || { echo "config not found" >&2; exit 2; }
[[ "$PIPELINE_ID" =~ ^[0-9]+$ && "$PIPELINE_ID" -gt 0 ]] || { echo "bad pipeline_run_id" >&2; exit 2; }
for id in "$@"; do [[ "$id" =~ ^[0-9]+$ && "$id" -gt 0 ]] || { echo "bad remote_job_id: $id" >&2; exit 2; }; done
# shellcheck source=/dev/null
source "$CONFIG"
: "${STATION_HOST:?missing STATION_HOST}"; : "${STATION_USER:?missing STATION_USER}"; : "${STATION_SSH_KEY:?missing STATION_SSH_KEY}"
SSH=(ssh -i "$STATION_SSH_KEY" -o BatchMode=yes -o ConnectTimeout=8 -o StrictHostKeyChecking=accept-new "${STATION_USER}@${STATION_HOST}")
remote_script='set -euo pipefail
pipeline_id="$1"; shift
[[ "$pipeline_id" =~ ^[0-9]+$ && "$pipeline_id" -gt 0 ]] || exit 2
base=/home/makler_storage/output
for id in "$@"; do
  [[ "$id" =~ ^[0-9]+$ && "$id" -gt 0 ]] || exit 2
  rm -rf -- "$base/job_$id"
done
rm -rf -- "$base/pipeline_$pipeline_id"'
"${SSH[@]}" bash -s -- "$PIPELINE_ID" "$@" <<< "$remote_script"