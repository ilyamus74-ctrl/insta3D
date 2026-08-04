#!/usr/bin/env python3
"""Diagnose multi-plane room geometry and Manhattan corner hypotheses.

Reads room_planes_accumulated.json produced by fuse_room_geometry.py. The tool
never promotes candidates into the production room model. It exposes every
fused plane group, explains why it was not confirmed, and searches for
wall/wall and wall/horizontal pairs whose rectangles form a plausible corner.
"""

from __future__ import annotations

import argparse
import json
import math
import sys
import time
from collections import Counter
from pathlib import Path
from typing import Any, Sequence

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


def orientation_angle_deg(a: Vec3, b: Vec3) -> float:
    cosine = max(-1.0, min(1.0, abs(dot(normalize(a), normalize(b)))))
    return math.degrees(math.acos(cosine))


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
        corners = [tuple(float(value) for value in corner) for corner in plane.get("corners_m", [])]
        if len(corners) < 3:
            return None
        projected = [dot(sub(corner, origin), direction) for corner in corners]
        intervals.append((min(projected), max(projected)))
    lower = max(interval[0] for interval in intervals)
    upper = min(interval[1] for interval in intervals)
    if upper <= lower:
        return None
    return add(origin, mul(direction, lower)), add(origin, mul(direction, upper)), upper - lower


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


def plane_kind(plane: dict[str, Any]) -> str:
    explicit = str(plane.get("type", "UNKNOWN"))
    if explicit == "WALL_CANDIDATE":
        return "WALL"
    if explicit == "CEILING_CANDIDATE":
        return "CEILING"
    if explicit == "FLOOR_CANDIDATE":
        return "FLOOR"
    normal = normalize(tuple(float(value) for value in plane.get("normal", (0.0, 0.0, 0.0))))
    vertical_component = abs(normal[1])
    if vertical_component >= 0.82:
        centroid = plane.get("centroid_m", (0.0, 0.0, 0.0))
        return "CEILING" if float(centroid[1]) >= 0.0 else "FLOOR"
    if vertical_component <= 0.35:
        return "WALL"
    return "OBLIQUE"


def support_tier(plane: dict[str, Any], minimum_keyframes: int) -> str:
    keyframes = int(plane.get("keyframe_count", 0))
    if bool(plane.get("confirmed", False)) and keyframes >= minimum_keyframes:
        return "CONFIRMED"
    if keyframes >= 2:
        return "MULTIVIEW_CANDIDATE"
    return "SINGLE_VIEW_CANDIDATE"


def rejection_reasons(
    plane: dict[str, Any], minimum_keyframes: int, minimum_area_m2: float
) -> list[str]:
    reasons: list[str] = []
    if int(plane.get("keyframe_count", 0)) < minimum_keyframes:
        reasons.append("INSUFFICIENT_KEYFRAME_SUPPORT")
    if float(plane.get("area_m2", 0.0)) < minimum_area_m2:
        reasons.append("INSUFFICIENT_FUSED_AREA")
    if not reasons and not bool(plane.get("confirmed", False)):
        reasons.append("SOURCE_MARKED_UNCONFIRMED")
    return reasons


def candidate_record(
    plane: dict[str, Any], minimum_keyframes: int, minimum_area_m2: float
) -> dict[str, Any]:
    normal = normalize(tuple(float(value) for value in plane["normal"]))
    kind = plane_kind(plane)
    if kind in {"CEILING", "FLOOR"}:
        gravity_error = math.degrees(math.acos(max(-1.0, min(1.0, abs(normal[1])))))
    elif kind == "WALL":
        gravity_error = math.degrees(math.asin(max(-1.0, min(1.0, abs(normal[1])))))
    else:
        gravity_error = None
    result = dict(plane)
    result.update(
        {
            "kind": kind,
            "support_tier": support_tier(plane, minimum_keyframes),
            "rejection_reasons": rejection_reasons(plane, minimum_keyframes, minimum_area_m2),
            "gravity_alignment_error_deg": gravity_error,
        }
    )
    return result


def relation_type(first_kind: str, second_kind: str) -> str | None:
    kinds = {first_kind, second_kind}
    if first_kind == second_kind == "WALL":
        return "WALL_WALL"
    if "WALL" in kinds and "CEILING" in kinds:
        return "WALL_CEILING"
    if "WALL" in kinds and "FLOOR" in kinds:
        return "WALL_FLOOR"
    return None


def make_hypotheses(
    candidates: Sequence[dict[str, Any]],
    maximum_orthogonality_error_deg: float,
    minimum_intersection_length_m: float,
    maximum_wall_gravity_error_deg: float,
    maximum_horizontal_gravity_error_deg: float,
) -> list[dict[str, Any]]:
    hypotheses: list[dict[str, Any]] = []
    for index, first in enumerate(candidates):
        for second in candidates[index + 1 :]:
            kind = relation_type(str(first["kind"]), str(second["kind"]))
            if kind is None:
                continue
            angle = orientation_angle_deg(
                tuple(float(value) for value in first["normal"]),
                tuple(float(value) for value in second["normal"]),
            )
            orthogonality_error = abs(90.0 - angle)
            overlap = rectangle_overlap_on_intersection(first, second)
            overlap_length = overlap[2] if overlap is not None else 0.0
            keyframes_a = set(int(value) for value in first.get("keyframe_ids", []))
            keyframes_b = set(int(value) for value in second.get("keyframe_ids", []))
            shared_keyframes = sorted(keyframes_a & keyframes_b)
            blockers: list[str] = []
            warnings: list[str] = []
            if orthogonality_error > maximum_orthogonality_error_deg:
                blockers.append("NOT_NEAR_ORTHOGONAL")
            if overlap is None or overlap_length < minimum_intersection_length_m:
                blockers.append("NO_SUPPORTED_RECTANGLE_INTERSECTION")
            combined_keyframes = len(keyframes_a | keyframes_b)
            if combined_keyframes < 3:
                blockers.append("INSUFFICIENT_COMBINED_KEYFRAMES")
            if (
                first["support_tier"] == "SINGLE_VIEW_CANDIDATE"
                and second["support_tier"] == "SINGLE_VIEW_CANDIDATE"
            ):
                blockers.append("NO_MULTIVIEW_ANCHOR")
            for candidate in (first, second):
                gravity_error = candidate.get("gravity_alignment_error_deg")
                if gravity_error is None:
                    blockers.append("OBLIQUE_PLANE_NOT_SUPPORTED")
                    continue
                limit = (
                    maximum_wall_gravity_error_deg
                    if candidate["kind"] == "WALL"
                    else maximum_horizontal_gravity_error_deg
                )
                if float(gravity_error) > limit:
                    blockers.append("GRAVITY_ALIGNMENT_TOO_WEAK")
            if not shared_keyframes:
                warnings.append("NO_SHARED_KEYFRAME_SUPPORT")
            angle_score = max(0.0, 1.0 - orthogonality_error / maximum_orthogonality_error_deg)
            overlap_score = min(1.0, overlap_length / 1.0)
            support_score = min(1.0, (combined_keyframes + len(shared_keyframes)) / 6.0)
            confirmation_score = (
                float(bool(first.get("confirmed"))) + float(bool(second.get("confirmed")))
            ) / 2.0
            gravity_values = [
                float(candidate.get("gravity_alignment_error_deg") or 90.0)
                for candidate in (first, second)
            ]
            gravity_score = max(0.0, 1.0 - sum(gravity_values) / (2.0 * maximum_horizontal_gravity_error_deg))
            score = (
                0.35 * angle_score
                + 0.20 * overlap_score
                + 0.20 * support_score
                + 0.10 * confirmation_score
                + 0.15 * gravity_score
            )
            hypothesis: dict[str, Any] = {
                "type": kind,
                "plane_a": int(first["id"]),
                "plane_b": int(second["id"]),
                "plane_a_support_tier": first["support_tier"],
                "plane_b_support_tier": second["support_tier"],
                "angle_deg": angle,
                "orthogonality_error_deg": orthogonality_error,
                "intersection_length_m": overlap_length,
                "shared_keyframe_ids": shared_keyframes,
                "combined_keyframe_count": combined_keyframes,
                "score": score,
                "accepted_diagnostic_hypothesis": not blockers,
                "blocking_reasons": sorted(set(blockers)),
                "support_warnings": warnings,
            }
            if overlap is not None:
                hypothesis["start_m"] = list(overlap[0])
                hypothesis["end_m"] = list(overlap[1])
            hypotheses.append(hypothesis)
    hypotheses.sort(key=lambda item: (bool(item["accepted_diagnostic_hypothesis"]), float(item["score"])), reverse=True)
    return hypotheses


def make_room_triples(
    candidates: Sequence[dict[str, Any]],
    pair_hypotheses: Sequence[dict[str, Any]],
    maximum_orthogonality_error_deg: float,
) -> list[dict[str, Any]]:
    by_id = {int(candidate["id"]): candidate for candidate in candidates}
    triples: list[dict[str, Any]] = []
    wall_pairs = [
        item for item in pair_hypotheses
        if item["type"] == "WALL_WALL" and item["accepted_diagnostic_hypothesis"]
    ]
    horizontal = [candidate for candidate in candidates if candidate["kind"] in {"CEILING", "FLOOR"}]
    for pair in wall_pairs:
        first = by_id[int(pair["plane_a"])]
        second = by_id[int(pair["plane_b"])]
        for top in horizontal:
            error_a = abs(
                90.0
                - orientation_angle_deg(
                    tuple(float(value) for value in first["normal"]),
                    tuple(float(value) for value in top["normal"]),
                )
            )
            error_b = abs(
                90.0
                - orientation_angle_deg(
                    tuple(float(value) for value in second["normal"]),
                    tuple(float(value) for value in top["normal"]),
                )
            )
            blockers: list[str] = []
            if error_a > maximum_orthogonality_error_deg:
                blockers.append("HORIZONTAL_NOT_ORTHOGONAL_TO_WALL_A")
            if error_b > maximum_orthogonality_error_deg:
                blockers.append("HORIZONTAL_NOT_ORTHOGONAL_TO_WALL_B")
            score = float(pair["score"]) + 0.25 * max(
                0.0,
                1.0 - (error_a + error_b) / (2.0 * maximum_orthogonality_error_deg),
            )
            triples.append(
                {
                    "type": "TWO_WALLS_WITH_" + str(top["kind"]),
                    "wall_a": int(first["id"]),
                    "wall_b": int(second["id"]),
                    "horizontal_plane": int(top["id"]),
                    "wall_pair_score": pair["score"],
                    "horizontal_error_to_wall_a_deg": error_a,
                    "horizontal_error_to_wall_b_deg": error_b,
                    "score": score,
                    "accepted_diagnostic_hypothesis": not blockers,
                    "blocking_reasons": blockers,
                }
            )
    triples.sort(key=lambda item: (bool(item["accepted_diagnostic_hypothesis"]), float(item["score"])), reverse=True)
    return triples


def plane_color(candidate: dict[str, Any]) -> Color:
    if candidate["support_tier"] == "CONFIRMED":
        return (0, 255, 0)
    kind = candidate["kind"]
    if kind == "WALL":
        return (0, 96, 255) if candidate["support_tier"] == "MULTIVIEW_CANDIDATE" else (64, 64, 180)
    if kind == "CEILING":
        return (255, 255, 255)
    if kind == "FLOOR":
        return (0, 255, 255)
    return (255, 128, 0)


def write_candidate_skeleton(
    path: Path,
    candidates: Sequence[dict[str, Any]],
    hypotheses: Sequence[dict[str, Any]],
) -> None:
    vertices: list[tuple[Vec3, Color]] = []
    edges: list[tuple[int, int]] = []
    for candidate in candidates:
        corners = [tuple(float(value) for value in corner) for corner in candidate.get("corners_m", [])]
        if len(corners) < 3:
            continue
        base = len(vertices)
        color = plane_color(candidate)
        vertices.extend((corner, color) for corner in corners)
        for index in range(len(corners)):
            edges.append((base + index, base + (index + 1) % len(corners)))
    for hypothesis in hypotheses:
        if not hypothesis["accepted_diagnostic_hypothesis"]:
            continue
        if "start_m" not in hypothesis or "end_m" not in hypothesis:
            continue
        base = len(vertices)
        start = tuple(float(value) for value in hypothesis["start_m"])
        end = tuple(float(value) for value in hypothesis["end_m"])
        vertices.append((start, (255, 0, 255)))
        vertices.append((end, (255, 0, 255)))
        edges.append((base, base + 1))
    lines = [
        "ply",
        "format ascii 1.0",
        "comment MaklerTour multi-plane candidate and corner diagnostics",
        "comment coordinate_system X_right_Y_up_Z_forward_meters",
        f"element vertex {len(vertices)}",
        "property float x",
        "property float y",
        "property float z",
        "property uchar red",
        "property uchar green",
        "property uchar blue",
        f"element edge {len(edges)}",
        "property int vertex1",
        "property int vertex2",
        "end_header",
    ]
    for xyz, rgb in vertices:
        lines.append(f"{xyz[0]:.6f} {xyz[1]:.6f} {xyz[2]:.6f} {rgb[0]} {rgb[1]} {rgb[2]}")
    lines.extend(f"{first} {second}" for first, second in edges)
    atomic_write(path, "\n".join(lines) + "\n")


def process_session(args: argparse.Namespace) -> dict[str, Any]:
    started = time.monotonic()
    session = Path(args.session).resolve()
    planes_path = session / "room_planes_accumulated.json"
    if not planes_path.is_file():
        raise RuntimeError(f"room plane document not found: {planes_path}")
    planes_document = load_json(planes_path)
    source_groups = planes_document.get("all_groups")
    if not isinstance(source_groups, list):
        raise RuntimeError("room_planes_accumulated.json does not contain all_groups")
    minimum_keyframes = int(planes_document.get("minimum_keyframes", 3))
    minimum_area_m2 = args.minimum_fused_plane_area_m2
    fusion_diagnostics_path = session / "room_fusion_diagnostics.json"
    if fusion_diagnostics_path.is_file():
        fusion_diagnostics = load_json(fusion_diagnostics_path)
        parameters = fusion_diagnostics.get("parameters", {})
        if isinstance(parameters, dict):
            minimum_area_m2 = float(parameters.get("minimum_fused_plane_area_m2", minimum_area_m2))
    candidates = [candidate_record(group, minimum_keyframes, minimum_area_m2) for group in source_groups]
    hypotheses = make_hypotheses(
        candidates,
        args.maximum_orthogonality_error_deg,
        args.minimum_intersection_length_m,
        args.maximum_wall_gravity_error_deg,
        args.maximum_horizontal_gravity_error_deg,
    )
    triples = make_room_triples(candidates, hypotheses, args.maximum_orthogonality_error_deg)
    accepted_pairs = [item for item in hypotheses if item["accepted_diagnostic_hypothesis"]]
    accepted_triples = [item for item in triples if item["accepted_diagnostic_hypothesis"]]
    best_wall_corner = next((item for item in accepted_pairs if item["type"] == "WALL_WALL"), None)
    best_ceiling_relation = next((item for item in accepted_pairs if item["type"] == "WALL_CEILING"), None)
    by_id = {int(candidate["id"]): candidate for candidate in candidates}
    fragmented_wall_counterparts: list[dict[str, Any]] = []
    for anchor in candidates:
        if anchor["kind"] != "WALL" or anchor["support_tier"] != "CONFIRMED":
            continue
        members: list[dict[str, Any]] = []
        for hypothesis in accepted_pairs:
            if hypothesis["type"] != "WALL_WALL":
                continue
            if int(anchor["id"]) not in {int(hypothesis["plane_a"]), int(hypothesis["plane_b"])}:
                continue
            other_id = (
                int(hypothesis["plane_b"])
                if int(hypothesis["plane_a"]) == int(anchor["id"])
                else int(hypothesis["plane_a"])
            )
            other = by_id[other_id]
            if other["support_tier"] == "CONFIRMED":
                continue
            members.append({
                "plane_id": other_id,
                "support_tier": other["support_tier"],
                "keyframe_ids": other.get("keyframe_ids", []),
                "score": hypothesis["score"],
                "orthogonality_error_deg": hypothesis["orthogonality_error_deg"],
                "intersection_length_m": hypothesis["intersection_length_m"],
            })
        member_keyframes = sorted({
            int(keyframe)
            for member in members
            for keyframe in member["keyframe_ids"]
        })
        if members:
            fragmented_wall_counterparts.append({
                "confirmed_anchor_plane": int(anchor["id"]),
                "candidate_plane_ids": [member["plane_id"] for member in members],
                "candidate_keyframe_ids": member_keyframes,
                "candidate_keyframe_count": len(member_keyframes),
                "candidate_group_count": len(members),
                "members": sorted(members, key=lambda item: float(item["score"]), reverse=True),
            })
    fragmented_wall_counterparts.sort(
        key=lambda item: (int(item["candidate_keyframe_count"]), int(item["candidate_group_count"])),
        reverse=True,
    )
    diagnoses: list[str] = []
    if best_wall_corner is not None:
        a = next(candidate for candidate in candidates if int(candidate["id"]) == int(best_wall_corner["plane_a"]))
        b = next(candidate for candidate in candidates if int(candidate["id"]) == int(best_wall_corner["plane_b"]))
        if a["support_tier"] == "CONFIRMED" or b["support_tier"] == "CONFIRMED":
            if a["support_tier"] != "CONFIRMED" or b["support_tier"] != "CONFIRMED":
                diagnoses.append("SECOND_WALL_PRESENT_BUT_UNCONFIRMED")
                if fragmented_wall_counterparts and fragmented_wall_counterparts[0]["candidate_keyframe_count"] >= 2:
                    diagnoses.append("SECOND_WALL_EVIDENCE_FRAGMENTED_ACROSS_GROUPS")
        else:
            diagnoses.append("WALL_CORNER_PRESENT_ONLY_IN_CANDIDATE_GROUPS")
    else:
        diagnoses.append("NO_GEOMETRIC_WALL_CORNER_HYPOTHESIS")
    ceiling_candidates = [candidate for candidate in candidates if candidate["kind"] == "CEILING"]
    if ceiling_candidates:
        if any(candidate["support_tier"] == "CONFIRMED" for candidate in ceiling_candidates):
            diagnoses.append("CEILING_CONFIRMED")
        elif any(candidate["support_tier"] == "MULTIVIEW_CANDIDATE" for candidate in ceiling_candidates):
            diagnoses.append("CEILING_PRESENT_BUT_BELOW_CONFIRMATION_THRESHOLD")
        else:
            diagnoses.append("CEILING_PRESENT_SINGLE_VIEW_ONLY")
    else:
        diagnoses.append("NO_CEILING_CANDIDATE")
    if accepted_triples:
        diagnoses.append("MANHATTAN_ROOM_CORNER_TRIPLE_PRESENT")
    rejection_counts = Counter(
        reason for candidate in candidates for reason in candidate["rejection_reasons"]
    )
    candidate_document = {
        "schema_version": SCHEMA_VERSION,
        "coordinate_system": "X_right_Y_up_Z_forward_meters",
        "source": "room_planes_accumulated.json/all_groups",
        "minimum_plane_keyframes": minimum_keyframes,
        "minimum_fused_plane_area_m2": minimum_area_m2,
        "candidate_count": len(candidates),
        "candidates": candidates,
    }
    hypotheses_document = {
        "schema_version": SCHEMA_VERSION,
        "coordinate_system": "X_right_Y_up_Z_forward_meters",
        "maximum_orthogonality_error_deg": args.maximum_orthogonality_error_deg,
        "minimum_intersection_length_m": args.minimum_intersection_length_m,
        "pair_hypothesis_count": len(hypotheses),
        "accepted_pair_hypothesis_count": len(accepted_pairs),
        "room_triple_count": len(triples),
        "accepted_room_triple_count": len(accepted_triples),
        "best_wall_corner": best_wall_corner,
        "best_ceiling_relation": best_ceiling_relation,
        "fragmented_wall_counterparts": fragmented_wall_counterparts,
        "pair_hypotheses": hypotheses,
        "room_triples": triples,
    }
    status = {
        "schema_version": SCHEMA_VERSION,
        "state": "READY",
        "mode": "MULTI_PLANE_MANHATTAN_CORNER_DIAGNOSTICS",
        "session": str(session),
        "source_confirmed_planes": int(planes_document.get("confirmed_plane_count", 0)),
        "candidate_plane_groups": len(candidates),
        "wall_candidates": sum(candidate["kind"] == "WALL" for candidate in candidates),
        "ceiling_candidates": len(ceiling_candidates),
        "floor_candidates": sum(candidate["kind"] == "FLOOR" for candidate in candidates),
        "multiview_unconfirmed_candidates": sum(
            candidate["support_tier"] == "MULTIVIEW_CANDIDATE" for candidate in candidates
        ),
        "accepted_wall_corner_hypotheses": sum(
            item["type"] == "WALL_WALL" and item["accepted_diagnostic_hypothesis"]
            for item in hypotheses
        ),
        "accepted_wall_ceiling_hypotheses": sum(
            item["type"] == "WALL_CEILING" and item["accepted_diagnostic_hypothesis"]
            for item in hypotheses
        ),
        "accepted_room_corner_triples": len(accepted_triples),
        "diagnosis": diagnoses,
        "candidate_rejection_reason_counts": dict(sorted(rejection_counts.items())),
        "best_wall_corner": best_wall_corner,
        "best_ceiling_relation": best_ceiling_relation,
        "fragmented_wall_counterparts": fragmented_wall_counterparts,
        "processing_ms": (time.monotonic() - started) * 1000.0,
        "files": {
            "candidates": "room_plane_candidates_accumulated.json",
            "corner_hypotheses": "room_corner_hypotheses_accumulated.json",
            "candidate_skeleton": "room_candidate_skeleton_accumulated.ply",
            "status": "room_multi_plane_status.json",
        },
    }
    atomic_write(
        session / "room_plane_candidates_accumulated.json",
        json.dumps(candidate_document, indent=2) + "\n",
    )
    atomic_write(
        session / "room_corner_hypotheses_accumulated.json",
        json.dumps(hypotheses_document, indent=2) + "\n",
    )
    write_candidate_skeleton(
        session / "room_candidate_skeleton_accumulated.ply",
        candidates,
        hypotheses,
    )
    atomic_write(
        session / "room_multi_plane_status.json",
        json.dumps(status, indent=2) + "\n",
    )
    return status


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("session", help="MaklerTour dual-phone session directory")
    parser.add_argument("--maximum-orthogonality-error-deg", type=float, default=18.0)
    parser.add_argument("--minimum-intersection-length-m", type=float, default=0.40)
    parser.add_argument("--maximum-wall-gravity-error-deg", type=float, default=15.0)
    parser.add_argument("--maximum-horizontal-gravity-error-deg", type=float, default=25.0)
    parser.add_argument("--minimum-fused-plane-area-m2", type=float, default=0.35)
    return parser


def main() -> int:
    args = build_parser().parse_args()
    try:
        status = process_session(args)
    except Exception as error:  # noqa: BLE001 - diagnostic CLI must report failures
        print(json.dumps({"state": "ERROR", "error": str(error)}, indent=2), file=sys.stderr)
        return 1
    print(json.dumps(status, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
