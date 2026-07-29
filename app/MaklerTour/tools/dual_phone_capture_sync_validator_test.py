#!/usr/bin/env python3
from __future__ import annotations

import json
import tempfile
from pathlib import Path

from dual_phone_capture_sync_validator import validate_capture


def write_json(path: Path, value: dict) -> None:
    path.write_text(json.dumps(value, indent=2) + "\n", encoding="utf-8")


def write_jsonl(path: Path, values: list[dict]) -> None:
    path.write_text(
        "".join(json.dumps(value) + "\n" for value in values),
        encoding="utf-8",
    )


def make_role(
    root: Path,
    role: str,
    capture_id: str,
    scheduled_ns: int,
    frame_offsets_ns: list[int],
) -> Path:
    role_dir = root / role.lower()
    role_dir.mkdir(parents=True)
    write_json(
        role_dir / "dual_capture_manifest.json",
        {
            "schema_version": 2,
            "dual_capture_id": capture_id,
            "role": role,
            "device_id": role.lower() + "-device",
            "camera_id": "0",
            "video_mode_id": "1920x1080@60",
            "scheduled_start_elapsed_ns": scheduled_ns,
            "start_call_elapsed_ns": scheduled_ns + 2_000_000,
            "captured": True,
            "recorded_duration_ns": 1_000_000_000,
            "file_size_bytes": 1000,
        },
    )
    write_json(
        role_dir / "camera_info.json",
        {"sensor_timestamp_source_name": "REALTIME"},
    )
    write_json(
        role_dir / "clock_sync.json",
        {
            "schema_version": 1,
            "dual_capture_id": capture_id,
            "role": role,
            "scheduled_start_elapsed_ns": scheduled_ns,
        },
    )
    write_jsonl(
        role_dir / "frames.jsonl",
        [
            {"type": "metadata", "schema_version": 1},
            *[
                {
                    "type": "frame",
                    "frame_index": index,
                    "sensor_timestamp_ns": scheduled_ns + offset,
                    "elapsed_realtime_receive_ns": scheduled_ns + offset + 4_000_000,
                }
                for index, offset in enumerate(frame_offsets_ns)
            ],
        ],
    )
    write_jsonl(
        role_dir / "encoder_pts.jsonl",
        [
            {"type": "metadata", "schema_version": 1},
            *[
                {"type": "sample", "sample_index": index, "pts_us": index * 16667}
                for index in range(len(frame_offsets_ns))
            ],
        ],
    )
    (role_dir / "imu.jsonl").write_text(
        '{"type":"metadata","schema_version":2}\n',
        encoding="utf-8",
    )
    return role_dir


def main() -> None:
    with tempfile.TemporaryDirectory() as temporary:
        root = Path(temporary)
        capture_id = "dp04-2-test-capture"
        master = make_role(
            root,
            "MASTER",
            capture_id,
            1_000_000_000,
            [0, 16_666_667, 33_333_334, 50_000_001, 66_666_668, 83_333_335],
        )
        slave = make_role(
            root,
            "SLAVE",
            capture_id,
            9_000_000_000,
            [2_000_000, 18_666_667, 35_333_334, 52_000_001, 68_666_668, 85_333_335],
        )
        report = validate_capture(master, slave, 25.0)
        assert report["status"] == "GOOD", report
        assert report["pairing"]["matched_count"] == 6, report
        assert report["pairing"]["absolute_delta_median_ms"] == 2.0, report
        assert report["master"]["imu_present"] is True, report
        assert report["slave"]["encoded_sample_count"] == 6, report
    print("OK")


if __name__ == "__main__":
    main()
