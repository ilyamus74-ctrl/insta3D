#!/usr/bin/env bash
set -euo pipefail

CONFIG="${1:?Usage: $0 ./stations.conf <job_id>}"
JOB_ID="${2:?missing job_id}"

# shellcheck source=/dev/null
source "$CONFIG"

SSH=(ssh -i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new "${STATION_USER}@${STATION_HOST}")

"${SSH[@]}" "cat '$STATION_BASE/status/job_${JOB_ID}.json' 2>/dev/null || echo '{\"status\":\"UNKNOWN\"}'"
