#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 3 || $# -gt 5 ]]; then
  echo "Usage: $0 ./stations.conf <source_remote_image_list_path> <destination_remote_image_list_path> [keep_ratio] [minimum_images]" >&2
  exit 1
fi

CONFIG="$1"
SOURCE_PATH="$2"
DEST_PATH="$3"
KEEP_RATIO="${4:-0.75}"
MINIMUM_IMAGES="${5:-3}"

source "$CONFIG"
: "${STATION_HOST:?missing STATION_HOST}"
: "${STATION_USER:?missing STATION_USER}"
: "${STATION_SSH_KEY:?missing STATION_SSH_KEY}"

SSH=(ssh -i "$STATION_SSH_KEY" -o StrictHostKeyChecking=accept-new "${STATION_USER}@${STATION_HOST}")

"${SSH[@]}" bash -s -- "$SOURCE_PATH" "$DEST_PATH" "$KEEP_RATIO" "$MINIMUM_IMAGES" <<'REMOTE_RETRY_LIST'
set -euo pipefail

src="$1"
dst="$2"
ratio="$3"
minimum="$4"

python3 - "$src" "$dst" "$ratio" "$minimum" <<'PY'
import json
import math
import os
import sys
import tempfile

src, dst, ratio_raw, minimum_raw = sys.argv[1:5]
try:
    ratio = float(ratio_raw)
    minimum = int(minimum_raw)
except ValueError as exc:
    print(f"Invalid retry parameters: {exc}", file=sys.stderr)
    sys.exit(2)

if ratio < 0:
    print(f"Invalid keep ratio: {ratio_raw}", file=sys.stderr)
    sys.exit(2)
if minimum < 0:
    print(f"Invalid minimum images: {minimum_raw}", file=sys.stderr)
    sys.exit(2)
if not os.path.isfile(src):
    print(f"Remote source image list not found: {src}", file=sys.stderr)
    sys.exit(3)

with open(src, "r", encoding="utf-8", errors="replace") as fh:
    lines = [line.strip() for line in fh if line.strip()]

total = len(lines)
if total <= 0:
    print(f"Remote source image list is empty: {src}", file=sys.stderr)
    sys.exit(4)

keep = math.floor(total * ratio)
keep = max(minimum, keep)
keep = min(keep, total)
retry_lines = lines[:keep]

dst_dir = os.path.dirname(dst) or "."
os.makedirs(dst_dir, exist_ok=True)
fd, tmp = tempfile.mkstemp(prefix=os.path.basename(dst) + ".", suffix=".tmp", dir=dst_dir, text=True)
try:
    with os.fdopen(fd, "w", encoding="utf-8") as out:
        out.write("\n".join(retry_lines))
        out.write("\n")
    os.replace(tmp, dst)
except Exception:
    try:
        os.unlink(tmp)
    except FileNotFoundError:
        pass
    raise

print(json.dumps({
    "status": "DONE",
    "source_count": total,
    "retry_count": keep,
    "source_path": src,
    "retry_path": dst,
}, ensure_ascii=False, separators=(",", ":")))
PY
REMOTE_RETRY_LIST