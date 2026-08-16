#!/usr/bin/env python3
import json
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "web/remote_station/scripts/analyze_tof_dense_error_h23.py"

def write_json(path, value):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(value, indent=2), encoding="utf-8")

def write_jsonl(path, rows):
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8") as handle:
        for row in rows:
            handle.write(json.dumps(row) + "\n")

def fixture(root):
    h22_report = root / "h22_report.json"
    h22_candidates = root / "h22_candidates.jsonl"
    camera_metadata = root / "camera_metadata.json"
    model_dir = root / "sparse" / "0"
    output = root / "h23_rows.jsonl"
    report = root / "h23_report.json"
    write_json(h22_report, {"status": "MEASURED", "strategies": {"geometric_footprint_p50": {"candidate_count": 1000}}})
    distance_scales = {750.0: 180.0, 1250.0: 240.0, 1750.0: 360.0, 2500.0: 320.0}
    row_factors = {row: 0.90 + row * (0.20 / 7.0) for row in range(8)}
    col_factors = {col: 1.10 - col * (0.20 / 7.0) for col in range(8)}
    rows = []
    sequence = 1
    for repeat in range(3):
        for distance, base_scale in distance_scales.items():
            for zone_row in range(8):
                for zone_col in range(8):
                    scale = base_scale * row_factors[zone_row] * col_factors[zone_col]
                    rows.append({
                        "type": "tof_dense_h22_candidate", "schema_version": 1,
                        "stage": "SFM-S01H2.2", "strategy": "geometric_footprint_p50",
                        "image": f"frame_{sequence:06d}.jpg", "video_timestamp_sec": sequence * 0.01,
                        "tof_sequence": sequence, "zone_index": zone_row * 8 + zone_col,
                        "zone_row": zone_row, "zone_column": zone_col,
                        "zone_radial": "center" if 2 <= zone_row <= 5 and 2 <= zone_col <= 5 else "edge",
                        "distance_mm": distance, "sigma_mm": 5.0, "target_status": 5,
                        "tof_camera_z_mm": distance, "dense_depth_units": distance / scale,
                        "scale_mm_per_colmap_unit": scale, "image_region": "center",
                        "time_quartile": f"Q{repeat + 1}",
                    })
                    sequence += 1
    write_jsonl(h22_candidates, rows)
    write_json(camera_metadata, {"colmap_camera_prior": {
        "usable_for_colmap": True, "source": "CAMERA2_FACTORY_INTRINSICS_RUNTIME_STREAM_CROP",
        "model": "SIMPLE_RADIAL", "params": [1300.0, 960.0, 540.0, 0.0],
        "source_resolution": [1920, 1080],
    }})
    model_dir.mkdir(parents=True, exist_ok=True)
    (model_dir / "cameras.txt").write_text("# Camera list\n1 SIMPLE_RADIAL 640 360 450 320 180 0.02\n", encoding="utf-8")
    return locals()

def main():
    with tempfile.TemporaryDirectory() as tmp:
        p = fixture(Path(tmp))
        subprocess.run([
            sys.executable, str(SCRIPT),
            "--h22-candidates", str(p["h22_candidates"]),
            "--h22-report", str(p["h22_report"]),
            "--strategy", "geometric_footprint_p50",
            "--camera-metadata", str(p["camera_metadata"]),
            "--sparse-model-dir", str(p["model_dir"]),
            "--minimum-group-count", "20",
            "--output-jsonl", str(p["output"]),
            "--report-json", str(p["report"]),
        ], check=True)
        report = json.loads(p["report"].read_text(encoding="utf-8"))
        assert report["status"] == "MEASURED"
        assert report["direct_tof_candidate_count"] == 768
        signals = report["controlled_signals"]
        assert signals["raw_distance_deformation_signal"] is True
        assert signals["distance_effect_persists_after_zone_normalization"] is True
        assert signals["zone_row_effect_persists_after_distance_normalization"] is True
        assert signals["zone_column_effect_persists_after_distance_normalization"] is True
        assert report["fully_normalized_residual"]["absolute_ratio_error_p95"] < 0.03
        audit = report["camera_optics_audit"]
        assert audit["status"] == "MEASURED"
        assert audit["camera_count"] == 1
        assert audit["camera_optics_drift_signal"] is True
        comparison = audit["cameras"][0]["comparison"]
        assert comparison["focal_delta_pct"] > 3.0
        assert abs(comparison["cx_delta_px"]) < 1e-9
        assert abs(comparison["cy_delta_px"]) < 1e-9
        assert report["geometry_mutation_enabled"] is False
        assert report["ready_for_geometry_mutation"] is False
        assert report["dense_input_modified"] is False
        assert report["dense_depth_modified"] is False
        assert report["fusion_enabled"] is False
    print("Result: PASS")

if __name__ == "__main__":
    main()
