#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 4 ]]; then
  echo "Usage: $0 ./stations.conf <job_id> <sparse_job_id> <model_id>" >&2
  exit 1
fi
CONFIG="$1"; JOB_ID="$2"; SPARSE_JOB_ID="$3"; MODEL_ID="$4"
if [[ ! -f "$CONFIG" ]]; then echo "ERROR: config not found: $CONFIG" >&2; exit 1; fi
# shellcheck source=/dev/null
source "$CONFIG"
: "${STATION_HOST:?missing STATION_HOST}"; : "${STATION_USER:?missing STATION_USER}"; : "${STATION_SSH_KEY:?missing STATION_SSH_KEY}"; : "${STATION_BASE:?missing STATION_BASE}"
COLMAP_MODE="${COLMAP_MODE:-native}"; COLMAP_BIN="${COLMAP_BIN:-colmap}"; COLMAP_IMAGE="${COLMAP_IMAGE:-}"
SSH_OPTS=(-i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new); SSH=(ssh "${SSH_OPTS[@]}" "${STATION_USER}@${STATION_HOST}")
REMOTE_DENSE="$STATION_BASE/output/job_${JOB_ID}/dense"; REMOTE_LOG="$STATION_BASE/logs/job_${JOB_ID}.nohup.log"
printf -v Q_BASE '%q' "$STATION_BASE"; printf -v Q_JOB '%q' "$JOB_ID"; printf -v Q_SPARSE '%q' "$SPARSE_JOB_ID"; printf -v Q_MODEL '%q' "$MODEL_ID"; printf -v Q_DENSE '%q' "$REMOTE_DENSE"; printf -v Q_LOG '%q' "$REMOTE_LOG"; printf -v Q_MODE '%q' "$COLMAP_MODE"; printf -v Q_BIN '%q' "$COLMAP_BIN"; printf -v Q_IMAGE '%q' "$COLMAP_IMAGE"
echo "==> Check remote sparse job result"
"${SSH[@]}" "test -f $Q_BASE/output/job_$Q_SPARSE/colmap/result.json && test -d $Q_BASE/output/job_$Q_SPARSE/colmap/sparse/$Q_MODEL"
echo "==> Prepare station dirs"
"${SSH[@]}" "mkdir -p $Q_DENSE $Q_BASE/logs $Q_BASE/status"
echo "==> Start COLMAP dense reconstruction job $JOB_ID"
"${SSH[@]}" "STATION_BASE=$Q_BASE COLMAP_MODE=$Q_MODE COLMAP_BIN=$Q_BIN COLMAP_IMAGE=$Q_IMAGE nohup $Q_BASE/scripts/process_colmap_dense.sh $Q_JOB $Q_SPARSE $Q_MODEL > $Q_LOG 2>&1 &"
echo "==> Started"
echo "Status:"
echo "  ./get_station_status.sh $CONFIG $JOB_ID"
echo "Fetch result:"
echo "  ./fetch_job_result.sh $CONFIG $JOB_ID ./output"