#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
FRAMES="${1:-/mnt/storage/makler_pipeline/output/job_180237696/frames}"
# Hybrid v2 uses controlled long-range pairs.
# COLMAP built-in loop detection is intentionally disabled.
OUT="${HYBRID_V2_OUTPUT:-/tmp/insta3d_single_hybrid_v2}"
COLMAP_BIN="${COLMAP_BIN:-colmap}"

if [[ ! -d "$FRAMES" ]]; then
  echo "ERROR: frames directory not found: $FRAMES" >&2
  exit 2
fi
if ! command -v "$COLMAP_BIN" >/dev/null 2>&1; then
  echo "ERROR: COLMAP executable not found: $COLMAP_BIN" >&2
  exit 2
fi
if [[ -e "$OUT" ]]; then
  echo "ERROR: output already exists (refusing to overwrite): $OUT" >&2
  exit 2
fi

mkdir -p "$OUT/logs" "$OUT/sparse" "$OUT/export/txt_models"
find "$FRAMES" -maxdepth 1 -type f \( -iname '*.jpg' -o -iname '*.jpeg' -o -iname '*.png' \) -printf '%f\n' | LC_ALL=C sort > "$OUT/frames.txt"
sha256sum "$FRAMES"/* > "$OUT/frames_sha256.txt"

"$COLMAP_BIN" feature_extractor \
  --database_path "$OUT/database.db" \
  --image_path "$FRAMES" \
  --ImageReader.camera_model SIMPLE_RADIAL \
  --ImageReader.single_camera 1 \
  --ImageReader.camera_params 1303.124942779541,540.0,960.0,0.0 \
  --FeatureExtraction.use_gpu 0 2>&1 | tee "$OUT/logs/feature_extractor.log"

"$COLMAP_BIN" sequential_matcher \
  --database_path "$OUT/database.db" \
  --FeatureMatching.use_gpu 0 \
  --SequentialMatching.overlap 60 \
  --SequentialMatching.loop_detection 0 2>&1 | tee "$OUT/logs/sequential_matcher.log"

python3 "$SCRIPT_DIR/generate_pairs.py" \
  --frames "$FRAMES" \
  --output "$OUT/controlled_long_range_pairs.txt" \
  --window 5 \
  --min-gap 61 | tee "$OUT/logs/generate_pairs.log"

"$COLMAP_BIN" matches_importer \
  --database_path "$OUT/database.db" \
  --match_list_path "$OUT/controlled_long_range_pairs.txt" \
  --match_type pairs \
  --FeatureMatching.use_gpu 0 2>&1 | tee "$OUT/logs/matches_importer.log"

"$COLMAP_BIN" mapper \
  --database_path "$OUT/database.db" \
  --image_path "$FRAMES" \
  --output_path "$OUT/sparse" 2>&1 | tee "$OUT/logs/mapper.log"

for model in "$OUT"/sparse/*; do
  [[ -d "$model" ]] || continue
  model_id="$(basename "$model")"
  txt_model="$OUT/export/txt_models/$model_id"
  mkdir -p "$txt_model"
  "$COLMAP_BIN" model_converter --input_path "$model" --output_path "$txt_model" --output_type TXT
  "$COLMAP_BIN" model_analyzer --path "$model" > "$OUT/export/model_analyzer_${model_id}.txt" 2>&1
done

python3 "$SCRIPT_DIR/diagnostics.py" \
  --models "$OUT/export/txt_models" \
  --output "$OUT/diagnostics.json" | tee "$OUT/logs/diagnostics.log"

echo "Hybrid v2 sparse experiment complete: $OUT"
