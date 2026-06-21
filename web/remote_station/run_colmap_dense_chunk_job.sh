#!/usr/bin/env bash
set -euo pipefail
if [[ $# -ne 8 ]]; then echo "Usage: $0 ./stations.conf <job_id> <parent_job_id> <sparse_job_id> <model_id> <chunk_id> <image_list_path> <mode>" >&2; exit 1; fi
CONFIG="$1"; shift; source "$CONFIG"
: "${STATION_HOST:?}"; : "${STATION_USER:?}"; : "${STATION_SSH_KEY:?}"; : "${STATION_BASE:?}"
SSH=(ssh -i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new "${STATION_USER}@${STATION_HOST}")
printf -v A '%q ' "$@"; printf -v B '%q' "$STATION_BASE"; printf -v CM '%q' "${COLMAP_MODE:-native}"; printf -v CB '%q' "${COLMAP_BIN:-colmap}"; printf -v CI '%q' "${COLMAP_IMAGE:-}"
"${SSH[@]}" "STATION_BASE=$B COLMAP_MODE=$CM COLMAP_BIN=$CB COLMAP_IMAGE=$CI $B/scripts/process_colmap_dense_chunk.sh $A"