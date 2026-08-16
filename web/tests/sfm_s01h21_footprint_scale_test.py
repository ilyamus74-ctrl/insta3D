#!/usr/bin/env python3
import json
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = (
    ROOT
    / "web/remote_station/scripts/measure_tof_sparse_scale_h21.py"
)


def write_json(path, value):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(
        json.dumps(value, indent=2),
        encoding="utf-8",
    )


def write_jsonl(path, rows):
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8") as handle:
        for row in rows:
            handle.write(json.dumps(row) + "\n")


def build_fixture(root, with_tof=True):
    observations = root / "observations.jsonl"
    h1_report = root / "h1.json"
    calibration = root / "calibration.json"
    model = root / "model"
    output = root / "out.jsonl"
    report = root / "report.json"

    model.mkdir(parents=True, exist_ok=True)

    (model / "cameras.txt").write_text(
        "# Camera list\n"
        "1 SIMPLE_PINHOLE 100 100 100 50 50\n",
        encoding="utf-8",
    )

    # Zone row=3/col=3 projects approximately to x/y 37.5..50.0.
    feature_rows = [
        (42.0, 42.0, 1),
        (46.0, 42.0, 2),
        (42.0, 46.0, 3),
        (46.0, 46.0, 4),
    ]
    points2d = " ".join(
        f"{x} {y} {point_id}"
        for x, y, point_id in feature_rows
    )
    (model / "images.txt").write_text(
        "# Image list\n"
        "1 1 0 0 0 0 0 0 1 frame_000001.jpg\n"
        + points2d
        + "\n",
        encoding="utf-8",
    )
    (model / "points3D.txt").write_text(
        "# 3D point list\n"
        "1 -0.16 -0.16 2 255 255 255 0.1 1 0\n"
        "2 -0.08 -0.16 2 255 255 255 0.1 1 1\n"
        "3 -0.16 -0.08 2 255 255 255 0.1 1 2\n"
        "4 -0.08 -0.08 2 255 255 255 0.1 1 3\n",
        encoding="utf-8",
    )

    if with_tof:
        write_jsonl(observations, [{
            "type": "tof_metric_observation",
            "schema_version": 1,
            "stage": "SFM-S01H1",
            "image": "frame_000001.jpg",
            "tof_sequence": 1,
            "tof_slot": 0,
            "zone_index": 27,
            "row": 3,
            "column": 3,
            "distance_mm": 1000.0,
            "sigma_mm": 5.0,
            "target_status": 5,
            "camera_xyz_mm": [
                -62.5,
                -62.5,
                1000.0,
            ],
        }])
        write_json(h1_report, {
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
        write_json(h1_report, {
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
            "rotation_tof_to_camera": [
                1, 0, 0,
                0, 1, 0,
                0, 0, 1,
            ],
            "translation_tof_to_camera_mm": [0, 0, 0],
        }],
    })

    return {
        "observations": observations,
        "h1_report": h1_report,
        "calibration": calibration,
        "model": model,
        "output": output,
        "report": report,
    }


def run_case(paths):
    subprocess.run([
        sys.executable,
        str(SCRIPT),
        "--observations", str(paths["observations"]),
        "--observation-report", str(paths["h1_report"]),
        "--tof-calibration", str(paths["calibration"]),
        "--model-dir", str(paths["model"]),
        "--output-jsonl", str(paths["output"]),
        "--report-json", str(paths["report"]),
        "--nearest-radii", "4,8,12,24",
        "--minimum-footprint-points", "2",
    ], check=True)

    return json.loads(
        paths["report"].read_text(encoding="utf-8")
    )


def main():
    with tempfile.TemporaryDirectory() as tmp:
        base = Path(tmp)

        skipped = run_case(
            build_fixture(base / "no_tof", with_tof=False)
        )
        assert skipped["status"] == "SKIPPED_NO_TOF_MEASUREMENT"
        assert skipped["geometry_mutation_enabled"] is False
        assert skipped["fusion_enabled"] is False

        measured = run_case(
            build_fixture(base / "measured", with_tof=True)
        )
        assert measured["status"] == "MEASURED"
        assert measured["registered_tof_observation_count"] == 1

        nearest = measured["strategies"]["nearest_24px"]
        assert nearest["candidate_count"] == 1
        assert abs(
            nearest["scale"]["robust_mm_per_colmap_unit"]
            - 500.0
        ) < 1e-9

        footprint = measured["strategies"]["footprint_median_all"]
        assert footprint["candidate_count"] == 1
        assert abs(
            footprint["scale"]["robust_mm_per_colmap_unit"]
            - 500.0
        ) < 1e-9

        front = measured["strategies"]["footprint_front_cluster"]
        assert front["candidate_count"] == 1
        assert abs(
            front["scale"]["robust_mm_per_colmap_unit"]
            - 500.0
        ) < 1e-9

        assert measured["ready_for_geometry_mutation"] is False
        assert measured["sparse_model_modified"] is False
        assert measured["points3d_modified"] is False
        assert measured["dense_input_modified"] is False
        assert measured["fusion_enabled"] is False

    print("Result: PASS")


if __name__ == "__main__":
    main()
