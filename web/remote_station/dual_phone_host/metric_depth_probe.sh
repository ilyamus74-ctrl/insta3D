#!/usr/bin/env bash
set -euo pipefail

HOST="${MAKLERTOUR_HOST:-127.0.0.1}"
PORT="${MAKLERTOUR_DASHBOARD_PORT:-48641}"
X="${1:-0.5}"
Y="${2:-0.5}"

python3 - "$HOST" "$PORT" "$X" "$Y" <<'PY_METRIC'
import json
import math
import sys
import urllib.parse
import urllib.request

host, port, raw_x, raw_y = sys.argv[1:]
try:
    x = float(raw_x)
    y = float(raw_y)
except ValueError as exc:
    raise SystemExit(f"X and Y must be numbers in range 0..1: {exc}")

if not (math.isfinite(x) and math.isfinite(y) and 0.0 <= x <= 1.0 and 0.0 <= y <= 1.0):
    raise SystemExit("X and Y must be finite numbers in range 0..1")

query = urllib.parse.urlencode({"x": f"{x:.6f}", "y": f"{y:.6f}"})
url = f"http://{host}:{port}/api/depth/probe?{query}"
try:
    with urllib.request.urlopen(url, timeout=5) as response:
        payload = json.load(response)
except Exception as exc:
    raise SystemExit(f"depth probe request failed: {url}: {exc}")

print(json.dumps(payload, ensure_ascii=False, indent=2, sort_keys=True))

if not payload.get("valid"):
    raise SystemExit(2)

fields = [
    ("distance_m", "distance"),
    ("raw_disparity_px", "d_raw"),
    ("disparity_zero_offset_px", "zero"),
    ("effective_disparity_px", "d_effective"),
    ("disparity_spread_px", "spread"),
    ("left_right_consistency_ratio", "lr_ratio"),
    ("measurement_confidence", "confidence"),
    ("reason", "reason"),
    ("sample_count", "samples"),
    ("source_x_px", "source_x"),
    ("source_y_px", "source_y"),
]
print("\nSUMMARY")
for key, label in fields:
    print(f"{label}={payload.get(key)}")
PY_METRIC
