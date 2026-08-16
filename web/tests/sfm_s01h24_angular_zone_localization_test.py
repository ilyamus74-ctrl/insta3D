#!/usr/bin/env python3
import json
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "web/remote_station/scripts/analyze_tof_dense_zone_h24.py"


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
        root = Path(tmp)
        observations = root / "observations.jsonl"
        observation_report = root / "observation_report.json"
        calibration = root / "tof_calibration.json"
        h22_candidates = root / "h22_candidates.jsonl"
        h22_report = root / "h22_report.json"
        h23_rows = root / "h23_rows.jsonl"
        h23_report = root / "h23_report.json"
        sparse = root / "sparse"
        output = root / "h24_rows.jsonl"
        report_path = root / "h24_report.json"

        write_json(observation_report, {
            "status": "MEASURED",
            "measurement_gate_pass": True,
            "profile": {
                "camera_calibration_profile_id": "fixture-profile",
                "tof_slot": 0,
                "rig_id": "fixture-rig",
                "rig_mount_revision": "rev-a",
            },
        })
        write_json(calibration, {
            "profiles": [{
                "status": "solved",
                "camera_calibration_profile_id": "fixture-profile",
                "tof_slot": 0,
                "rig_id": "fixture-rig",
                "rig_mount_revision": "rev-a",
                "tof_width": 8,
                "tof_height": 8,
                "tof_intrinsics": {
                    "fx_zones": 8.0,
                    "fy_zones": 8.0,
                    "cx_zones": 3.5,
                    "cy_zones": 3.5,
                },
                "rotation_tof_to_camera": [
                    1.0, 0.0, 0.0,
                    0.0, 1.0, 0.0,
                    0.0, 0.0, 1.0,
                ],
                "translation_tof_to_camera_mm": [0.0, 0.0, 0.0],
            }]
        })

        sparse.mkdir(parents=True, exist_ok=True)
        (sparse / "cameras.txt").write_text(
            "# Camera list\n"
            "1 SIMPLE_RADIAL 1080 1920 1300 540 960 0.01\n",
            encoding="utf-8",
        )

        obs_rows = []
        h22_rows = []
        decomp_rows = []
        sequence = 1

        for repeat in range(4):
            for row in range(8):
                for column in range(8):
                    zone = row * 8 + column
                    distance = 700.0 + repeat * 400.0
                    x = distance * ((column - 3.5) / 8.0)
                    y = distance * ((row - 3.5) / 8.0)
                    image = f"frame_{repeat:06d}.jpg"
                    key = {
                        "image": image,
                        "tof_sequence": sequence,
                        "zone_index": zone,
                    }
                    observation = {
                        "type": "tof_metric_observation",
                        **key,
                        "tof_slot": 0,
                        "row": row,
                        "column": column,
                        "distance_mm": distance,
                        "sigma_mm": 5.0,
                        "target_status": 5,
                        "camera_xyz_mm": [x, y, distance],
                    }
                    ratio = (
                        1.0
                        + (row - 3.5) * 0.025
                        - (column - 3.5) * 0.020
                    )
                    candidate = {
                        "type": "tof_dense_h22_candidate",
                        "stage": "SFM-S01H2.2",
                        "strategy": "geometric_footprint_p50",
                        **key,
                        "zone_row": row,
                        "zone_column": column,
                        "distance_mm": distance,
                        "scale_mm_per_colmap_unit": 200.0 * ratio,
                    }
                    decomposition = {
                        **candidate,
                        "type": "tof_dense_h23_decomposition",
                        "stage": "SFM-S01H2.3",
                        "strategy": "geometric_footprint_p50",
                        "distance_normalized_ratio": ratio,
                        "expected_scale_from_distance": 200.0,
                    }
                    obs_rows.append(observation)
                    h22_rows.append(candidate)
                    decomp_rows.append(decomposition)
                    sequence += 1

        write_jsonl(observations, obs_rows)
        write_jsonl(h22_candidates, h22_rows)
        write_jsonl(h23_rows, decomp_rows)
        write_json(h22_report, {
            "status": "MEASURED",
            "strategies": {
                "geometric_footprint_p50": {
                    "candidate_count": len(h22_rows)
                }
            },
        })
        write_json(h23_report, {
            "status": "MEASURED",
            "controlled_signals": {
                "distance_effect_persists_after_zone_normalization": True,
                "zone_row_effect_persists_after_distance_normalization": True,
                "zone_column_effect_persists_after_distance_normalization": True,
            },
        })

        subprocess.run([
            sys.executable,
            str(SCRIPT),
            "--observations", str(observations),
            "--observation-report", str(observation_report),
            "--tof-calibration", str(calibration),
            "--h22-candidates", str(h22_candidates),
            "--h22-report", str(h22_report),
            "--h23-decomposition", str(h23_rows),
            "--h23-report", str(h23_report),
            "--sparse-model-dir", str(sparse),
            "--strategy", "geometric_footprint_p50",
            "--minimum-zone-count", "2",
            "--minimum-distance-count", "2",
            "--output-jsonl", str(output),
            "--report-json", str(report_path),
        ], check=True)

        report = json.loads(report_path.read_text(encoding="utf-8"))
        assert report["status"] == "MEASURED"
        assert report["input_counts"]["joined_localized_rows"] == 256
        assert len(report["signed_localization"]["per_zone"]) == 64
        assert len(
            report["signed_localization"][
                "zone_grid_signed_ratio_residual_p50"
            ]
        ) == 8
        assert report["signed_localization"]["zone_row_ratio_spread"] > 1.10
        assert report["signed_localization"]["zone_column_ratio_spread"] > 1.10
        assert report["active_perturbation_sensitivity"]["status"] == (
            "SKIPPED_DENSE_UNAVAILABLE"
        )
        assert report["decision"]["classification"] in {
            "RGB_IMAGE_REGION_PATTERN_SUPPORTED",
            "INSUFFICIENT_SUPPORT",
        }
        assert report["measurement_only"] is True
        assert report["calibration_mutation_enabled"] is False
        assert report["geometry_mutation_enabled"] is False
        assert report["ready_for_geometry_mutation"] is False
        assert report["dense_input_modified"] is False
        assert report["dense_depth_modified"] is False
        assert report["fusion_enabled"] is False

        lines = output.read_text(encoding="utf-8").splitlines()
        assert len(lines) == 257
        first = json.loads(lines[0])
        assert first["type"] == "metadata"
        assert first["calibration_mutation_enabled"] is False

    print("Result: PASS")


if __name__ == "__main__":
    main()
