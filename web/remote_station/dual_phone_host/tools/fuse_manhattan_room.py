#!/usr/bin/env python3
"""Fuse fragmented room-plane groups into a conservative Manhattan room model.

The tool consumes the candidate and pair-hypothesis documents produced by
analyze_multi_plane_corners.py. It does not overwrite the original robust fusion
outputs. Instead it emits a separate, auditable Manhattan model in which
fragmented coplanar wall groups may be merged and a second wall may be promoted
only when a strong shared-keyframe 90-degree corner supports it.
"""

from __future__ import annotations

import argparse
import json
import math
import sys
import time
from pathlib import Path
from typing import Any, Iterable, Sequence

SCHEMA_VERSION = 1
Vec3 = tuple[float, float, float]
Color = tuple[int, int, int]


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


def norm(value: Vec3) -> float:
    return math.sqrt(max(0.0, dot(value, value)))


def normalize(value: Vec3) -> Vec3:
    length = norm(value)
    if length <= 1e-12:
        return (0.0, 0.0, 0.0)
    return (value[0] / length, value[1] / length, value[2] / length)


def orientation_angle_deg(first: Vec3, second: Vec3) -> float:
    cosine = max(-1.0, min(1.0, abs(dot(normalize(first), normalize(second)))))
    return math.degrees(math.acos(cosine))


def wall_axis(normal: Vec3) -> Vec3:
    projected = normalize((normal[0], 0.0, normal[2]))
    if norm(projected) <= 1e-12:
        return (0.0, 0.0, 0.0)
    return projected


def align_axis_sign(axis: Vec3, reference: Vec3) -> Vec3:
    return mul(axis, -1.0) if dot(axis, reference) < 0.0 else axis


def atomic_write(path: Path, text: str) -> None:
    temporary = path.with_name(path.name + ".tmp")
    temporary.write_text(text, encoding="utf-8")
    temporary.replace(path)


def load_json(path: Path) -> dict[str, Any]:
    with path.open("r", encoding="utf-8") as handle:
        document = json.load(handle)
    if not isinstance(document, dict):
        raise ValueError(f"{path}: JSON root must be an object")
    return document


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


def plane_intersection(first: dict[str, Any], second: dict[str, Any]) -> tuple[Vec3, Vec3] | None:
    normal_a = normalize(tuple(float(value) for value in first["normal"]))
    normal_b = normalize(tuple(float(value) for value in second["normal"]))
    direction = normalize(cross(normal_a, normal_b))
    if norm(direction) < 1e-6:
        return None
    solution = solve_3x3(
        [list(normal_a), list(normal_b), list(direction)],
        [-float(first["d_m"]), -float(second["d_m"]), 0.0],
    )
    if solution is None:
        return None
    return (tuple(solution), direction)  # type: ignore[return-value]


def rectangle_overlap_on_intersection(
    first: dict[str, Any], second: dict[str, Any]
) -> tuple[Vec3, Vec3, float] | None:
    intersection = plane_intersection(first, second)
    if intersection is None:
        return None
    origin, direction = intersection
    intervals: list[tuple[float, float]] = []
    for plane in (first, second):
        corners = [
            tuple(float(value) for value in corner)
            for corner in plane.get("corners_m", [])
        ]
        if len(corners) < 3:
            return None
        projected = [dot(sub(corner, origin), direction) for corner in corners]
        intervals.append((min(projected), max(projected)))
    lower = max(interval[0] for interval in intervals)
    upper = min(interval[1] for interval in intervals)
    if upper <= lower:
        return None
    return (
        add(origin, mul(direction, lower)),
        add(origin, mul(direction, upper)),
        upper - lower,
    )


def plane_basis(normal: Vec3) -> tuple[Vec3, Vec3]:
    helper = (0.0, 1.0, 0.0) if abs(normal[1]) < 0.85 else (1.0, 0.0, 0.0)
    first = normalize(cross(helper, normal))
    second = normalize(cross(normal, first))
    return first, second


def rectangle_from_points(points: Sequence[Vec3], normal: Vec3, d_m: float) -> tuple[list[Vec3], float, Vec3]:
    if not points:
        return [], 0.0, (0.0, 0.0, 0.0)
    centroid = tuple(sum(point[index] for point in points) / len(points) for index in range(3))
    centroid = sub(centroid, mul(normal, dot(normal, centroid) + d_m))
    first, second = plane_basis(normal)
    coordinates = [
        (dot(sub(point, centroid), first), dot(sub(point, centroid), second))
        for point in points
    ]
    minimum_first = min(value[0] for value in coordinates)
    maximum_first = max(value[0] for value in coordinates)
    minimum_second = min(value[1] for value in coordinates)
    maximum_second = max(value[1] for value in coordinates)
    corners = [
        add(add(centroid, mul(first, minimum_first)), mul(second, minimum_second)),
        add(add(centroid, mul(first, maximum_first)), mul(second, minimum_second)),
        add(add(centroid, mul(first, maximum_first)), mul(second, maximum_second)),
        add(add(centroid, mul(first, minimum_first)), mul(second, maximum_second)),
    ]
    area = max(0.0, (maximum_first - minimum_first) * (maximum_second - minimum_second))
    return corners, area, centroid


def candidate_weight(candidate: dict[str, Any]) -> float:
    area = max(0.20, float(candidate.get("area_m2", 0.0)))
    keyframes = max(1, int(candidate.get("keyframe_count", 0)))
    observations = max(1, int(candidate.get("observation_count", 1)))
    confirmed_bonus = 1.35 if candidate.get("support_tier") == "CONFIRMED" else 1.0
    return area * keyframes * math.sqrt(float(observations)) * confirmed_bonus


def weighted_mean(values: Iterable[tuple[float, float]]) -> float:
    pairs = list(values)
    weight_sum = sum(weight for _value, weight in pairs)
    if weight_sum <= 1e-12:
        return 0.0
    return sum(value * weight for value, weight in pairs) / weight_sum


def choose_seed_pair(
    candidates_by_id: dict[int, dict[str, Any]],
    hypotheses: Sequence[dict[str, Any]],
    maximum_seed_orthogonality_error_deg: float,
) -> dict[str, Any] | None:
    ranked: list[tuple[int, float, dict[str, Any], str]] = []
    for hypothesis in hypotheses:
        if hypothesis.get("type") != "WALL_WALL":
            continue
        if not hypothesis.get("accepted_diagnostic_hypothesis", False):
            continue
        if float(hypothesis.get("orthogonality_error_deg", 90.0)) > maximum_seed_orthogonality_error_deg:
            continue
        first = candidates_by_id.get(int(hypothesis["plane_a"]))
        second = candidates_by_id.get(int(hypothesis["plane_b"]))
        if first is None or second is None:
            continue

        first_single = first.get("support_tier") == "SINGLE_VIEW_CANDIDATE"
        second_single = second.get("support_tier") == "SINGLE_VIEW_CANDIDATE"
        shared = len(hypothesis.get("shared_keyframe_ids", []))
        combined_keyframes = int(hypothesis.get("combined_keyframe_count", 0))
        confirmed_count = sum(
            candidate.get("support_tier") == "CONFIRMED"
            for candidate in (first, second)
        )

        direct_multiview = not first_single and not second_single
        bootstrap_fragment = (
            confirmed_count >= 1
            and shared >= 1
            and combined_keyframes >= 3
            and first_single != second_single
        )
        if not direct_multiview and not bootstrap_fragment:
            continue

        minimum_support = min(
            int(first.get("keyframe_count", 0)),
            int(second.get("keyframe_count", 0)),
        )
        score = (
            float(hypothesis.get("score", 0.0))
            + 0.35 * min(2, shared) / 2.0
            + 0.15 * min(3, minimum_support) / 3.0
            + 0.05 * confirmed_count
        )
        if shared == 0:
            score -= 0.20

        if direct_multiview:
            mode_priority = 1
            selection_mode = "DIRECT_MULTIVIEW"
        else:
            # The single-view plane only initializes the second Manhattan axis.
            # Actual wall promotion still happens later and still requires the
            # merged cluster to have multiview and shared-keyframe support.
            mode_priority = 0
            selection_mode = "CONFIRMED_WALL_FRAGMENT_BOOTSTRAP"
            score -= 0.05
        ranked.append((mode_priority, score, hypothesis, selection_mode))

    if not ranked:
        return None
    ranked.sort(key=lambda item: (item[0], item[1]), reverse=True)
    result = dict(ranked[0][2])
    result["seed_selection_score"] = ranked[0][1]
    result["seed_selection_mode"] = ranked[0][3]
    return result


def aligned_plane_offset(candidate: dict[str, Any], axis: Vec3) -> tuple[Vec3, float]:
    normal = normalize(tuple(float(value) for value in candidate["normal"]))
    d_m = float(candidate["d_m"])
    if dot(normal, axis) < 0.0:
        normal = mul(normal, -1.0)
        d_m = -d_m
    return normal, d_m


def assign_wall_candidates(
    candidates: Sequence[dict[str, Any]],
    axes: tuple[Vec3, Vec3],
    maximum_assignment_error_deg: float,
    maximum_wall_gravity_error_deg: float,
) -> tuple[list[list[dict[str, Any]]], list[dict[str, Any]]]:
    assigned: list[list[dict[str, Any]]] = [[], []]
    rejected: list[dict[str, Any]] = []
    for candidate in candidates:
        if candidate.get("kind") != "WALL":
            continue
        gravity_value = candidate.get("gravity_alignment_error_deg")
        gravity_error = 90.0 if gravity_value is None else float(gravity_value)
        projected = wall_axis(tuple(float(value) for value in candidate["normal"]))
        errors = [orientation_angle_deg(projected, axis) for axis in axes]
        best_axis = min(range(2), key=lambda index: errors[index])
        reason: str | None = None
        if gravity_error > maximum_wall_gravity_error_deg:
            reason = "WALL_GRAVITY_ALIGNMENT_TOO_WEAK"
        elif errors[best_axis] > maximum_assignment_error_deg:
            reason = "OUTSIDE_MANHATTAN_AXIS_TOLERANCE"
        if reason is not None:
            rejected.append(
                {
                    "source_plane_id": int(candidate["id"]),
                    "reason": reason,
                    "axis_errors_deg": errors,
                    "gravity_alignment_error_deg": gravity_error,
                }
            )
            continue
        record = dict(candidate)
        record["manhattan_axis"] = best_axis + 1
        record["manhattan_axis_error_deg"] = errors[best_axis]
        assigned[best_axis].append(record)
    return assigned, rejected


def cluster_parallel_planes(
    candidates: Sequence[dict[str, Any]],
    axis: Vec3,
    axis_index: int,
    maximum_plane_offset_m: float,
    minimum_area_m2: float,
    minimum_source_keyframes: int,
) -> list[dict[str, Any]]:
    clusters: list[dict[str, Any]] = []
    ordered = sorted(candidates, key=candidate_weight, reverse=True)
    for candidate in ordered:
        _normal, d_m = aligned_plane_offset(candidate, axis)
        weight = candidate_weight(candidate)
        selected: dict[str, Any] | None = None
        selected_delta = float("inf")
        for cluster in clusters:
            mean_d = weighted_mean(cluster["offset_samples"])
            delta = abs(d_m - mean_d)
            if delta <= maximum_plane_offset_m and delta < selected_delta:
                selected = cluster
                selected_delta = delta
        if selected is None:
            selected = {
                "axis_index": axis_index,
                "axis": axis,
                "members": [],
                "offset_samples": [],
            }
            clusters.append(selected)
        selected["members"].append(candidate)
        selected["offset_samples"].append((d_m, weight))

    merged: list[dict[str, Any]] = []
    for cluster_index, cluster in enumerate(clusters, start=1):
        members = cluster["members"]
        source_ids = sorted(int(member["id"]) for member in members)
        keyframes = sorted(
            {
                int(keyframe)
                for member in members
                for keyframe in member.get("keyframe_ids", [])
            }
        )
        d_m = weighted_mean(cluster["offset_samples"])
        corner_points = [
            tuple(float(value) for value in corner)
            for member in members
            for corner in member.get("corners_m", [])
        ]
        corners, area_m2, centroid = rectangle_from_points(corner_points, axis, d_m)
        weights = [candidate_weight(member) for member in members]
        weight_sum = sum(weights)
        rms_m = (
            sum(float(member.get("rms_m", 0.0)) * weight for member, weight in zip(members, weights))
            / max(weight_sum, 1e-12)
        )
        offsets = [sample[0] for sample in cluster["offset_samples"]]
        normal_errors = [
            orientation_angle_deg(
                wall_axis(tuple(float(value) for value in member["normal"])),
                axis,
            )
            for member in members
        ]
        source_confirmed = sum(member.get("support_tier") == "CONFIRMED" for member in members)
        source_multiview = sum(
            member.get("support_tier") in {"CONFIRMED", "MULTIVIEW_CANDIDATE"}
            for member in members
        )
        keyframe_count = len(keyframes)
        source_supported = keyframe_count >= minimum_source_keyframes and area_m2 >= minimum_area_m2
        merged.append(
            {
                "id": f"W{axis_index}_{cluster_index}",
                "kind": "WALL",
                "type": "WALL_CANDIDATE",
                "normal": list(axis),
                "d_m": d_m,
                "centroid_m": list(centroid),
                "area_m2": area_m2,
                "rms_m": rms_m,
                "corners_m": [list(corner) for corner in corners],
                "keyframe_ids": keyframes,
                "keyframe_count": keyframe_count,
                "source_plane_ids": source_ids,
                "fragment_count": len(source_ids),
                "source_confirmed_group_count": source_confirmed,
                "source_multiview_group_count": source_multiview,
                "plane_offset_spread_m": max(offsets) - min(offsets) if offsets else 0.0,
                "maximum_source_axis_error_deg": max(normal_errors) if normal_errors else 0.0,
                "support_tier": "SOURCE_CONFIRMED_MERGED" if source_supported else (
                    "MULTIVIEW_MERGED" if keyframe_count >= 2 else "SINGLE_VIEW_MERGED"
                ),
                "promoted": False,
                "confirmation_source": "SOURCE_SUPPORT" if source_supported else None,
            }
        )
    merged.sort(
        key=lambda item: (
            int(item["keyframe_count"]),
            int(item["source_confirmed_group_count"]),
            float(item["area_m2"]),
        ),
        reverse=True,
    )
    return merged


def make_merged_wall_pairs(
    first_axis_clusters: Sequence[dict[str, Any]],
    second_axis_clusters: Sequence[dict[str, Any]],
    seed_orthogonality_error_deg: float,
    args: argparse.Namespace,
) -> list[dict[str, Any]]:
    pairs: list[dict[str, Any]] = []
    for first in first_axis_clusters:
        for second in second_axis_clusters:
            overlap = rectangle_overlap_on_intersection(first, second)
            overlap_length = overlap[2] if overlap is not None else 0.0
            keyframes_a = set(int(value) for value in first["keyframe_ids"])
            keyframes_b = set(int(value) for value in second["keyframe_ids"])
            shared = sorted(keyframes_a & keyframes_b)
            blockers: list[str] = []
            if seed_orthogonality_error_deg > args.maximum_promoted_corner_orthogonality_error_deg:
                blockers.append("RAW_ORTHOGONALITY_TOO_WEAK")
            if len(keyframes_a) < args.minimum_promoted_wall_keyframes:
                blockers.append("WALL_A_INSUFFICIENT_KEYFRAMES")
            if len(keyframes_b) < args.minimum_promoted_wall_keyframes:
                blockers.append("WALL_B_INSUFFICIENT_KEYFRAMES")
            if len(shared) < args.minimum_shared_corner_keyframes:
                blockers.append("INSUFFICIENT_SHARED_CORNER_KEYFRAMES")
            if overlap is None or overlap_length < args.minimum_intersection_length_m:
                blockers.append("NO_SUPPORTED_RECTANGLE_INTERSECTION")
            if (
                int(first["source_confirmed_group_count"])
                + int(second["source_confirmed_group_count"])
                < 1
            ):
                blockers.append("NO_CONFIRMED_SOURCE_ANCHOR")
            support_score = min(1.0, (len(keyframes_a) + len(keyframes_b)) / 10.0)
            shared_score = min(1.0, len(shared) / 3.0)
            overlap_score = min(1.0, overlap_length / 2.0)
            confirmed_score = min(
                1.0,
                (
                    int(first["source_confirmed_group_count"])
                    + int(second["source_confirmed_group_count"])
                )
                / 2.0,
            )
            fragmentation_score = min(
                1.0,
                (int(first["fragment_count"]) + int(second["fragment_count"])) / 8.0,
            )
            score = (
                0.25 * support_score
                + 0.30 * shared_score
                + 0.20 * overlap_score
                + 0.15 * confirmed_score
                + 0.10 * fragmentation_score
            )
            pair: dict[str, Any] = {
                "type": "WALL_CORNER",
                "wall_a": first["id"],
                "wall_b": second["id"],
                "wall_a_source_plane_ids": first["source_plane_ids"],
                "wall_b_source_plane_ids": second["source_plane_ids"],
                "raw_seed_orthogonality_error_deg": seed_orthogonality_error_deg,
                "snapped_angle_deg": 90.0,
                "intersection_length_m": overlap_length,
                "shared_keyframe_ids": shared,
                "shared_keyframe_count": len(shared),
                "score": score,
                "promoted": not blockers,
                "blocking_reasons": blockers,
            }
            if overlap is not None:
                pair["start_m"] = list(overlap[0])
                pair["end_m"] = list(overlap[1])
            pairs.append(pair)
    pairs.sort(key=lambda item: (bool(item["promoted"]), float(item["score"])), reverse=True)
    return pairs


def evaluate_horizontal_candidates(
    candidates: Sequence[dict[str, Any]],
    promoted_walls: Sequence[dict[str, Any]],
    args: argparse.Namespace,
) -> list[dict[str, Any]]:
    result: list[dict[str, Any]] = []
    for source in candidates:
        if source.get("kind") not in {"CEILING", "FLOOR"}:
            continue
        record = dict(source)
        keyframes = set(int(value) for value in source.get("keyframe_ids", []))
        blockers: list[str] = []
        relations: list[dict[str, Any]] = []
        if len(keyframes) < args.minimum_promoted_horizontal_keyframes:
            blockers.append("HORIZONTAL_REQUIRES_MULTIVIEW_SUPPORT")
        gravity_value = source.get("gravity_alignment_error_deg")
        gravity_error = 90.0 if gravity_value is None else float(gravity_value)
        if gravity_error > args.maximum_horizontal_gravity_error_deg:
            blockers.append("HORIZONTAL_GRAVITY_ALIGNMENT_TOO_WEAK")
        for wall in promoted_walls:
            wall_keyframes = set(int(value) for value in wall.get("keyframe_ids", []))
            angle_error = abs(
                90.0
                - orientation_angle_deg(
                    tuple(float(value) for value in source["normal"]),
                    tuple(float(value) for value in wall["normal"]),
                )
            )
            overlap = rectangle_overlap_on_intersection(source, wall)
            overlap_length = overlap[2] if overlap is not None else 0.0
            shared = sorted(keyframes & wall_keyframes)
            relation = {
                "wall": wall["id"],
                "orthogonality_error_deg": angle_error,
                "intersection_length_m": overlap_length,
                "shared_keyframe_ids": shared,
            }
            relations.append(relation)
            if angle_error > args.maximum_promoted_horizontal_orthogonality_error_deg:
                blockers.append("HORIZONTAL_NOT_ORTHOGONAL_TO_PROMOTED_WALL")
            if overlap is None or overlap_length < args.minimum_intersection_length_m:
                blockers.append("HORIZONTAL_WALL_INTERSECTION_UNSUPPORTED")
            if not shared:
                blockers.append("HORIZONTAL_HAS_NO_SHARED_WALL_KEYFRAME")
        if len(promoted_walls) < 2:
            blockers.append("NO_PROMOTED_WALL_CORNER")
        record["manhattan_relations"] = relations
        record["promoted"] = not blockers
        record["promotion_blocking_reasons"] = sorted(set(blockers))
        result.append(record)
    result.sort(
        key=lambda item: (
            bool(item["promoted"]),
            int(item.get("keyframe_count", 0)),
            float(item.get("area_m2", 0.0)),
        ),
        reverse=True,
    )
    return result


def write_skeleton(
    path: Path,
    planes: Sequence[dict[str, Any]],
    edges: Sequence[dict[str, Any]],
) -> None:
    vertices: list[tuple[Vec3, Color]] = []
    lines_out: list[tuple[int, int]] = []
    wall_colors: list[Color] = [(160, 160, 160), (0, 96, 255)]
    wall_index = 0
    for plane in planes:
        corners = [
            tuple(float(value) for value in corner)
            for corner in plane.get("corners_m", [])
        ]
        if len(corners) < 3:
            continue
        if plane.get("kind") == "WALL":
            color = wall_colors[min(wall_index, len(wall_colors) - 1)]
            wall_index += 1
        elif plane.get("kind") == "CEILING":
            color = (255, 255, 255)
        elif plane.get("kind") == "FLOOR":
            color = (0, 255, 255)
        else:
            color = (255, 128, 0)
        base = len(vertices)
        vertices.extend((corner, color) for corner in corners)
        for index in range(len(corners)):
            lines_out.append((base + index, base + (index + 1) % len(corners)))
    for edge in edges:
        if "start_m" not in edge or "end_m" not in edge:
            continue
        base = len(vertices)
        vertices.append((tuple(float(value) for value in edge["start_m"]), (255, 0, 255)))
        vertices.append((tuple(float(value) for value in edge["end_m"]), (255, 0, 255)))
        lines_out.append((base, base + 1))
    output = [
        "ply",
        "format ascii 1.0",
        "comment MaklerTour Manhattan fused room model",
        "comment coordinate_system X_right_Y_up_Z_forward_meters",
        f"element vertex {len(vertices)}",
        "property float x",
        "property float y",
        "property float z",
        "property uchar red",
        "property uchar green",
        "property uchar blue",
        f"element edge {len(lines_out)}",
        "property int vertex1",
        "property int vertex2",
        "end_header",
    ]
    for xyz, rgb in vertices:
        output.append(
            f"{xyz[0]:.6f} {xyz[1]:.6f} {xyz[2]:.6f} "
            f"{rgb[0]} {rgb[1]} {rgb[2]}"
        )
    output.extend(f"{first} {second}" for first, second in lines_out)
    atomic_write(path, "\n".join(output) + "\n")


def process_session(args: argparse.Namespace) -> dict[str, Any]:
    started = time.monotonic()
    session = Path(args.session).resolve()
    candidates_path = session / "room_plane_candidates_accumulated.json"
    hypotheses_path = session / "room_corner_hypotheses_accumulated.json"
    if not candidates_path.is_file() or not hypotheses_path.is_file():
        raise RuntimeError("multi-plane candidate diagnostics are missing")
    candidates_document = load_json(candidates_path)
    hypotheses_document = load_json(hypotheses_path)
    candidates = candidates_document.get("candidates")
    hypotheses = hypotheses_document.get("pair_hypotheses")
    if not isinstance(candidates, list) or not isinstance(hypotheses, list):
        raise RuntimeError("candidate or pair-hypothesis arrays are missing")
    by_id = {int(candidate["id"]): candidate for candidate in candidates}
    seed = choose_seed_pair(
        by_id,
        hypotheses,
        args.maximum_seed_orthogonality_error_deg,
    )
    if seed is None:
        raise RuntimeError("no multiview wall/wall seed pair is available")
    seed_first = by_id[int(seed["plane_a"])]
    seed_second = by_id[int(seed["plane_b"])]
    raw_axis_a = wall_axis(tuple(float(value) for value in seed_first["normal"]))
    raw_axis_b = wall_axis(tuple(float(value) for value in seed_second["normal"]))
    raw_orthogonality_error = abs(90.0 - orientation_angle_deg(raw_axis_a, raw_axis_b))
    axis_a = raw_axis_a
    axis_b = normalize((-axis_a[2], 0.0, axis_a[0]))
    axis_b = align_axis_sign(axis_b, raw_axis_b)
    assigned, rejected = assign_wall_candidates(
        candidates,
        (axis_a, axis_b),
        args.maximum_manhattan_assignment_error_deg,
        args.maximum_wall_gravity_error_deg,
    )
    minimum_source_keyframes = int(candidates_document.get("minimum_plane_keyframes", 3))
    minimum_area_m2 = float(candidates_document.get("minimum_fused_plane_area_m2", 0.35))
    first_clusters = cluster_parallel_planes(
        assigned[0],
        axis_a,
        1,
        args.maximum_parallel_plane_offset_m,
        minimum_area_m2,
        minimum_source_keyframes,
    )
    second_clusters = cluster_parallel_planes(
        assigned[1],
        axis_b,
        2,
        args.maximum_parallel_plane_offset_m,
        minimum_area_m2,
        minimum_source_keyframes,
    )
    wall_pairs = make_merged_wall_pairs(
        first_clusters,
        second_clusters,
        raw_orthogonality_error,
        args,
    )
    promoted_pair = next((pair for pair in wall_pairs if pair["promoted"]), None)
    clusters_by_id = {
        str(cluster["id"]): cluster
        for cluster in [*first_clusters, *second_clusters]
    }
    promoted_walls: list[dict[str, Any]] = []
    if promoted_pair is not None:
        for wall_id in (promoted_pair["wall_a"], promoted_pair["wall_b"]):
            wall = dict(clusters_by_id[str(wall_id)])
            wall["promoted"] = True
            wall["support_tier"] = "MANHATTAN_CORNER_CONFIRMED"
            wall["confirmation_source"] = "SHARED_KEYFRAME_ORTHOGONAL_CORNER"
            promoted_walls.append(wall)
    horizontal_candidates = evaluate_horizontal_candidates(candidates, promoted_walls, args)
    promoted_horizontal = [candidate for candidate in horizontal_candidates if candidate["promoted"]]
    promoted_planes = [*promoted_walls, *promoted_horizontal]
    promoted_edges = [promoted_pair] if promoted_pair is not None else []

    diagnoses: list[str] = []
    if promoted_pair is not None:
        diagnoses.extend(
            [
                "MANHATTAN_WALL_CORNER_CONFIRMED",
                "FRAGMENTED_WALL_GROUPS_MERGED",
                "SECOND_WALL_PROMOTED_FROM_SHARED_KEYFRAME_CORNER",
            ]
        )
    else:
        diagnoses.append("NO_WALL_CORNER_MET_PROMOTION_RULES")
    if promoted_horizontal:
        diagnoses.append("HORIZONTAL_PLANE_CONFIRMED_WITH_WALL_CORNER")
    elif any(candidate.get("kind") == "CEILING" for candidate in horizontal_candidates):
        diagnoses.append("CEILING_NOT_PROMOTED_WITHOUT_MULTIVIEW_CORNER_SUPPORT")
    else:
        diagnoses.append("NO_CEILING_CANDIDATE")

    planes_document = {
        "schema_version": SCHEMA_VERSION,
        "coordinate_system": "X_right_Y_up_Z_forward_meters",
        "mode": "CONSERVATIVE_MANHATTAN_FRAGMENT_FUSION",
        "seed_pair": seed,
        "raw_seed_orthogonality_error_deg": raw_orthogonality_error,
        "manhattan_axes": [list(axis_a), list(axis_b)],
        "plane_count": len(promoted_planes),
        "planes": promoted_planes,
        "merged_wall_clusters": [*first_clusters, *second_clusters],
        "horizontal_candidates": horizontal_candidates,
        "rejected_wall_candidates": rejected,
    }
    edges_document = {
        "schema_version": SCHEMA_VERSION,
        "coordinate_system": "X_right_Y_up_Z_forward_meters",
        "edge_count": len(promoted_edges),
        "edges": promoted_edges,
        "all_merged_wall_pairs": wall_pairs,
    }
    status = {
        "schema_version": SCHEMA_VERSION,
        "state": "READY",
        "mode": "CONSERVATIVE_MANHATTAN_FRAGMENT_FUSION",
        "session": str(session),
        "seed_source_planes": [int(seed["plane_a"]), int(seed["plane_b"])],
        "raw_seed_orthogonality_error_deg": raw_orthogonality_error,
        "axis_1_source_candidates": len(assigned[0]),
        "axis_2_source_candidates": len(assigned[1]),
        "axis_1_merged_clusters": len(first_clusters),
        "axis_2_merged_clusters": len(second_clusters),
        "promoted_wall_count": len(promoted_walls),
        "promoted_edge_count": len(promoted_edges),
        "promoted_horizontal_count": len(promoted_horizontal),
        "promoted_wall_corner": promoted_pair,
        "diagnosis": diagnoses,
        "processing_ms": (time.monotonic() - started) * 1000.0,
        "files": {
            "planes": "room_planes_manhattan_accumulated.json",
            "edges": "room_edges_manhattan_accumulated.json",
            "skeleton": "room_skeleton_manhattan_accumulated.ply",
            "status": "room_manhattan_fusion_status.json",
        },
    }
    atomic_write(
        session / "room_planes_manhattan_accumulated.json",
        json.dumps(planes_document, indent=2) + "\n",
    )
    atomic_write(
        session / "room_edges_manhattan_accumulated.json",
        json.dumps(edges_document, indent=2) + "\n",
    )
    write_skeleton(
        session / "room_skeleton_manhattan_accumulated.ply",
        promoted_planes,
        promoted_edges,
    )
    atomic_write(
        session / "room_manhattan_fusion_status.json",
        json.dumps(status, indent=2) + "\n",
    )
    return status


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("session", help="MaklerTour dual-phone session directory")
    parser.add_argument("--maximum-seed-orthogonality-error-deg", type=float, default=8.0)
    parser.add_argument("--maximum-promoted-corner-orthogonality-error-deg", type=float, default=8.0)
    parser.add_argument("--maximum-manhattan-assignment-error-deg", type=float, default=20.0)
    parser.add_argument("--maximum-parallel-plane-offset-m", type=float, default=0.55)
    parser.add_argument("--maximum-wall-gravity-error-deg", type=float, default=15.0)
    parser.add_argument("--maximum-horizontal-gravity-error-deg", type=float, default=15.0)
    parser.add_argument("--maximum-promoted-horizontal-orthogonality-error-deg", type=float, default=10.0)
    parser.add_argument("--minimum-promoted-wall-keyframes", type=int, default=2)
    parser.add_argument("--minimum-shared-corner-keyframes", type=int, default=2)
    parser.add_argument("--minimum-promoted-horizontal-keyframes", type=int, default=2)
    parser.add_argument("--minimum-intersection-length-m", type=float, default=0.40)
    return parser


def main() -> int:
    args = build_parser().parse_args()
    try:
        status = process_session(args)
    except Exception as error:  # noqa: BLE001 - CLI must report all fusion failures
        print(json.dumps({"state": "ERROR", "error": str(error)}, indent=2), file=sys.stderr)
        return 1
    print(json.dumps(status, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
