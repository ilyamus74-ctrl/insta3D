#!/usr/bin/env bash
set -euo pipefail
if [[ $# -lt 3 || $# -gt 6 ]]; then echo "Usage: $0 ./stations.conf <job_id> <local_bundle_tgz> [max_pairs] [num_disparities] [block_size]" >&2; exit 1; fi
CONFIG="$1"; JOB_ID="$2"; LOCAL_BUNDLE="$3"; MAX_PAIRS="${4:-40}"; NUM_DISPARITIES="${5:-128}"; BLOCK_SIZE="${6:-7}"
[[ -f "$CONFIG" ]] || { echo "ERROR: config not found: $CONFIG" >&2; exit 1; }
[[ -f "$LOCAL_BUNDLE" ]] || { echo "ERROR: local bundle not found: $LOCAL_BUNDLE" >&2; exit 1; }
# shellcheck source=/dev/null
source "$CONFIG"
: "${STATION_HOST:?missing STATION_HOST}"; : "${STATION_USER:?missing STATION_USER}"; : "${STATION_SSH_KEY:?missing STATION_SSH_KEY}"; : "${STATION_BASE:?missing STATION_BASE}"
SSH_OPTS=(-i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new)
SSH=(ssh "${SSH_OPTS[@]}" "${STATION_USER}@${STATION_HOST}")
REMOTE_BUNDLE="$STATION_BASE/incoming/job_${JOB_ID}_maklertour_synced_dense.tgz"
REMOTE_WORK="$STATION_BASE/work/job_${JOB_ID}"
REMOTE_OUTPUT="$STATION_BASE/output/job_${JOB_ID}"
REMOTE_LOG="$STATION_BASE/logs/job_${JOB_ID}.nohup.log"
echo "==> Prepare station dirs"
"${SSH[@]}" "mkdir -p '$STATION_BASE/incoming' '$REMOTE_WORK' '$REMOTE_OUTPUT' '$STATION_BASE/logs' '$STATION_BASE/status' '$STATION_BASE/input/job_${JOB_ID}'"
echo "==> Upload bundle to ${STATION_HOST}:${REMOTE_BUNDLE}"
if command -v rsync >/dev/null 2>&1; then rsync -az --progress -e "ssh -i '$STATION_SSH_KEY' -o StrictHostKeyChecking=accept-new" "$LOCAL_BUNDLE" "${STATION_USER}@${STATION_HOST}:$REMOTE_BUNDLE"; else scp -i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new "$LOCAL_BUNDLE" "${STATION_USER}@${STATION_HOST}:$REMOTE_BUNDLE"; fi
PARAMS=$(printf '{"job_type":"MAKLERTOUR_SYNCED_DENSE","max_pairs":%s,"num_disparities":%s,"block_size":%s}\n' "$MAX_PAIRS" "$NUM_DISPARITIES" "$BLOCK_SIZE")
printf -v Q_PARAMS '%q' "$PARAMS"
echo "==> Start synced dense job $JOB_ID"
"${SSH[@]}" "printf %s $Q_PARAMS > '$STATION_BASE/input/job_${JOB_ID}/parameters.json' && nohup '$STATION_BASE/scripts/process_maklertour_synced_dense.sh' '$JOB_ID' '$REMOTE_BUNDLE' '$REMOTE_OUTPUT' '$MAX_PAIRS' '$NUM_DISPARITIES' '$BLOCK_SIZE' > '$REMOTE_LOG' 2>&1 &"
echo "==> Started"
echo "Status: ./get_station_status.sh $CONFIG $JOB_ID"
echo "Fetch result: ./fetch_job_result.sh $CONFIG $JOB_ID ./output"