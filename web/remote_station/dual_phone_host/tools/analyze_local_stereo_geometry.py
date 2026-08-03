#!/usr/bin/env python3
"""Audit local stereo geometry separately from camera-pose accumulation.

The tool uses existing keyframe local/world PLY files and trajectory/diagnostic
JSON. It never changes point clouds or tracking decisions and requires only the
Python standard library.
"""

from __future__ import annotations

import argparse
import json
import math
import random
import re
import statistics
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Iterable, Sequence

SCHEMA_VERSION = 1
KEYFRAME_RE = re.compile(r"keyframe_(\d+)_local\.ply$")

Vec3 = tuple[float, float, float]


@dataclass(slots=True)
class Plane:
    normal: Vec3
    d_m: float
    centroid: Vec3
    inliers: int
    rms_m: float
    yaw_deg: float


def add(a: Vec3, b: Vec3) -> Vec3:
    return (a[0] + b[0], a[1] + b[1], a[2] + b[2])


def sub(a: Vec3, b: Vec3) -> Vec3:
    return (a[0] - b[0], a[1] - b[1], a[2] - b[2])


def mul(a: Vec3, value: float) -> Vec3:
    return (a[0] * value, a[1] * value, a[2] * value)


def dot(a: Vec3, b: Vec3) -> float:
    return a[0] * b[0] + a[1] * b[1] + a[2] * b[2]


def cross(a: Vec3, b: Vec3) -> Vec3:
    return (
        a[1] * b[2] - a[2] * b[1],
        a[2] * b[0] - a[0] * b[2],
        a[0] * b[1] - a[1] * b[0],
    )


def norm(a: Vec3) -> float:
    return math.sqrt(max(0.0, dot(a, a)))


def normalize(a: Vec3) -> Vec3:
    length = norm(a)
    if length <= 1e-12:
        return (0.0, 0.0, 0.0)
    return mul(a, 1.0 / length)


def read_ply_xyz(path: Path) -> list[Vec3]:
    with path.open("r", encoding="utf-8", errors="strict") as handle:
        first = handle.readline().strip()
        if first != "ply":
            raise ValueError(f"{path}: not an ASCII PLY")
        vertex_count = None
        properties: list[str] = []
        in_vertex = False
        while True:
            line = handle.readline()
            if not line:
                raise ValueError(f"{path}: missing end_header")
            text = line.strip()
            if text.startswith("element "):
                tokens = text.split()
                in_vertex = len(tokens) >= 3 and tokens[1] == "vertex"
                if in_vertex:
                    vertex_count = int(tokens[2])
            elif text.startswith("property ") and in_vertex:
                properties.append(text.split()[-1])
            elif text == "end_header":
                break
        if vertex_count is None:
            raise ValueError(f"{path}: vertex count missing")
        try:
            ix, iy, iz = (properties.index(name) for name in ("x", "y", "z"))
        except ValueError as error:
            raise ValueError(f"{path}: x/y/z properties missing") from error
        points: list[Vec3] = []
        for _ in range(vertex_count):
            fields = handle.readline().split()
            if not fields:
                break
            point = (float(fields[ix]), float(fields[iy]), float(fields[iz]))
            if all(math.isfinite(value) for value in point):
                points.append(point)
        return points


def percentile(values: Sequence[float], fraction: float) -> float | None:
    if not values:
        return None
    ordered = sorted(values)
    index = int(round((len(ordered) - 1) * max(0.0, min(1.0, fraction))))
    return ordered[index]


def fit_dominant_plane(points: Sequence[Vec3], seed: int) -> Plane | None:
    candidates = [p for p in points if 0.35 <= norm(p) <= 8.0]
    if len(candidates) < 120:
        return None
    if len(candidates) > 14000:
        random.Random(seed).shuffle(candidates)
        candidates = candidates[:14000]
    rng = random.Random(seed)
    threshold = 0.045
    best_normal: Vec3 | None = None
    best_d = 0.0
    best_indices: list[int] = []
    iterations = min(500, max(180, len(candidates) // 20))
    for _ in range(iterations):
        p0, p1, p2 = rng.sample(candidates, 3)
        normal = normalize(cross(sub(p1, p0), sub(p2, p0)))
        if norm(normal) < 0.9:
            continue
        d_m = -dot(normal, p0)
        indices = [
            index for index, point in enumerate(candidates)
            if abs(dot(normal, point) + d_m) <= threshold
        ]
        if len(indices) > len(best_indices):
            best_normal, best_d, best_indices = normal, d_m, indices
    if best_normal is None or len(best_indices) < 100:
        return None
    inlier_points = [candidates[index] for index in best_indices]
    count = float(len(inlier_points))
    centroid = (
        sum(point[0] for point in inlier_points) / count,
        sum(point[1] for point in inlier_points) / count,
        sum(point[2] for point in inlier_points) / count,
    )
    # Keep the normal in a deterministic hemisphere. Plane sign is otherwise
    # arbitrary and makes yaw comparisons misleading.
    normal = best_normal
    d_m = best_d
    if normal[2] < 0.0:
        normal = mul(normal, -1.0)
        d_m = -d_m
    residuals = [abs(dot(normal, point) + d_m) for point in inlier_points]
    rms = math.sqrt(sum(value * value for value in residuals) / len(residuals))
    return Plane(
        normal=normal,
        d_m=d_m,
        centroid=centroid,
        inliers=len(inlier_points),
        rms_m=rms,
        yaw_deg=math.degrees(math.atan2(normal[0], normal[2])),
    )


def load_json(path: Path, default):
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return default


def trajectory_samples(payload) -> list[dict]:
    if isinstance(payload, list):
        return [item for item in payload if isinstance(item, dict)]
    if isinstance(payload, dict):
        for key in ("samples", "trajectory"):
            value = payload.get(key)
            if isinstance(value, list):
                return [item for item in value if isinstance(item, dict)]
    return []


def matrix_from_sample(sample: dict) -> list[list[float]] | None:
    value = sample.get("world_from_camera")
    if isinstance(value, list) and len(value) == 4 and all(isinstance(row, list) and len(row) == 4 for row in value):
        return [[float(cell) for cell in row] for row in value]
    return None


def transform_point(matrix: Sequence[Sequence[float]], point: Vec3) -> Vec3:
    x, y, z = point
    return (
        matrix[0][0] * x + matrix[0][1] * y + matrix[0][2] * z + matrix[0][3],
        matrix[1][0] * x + matrix[1][1] * y + matrix[1][2] * z + matrix[1][3],
        matrix[2][0] * x + matrix[2][1] * y + matrix[2][2] * z + matrix[2][3],
    )


def compare_transform(local: Sequence[Vec3], world: Sequence[Vec3], matrix) -> dict:
    count = min(len(local), len(world))
    if count == 0 or matrix is None:
        return {"available": False}
    stride = max(1, count // 2000)
    errors = [
        norm(sub(transform_point(matrix, local[index]), world[index]))
        for index in range(0, count, stride)
    ]
    return {
        "available": True,
        "compared_points": len(errors),
        "median_error_m": statistics.median(errors),
        "p95_error_m": percentile(errors, 0.95),
        "maximum_error_m": max(errors),
    }


def read_jsonl(path: Path) -> list[dict]:
    result: list[dict] = []
    try:
        with path.open("r", encoding="utf-8") as handle:
            for line in handle:
                try:
                    value = json.loads(line)
                except json.JSONDecodeError:
                    continue
                if isinstance(value, dict):
                    result.append(value)
    except OSError:
        pass
    return result


def baseline_mm(session: Path) -> float | None:
    calibration = load_json(session / "stereo_calibration.json", {})
    candidates = []
    if isinstance(calibration, dict):
        candidates.append(calibration)
        stereo = calibration.get("stereo")
        if isinstance(stereo, dict):
            candidates.append(stereo)
    for candidate in candidates:
        for key in ("measured_baseline_mm", "baseline_mm"):
            value = candidate.get(key)
            if isinstance(value, (int, float)) and math.isfinite(float(value)):
                return float(value)
    return None


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("session", type=Path)
    args = parser.parse_args()
    session = args.session.resolve()
    keyframe_dir = session / "keyframes"
    if not keyframe_dir.is_dir():
        raise SystemExit(f"keyframe directory not found: {keyframe_dir}")

    trajectory = trajectory_samples(load_json(session / "camera_trajectory.json", []))
    by_id = {
        int(sample.get("keyframe_id", 0)): sample
        for sample in trajectory
        if int(sample.get("keyframe_id", 0)) > 0
    }
    records: list[dict] = []
    for local_path in sorted(keyframe_dir.glob("keyframe_*_local.ply")):
        match = KEYFRAME_RE.search(local_path.name)
        if not match:
            continue
        keyframe_id = int(match.group(1))
        filtered_local = keyframe_dir / f"keyframe_{keyframe_id}_local_filtered.ply"
        filtered_world = keyframe_dir / f"keyframe_{keyframe_id}_world_filtered.ply"
        analysis_local_path = filtered_local if filtered_local.exists() else local_path
        world_path = filtered_world if filtered_world.exists() else keyframe_dir / f"keyframe_{keyframe_id}_world.ply"
        local = read_ply_xyz(analysis_local_path)
        world = read_ply_xyz(world_path) if world_path.exists() else []
        sample = by_id.get(keyframe_id, {})
        plane = fit_dominant_plane(local, keyframe_id)
        ranges = [norm(point) for point in local]
        record = {
            "keyframe_id": keyframe_id,
            "pair_index": sample.get("pair_index"),
            "method": sample.get("method"),
            "local_source": analysis_local_path.name,
            "local_vertices": len(local),
            "world_vertices": len(world),
            "range_median_m": percentile(ranges, 0.50),
            "range_p95_m": percentile(ranges, 0.95),
            "trajectory_accumulated_yaw_deg": sample.get("accumulated_yaw_deg"),
            "trajectory_translation_m": sample.get("translation_m"),
            "dominant_plane": None if plane is None else {
                "normal": list(plane.normal),
                "d_m": plane.d_m,
                "distance_m": abs(plane.d_m),
                "centroid_m": list(plane.centroid),
                "inliers": plane.inliers,
                "inlier_fraction": plane.inliers / max(1, len(local)),
                "rms_m": plane.rms_m,
                "local_normal_yaw_deg": plane.yaw_deg,
            },
            "local_to_world_check": compare_transform(
                local, world, matrix_from_sample(sample)),
        }
        records.append(record)

    pose_events = read_jsonl(session / "pose_validation.jsonl")
    pnp_translations = [
        float(event.get("pnp_translation_m", event.get("translation_m", 0.0)))
        for event in pose_events
        if isinstance(event.get("pnp_translation_m", event.get("translation_m")), (int, float))
    ]
    unsafe_pnp = [value for value in pnp_translations if value > 0.08]
    plane_yaws = [
        float(record["dominant_plane"]["local_normal_yaw_deg"])
        for record in records if record["dominant_plane"] is not None
    ]
    trajectory_yaws = [
        float(record["trajectory_accumulated_yaw_deg"])
        for record in records
        if isinstance(record.get("trajectory_accumulated_yaw_deg"), (int, float))
    ]
    local_plane_span = max(plane_yaws) - min(plane_yaws) if plane_yaws else None
    trajectory_span = max(trajectory_yaws) - min(trajectory_yaws) if trajectory_yaws else None
    baseline = baseline_mm(session)
    expected_pivot_radius_m = baseline / 2000.0 if baseline else None

    findings: list[dict] = []
    if local_plane_span is not None and trajectory_span is not None and trajectory_span >= 15.0 and local_plane_span <= 7.0:
        findings.append({
            "code": "FRONT_PARALLEL_LOCAL_DEPTH_LAYER",
            "severity": "HIGH",
            "meaning": "Camera yaw changed substantially while the dominant local plane stayed nearly front-parallel. Inspect rectification, disparity and calibrated principal point before global fusion.",
        })
    transform_errors = [
        record["local_to_world_check"].get("p95_error_m")
        for record in records
        if record["local_to_world_check"].get("available")
    ]
    if transform_errors and max(float(value) for value in transform_errors if value is not None) < 1e-4:
        findings.append({
            "code": "LOCAL_TO_WORLD_MATRIX_APPLIED_EXACTLY",
            "severity": "INFO",
            "meaning": "Saved world PLY files agree with recorded world_from_camera matrices; this does not prove that the pose itself is correct.",
        })
    if unsafe_pnp:
        findings.append({
            "code": "UNSAFE_TRIPOD_PNP_TRANSLATION",
            "severity": "HIGH",
            "meaning": "At least one PnP estimate exceeds the temporary 8 cm tripod safety limit and must remain diagnostic-only.",
        })
    if not findings:
        findings.append({
            "code": "NO_SINGLE_DOMINANT_FAILURE",
            "severity": "MEDIUM",
            "meaning": "The available PLY and trajectory outputs do not isolate one failure. Run the controlled matte-wall 0/10/20 degree test.",
        })

    result = {
        "schema_version": SCHEMA_VERSION,
        "mode": "LOCAL_STEREO_GEOMETRY_VALIDATION_SAFE_TRIPOD",
        "session": str(session),
        "generated_unix_ms": int(time.time() * 1000),
        "keyframes": records,
        "summary": {
            "keyframes_analyzed": len(records),
            "local_dominant_plane_yaw_span_deg": local_plane_span,
            "trajectory_yaw_span_deg": trajectory_span,
            "pnp_translation_median_m": statistics.median(pnp_translations) if pnp_translations else None,
            "pnp_translation_maximum_m": max(pnp_translations) if pnp_translations else None,
            "unsafe_pnp_translation_events": len(unsafe_pnp),
            "measured_baseline_mm": baseline,
            "assumed_tripod_pivot_radius_m": expected_pivot_radius_m,
        },
        "findings": findings,
        "controlled_test": {
            "scene": "single matte textured wall, no glass or reflections",
            "poses_deg": [0, 10, 20],
            "expected": "The same physical wall must remain one world plane. Its local camera-frame normal should rotate by approximately the opposite camera yaw.",
        },
    }
    (session / "local_stereo_validation.json").write_text(
        json.dumps(result, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    lines = [
        "LM02.7B.5.2.5 local stereo geometry validation",
        f"keyframes: {len(records)}",
        f"local dominant-plane yaw span: {local_plane_span}",
        f"trajectory yaw span: {trajectory_span}",
        f"unsafe PnP translations (>0.08 m): {len(unsafe_pnp)}",
        "",
    ]
    for finding in findings:
        lines.append(f"[{finding['severity']}] {finding['code']}: {finding['meaning']}")
    (session / "local_stereo_validation.txt").write_text(
        "\n".join(lines) + "\n", encoding="utf-8")
    print(json.dumps(result["summary"], indent=2, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
