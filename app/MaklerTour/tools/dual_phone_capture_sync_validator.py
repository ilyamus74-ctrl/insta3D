#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import math
import statistics
from pathlib import Path
from typing import Any, Iterable


def load_json(path: Path) -> dict[str, Any]:
    with path.open("r", encoding="utf-8") as handle:
        value = json.load(handle)
    if not isinstance(value, dict):
        raise ValueError(f"{path} must contain a JSON object")
    return value


def load_jsonl(path: Path, row_type: str) -> tuple[dict[str, Any], list[dict[str, Any]]]:
    metadata: dict[str, Any] = {}
    rows: list[dict[str, Any]] = []
    with path.open("r", encoding="utf-8") as handle:
        for line_number, raw in enumerate(handle, 1):
            raw = raw.strip()
            if not raw:
                continue
            try:
                value = json.loads(raw)
            except json.JSONDecodeError as error:
                raise ValueError(f"{path}:{line_number}: invalid JSON: {error}") from error
            if not isinstance(value, dict):
                raise ValueError(f"{path}:{line_number}: row must be an object")
            if value.get("type") == "metadata" and not metadata:
                metadata = value
            elif value.get("type") == row_type:
                rows.append(value)
    return metadata, rows


def percentile(values: list[float], p: float) -> float | None:
    if not values:
        return None
    ordered = sorted(values)
    if len(ordered) == 1:
        return ordered[0]
    position = (len(ordered) - 1) * p
    lower = math.floor(position)
    upper = math.ceil(position)
    if lower == upper:
        return ordered[lower]
    fraction = position - lower
    return ordered[lower] * (1.0 - fraction) + ordered[upper] * fraction


def number_or_none(value: Any) -> float | None:
    return float(value) if isinstance(value, (int, float)) else None


def int_or_none(value: Any) -> int | None:
    return int(value) if isinstance(value, int) else None


def pick_timeline(
    role_dir: Path,
    manifest: dict[str, Any],
    camera_info: dict[str, Any],
    frames: list[dict[str, Any]],
) -> tuple[str, list[int], str]:
    scheduled = int_or_none(manifest.get("scheduled_start_elapsed_ns"))
    if scheduled is None:
        raise ValueError(f"{role_dir}: manifest has no scheduled_start_elapsed_ns")

    timestamp_source = camera_info.get("sensor_timestamp_source_name")
    if timestamp_source == "REALTIME":
        sensor_values = [
            int(row["sensor_timestamp_ns"])
            for row in frames
            if isinstance(row.get("sensor_timestamp_ns"), int)
        ]
        if sensor_values:
            return (
                "sensor_timestamp_ns",
                [value - scheduled for value in sensor_values],
                "CAMERA2_REALTIME",
            )

    receive_values = [
        int(row["elapsed_realtime_receive_ns"])
        for row in frames
        if isinstance(row.get("elapsed_realtime_receive_ns"), int)
    ]
    if not receive_values:
        raise ValueError(f"{role_dir}: no usable frame timestamps")
    return (
        "elapsed_realtime_receive_ns",
        [value - scheduled for value in receive_values],
        "CALLBACK_RECEIVE_FALLBACK",
    )


def pair_timelines(
    master_ns: list[int],
    slave_ns: list[int],
    max_delta_ns: int,
) -> tuple[list[int], int, int]:
    master = sorted(master_ns)
    slave = sorted(slave_ns)
    pairs: list[int] = []
    i = 0
    j = 0
    while i < len(master) and j < len(slave):
        delta = slave[j] - master[i]
        if abs(delta) <= max_delta_ns:
            next_slave_delta = (
                slave[j + 1] - master[i]
                if j + 1 < len(slave)
                else None
            )
            next_master_delta = (
                slave[j] - master[i + 1]
                if i + 1 < len(master)
                else None
            )
            if (
                next_slave_delta is not None
                and abs(next_slave_delta) < abs(delta)
            ):
                j += 1
                continue
            if (
                next_master_delta is not None
                and abs(next_master_delta) < abs(delta)
            ):
                i += 1
                continue
            pairs.append(delta)
            i += 1
            j += 1
        elif delta < -max_delta_ns:
            j += 1
        else:
            i += 1
    return pairs, len(master) - len(pairs), len(slave) - len(pairs)


def timeline_fps(values_ns: list[int]) -> float | None:
    if len(values_ns) < 2:
        return None
    span = max(values_ns) - min(values_ns)
    if span <= 0:
        return None
    return (len(values_ns) - 1) * 1_000_000_000.0 / span


def validate_role_dir(role_dir: Path) -> dict[str, Any]:
    manifest_path = role_dir / "dual_capture_manifest.json"
    frames_path = role_dir / "frames.jsonl"
    pts_path = role_dir / "encoder_pts.jsonl"
    camera_info_path = role_dir / "camera_info.json"
    imu_path = role_dir / "imu.jsonl"
    clock_path = role_dir / "clock_sync.json"

    for required in (manifest_path, frames_path, camera_info_path, clock_path):
        if not required.is_file() or required.stat().st_size <= 0:
            raise ValueError(f"required file missing or empty: {required}")

    manifest = load_json(manifest_path)
    camera_info = load_json(camera_info_path)
    frame_metadata, frames = load_jsonl(frames_path, "frame")
    pts_metadata: dict[str, Any] = {}
    samples: list[dict[str, Any]] = []
    if pts_path.is_file() and pts_path.stat().st_size > 0:
        pts_metadata, samples = load_jsonl(pts_path, "sample")

    timeline_field, relative_ns, timeline_quality = pick_timeline(
        role_dir,
        manifest,
        camera_info,
        frames,
    )
    pts_values = [
        int(row["pts_us"])
        for row in samples
        if isinstance(row.get("pts_us"), int)
    ]
    return {
        "role_dir": str(role_dir),
        "manifest": manifest,
        "camera_info": camera_info,
        "frame_metadata": frame_metadata,
        "pts_metadata": pts_metadata,
        "frames": frames,
        "relative_ns": relative_ns,
        "timeline_field": timeline_field,
        "timeline_quality": timeline_quality,
        "pts_us": pts_values,
        "imu_present": imu_path.is_file() and imu_path.stat().st_size > 0,
    }


def summarize_role(role: dict[str, Any]) -> dict[str, Any]:
    manifest = role["manifest"]
    relative_ns: list[int] = role["relative_ns"]
    pts_us: list[int] = role["pts_us"]
    return {
        "role": manifest.get("role"),
        "device_id": manifest.get("device_id"),
        "camera_id": manifest.get("camera_id"),
        "video_mode_id": manifest.get("video_mode_id"),
        "captured": manifest.get("captured"),
        "timeline_field": role["timeline_field"],
        "timeline_quality": role["timeline_quality"],
        "sensor_timestamp_source_name": role["camera_info"].get(
            "sensor_timestamp_source_name"
        ),
        "capture_result_count": len(relative_ns),
        "capture_result_fps": timeline_fps(relative_ns),
        "encoded_sample_count": len(pts_us),
        "encoded_sample_fps": (
            (len(pts_us) - 1) * 1_000_000.0 / (max(pts_us) - min(pts_us))
            if len(pts_us) > 1 and max(pts_us) > min(pts_us)
            else None
        ),
        "capture_result_vs_encoded_count_delta": len(relative_ns) - len(pts_us),
        "imu_present": role["imu_present"],
        "recorded_duration_ns": manifest.get("recorded_duration_ns"),
        "file_size_bytes": manifest.get("file_size_bytes"),
    }


def validate_capture(
    master_dir: Path,
    slave_dir: Path,
    max_pair_delta_ms: float,
) -> dict[str, Any]:
    master = validate_role_dir(master_dir)
    slave = validate_role_dir(slave_dir)
    master_manifest = master["manifest"]
    slave_manifest = slave["manifest"]

    master_id = master_manifest.get("dual_capture_id")
    slave_id = slave_manifest.get("dual_capture_id")
    if not master_id or master_id != slave_id:
        raise ValueError(
            f"dual_capture_id mismatch: master={master_id!r} slave={slave_id!r}"
        )
    if master_manifest.get("role") != "MASTER":
        raise ValueError("master manifest role must be MASTER")
    if slave_manifest.get("role") != "SLAVE":
        raise ValueError("slave manifest role must be SLAVE")

    max_delta_ns = int(max_pair_delta_ms * 1_000_000.0)
    deltas_ns, unmatched_master, unmatched_slave = pair_timelines(
        master["relative_ns"],
        slave["relative_ns"],
        max_delta_ns,
    )
    signed_ms = [value / 1_000_000.0 for value in deltas_ns]
    absolute_ms = [abs(value) for value in signed_ms]
    matched = len(deltas_ns)
    total_reference = max(
        len(master["relative_ns"]),
        len(slave["relative_ns"]),
        1,
    )
    master_start_call = int_or_none(master_manifest.get("start_call_elapsed_ns"))
    master_scheduled = int_or_none(
        master_manifest.get("scheduled_start_elapsed_ns")
    )
    slave_start_call = int_or_none(slave_manifest.get("start_call_elapsed_ns"))
    slave_scheduled = int_or_none(
        slave_manifest.get("scheduled_start_elapsed_ns")
    )
    start_call_skew_ms = None
    if None not in (
        master_start_call,
        master_scheduled,
        slave_start_call,
        slave_scheduled,
    ):
        start_call_skew_ms = (
            (slave_start_call - slave_scheduled)
            - (master_start_call - master_scheduled)
        ) / 1_000_000.0

    quality = "NOT_READY"
    if matched >= 3:
        p95 = percentile(absolute_ms, 0.95)
        maximum = max(absolute_ms) if absolute_ms else None
        match_ratio = matched / total_reference
        if p95 is not None and p95 <= 8.0 and match_ratio >= 0.90:
            quality = "GOOD"
        elif p95 is not None and p95 <= max_pair_delta_ms and match_ratio >= 0.70:
            quality = "FAIR"
        else:
            quality = "POOR"

    return {
        "schema_version": 1,
        "dual_capture_id": master_id,
        "status": quality,
        "pairing_method": "NEAREST_RELATIVE_TO_SCHEDULED_START",
        "max_pair_delta_ms": max_pair_delta_ms,
        "mapping_limitations": [
            "Camera2 capture-result timestamps and MP4 sample PTS are separate timelines.",
            "Exact capture-result-to-encoded-frame mapping is not yet proven.",
            "CALLBACK_RECEIVE_FALLBACK includes pipeline and scheduler latency.",
        ],
        "master": summarize_role(master),
        "slave": summarize_role(slave),
        "pairing": {
            "matched_count": matched,
            "unmatched_master_count": unmatched_master,
            "unmatched_slave_count": unmatched_slave,
            "match_ratio": matched / total_reference,
            "signed_delta_median_ms": (
                statistics.median(signed_ms) if signed_ms else None
            ),
            "absolute_delta_median_ms": (
                statistics.median(absolute_ms) if absolute_ms else None
            ),
            "absolute_delta_p95_ms": percentile(absolute_ms, 0.95),
            "absolute_delta_max_ms": max(absolute_ms) if absolute_ms else None,
            "start_call_relative_skew_ms": start_call_skew_ms,
        },
    }


def main() -> int:
    parser = argparse.ArgumentParser(
        description=(
            "Validate two local DP04.2 dual-phone capture members before upload"
        )
    )
    parser.add_argument("master_dir", type=Path)
    parser.add_argument("slave_dir", type=Path)
    parser.add_argument("--output", type=Path)
    parser.add_argument("--max-pair-delta-ms", type=float, default=25.0)
    args = parser.parse_args()

    report = validate_capture(
        args.master_dir,
        args.slave_dir,
        args.max_pair_delta_ms,
    )
    rendered = json.dumps(report, indent=2, sort_keys=True)
    if args.output:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(rendered + "\n", encoding="utf-8")
    print(rendered)
    return 0 if report["status"] in {"GOOD", "FAIR"} else 2


if __name__ == "__main__":
    raise SystemExit(main())
