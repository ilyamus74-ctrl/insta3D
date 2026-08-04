#!/usr/bin/env python3
"""Select the best COLMAP model and export a metric dual-phone trajectory."""

from __future__ import annotations

import argparse
import json
import math
import statistics
from pathlib import Path
from typing import Any


def quaternion_rotation(qw: float, qx: float, qy: float, qz: float) -> list[list[float]]:
    norm = math.sqrt(qw * qw + qx * qx + qy * qy + qz * qz)
    if norm <= 1e-12:
        raise ValueError("invalid zero quaternion")
    qw, qx, qy, qz = (value / norm for value in (qw, qx, qy, qz))
    return [
        [1 - 2 * (qy * qy + qz * qz), 2 * (qx * qy - qz * qw), 2 * (qx * qz + qy * qw)],
        [2 * (qx * qy + qz * qw), 1 - 2 * (qx * qx + qz * qz), 2 * (qy * qz - qx * qw)],
        [2 * (qx * qz - qy * qw), 2 * (qy * qz + qx * qw), 1 - 2 * (qx * qx + qy * qy)],
    ]


def camera_center(qvec: list[float], tvec: list[float]) -> list[float]:
    rotation = quaternion_rotation(*qvec)
    return [
        -sum(rotation[row][column] * tvec[row] for row in range(3))
        for column in range(3)
    ]


def parse_images(path: Path) -> list[dict[str, Any]]:
    lines = path.read_text(encoding="utf-8").splitlines()
    result: list[dict[str, Any]] = []
    index = 0
    while index < len(lines):
        line = lines[index].strip()
        index += 1
        if not line or line.startswith("#"):
            continue
        parts = line.split()
        if len(parts) < 10:
            continue
        image_id = int(parts[0])
        qvec = [float(value) for value in parts[1:5]]
        tvec = [float(value) for value in parts[5:8]]
        camera_id = int(parts[8])
        name = " ".join(parts[9:])
        result.append(
            {
                "image_id": image_id,
                "camera_id": camera_id,
                "name": name,
                "qvec": qvec,
                "tvec": tvec,
                "position_m": camera_center(qvec, tvec),
            }
        )
        if index < len(lines):
            index += 1  # POINTS2D line
    return result


def distance(first: list[float], second: list[float]) -> float:
    return math.sqrt(sum((first[i] - second[i]) ** 2 for i in range(3)))


def path_length(samples: list[dict[str, Any]]) -> float:
    return sum(
        distance(samples[index - 1]["position_m"], samples[index]["position_m"])
        for index in range(1, len(samples))
    )


def read_live_path(session: Path) -> float | None:
    path = session / "camera_trajectory.json"
    if not path.is_file():
        return None
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return None
    samples = (
        value.get("trajectory", value.get("samples", value))
        if isinstance(value, dict)
        else value
    )
    if not isinstance(samples, list):
        return None
    positions = [
        item.get("position_m")
        for item in samples
        if isinstance(item, dict)
        and isinstance(item.get("position_m"), list)
        and len(item["position_m"]) == 3
    ]
    if len(positions) < 2:
        return 0.0
    return sum(distance(positions[index - 1], positions[index]) for index in range(1, len(positions)))


def trajectory_ply(samples: list[dict[str, Any]]) -> str:
    edges = max(0, len(samples) - 1)
    lines = [
        "ply",
        "format ascii 1.0",
        "comment LM02.7B.5.3.1 offline COLMAP rig CAMERA_A trajectory",
        f"element vertex {len(samples)}",
        "property float x",
        "property float y",
        "property float z",
        "property uchar red",
        "property uchar green",
        "property uchar blue",
        f"element edge {edges}",
        "property int vertex1",
        "property int vertex2",
        "end_header",
    ]
    for sample in samples:
        x, y, z = sample["position_m"]
        lines.append(f"{x:.9f} {y:.9f} {z:.9f} 0 255 255")
    for index in range(edges):
        lines.append(f"{index} {index + 1}")
    return "\n".join(lines) + "\n"


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("workspace", type=Path)
    parser.add_argument("session_dir", type=Path)
    parser.add_argument("--known-path-length-m", type=float, default=0.0)
    args = parser.parse_args()

    workspace = args.workspace.resolve()
    session = args.session_dir.resolve()
    models: list[tuple[int, Path, list[dict[str, Any]]]] = []
    for directory in sorted((workspace / "sparse_txt").glob("*")):
        images_path = directory / "images.txt"
        if not images_path.is_file():
            continue
        images = parse_images(images_path)
        camera_a_count = sum(image["name"].startswith("CAMERA_A/") for image in images)
        models.append((camera_a_count, directory, images))
    if not models:
        raise SystemExit("no converted sparse COLMAP models were found")
    models.sort(key=lambda item: (item[0], len(item[2])), reverse=True)
    _, selected_dir, images = models[0]

    camera_a = sorted(
        (image for image in images if image["name"].startswith("CAMERA_A/")),
        key=lambda image: image["name"],
    )
    camera_b_by_stem = {
        Path(image["name"]).stem: image
        for image in images
        if image["name"].startswith("CAMERA_B/")
    }
    if len(camera_a) < 2:
        raise SystemExit("selected COLMAP model registered fewer than two CAMERA_A images")

    baseline_samples = []
    for image_a in camera_a:
        image_b = camera_b_by_stem.get(Path(image_a["name"]).stem)
        if image_b:
            baseline_samples.append(distance(image_a["position_m"], image_b["position_m"]))

    colmap_length = path_length(camera_a)
    start_end = distance(camera_a[0]["position_m"], camera_a[-1]["position_m"])
    live_length = read_live_path(session)
    known_ratio = (
        colmap_length / args.known_path_length_m
        if args.known_path_length_m > 0.0
        else None
    )
    model_id = selected_dir.name
    trajectory = {
        "schema_version": 1,
        "mode": "LM02.7B.5.3.1_OFFLINE_COLMAP_RIG",
        "scale_source": "FIXED_STEREO_BASELINE",
        "selected_model_id": model_id,
        "selected_model_text_path": str(selected_dir),
        "selected_model_binary_path": str(workspace / "sparse" / model_id),
        "registered_camera_a_images": len(camera_a),
        "registered_stereo_pairs": len(baseline_samples),
        "path_length_m": colmap_length,
        "start_end_displacement_m": start_end,
        "known_path_length_m": args.known_path_length_m if args.known_path_length_m > 0 else None,
        "colmap_to_known_path_ratio": known_ratio,
        "live_path_length_m": live_length,
        "live_to_colmap_path_ratio": (
            live_length / colmap_length
            if live_length is not None and colmap_length > 1e-9
            else None
        ),
        "observed_stereo_baseline_median_m": (
            statistics.median(baseline_samples) if baseline_samples else None
        ),
        "observed_stereo_baseline_min_m": min(baseline_samples) if baseline_samples else None,
        "observed_stereo_baseline_max_m": max(baseline_samples) if baseline_samples else None,
        "trajectory": camera_a,
    }
    (workspace / "offline_colmap_trajectory.json").write_text(
        json.dumps(trajectory, indent=2) + "\n", encoding="utf-8"
    )
    (workspace / "offline_colmap_trajectory.ply").write_text(
        trajectory_ply(camera_a), encoding="utf-8"
    )
    summary = {key: value for key, value in trajectory.items() if key != "trajectory"}
    (workspace / "offline_colmap_summary.json").write_text(
        json.dumps(summary, indent=2) + "\n", encoding="utf-8"
    )
    print(json.dumps(summary, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
