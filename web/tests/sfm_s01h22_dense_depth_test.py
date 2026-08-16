#!/usr/bin/env python3
import json
import os
import subprocess
import sys
import tempfile
from pathlib import Path

import numpy as np

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "web/remote_station/scripts/measure_tof_dense_depth_h22.py"


def write_json(path, value):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(value, indent=2), encoding="utf-8")


def write_jsonl(path, rows):
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8") as handle:
        for row in rows:
            handle.write(json.dumps(row) + "\n")


def write_colmap_depth(path, array):
    path.parent.mkdir(parents=True, exist_ok=True)
    data = np.asarray(array, dtype=np.float32)
    if data.ndim == 2:
        data = data[:, :, None]
    height, width, channels = data.shape
    encoded = np.transpose(data, (1, 0, 2)).reshape(-1, order="F")
    with path.open("wb") as handle:
        handle.write(f"{width}&{height}&{channels}&".encode("ascii"))
        encoded.astype(np.float32).tofile(handle)


def fixture(root, with_tof=True, with_dense=True):
    observations = root / "observations.jsonl"
    h1 = root / "h1.json"
    calibration = root / "calibration.json"
    dense = root / "dense_job"
    output = root / "h22.jsonl"
    report = root / "h22.json"

    if with_tof:
        rows = []
        for index in range(40):
            rows.append({
                "type": "tof_metric_observation",
                "schema_version": 1,
                "stage": "SFM-S01H1",
                "image": "frame_000001.jpg",
                "video_timestamp_sec": float(index) / 10.0,
                "camera_frame_index": index,
                "tof_sequence": index,
                "tof_slot": 0,
                "zone_index": 27,
                "row": 3,
                "column": 3,
                "distance_mm": 1000.0,
                "sigma_mm": 5.0,
                "target_status": 5,
                "camera_xyz_mm": [-62.5, -62.5, 1000.0],
            })
        write_jsonl(observations, rows)
        write_json(h1, {
            "status": "MEASURED",
            "measurement_gate_pass": True,
            "ready_for_sparse_scale_measurement": True,
            "profile": {
                "rig_id": "rig",
                "rig_mount_revision": "rev-a",
                "camera_calibration_profile_id": "profile",
                "tof_slot": 0,
            },
        })
    else:
        write_jsonl(observations, [])
        write_json(h1, {
            "status": "SKIPPED_NO_TOF",
            "measurement_gate_pass": False,
            "ready_for_sparse_scale_measurement": False,
        })

    write_json(calibration, {
        "profiles": [{
            "status": "solved",
            "rig_id": "rig",
            "rig_mount_revision": "rev-a",
            "camera_calibration_profile_id": "profile",
            "tof_slot": 0,
            "tof_width": 8,
            "tof_height": 8,
            "tof_intrinsics": {
                "fx_zones": 8.0,
                "fy_zones": 8.0,
                "cx_zones": 3.5,
                "cy_zones": 3.5,
            },
            "rotation_tof_to_camera": [1,0,0,0,1,0,0,0,1],
            "translation_tof_to_camera_mm": [0,0,0],
        }],
    })

    if with_dense:
        chunk = dense / "chunks/chunk_0"
        model = chunk / "workspace_model_text"
        model.mkdir(parents=True, exist_ok=True)
        (model / "cameras.txt").write_text(
            "# Camera list\n1 SIMPLE_PINHOLE 100 100 100 50 50\n",
            encoding="utf-8",
        )
        (model / "images.txt").write_text(
            "# Image list\n"
            "1 1 0 0 0 0 0 0 1 frame_000001.jpg\n"
            "\n",
            encoding="utf-8",
        )
        depth = np.full((100, 100), 2.0, dtype=np.float32)
        depth_dir = chunk / "undistorted/stereo/depth_maps"
        write_colmap_depth(
            depth_dir / "frame_000001.jpg.geometric.bin", depth
        )
        write_colmap_depth(
            depth_dir / "frame_000001.jpg.photometric.bin", depth
        )

    return {
        "observations": observations,
        "h1": h1,
        "calibration": calibration,
        "dense": dense,
        "output": output,
        "report": report,
    }


def run_case(paths):
    subprocess.run([
        sys.executable,
        str(SCRIPT),
        "--observations", str(paths["observations"]),
        "--observation-report", str(paths["h1"]),
        "--tof-calibration", str(paths["calibration"]),
        "--dense-job-dir", str(paths["dense"]),
        "--output-jsonl", str(paths["output"]),
        "--report-json", str(paths["report"]),
    ], check=True)
    return json.loads(paths["report"].read_text(encoding="utf-8"))


def main():
    with tempfile.TemporaryDirectory() as tmp:
        base = Path(tmp)

        no_tof = run_case(fixture(base / "no_tof", with_tof=False))
        assert no_tof["status"] == "SKIPPED_NO_TOF_MEASUREMENT"
        assert no_tof["geometry_mutation_enabled"] is False

        no_dense = run_case(fixture(base / "no_dense", with_dense=False))
        assert no_dense["status"] == "SKIPPED_DENSE_UNAVAILABLE"
        assert no_dense["dense_input_modified"] is False

        measured = run_case(fixture(base / "measured"))
        assert measured["status"] == "MEASURED"
        assert measured["metric_policy"]["beyond_4m"] == "APPROXIMATE_ONLY"
        assert measured["metric_policy"]["tof_extrapolation_beyond_range"] is False

        for strategy in (
            "geometric_center",
            "geometric_footprint_p25",
            "geometric_footprint_p50",
            "geometric_footprint_p75",
            "geometric_footprint_front_cluster",
            "photometric_center",
            "photometric_footprint_p50",
        ):
            summary = measured["strategies"][strategy]
            assert summary["candidate_count"] == 40, (strategy, summary)
            assert abs(
                summary["scale"]["robust_mm_per_colmap_unit"] - 500.0
            ) < 1e-9, (strategy, summary)
            assert summary["residuals"]["inlier_depth_error_p95_mm"] == 0.0

        reference = measured["strategies"]["geometric_footprint_p50"]
        assert "distance" in reference["decomposition"]
        assert "zone_row" in reference["decomposition"]
        assert "zone_column" in reference["decomposition"]
        assert "zone_radial" in reference["decomposition"]
        assert "sigma" in reference["decomposition"]
        assert "time_quartile" in reference["decomposition"]
        assert "image_region" in reference["decomposition"]

        assert measured["ready_for_geometry_mutation"] is False
        assert measured["dense_depth_modified"] is False
        assert measured["fusion_enabled"] is False

    print("Result: PASS")


if __name__ == "__main__":
    main()
