#!/usr/bin/env python3
"""Robust post-capture filtering and multi-keyframe room-plane fusion.

Consumes keyframes/keyframe_<N>_{local,world}.ply produced by LM02.7B.5.2.3.
No third-party Python modules are required.
"""

from __future__ import annotations

import argparse
import json
import math
import random
import re
import sys
import time
from collections import defaultdict
from dataclasses import dataclass, field
from pathlib import Path
from typing import Iterable, Iterator, Sequence

SCHEMA_VERSION = 1
KEYFRAME_RE = re.compile(r"keyframe_(\d+)_local\.ply$")

Vec3 = tuple[float, float, float]
Color = tuple[int, int, int]


@dataclass(slots=True)
class Point:
    xyz: Vec3
    rgb: Color


@dataclass(slots=True)
class PlaneObservation:
    keyframe_id: int
    normal: Vec3
    d_m: float
    centroid: Vec3
    rms_m: float
    inlier_count: int
    area_m2: float
    corners: list[Vec3]
    plane_type: str


@dataclass(slots=True)
class FusedPlane:
    plane_id: int
    normal_sum: list[float] = field(default_factory=lambda: [0.0, 0.0, 0.0])
    d_sum: float = 0.0
    weight_sum: float = 0.0
    keyframes: set[int] = field(default_factory=set)
    observations: int = 0
    rms_weighted_sum: float = 0.0
    support_points: list[Vec3] = field(default_factory=list)
    plane_type_votes: dict[str, int] = field(default_factory=lambda: defaultdict(int))

    def normal(self) -> Vec3:
        return normalize(tuple(self.normal_sum))

    def d_m(self) -> float:
        return self.d_sum / max(self.weight_sum, 1e-9)

    def centroid(self) -> Vec3:
        if not self.support_points:
            return (0.0, 0.0, 0.0)
        count = float(len(self.support_points))
        return tuple(sum(p[i] for p in self.support_points) / count for i in range(3))  # type: ignore[return-value]

    def plane_type(self) -> str:
        if not self.plane_type_votes:
            return "UNKNOWN"
        return max(self.plane_type_votes.items(), key=lambda item: item[1])[0]


@dataclass(slots=True)
class Edge:
    plane_a: int
    plane_b: int
    edge_type: str
    start: Vec3
    end: Vec3
    length_m: float


def add(a: Vec3, b: Vec3) -> Vec3:
    return (a[0] + b[0], a[1] + b[1], a[2] + b[2])


def sub(a: Vec3, b: Vec3) -> Vec3:
    return (a[0] - b[0], a[1] - b[1], a[2] - b[2])


def mul(a: Vec3, scalar: float) -> Vec3:
    return (a[0] * scalar, a[1] * scalar, a[2] * scalar)


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
    return (a[0] / length, a[1] / length, a[2] / length)


def canonical_plane(normal: Vec3, d_m: float) -> tuple[Vec3, float]:
    n = normalize(normal)
    dominant = max(range(3), key=lambda index: abs(n[index]))
    if n[dominant] < 0.0:
        return mul(n, -1.0), -d_m
    return n, d_m


def angle_deg(a: Vec3, b: Vec3) -> float:
    value = max(-1.0, min(1.0, abs(dot(normalize(a), normalize(b)))))
    return math.degrees(math.acos(value))


def distance(a: Vec3, b: Vec3) -> float:
    return norm(sub(a, b))


def voxel_key(point: Vec3, size: float) -> tuple[int, int, int]:
    return tuple(math.floor(value / size) for value in point)  # type: ignore[return-value]


def read_ascii_ply(path: Path) -> list[Point]:
    with path.open("r", encoding="utf-8", errors="strict") as handle:
        first = handle.readline().strip()
        if first != "ply":
            raise ValueError(f"{path}: not a PLY file")
        vertex_count: int | None = None
        properties: list[str] = []
        in_vertex = False
        while True:
            line = handle.readline()
            if not line:
                raise ValueError(f"{path}: incomplete PLY header")
            stripped = line.strip()
            if stripped == "format binary_little_endian 1.0" or stripped == "format binary_big_endian 1.0":
                raise ValueError(f"{path}: binary PLY is not supported")
            if stripped.startswith("element vertex "):
                vertex_count = int(stripped.split()[-1])
                in_vertex = True
                properties.clear()
            elif stripped.startswith("element "):
                in_vertex = False
            elif in_vertex and stripped.startswith("property "):
                properties.append(stripped.split()[-1])
            elif stripped == "end_header":
                break
        if vertex_count is None:
            raise ValueError(f"{path}: vertex count missing")
        required = {"x", "y", "z"}
        if not required.issubset(properties):
            raise ValueError(f"{path}: x/y/z properties missing")
        indices = {name: properties.index(name) for name in properties}
        points: list[Point] = []
        for _ in range(vertex_count):
            fields = handle.readline().split()
            if len(fields) < len(properties):
                raise ValueError(f"{path}: truncated vertex data")
            xyz = (
                float(fields[indices["x"]]),
                float(fields[indices["y"]]),
                float(fields[indices["z"]]),
            )
            rgb = (
                int(float(fields[indices.get("red", -1)])) if "red" in indices else 200,
                int(float(fields[indices.get("green", -1)])) if "green" in indices else 200,
                int(float(fields[indices.get("blue", -1)])) if "blue" in indices else 200,
            )
            if all(math.isfinite(value) for value in xyz):
                points.append(Point(xyz=xyz, rgb=rgb))
        return points


def write_ascii_ply(path: Path, points: Sequence[Point], comment: str) -> None:
    lines = [
        "ply",
        "format ascii 1.0",
        f"comment MaklerTour {comment}",
        "comment coordinate_system X_right_Y_up_Z_forward_meters",
        f"element vertex {len(points)}",
        "property float x",
        "property float y",
        "property float z",
        "property uchar red",
        "property uchar green",
        "property uchar blue",
        "end_header",
    ]
    for point in points:
        lines.append(
            f"{point.xyz[0]:.6f} {point.xyz[1]:.6f} {point.xyz[2]:.6f} "
            f"{point.rgb[0]} {point.rgb[1]} {point.rgb[2]}"
        )
    atomic_write(path, "\n".join(lines) + "\n")


def atomic_write(path: Path, contents: str) -> None:
    temporary = path.with_name(path.name + ".tmp")
    temporary.write_text(contents, encoding="utf-8")
    temporary.replace(path)


def voxel_downsample_pairs(
    local_points: Sequence[Point],
    world_points: Sequence[Point],
    voxel_m: float,
    min_range_m: float,
    max_range_m: float,
) -> list[tuple[Point, Point]]:
    if len(local_points) != len(world_points):
        raise ValueError("local/world keyframe PLY vertex counts differ")
    bins: dict[tuple[int, int, int], list[float]] = {}
    for local, world in zip(local_points, world_points):
        radius = norm(local.xyz)
        if radius < min_range_m or radius > max_range_m:
            continue
        key = voxel_key(local.xyz, voxel_m)
        bucket = bins.setdefault(key, [0.0] * 10)
        for index in range(3):
            bucket[index] += local.xyz[index]
            bucket[index + 3] += world.xyz[index]
            bucket[index + 6] += local.rgb[index]
        bucket[9] += 1.0
    result: list[tuple[Point, Point]] = []
    for bucket in bins.values():
        count = bucket[9]
        local_xyz = tuple(bucket[i] / count for i in range(3))
        world_xyz = tuple(bucket[i + 3] / count for i in range(3))
        rgb = tuple(max(0, min(255, round(bucket[i + 6] / count))) for i in range(3))
        result.append((Point(local_xyz, rgb), Point(world_xyz, rgb)))
    return result


def radius_filter_pairs(
    pairs: Sequence[tuple[Point, Point]],
    radius_m: float,
    minimum_neighbours: int,
) -> list[tuple[Point, Point]]:
    if not pairs:
        return []
    cell_size = radius_m
    grid: dict[tuple[int, int, int], list[int]] = defaultdict(list)
    for index, pair in enumerate(pairs):
        grid[voxel_key(pair[0].xyz, cell_size)].append(index)
    radius_squared = radius_m * radius_m
    kept: list[tuple[Point, Point]] = []
    for index, pair in enumerate(pairs):
        cell = voxel_key(pair[0].xyz, cell_size)
        neighbours = 0
        for dx in (-1, 0, 1):
            for dy in (-1, 0, 1):
                for dz in (-1, 0, 1):
                    for other_index in grid.get((cell[0] + dx, cell[1] + dy, cell[2] + dz), ()):
                        if other_index == index:
                            continue
                        delta = sub(pair[0].xyz, pairs[other_index][0].xyz)
                        if dot(delta, delta) <= radius_squared:
                            neighbours += 1
                            if neighbours >= minimum_neighbours:
                                kept.append(pair)
                                break
                    if neighbours >= minimum_neighbours:
                        break
                if neighbours >= minimum_neighbours:
                    break
            if neighbours >= minimum_neighbours:
                break
    return kept


def depth_shell_filter_pairs(
    pairs: Sequence[tuple[Point, Point]],
    angular_bin_deg: float = 1.5,
    maximum_radial_spread_m: float = 0.55,
) -> list[tuple[Point, Point]]:
    """Suppress long radial chains caused by inconsistent disparity.

    Within a small azimuth/elevation cell, preserve the nearest coherent shell and
    reject points substantially behind it. Real surfaces remain because adjacent
    angular bins are processed independently.
    """
    bins: dict[tuple[int, int], list[tuple[float, int]]] = defaultdict(list)
    for index, pair in enumerate(pairs):
        x, y, z = pair[0].xyz
        radius = max(norm(pair[0].xyz), 1e-9)
        azimuth = math.degrees(math.atan2(x, z))
        elevation = math.degrees(math.asin(max(-1.0, min(1.0, y / radius))))
        key = (math.floor(azimuth / angular_bin_deg), math.floor(elevation / angular_bin_deg))
        bins[key].append((radius, index))
    accepted: set[int] = set()
    for values in bins.values():
        values.sort()
        if len(values) <= 2:
            accepted.update(index for _, index in values)
            continue
        base_count = min(3, len(values))
        near_radius = sum(values[i][0] for i in range(base_count)) / base_count
        limit = near_radius + maximum_radial_spread_m
        accepted.update(index for radius, index in values if radius <= limit)
    return [pair for index, pair in enumerate(pairs) if index in accepted]


def plane_from_three(a: Vec3, b: Vec3, c: Vec3) -> tuple[Vec3, float] | None:
    normal = cross(sub(b, a), sub(c, a))
    if norm(normal) < 1e-6:
        return None
    n = normalize(normal)
    return canonical_plane(n, -dot(n, a))


def solve_3x3(matrix: list[list[float]], values: list[float]) -> list[float] | None:
    augmented = [row[:] + [values[index]] for index, row in enumerate(matrix)]
    for column in range(3):
        pivot = max(range(column, 3), key=lambda row: abs(augmented[row][column]))
        if abs(augmented[pivot][column]) < 1e-10:
            return None
        augmented[column], augmented[pivot] = augmented[pivot], augmented[column]
        divisor = augmented[column][column]
        augmented[column] = [value / divisor for value in augmented[column]]
        for row in range(3):
            if row == column:
                continue
            factor = augmented[row][column]
            augmented[row] = [
                augmented[row][index] - factor * augmented[column][index]
                for index in range(4)
            ]
    return [augmented[row][3] for row in range(3)]


def refine_plane(points: Sequence[Vec3], initial_normal: Vec3) -> tuple[Vec3, float, float] | None:
    axis = max(range(3), key=lambda index: abs(initial_normal[index]))
    independent = [index for index in range(3) if index != axis]
    suu = suv = svv = su = sv = count = 0.0
    suq = svq = sq = 0.0
    for point in points:
        u = point[independent[0]]
        v = point[independent[1]]
        q = point[axis]
        suu += u * u
        suv += u * v
        svv += v * v
        su += u
        sv += v
        suq += u * q
        svq += v * q
        sq += q
        count += 1.0
    solution = solve_3x3(
        [[suu, suv, su], [suv, svv, sv], [su, sv, count]],
        [suq, svq, sq],
    )
    if solution is None:
        return None
    coefficients = [0.0, 0.0, 0.0]
    coefficients[independent[0]] = solution[0]
    coefficients[independent[1]] = solution[1]
    coefficients[axis] = -1.0
    normal = normalize(tuple(coefficients))
    d_m = solution[2] / math.sqrt(sum(value * value for value in coefficients))
    normal, d_m = canonical_plane(normal, d_m)
    residuals = [dot(normal, point) + d_m for point in points]
    rms = math.sqrt(sum(value * value for value in residuals) / max(1, len(residuals)))
    return normal, d_m, rms


def plane_basis(normal: Vec3) -> tuple[Vec3, Vec3]:
    helper = (0.0, 1.0, 0.0) if abs(normal[1]) < 0.85 else (1.0, 0.0, 0.0)
    u = normalize(cross(helper, normal))
    v = normalize(cross(normal, u))
    return u, v


def plane_rectangle(points: Sequence[Vec3], normal: Vec3, d_m: float) -> tuple[list[Vec3], float, Vec3]:
    centroid = tuple(sum(point[i] for point in points) / len(points) for i in range(3))
    centroid = sub(centroid, mul(normal, dot(normal, centroid) + d_m))
    u, v = plane_basis(normal)
    coordinates = [(dot(sub(point, centroid), u), dot(sub(point, centroid), v)) for point in points]
    min_u = min(value[0] for value in coordinates)
    max_u = max(value[0] for value in coordinates)
    min_v = min(value[1] for value in coordinates)
    max_v = max(value[1] for value in coordinates)
    corners = [
        add(add(centroid, mul(u, min_u)), mul(v, min_v)),
        add(add(centroid, mul(u, max_u)), mul(v, min_v)),
        add(add(centroid, mul(u, max_u)), mul(v, max_v)),
        add(add(centroid, mul(u, min_u)), mul(v, max_v)),
    ]
    return corners, max(0.0, (max_u - min_u) * (max_v - min_v)), centroid


def classify_plane(normal: Vec3, centroid: Vec3) -> str:
    vertical_component = abs(normal[1])
    if vertical_component >= 0.82:
        return "CEILING_CANDIDATE" if centroid[1] > 0.0 else "FLOOR_CANDIDATE"
    if vertical_component <= 0.35:
        return "WALL_CANDIDATE"
    return "OBLIQUE_CANDIDATE"


def extract_planes(
    points: Sequence[Point],
    keyframe_id: int,
    threshold_m: float,
    minimum_inliers: int,
    maximum_planes: int,
) -> list[PlaneObservation]:
    remaining = list(range(len(points)))
    observations: list[PlaneObservation] = []
    rng = random.Random(0x5A17 + keyframe_id)
    for _ in range(maximum_planes):
        if len(remaining) < minimum_inliers:
            break
        best: list[int] = []
        best_plane: tuple[Vec3, float] | None = None
        iterations = min(450, max(120, len(remaining) // 4))
        for _iteration in range(iterations):
            sample = rng.sample(remaining, 3)
            candidate = plane_from_three(
                points[sample[0]].xyz,
                points[sample[1]].xyz,
                points[sample[2]].xyz,
            )
            if candidate is None:
                continue
            normal, d_m = candidate
            inliers = [
                index for index in remaining
                if abs(dot(normal, points[index].xyz) + d_m) <= threshold_m
            ]
            if len(inliers) > len(best):
                best = inliers
                best_plane = candidate
        if best_plane is None or len(best) < minimum_inliers:
            break
        coordinates = [points[index].xyz for index in best]
        refined = refine_plane(coordinates, best_plane[0])
        if refined is None:
            break
        normal, d_m, rms = refined
        refined_inliers = [
            index for index in remaining
            if abs(dot(normal, points[index].xyz) + d_m) <= threshold_m
        ]
        if len(refined_inliers) < minimum_inliers:
            remaining = [index for index in remaining if index not in set(best)]
            continue
        coordinates = [points[index].xyz for index in refined_inliers]
        corners, area_m2, centroid = plane_rectangle(coordinates, normal, d_m)
        if area_m2 >= 0.20:
            observations.append(
                PlaneObservation(
                    keyframe_id=keyframe_id,
                    normal=normal,
                    d_m=d_m,
                    centroid=centroid,
                    rms_m=rms,
                    inlier_count=len(refined_inliers),
                    area_m2=area_m2,
                    corners=corners,
                    plane_type=classify_plane(normal, centroid),
                )
            )
        removed = set(refined_inliers)
        remaining = [index for index in remaining if index not in removed]
    return observations


def fuse_plane_observations(
    observations: Sequence[PlaneObservation],
    maximum_angle_deg: float,
    maximum_distance_m: float,
) -> list[FusedPlane]:
    groups: list[FusedPlane] = []
    for observation in sorted(observations, key=lambda item: item.inlier_count, reverse=True):
        best_group: FusedPlane | None = None
        best_score = float("inf")
        for group in groups:
            group_normal = group.normal()
            angle = angle_deg(group_normal, observation.normal)
            distance_delta = abs(group.d_m() - observation.d_m)
            if angle > maximum_angle_deg or distance_delta > maximum_distance_m:
                continue
            score = angle / maximum_angle_deg + distance_delta / maximum_distance_m
            if score < best_score:
                best_score = score
                best_group = group
        if best_group is None:
            best_group = FusedPlane(plane_id=len(groups) + 1)
            groups.append(best_group)
        aligned_normal = observation.normal
        aligned_d = observation.d_m
        if dot(best_group.normal(), aligned_normal) < 0.0 and best_group.weight_sum > 0.0:
            aligned_normal = mul(aligned_normal, -1.0)
            aligned_d = -aligned_d
        weight = max(1.0, float(observation.inlier_count))
        for index in range(3):
            best_group.normal_sum[index] += aligned_normal[index] * weight
        best_group.d_sum += aligned_d * weight
        best_group.weight_sum += weight
        best_group.keyframes.add(observation.keyframe_id)
        best_group.observations += 1
        best_group.rms_weighted_sum += observation.rms_m * weight
        best_group.support_points.extend(observation.corners)
        best_group.plane_type_votes[observation.plane_type] += 1
    return groups


def solve_plane_intersection(a: FusedPlane, b: FusedPlane) -> tuple[Vec3, Vec3] | None:
    n1 = a.normal()
    n2 = b.normal()
    direction = normalize(cross(n1, n2))
    if norm(direction) < 1e-6:
        return None
    matrix = [list(n1), list(n2), list(direction)]
    solution = solve_3x3(matrix, [-a.d_m(), -b.d_m(), 0.0])
    if solution is None:
        return None
    return (tuple(solution), direction)  # type: ignore[return-value]


def make_edges(planes: Sequence[dict]) -> list[Edge]:
    edges: list[Edge] = []
    for first_index, first in enumerate(planes):
        for second in planes[first_index + 1:]:
            normal_a = tuple(first["normal"])
            normal_b = tuple(second["normal"])
            raw_dot = abs(dot(normalize(normal_a), normalize(normal_b)))
            if raw_dot > 0.45:
                continue
            proxy_a = FusedPlane(plane_id=int(first["id"]))
            proxy_b = FusedPlane(plane_id=int(second["id"]))
            proxy_a.normal_sum = list(normal_a)
            proxy_b.normal_sum = list(normal_b)
            proxy_a.d_sum = float(first["d_m"])
            proxy_b.d_sum = float(second["d_m"])
            proxy_a.weight_sum = proxy_b.weight_sum = 1.0
            intersection = solve_plane_intersection(proxy_a, proxy_b)
            if intersection is None:
                continue
            origin, direction = intersection
            intervals: list[tuple[float, float]] = []
            for plane in (first, second):
                values = [dot(sub(tuple(corner), origin), direction) for corner in plane["corners_m"]]
                intervals.append((min(values), max(values)))
            lower = max(interval[0] for interval in intervals)
            upper = min(interval[1] for interval in intervals)
            if upper - lower < 0.25:
                continue
            start = add(origin, mul(direction, lower))
            end = add(origin, mul(direction, upper))
            type_a = str(first["type"])
            type_b = str(second["type"])
            types = {type_a, type_b}
            if "FLOOR_CANDIDATE" in types and "WALL_CANDIDATE" in types:
                edge_type = "FLOOR_WALL"
            elif "CEILING_CANDIDATE" in types and "WALL_CANDIDATE" in types:
                edge_type = "CEILING_WALL"
            elif type_a == type_b == "WALL_CANDIDATE":
                edge_type = "WALL_CORNER"
            else:
                edge_type = "PLANE_INTERSECTION"
            edges.append(
                Edge(
                    plane_a=int(first["id"]),
                    plane_b=int(second["id"]),
                    edge_type=edge_type,
                    start=start,
                    end=end,
                    length_m=upper - lower,
                )
            )
    return edges


def write_skeleton_ply(path: Path, planes: Sequence[dict], edges: Sequence[Edge]) -> None:
    vertices: list[tuple[Vec3, Color]] = []
    line_indices: list[tuple[int, int, str]] = []
    for plane in planes:
        start_index = len(vertices)
        color: Color
        if plane["type"] == "WALL_CANDIDATE":
            color = (0, 255, 0)
        elif plane["type"] == "FLOOR_CANDIDATE":
            color = (0, 128, 255)
        elif plane["type"] == "CEILING_CANDIDATE":
            color = (255, 255, 0)
        else:
            color = (255, 128, 0)
        for corner in plane["corners_m"]:
            vertices.append((tuple(corner), color))
        for index in range(4):
            line_indices.append((start_index + index, start_index + (index + 1) % 4, "PLANE_BOUNDARY"))
    for edge in edges:
        start_index = len(vertices)
        vertices.append((edge.start, (255, 0, 255)))
        vertices.append((edge.end, (255, 0, 255)))
        line_indices.append((start_index, start_index + 1, edge.edge_type))
    lines = [
        "ply",
        "format ascii 1.0",
        "comment MaklerTour accumulated multi-keyframe room skeleton",
        "comment coordinate_system X_right_Y_up_Z_forward_meters",
        f"element vertex {len(vertices)}",
        "property float x",
        "property float y",
        "property float z",
        "property uchar red",
        "property uchar green",
        "property uchar blue",
        f"element edge {len(line_indices)}",
        "property int vertex1",
        "property int vertex2",
        "end_header",
    ]
    for xyz, rgb in vertices:
        lines.append(f"{xyz[0]:.6f} {xyz[1]:.6f} {xyz[2]:.6f} {rgb[0]} {rgb[1]} {rgb[2]}")
    for first, second, _edge_type in line_indices:
        lines.append(f"{first} {second}")
    atomic_write(path, "\n".join(lines) + "\n")


def aggregate_filtered_cloud(
    filtered_world: dict[int, list[Point]],
    voxel_m: float,
    minimum_keyframes: int,
) -> list[Point]:
    voxels: dict[tuple[int, int, int], dict] = {}
    for keyframe_id, points in filtered_world.items():
        observed: set[tuple[int, int, int]] = set()
        for point in points:
            key = voxel_key(point.xyz, voxel_m)
            bucket = voxels.setdefault(
                key,
                {"position": [0.0, 0.0, 0.0], "colour": [0.0, 0.0, 0.0], "samples": 0, "keyframes": set()},
            )
            for index in range(3):
                bucket["position"][index] += point.xyz[index]
                bucket["colour"][index] += point.rgb[index]
            bucket["samples"] += 1
            if key not in observed:
                bucket["keyframes"].add(keyframe_id)
                observed.add(key)
    result: list[Point] = []
    for bucket in voxels.values():
        if len(bucket["keyframes"]) < minimum_keyframes:
            continue
        samples = float(bucket["samples"])
        result.append(
            Point(
                xyz=tuple(value / samples for value in bucket["position"]),
                rgb=tuple(max(0, min(255, round(value / samples))) for value in bucket["colour"]),
            )
        )
    return result


def process_session(args: argparse.Namespace) -> dict:
    started = time.monotonic()
    session = Path(args.session).resolve()
    keyframe_directory = session / "keyframes"
    if not keyframe_directory.is_dir():
        raise RuntimeError(f"keyframe directory not found: {keyframe_directory}")
    local_paths: list[tuple[int, Path]] = []
    for path in keyframe_directory.glob("keyframe_*_local.ply"):
        match = KEYFRAME_RE.search(path.name)
        if match:
            local_paths.append((int(match.group(1)), path))
    local_paths.sort()
    if not local_paths:
        raise RuntimeError("no local keyframe PLY files found")

    filtered_world: dict[int, list[Point]] = {}
    observations: list[PlaneObservation] = []
    keyframe_reports: list[dict] = []
    for keyframe_id, local_path in local_paths:
        world_path = keyframe_directory / f"keyframe_{keyframe_id}_world.ply"
        if not world_path.is_file():
            continue
        local_points = read_ascii_ply(local_path)
        world_points = read_ascii_ply(world_path)
        pairs = voxel_downsample_pairs(
            local_points,
            world_points,
            args.local_voxel_m,
            args.min_range_m,
            args.max_range_m,
        )
        after_voxel = len(pairs)
        pairs = radius_filter_pairs(pairs, args.radius_m, args.minimum_neighbours)
        after_radius = len(pairs)
        pairs = depth_shell_filter_pairs(pairs, args.angular_bin_deg, args.maximum_radial_spread_m)
        after_shell = len(pairs)
        local_filtered = [pair[0] for pair in pairs]
        world_filtered = [pair[1] for pair in pairs]
        filtered_world[keyframe_id] = world_filtered
        write_ascii_ply(
            keyframe_directory / f"keyframe_{keyframe_id}_local_filtered.ply",
            local_filtered,
            f"robust local filtered keyframe {keyframe_id}",
        )
        write_ascii_ply(
            keyframe_directory / f"keyframe_{keyframe_id}_world_filtered.ply",
            world_filtered,
            f"robust world filtered keyframe {keyframe_id}",
        )
        planes = extract_planes(
            world_filtered,
            keyframe_id,
            args.plane_threshold_m,
            args.minimum_plane_inliers,
            args.maximum_planes_per_keyframe,
        )
        observations.extend(planes)
        keyframe_reports.append(
            {
                "keyframe_id": keyframe_id,
                "input_points": len(local_points),
                "after_voxel": after_voxel,
                "after_radius": after_radius,
                "after_depth_shell": after_shell,
                "removed_points": len(local_points) - after_shell,
                "local_plane_observations": len(planes),
            }
        )

    filtered_raw = aggregate_filtered_cloud(filtered_world, args.global_voxel_m, 1)
    filtered_multiview = aggregate_filtered_cloud(
        filtered_world,
        args.global_voxel_m,
        args.minimum_cloud_keyframes,
    )
    write_ascii_ply(
        session / "point_cloud_accumulated_filtered_raw.ply",
        filtered_raw,
        "robust filtered accumulated cloud before multiview confirmation",
    )
    write_ascii_ply(
        session / "point_cloud_accumulated_filtered.ply",
        filtered_multiview,
        "robust filtered multi-keyframe confirmed accumulated cloud",
    )

    groups = fuse_plane_observations(
        observations,
        args.maximum_plane_angle_deg,
        args.maximum_plane_distance_m,
    )
    confirmed_planes: list[dict] = []
    all_plane_groups: list[dict] = []
    for group in groups:
        normal = group.normal()
        d_m = group.d_m()
        corners, area_m2, centroid = plane_rectangle(group.support_points, normal, d_m)
        plane = {
            "id": group.plane_id,
            "type": group.plane_type(),
            "normal": list(normal),
            "d_m": d_m,
            "centroid_m": list(centroid),
            "area_m2": area_m2,
            "rms_m": group.rms_weighted_sum / max(group.weight_sum, 1e-9),
            "observation_count": group.observations,
            "keyframe_count": len(group.keyframes),
            "keyframe_ids": sorted(group.keyframes),
            "corners_m": [list(corner) for corner in corners],
            "confirmed": len(group.keyframes) >= args.minimum_plane_keyframes,
        }
        all_plane_groups.append(plane)
        if plane["confirmed"] and area_m2 >= args.minimum_fused_plane_area_m2:
            confirmed_planes.append(plane)

    edges = make_edges(confirmed_planes)
    planes_document = {
        "schema_version": SCHEMA_VERSION,
        "coordinate_system": "X_right_Y_up_Z_forward_meters",
        "minimum_keyframes": args.minimum_plane_keyframes,
        "confirmed_plane_count": len(confirmed_planes),
        "observation_count": len(observations),
        "planes": confirmed_planes,
        "all_groups": all_plane_groups,
    }
    edges_document = {
        "schema_version": SCHEMA_VERSION,
        "coordinate_system": "X_right_Y_up_Z_forward_meters",
        "edge_count": len(edges),
        "edges": [
            {
                "type": edge.edge_type,
                "plane_a": edge.plane_a,
                "plane_b": edge.plane_b,
                "start_m": list(edge.start),
                "end_m": list(edge.end),
                "length_m": edge.length_m,
            }
            for edge in edges
        ],
    }
    atomic_write(session / "room_planes_accumulated.json", json.dumps(planes_document, indent=2) + "\n")
    atomic_write(session / "room_edges_accumulated.json", json.dumps(edges_document, indent=2) + "\n")
    write_skeleton_ply(session / "room_skeleton_accumulated.ply", confirmed_planes, edges)

    status = {
        "schema_version": SCHEMA_VERSION,
        "state": "READY",
        "mode": "ROBUST_LOCAL_CLOUD_GLOBAL_PLANE_FUSION",
        "session": str(session),
        "keyframes_processed": len(filtered_world),
        "input_points": sum(item["input_points"] for item in keyframe_reports),
        "filtered_points_raw": len(filtered_raw),
        "filtered_points_multiview": len(filtered_multiview),
        "local_plane_observations": len(observations),
        "fused_plane_groups": len(groups),
        "confirmed_planes": len(confirmed_planes),
        "confirmed_edges": len(edges),
        "minimum_plane_keyframes": args.minimum_plane_keyframes,
        "minimum_cloud_keyframes": args.minimum_cloud_keyframes,
        "processing_ms": (time.monotonic() - started) * 1000.0,
        "files": {
            "filtered_raw_cloud": "point_cloud_accumulated_filtered_raw.ply",
            "filtered_multiview_cloud": "point_cloud_accumulated_filtered.ply",
            "planes": "room_planes_accumulated.json",
            "edges": "room_edges_accumulated.json",
            "skeleton": "room_skeleton_accumulated.ply",
            "diagnostics": "room_fusion_diagnostics.json",
        },
    }
    diagnostics = {
        "schema_version": SCHEMA_VERSION,
        "status": status,
        "parameters": vars(args),
        "keyframes": keyframe_reports,
    }
    diagnostics["parameters"]["session"] = str(session)
    atomic_write(session / "room_fusion_diagnostics.json", json.dumps(diagnostics, indent=2) + "\n")
    atomic_write(session / "room_fusion_status.json", json.dumps(status, indent=2) + "\n")
    return status


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("session", help="MaklerTour dual-phone session directory")
    parser.add_argument("--min-range-m", type=float, default=0.45)
    parser.add_argument("--max-range-m", type=float, default=6.0)
    parser.add_argument("--local-voxel-m", type=float, default=0.035)
    parser.add_argument("--global-voxel-m", type=float, default=0.04)
    parser.add_argument("--radius-m", type=float, default=0.14)
    parser.add_argument("--minimum-neighbours", type=int, default=4)
    parser.add_argument("--angular-bin-deg", type=float, default=1.5)
    parser.add_argument("--maximum-radial-spread-m", type=float, default=0.55)
    parser.add_argument("--plane-threshold-m", type=float, default=0.045)
    parser.add_argument("--minimum-plane-inliers", type=int, default=55)
    parser.add_argument("--maximum-planes-per-keyframe", type=int, default=6)
    parser.add_argument("--maximum-plane-angle-deg", type=float, default=9.0)
    parser.add_argument("--maximum-plane-distance-m", type=float, default=0.20)
    parser.add_argument("--minimum-plane-keyframes", type=int, default=3)
    parser.add_argument("--minimum-cloud-keyframes", type=int, default=2)
    parser.add_argument("--minimum-fused-plane-area-m2", type=float, default=0.35)
    return parser


def main() -> int:
    parser = build_parser()
    args = parser.parse_args()
    try:
        status = process_session(args)
    except Exception as error:  # noqa: BLE001 - CLI must report all processing failures
        print(json.dumps({"state": "ERROR", "error": str(error)}, indent=2), file=sys.stderr)
        return 1
    print(json.dumps(status, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
