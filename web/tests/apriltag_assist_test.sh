#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HELPER="$ROOT/remote_station/scripts/analyze_apriltag_assist.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

mkdir -p "$TMP/frames" "$TMP/sparse/0/txt" "$TMP/sparse/1/txt"
for n in 1 2 3 101 102 103; do
  touch "$TMP/frames/frame_$(printf '%06d' "$n").jpg"
done

cat > "$TMP/sparse/0/txt/images.txt" <<'EOF'
# Image list
1 1 0 0 0 0 0 0 1 frame_000001.jpg
2 1 0 0 0 0 0 0 1 frame_000002.jpg
3 1 0 0 0 0 0 0 1 frame_000003.jpg
EOF

cat > "$TMP/sparse/1/txt/images.txt" <<'EOF'
# Image list
1 1 0 0 0 0 0 0 1 frame_000101.jpg
2 1 0 0 0 0 0 0 1 frame_000102.jpg
3 1 0 0 0 0 0 0 1 frame_000103.jpg
EOF

cat > "$TMP/detections.json" <<'EOF'
{
  "detections": [
    {"source_path":"frame_000001.jpg","marker_id":7,"corners":[[1,1],[2,1],[2,2],[1,2]]},
    {"source_path":"frame_000002.jpg","marker_id":7,"corners":[[1,1],[2,1],[2,2],[1,2]]},
    {"source_path":"frame_000003.jpg","marker_id":7,"corners":[[1,1],[2,1],[2,2],[1,2]]},
    {"source_path":"frame_000101.jpg","marker_id":7,"corners":[[1,1],[2,1],[2,2],[1,2]]},
    {"source_path":"frame_000102.jpg","marker_id":7,"corners":[[1,1],[2,1],[2,2],[1,2]]},
    {"source_path":"frame_000103.jpg","marker_id":7,"corners":[[1,1],[2,1],[2,2],[1,2]]}
  ]
}
EOF

APRILTAG_ASSIST_DETECTIONS_JSON="$TMP/detections.json" \
  "$HELPER" \
  --frames-dir "$TMP/frames" \
  --sparse-dir "$TMP/sparse" \
  --output-json "$TMP/ready.json" \
  --min-observations 3

python3 - "$TMP/ready.json" <<'PY'
import json,sys
d=json.load(open(sys.argv[1],encoding='utf-8'))
assert d['status']=='MARKERS_READY', d
assert d['bridge_tags']==[7], d
assert d['scale_ready_components']==['0','1'], d
assert d['completed_with_warnings'] is False, d
PY

cat > "$TMP/empty.json" <<'EOF'
{"detections":[]}
EOF

APRILTAG_ASSIST_DETECTIONS_JSON="$TMP/empty.json" \
  "$HELPER" \
  --frames-dir "$TMP/frames" \
  --sparse-dir "$TMP/sparse" \
  --output-json "$TMP/not_found.json" \
  --min-observations 3

python3 - "$TMP/not_found.json" <<'PY'
import json,sys
d=json.load(open(sys.argv[1],encoding='utf-8'))
assert d['status']=='MARKERS_NOT_FOUND', d
assert d['completed_with_warnings'] is True, d
assert 'проблемы их стыковки' in d['warning_text'], d
PY

echo "OK"
