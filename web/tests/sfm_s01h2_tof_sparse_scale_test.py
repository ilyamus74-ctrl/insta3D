#!/usr/bin/env python3
import json
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "web/remote_station/scripts/measure_tof_sparse_scale.py"


def write_json(path, value):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(value, indent=2), encoding="utf-8")


def write_jsonl(path, rows):
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8") as handle:
        for row in rows:
            handle.write(json.dumps(row) + "\n")


def main():
    with tempfile.TemporaryDirectory() as tmp:
        base = Path(tmp)
        observations = base / "observations.jsonl"
        h1 = base / "h1.json"
        model = base / "model0"
        out = base / "matches.jsonl"
        report = base / "report.json"

        write_jsonl(observations, [])
        write_json(h1, {
            "status": "SKIPPED_NO_TOF",
            "measurement_gate_pass": False,
            "ready_for_sparse_scale_measurement": False,
        })
        model.mkdir(parents=True)
        subprocess.run([
            sys.executable, str(SCRIPT),
            "--observations", str(observations),
            "--observation-report", str(h1),
            "--model-dir", str(model),
            "--output-jsonl", str(out),
            "--report-json", str(report),
        ], check=True)
        skipped = json.loads(report.read_text(encoding="utf-8"))
        assert skipped["status"] == "SKIPPED_NO_TOF_MEASUREMENT"
        assert skipped["geometry_mutation_enabled"] is False
        assert skipped["fusion_enabled"] is False

        rows = [
            {
                "type": "tof_metric_observation",
                "image": "frame_000001.jpg",
                "tof_sequence": i,
                "tof_slot": 0,
                "zone_index": 27,
                "distance_mm": 1000.0,
                "sigma_mm": 5.0,
                "target_status": 5,
                "camera_xyz_mm": [0.0, 0.0, 1000.0],
            }
            for i in range(40)
        ]
        write_jsonl(observations, rows)
        write_json(h1, {
            "status": "MEASURED",
            "measurement_gate_pass": True,
            "ready_for_sparse_scale_measurement": True,
        })
        (model / "cameras.txt").write_text(
            "1 SIMPLE_PINHOLE 100 100 100 50 50\n", encoding="utf-8"
        )
        (model / "images.txt").write_text(
            "1 1 0 0 0 0 0 0 1 frame_000001.jpg\n"
            "50 50 1\n",
            encoding="utf-8",
        )
        (model / "points3D.txt").write_text(
            "1 0 0 2 255 255 255 0.1 1 0\n", encoding="utf-8"
        )
        subprocess.run([
            sys.executable, str(SCRIPT),
            "--observations", str(observations),
            "--observation-report", str(h1),
            "--model-dir", str(model),
            "--output-jsonl", str(out),
            "--report-json", str(report),
        ], check=True)
        measured = json.loads(report.read_text(encoding="utf-8"))
        assert measured["status"] == "MEASURED"
        assert measured["scale_candidate_count"] == 40
        assert measured["inlier_count"] == 40
        assert measured["scale_candidate_available"] is True
        assert abs(measured["scale"]["robust_mm_per_colmap_unit"] - 500.0) < 1e-9
        assert measured["residuals"]["inlier_depth_error_p95_mm"] == 0.0
        assert measured["pixel_match"]["p95_px"] == 0.0
        assert measured["ready_for_geometry_mutation"] is False
        assert measured["sparse_model_modified"] is False
        assert measured["dense_input_modified"] is False
        assert measured["fusion_enabled"] is False

    print("Result: PASS")


if __name__ == "__main__":
    main()
