#!/usr/bin/env python3
"""Aggregate sparse COLMAP TXT models and camera-trajectory diagnostics."""

from __future__ import annotations

import argparse
import json
import math
import re
import statistics
from pathlib import Path


FRAME_NUMBER = re.compile(r"(\d+)(?=\.[^.]+$)")


def qvec_rotation(q: list[float]) -> list[list[float]]:
    w, x, y, z = q
    return [
        [1 - 2 * y * y - 2 * z * z, 2 * x * y - 2 * w * z, 2 * x * z + 2 * w * y],
        [2 * x * y + 2 * w * z, 1 - 2 * x * x - 2 * z * z, 2 * y * z - 2 * w * x],
        [2 * x * z - 2 * w * y, 2 * y * z + 2 * w * x, 1 - 2 * x * x - 2 * y * y],
    ]


def camera_center(q: list[float], t: list[float]) -> list[float]:
    rotation = qvec_rotation(q)
    return [-sum(rotation[row][col] * t[row] for row in range(3)) for col in range(3)]


def distance(a: list[float], b: list[float]) -> float:
    return math.sqrt(sum((x - y) ** 2 for x, y in zip(a, b)))


def frame_key(name: str) -> tuple[int, str]:
    match = FRAME_NUMBER.search(name)
    return (int(match.group(1)) if match else 2**63 - 1, name)


def read_images(path: Path) -> list[dict[str, object]]:
    images = []
    expect_header = True
    for raw_line in path.read_text(encoding="utf-8").splitlines():
        if raw_line.startswith("#"):
            continue
        if expect_header:
            if not raw_line.strip():
                continue
            fields = raw_line.split()
            q = [float(value) for value in fields[1:5]]
            t = [float(value) for value in fields[5:8]]
            images.append({"name": fields[9], "center": camera_center(q, t)})
            expect_header = False
        else:
            # Every image header is followed by one POINTS2D line, which may be empty.
            expect_header = True
    return sorted(images, key=lambda item: frame_key(str(item["name"])))


def read_point_errors(path: Path) -> list[float]:
    errors = []
    for line in path.read_text(encoding="utf-8").splitlines():
        if line and not line.startswith("#"):
            errors.append(float(line.split()[7]))
    return errors


def model_metrics(model: Path) -> dict[str, object]:
    images = read_images(model / "images.txt")
    errors = read_point_errors(model / "points3D.txt")
    centers = [item["center"] for item in images]
    steps = [distance(a, b) for a, b in zip(centers, centers[1:])]
    endpoint = distance(centers[0], centers[-1]) if len(centers) > 1 else None
    path_length = sum(steps)
    return {
        "model": model.name,
        "registered_images": len(images),
        "sparse_points": len(errors),
        "mean_point_reprojection_error_px": statistics.fmean(errors) if errors else None,
        "first_registered_frame": images[0]["name"] if images else None,
        "last_registered_frame": images[-1]["name"] if images else None,
        "endpoint_distance": endpoint,
        "trajectory_path_length": path_length,
        "normalized_endpoint_distance": endpoint / path_length if endpoint is not None and path_length else None,
        "adjacent_step_median": statistics.median(steps) if steps else None,
        "adjacent_step_p95": sorted(steps)[math.ceil(0.95 * len(steps)) - 1] if steps else None,
        "adjacent_step_max": max(steps) if steps else None,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--models", required=True, type=Path, help="directory containing TXT model subdirectories")
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args()
    models = [model_metrics(path) for path in sorted(args.models.iterdir()) if (path / "images.txt").is_file()]
    largest = max(models, key=lambda item: int(item["registered_images"]), default=None)
    result = {
        "status": "PASS" if models else "FAIL",
        "components": len(models),
        "registered_images_sum": sum(int(item["registered_images"]) for item in models),
        "sparse_points_sum": sum(int(item["sparse_points"]) for item in models),
        "largest_model": largest,
        "models": models,
        "notes": [
            "COLMAP reconstructions can overlap; registered_images_sum is not necessarily a unique-image count.",
            "Distances are in arbitrary SfM units. Reprojection error is the mean points3D ERROR field.",
        ],
    }
    args.output.write_text(json.dumps(result, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(json.dumps(result, indent=2, ensure_ascii=False))
    return 0 if models else 1


if __name__ == "__main__":
    raise SystemExit(main())
