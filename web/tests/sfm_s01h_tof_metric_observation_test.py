#!/usr/bin/env python3
import json
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "web/remote_station/scripts/build_tof_metric_observations.py"


def write_json(path, value):
    Path(path).write_text(json.dumps(value, indent=2), encoding="utf-8")


def write_jsonl(path, rows):
    with Path(path).open("w", encoding="utf-8") as handle:
        for row in rows:
            handle.write(json.dumps(row) + "\n")


def run_case(root, with_tof):
    root.mkdir(parents=True, exist_ok=True)
    assoc = root / "assoc.jsonl"
    assoc_report = root / "assoc_report.json"
    tof = root / "tof.jsonl"
    calib = root / "calib.json"
    out = root / "observations.jsonl"
    report = root / "report.json"

    if with_tof:
        write_jsonl(assoc, [{
            "type": "selected_sensor_association",
            "image": "frame_000001.jpg",
            "video_timestamp_sec": 1.0,
            "camera_frame_index": 60,
            "tof": {
                "accepted": True,
                "raw_frame_found": True,
                "sequence": 10,
                "slot": 0,
            },
        }])
        write_json(assoc_report, {
            "temporal_candidate_pass": True,
            "tof": {
                "raw_sidecar_available": True,
                "selected_with_accepted_pair": 1,
                "selected_with_accepted_pair_and_raw_frame": 1,
            },
            "tof_calibration": {
                "binding_status": "MATCHED_CAPTURE_IDENTITY",
                "identity_match": True,
                "matching_profile_count": 1,
                "observed_tof_slots": [0],
            },
        })
        distances = [1000] * 64
        sigmas = [10] * 64
        statuses = [5] * 64
        targets = [1] * 64
        statuses[1] = 255
        sigmas[2] = 200
        distances[3] = 50
        write_jsonl(tof, [
            {"type": "metadata"},
            {
                "type": "tof_frame",
                "sequence": 10,
                "slot": 0,
                "width": 8,
                "height": 8,
                "distance_mm": distances,
                "sigma_mm": sigmas,
                "target_status": statuses,
                "nb_target_detected": targets,
            },
        ])
        write_json(calib, {
            "capture_identity": {
                "device_id": "device",
                "rig_id": "rig",
                "rig_mount_revision": "rev-a",
                "selected_camera_id": "0",
                "active_calibration_profile_id": "profile",
            },
            "profiles": [{
                "status": "solved",
                "rig_id": "rig",
                "rig_mount_revision": "rev-a",
                "master_device_id": "device",
                "master_camera_id": "0",
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
    else:
        write_jsonl(assoc, [])
        write_json(assoc_report, {
            "temporal_candidate_pass": True,
            "tof": {
                "raw_sidecar_available": False,
                "selected_with_accepted_pair": 0,
                "selected_with_accepted_pair_and_raw_frame": 0,
            },
            "tof_calibration": {},
        })

    subprocess.run([
        sys.executable,
        str(SCRIPT),
        "--associations", str(assoc),
        "--association-report", str(assoc_report),
        "--tof-frames", str(tof),
        "--tof-calibration", str(calib),
        "--output-jsonl", str(out),
        "--output-report", str(report),
    ], check=True)

    return json.loads(report.read_text(encoding="utf-8"))


def main():
    with tempfile.TemporaryDirectory() as tmp:
        base = Path(tmp)

        no_tof = run_case(base / "no_tof", False)
        assert no_tof["status"] == "SKIPPED_NO_TOF"
        assert no_tof["geometry_mutation_enabled"] is False
        assert no_tof["fusion_enabled"] is False

        measured_root = base / "measured"
        measured = run_case(measured_root, True)
        assert measured["status"] == "MEASURED"
        assert measured["metric_observation_count"] == 61
        assert measured["ready_for_sparse_scale_measurement"] is True
        assert measured["ready_for_geometry_mutation"] is False
        assert measured["rejected_zones"]["invalid_status"] == 1
        assert measured["rejected_zones"]["sigma_too_high"] == 1
        assert measured["rejected_zones"]["distance_out_of_range"] == 1

        rows = [
            json.loads(line)
            for line in (measured_root / "observations.jsonl")
                .read_text(encoding="utf-8").splitlines()
        ]
        obs = next(
            row for row in rows
            if row.get("type") == "tof_metric_observation"
        )
        assert obs["tof_xyz_mm"][2] == 1000.0
        assert obs["camera_xyz_mm"][2] == 1000.0

    print("Result: PASS")


if __name__ == "__main__":
    main()
