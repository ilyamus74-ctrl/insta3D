#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 3 || $# -gt 7 ]]; then
  echo "Usage: $0 ./stations.conf <job_id> <remote_frames_dir> [matcher] [sequential_overlap] [loop_detection] [parameters_json]" >&2
  exit 1
fi

CONFIG="$1"
JOB_ID="$2"
REMOTE_FRAMES_DIR="$3"
REQ_MATCHER="${4:-}"
REQ_OVERLAP="${5:-}"
REQ_LOOP="${6:-}"
REQ_PARAMETERS_JSON="${7:-}"

if [[ ! -f "$CONFIG" ]]; then
  echo "ERROR: config not found: $CONFIG" >&2
  exit 1
fi

# shellcheck source=/dev/null
source "$CONFIG"

: "${STATION_HOST:?missing STATION_HOST}"
: "${STATION_USER:?missing STATION_USER}"
: "${STATION_SSH_KEY:?missing STATION_SSH_KEY}"
: "${STATION_BASE:?missing STATION_BASE}"
COLMAP_MODE="${COLMAP_MODE:-native}"
COLMAP_BIN="${COLMAP_BIN:-colmap}"
COLMAP_IMAGE="${COLMAP_IMAGE:-}"
COLMAP_MATCHER="${REQ_MATCHER:-${COLMAP_MATCHER:-sequential}}"
COLMAP_SEQUENTIAL_OVERLAP="${REQ_OVERLAP:-${COLMAP_SEQUENTIAL_OVERLAP:-}}"
COLMAP_LOOP_DETECTION="${REQ_LOOP:-${COLMAP_LOOP_DETECTION:-0}}"
COLMAP_CAMERA_MODEL_AUTO_FROM_METADATA="${COLMAP_CAMERA_MODEL_AUTO_FROM_METADATA:-0}"

resolve_runner_sparse_settings() {
  python3 - "$REQ_PARAMETERS_JSON" "$COLMAP_SEQUENTIAL_OVERLAP" "$COLMAP_LOOP_DETECTION" <<'PYRUNNER'
import json
import sys

payload, env_overlap, env_loop = sys.argv[1], sys.argv[2], sys.argv[3]
SAFE_OVERLAP = 60
SAFE_LOOP = 0
warnings = []
try:
    data = json.loads(payload) if payload else {}
except Exception as exc:
    warnings.append(f"invalid runner parameters JSON: {exc}; using argument/environment/default sparse settings")
    data = {}

def dig(obj, parts):
    cur = obj
    for part in parts:
        if not isinstance(cur, dict) or part not in cur:
            return None
        cur = cur[part]
    return cur

def valid_overlap(raw, source):
    try:
        if isinstance(raw, bool):
            raise ValueError('boolean is not an integer overlap')
        value = int(str(raw).strip())
    except Exception:
        warnings.append(f"invalid runner sequential_overlap from {source}: {raw!r}; expected integer 1-200")
        return None
    if not 1 <= value <= 200:
        warnings.append(f"invalid runner sequential_overlap from {source}: {value}; expected range 1-200")
        return None
    return value

def valid_loop(raw):
    if isinstance(raw, bool):
        return 1 if raw else 0
    text = str(raw).strip().lower()
    if text in ('1', 'true', 'yes', 'on'):
        return 1
    if text in ('0', 'false', 'no', 'off', ''):
        return 0
    return None

candidates = [
    ('ui_snapshot', dig(data, ['settings', 'sparse', 'sequential_overlap'])),
    ('legacy', dig(data, ['sparse', 'sequential_overlap'])),
    ('env', env_overlap),
]
overlap = None
source = 'default'
for candidate_source, raw in candidates:
    if raw is None or raw == '':
        continue
    parsed = valid_overlap(raw, candidate_source)
    if parsed is not None:
        overlap = parsed
        source = candidate_source
        break
if overlap is None:
    overlap = SAFE_OVERLAP

loop = None
for raw in (dig(data, ['settings', 'sparse', 'loop_detection']), dig(data, ['sparse', 'loop_detection']), env_loop):
    if raw is None or raw == '':
        continue
    parsed = valid_loop(raw)
    if parsed is not None:
        loop = parsed
        break
if loop is None:
    loop = SAFE_LOOP

print(overlap)
print(loop)
print(source)
for warning in warnings:
    print(warning)
PYRUNNER
}

RUNNER_RESOLVED=()
mapfile -t RUNNER_RESOLVED < <(resolve_runner_sparse_settings)
COLMAP_SEQUENTIAL_OVERLAP="${RUNNER_RESOLVED[0]:-60}"
COLMAP_LOOP_DETECTION="${RUNNER_RESOLVED[1]:-0}"
COLMAP_SPARSE_SETTINGS_SOURCE="${RUNNER_RESOLVED[2]:-default}"
for warning in "${RUNNER_RESOLVED[@]:3}"; do
  [[ -n "$warning" ]] && echo "WARNING: $warning" >&2
done

echo "Runner sparse settings: matcher=$COLMAP_MATCHER overlap=$COLMAP_SEQUENTIAL_OVERLAP loop_detection=$COLMAP_LOOP_DETECTION source=$COLMAP_SPARSE_SETTINGS_SOURCE"

SSH_OPTS=(-i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new)
SSH=(ssh "${SSH_OPTS[@]}" "${STATION_USER}@${STATION_HOST}")

REMOTE_OUTPUT="$STATION_BASE/output/job_${JOB_ID}/colmap"
REMOTE_LOG="$STATION_BASE/logs/job_${JOB_ID}.nohup.log"

printf -v Q_FRAMES '%q' "$REMOTE_FRAMES_DIR"
printf -v Q_BASE '%q' "$STATION_BASE"
printf -v Q_JOB '%q' "$JOB_ID"
printf -v Q_OUTPUT '%q' "$REMOTE_OUTPUT"
printf -v Q_LOG '%q' "$REMOTE_LOG"
printf -v Q_STATION_BASE '%q' "$STATION_BASE"
printf -v Q_COLMAP_MODE '%q' "$COLMAP_MODE"
printf -v Q_COLMAP_BIN '%q' "$COLMAP_BIN"
printf -v Q_COLMAP_IMAGE '%q' "$COLMAP_IMAGE"
printf -v Q_COLMAP_MATCHER '%q' "$COLMAP_MATCHER"
printf -v Q_COLMAP_SEQUENTIAL_OVERLAP '%q' "$COLMAP_SEQUENTIAL_OVERLAP"
printf -v Q_COLMAP_CAMERA_MODEL_AUTO_FROM_METADATA '%q' "$COLMAP_CAMERA_MODEL_AUTO_FROM_METADATA"
printf -v Q_COLMAP_LOOP_DETECTION '%q' "$COLMAP_LOOP_DETECTION"
printf -v Q_PARAMETERS_JSON '%q' "$REQ_PARAMETERS_JSON"
REMOTE_CMD="test -d $Q_FRAMES"

echo "==> Check remote frames directory"
if ! "${SSH[@]}" "$REMOTE_CMD"; then
  echo "ERROR: remote frames directory not found: $REMOTE_FRAMES_DIR" >&2
  exit 1
fi

echo "==> Prepare station dirs"
"${SSH[@]}" "mkdir -p $Q_OUTPUT $Q_BASE/logs $Q_BASE/status"

echo "==> Start COLMAP sparse reconstruction job $JOB_ID"
"${SSH[@]}" "mkdir -p $Q_BASE/input/job_${JOB_ID} && if [ -n $Q_PARAMETERS_JSON ]; then printf %s $Q_PARAMETERS_JSON > $Q_BASE/input/job_${JOB_ID}/parameters.json; else printf '{\"sparse\":{\"matcher\":\"%s\",\"sequential_overlap\":%s,\"loop_detection\":%s}}\n' $Q_COLMAP_MATCHER $Q_COLMAP_SEQUENTIAL_OVERLAP $Q_COLMAP_LOOP_DETECTION > $Q_BASE/input/job_${JOB_ID}/parameters.json; fi && STATION_BASE=$Q_STATION_BASE COLMAP_MODE=$Q_COLMAP_MODE COLMAP_BIN=$Q_COLMAP_BIN COLMAP_IMAGE=$Q_COLMAP_IMAGE COLMAP_MATCHER=$Q_COLMAP_MATCHER COLMAP_SEQUENTIAL_OVERLAP=$Q_COLMAP_SEQUENTIAL_OVERLAP COLMAP_LOOP_DETECTION=$Q_COLMAP_LOOP_DETECTION COLMAP_CAMERA_MODEL_AUTO_FROM_METADATA=$Q_COLMAP_CAMERA_MODEL_AUTO_FROM_METADATA nohup $Q_BASE/scripts/process_colmap_sparse.sh $Q_JOB $Q_FRAMES $Q_OUTPUT > $Q_LOG 2>&1 &"

echo "==> Started"
echo "Status:"
echo "  ./get_station_status.sh $CONFIG $JOB_ID"
echo "Fetch result:"
echo "  ./fetch_job_result.sh $CONFIG $JOB_ID ./output"
