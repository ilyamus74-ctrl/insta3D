#!/usr/bin/env python3
"""
Align two disconnected COLMAP components using bridge-frame camera poses.

The common database contains cross-component image matches with systematic
frame-number offsets (for example +128). Those matched image pairs provide
camera-center and camera-orientation correspondences between sparse models.

Pipeline:
  1. Read model 0/model 1 camera poses from images.txt.
  2. Read cross-component raw matches from database.db.
  3. Group matched image pairs by frame-number offset.
  4. Estimate model1 -> model0 Sim(3) for every sufficiently supported offset
     with RANSAC over camera centers.
  5. Validate every hypothesis with camera orientations and raw 3D<->3D
     correspondences from the same offset group.
  6. Transform and concatenate the already generated dense PLY files.

No COLMAP/dense reconstruction is rerun. No database row is created.
"""

from __future__ import annotations

import argparse
import csv
import datetime as dt
import hashlib
import json
import math
import os
import re
import sqlite3
import struct
import sys
import time
import traceback
from collections import Counter, defaultdict
from pathlib import Path
from typing import Any, Iterable

import numpy as np


MAX_IMAGE_ID = 2_147_483_647

PLY_TYPES: dict[str, tuple[str, str, int]] = {
    "char": ("b", "i1", 1),
    "int8": ("b", "i1", 1),
    "uchar": ("B", "u1", 1),
    "uint8": ("B", "u1", 1),
    "short": ("h", "<i2", 2),
    "int16": ("h", "<i2", 2),
    "ushort": ("H", "<u2", 2),
    "uint16": ("H", "<u2", 2),
    "int": ("i", "<i4", 4),
    "int32": ("i", "<i4", 4),
    "uint": ("I", "<u4", 4),
    "uint32": ("I", "<u4", 4),
    "float": ("f", "<f4", 4),
    "float32": ("f", "<f4", 4),
    "double": ("d", "<f8", 8),
    "float64": ("d", "<f8", 8),
}


def utc_now() -> str:
    return dt.datetime.now(dt.timezone.utc).isoformat()


def write_json_atomic(path: Path, payload: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix(path.suffix + ".tmp")
    tmp.write_text(
        json.dumps(payload, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )
    os.replace(tmp, path)


def md5_file(path: Path) -> str:
    digest = hashlib.md5()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(8 * 1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def decode_pair_id(pair_id: int) -> tuple[int, int]:
    image_id2 = int(pair_id % MAX_IMAGE_ID)
    image_id1 = int((pair_id - image_id2) // MAX_IMAGE_ID)
    return image_id1, image_id2


def qvec_to_rotmat(qvec: Iterable[float]) -> np.ndarray:
    w, x, y, z = [float(value) for value in qvec]
    return np.array(
        [
            [
                1 - 2 * y * y - 2 * z * z,
                2 * x * y - 2 * w * z,
                2 * z * x + 2 * w * y,
            ],
            [
                2 * x * y + 2 * w * z,
                1 - 2 * x * x - 2 * z * z,
                2 * y * z - 2 * w * x,
            ],
            [
                2 * x * z - 2 * w * y,
                2 * y * z + 2 * w * x,
                1 - 2 * x * x - 2 * y * y,
            ],
        ],
        dtype=float,
    )


def frame_number(name: str) -> int | None:
    match = re.search(r"(\d+)(?=\.[^.]+$)", Path(name).name)
    return int(match.group(1)) if match else None


def parse_images_txt(path: Path) -> dict[str, dict[str, Any]]:
    lines = path.read_text(encoding="utf-8", errors="replace").splitlines()
    output: dict[str, dict[str, Any]] = {}

    index = 0
    while index < len(lines):
        text = lines[index].strip()
        index += 1

        if not text or text.startswith("#"):
            continue

        parts = text.split(maxsplit=9)
        if len(parts) < 10:
            continue

        try:
            image_id = int(parts[0])
            qvec = [float(value) for value in parts[1:5]]
            tvec = np.array(
                [float(value) for value in parts[5:8]],
                dtype=float,
            )
            camera_id = int(parts[8])
        except ValueError:
            continue

        name = parts[9]
        world_to_camera = qvec_to_rotmat(qvec)
        camera_to_world = world_to_camera.T
        center = -camera_to_world @ tvec

        points_line = ""
        if index < len(lines):
            points_line = lines[index].strip()
            index += 1

        point3d_ids: list[int] = []
        if points_line and not points_line.startswith("#"):
            values = points_line.split()
            for offset in range(0, len(values) - 2, 3):
                try:
                    point3d_ids.append(int(values[offset + 2]))
                except ValueError:
                    point3d_ids.append(-1)

        output[name] = {
            "name": name,
            "image_id": image_id,
            "camera_id": camera_id,
            "frame_number": frame_number(name),
            "center": center,
            "camera_to_world": camera_to_world,
            "point3d_ids": point3d_ids,
        }

    return output


def parse_points3d_txt(path: Path) -> dict[int, np.ndarray]:
    output: dict[int, np.ndarray] = {}

    with path.open("r", encoding="utf-8", errors="replace") as handle:
        for line in handle:
            text = line.strip()
            if not text or text.startswith("#"):
                continue

            parts = text.split()
            if len(parts) < 4:
                continue

            try:
                point_id = int(parts[0])
                xyz = np.array(
                    [float(parts[1]), float(parts[2]), float(parts[3])],
                    dtype=float,
                )
            except ValueError:
                continue

            if np.all(np.isfinite(xyz)):
                output[point_id] = xyz

    return output


def robust_diagonal(points: np.ndarray) -> float:
    if len(points) < 3:
        raise RuntimeError("Too few points for robust diagonal")

    q01 = np.quantile(points, 0.01, axis=0)
    q99 = np.quantile(points, 0.99, axis=0)
    diagonal = float(np.linalg.norm(q99 - q01))

    if not np.isfinite(diagonal) or diagonal <= 0:
        raise RuntimeError("Invalid robust diagonal")

    return diagonal


def weighted_umeyama(
    source: np.ndarray,
    target: np.ndarray,
    weights: np.ndarray | None = None,
) -> tuple[float, np.ndarray, np.ndarray]:
    if source.shape != target.shape or source.ndim != 2 or source.shape[1] != 3:
        raise ValueError("Source and target must have shape Nx3")
    if len(source) < 3:
        raise ValueError("At least three correspondences are required")

    if weights is None:
        weights = np.ones(len(source), dtype=float)
    else:
        weights = np.asarray(weights, dtype=float)

    weights = np.maximum(weights, 0)
    total = float(weights.sum())
    if total <= 0:
        raise ValueError("All weights are zero")

    normalized = weights / total

    source_mean = np.sum(source * normalized[:, None], axis=0)
    target_mean = np.sum(target * normalized[:, None], axis=0)

    source_centered = source - source_mean
    target_centered = target - target_mean

    covariance = target_centered.T @ (
        source_centered * normalized[:, None]
    )

    u_matrix, singular_values, vt_matrix = np.linalg.svd(covariance)

    correction = np.eye(3)
    if np.linalg.det(u_matrix @ vt_matrix) < 0:
        correction[2, 2] = -1

    rotation = u_matrix @ correction @ vt_matrix

    source_variance = float(
        np.sum(
            normalized
            * np.sum(source_centered * source_centered, axis=1)
        )
    )
    if source_variance <= 1e-18:
        raise ValueError("Degenerate source geometry")

    scale = float(
        np.sum(singular_values * np.diag(correction))
        / source_variance
    )
    translation = target_mean - scale * (rotation @ source_mean)

    if (
        not np.isfinite(scale)
        or scale <= 0
        or not np.all(np.isfinite(rotation))
        or not np.all(np.isfinite(translation))
    ):
        raise ValueError("Invalid Sim(3)")

    return scale, rotation, translation


def apply_sim3(
    points: np.ndarray,
    scale: float,
    rotation: np.ndarray,
    translation: np.ndarray,
) -> np.ndarray:
    return (scale * (rotation @ points.T)).T + translation


def transform_matrix(
    scale: float,
    rotation: np.ndarray,
    translation: np.ndarray,
) -> np.ndarray:
    matrix = np.eye(4, dtype=float)
    matrix[:3, :3] = scale * rotation
    matrix[:3, 3] = translation
    return matrix


def rotation_angle_degrees(matrix: np.ndarray) -> float:
    cosine = (float(np.trace(matrix)) - 1.0) * 0.5
    cosine = max(-1.0, min(1.0, cosine))
    return math.degrees(math.acos(cosine))


def orientation_errors_degrees(
    global_rotation: np.ndarray,
    source_orientations: np.ndarray,
    anchor_orientations: np.ndarray,
) -> np.ndarray:
    output = np.empty(len(source_orientations), dtype=float)

    for index in range(len(source_orientations)):
        predicted = global_rotation @ source_orientations[index]
        relative = anchor_orientations[index].T @ predicted
        output[index] = rotation_angle_degrees(relative)

    return output


def nondegenerate_triplet(points: np.ndarray) -> bool:
    edge1 = points[1] - points[0]
    edge2 = points[2] - points[0]
    reference = max(
        float(np.linalg.norm(edge1)),
        float(np.linalg.norm(edge2)),
        1e-12,
    )
    area = float(np.linalg.norm(np.cross(edge1, edge2)))
    return area > reference * reference * 1e-4


def decode_matches(
    data: bytes | None,
    rows: int,
    cols: int,
) -> list[tuple[int, int]]:
    if not data or rows <= 0 or cols != 2:
        return []

    expected = rows * cols * 4
    if len(data) < expected:
        return []

    return [
        (int(left), int(right))
        for left, right in struct.iter_unpack("<II", data[:expected])
    ]


def collect_cross_pairs(
    database_path: Path,
    model0_images: dict[str, dict[str, Any]],
    model1_images: dict[str, dict[str, Any]],
    model0_points: dict[int, np.ndarray],
    model1_points: dict[int, np.ndarray],
) -> tuple[
    dict[int, list[dict[str, Any]]],
    dict[int, list[dict[str, Any]]],
    dict[str, Any],
]:
    connection = sqlite3.connect(
        f"file:{database_path}?mode=ro",
        uri=True,
    )

    try:
        name_by_database_id = {
            int(image_id): str(name)
            for image_id, name in connection.execute(
                "SELECT image_id, name FROM images"
            )
        }
        database_id_by_name = {
            name: image_id
            for image_id, name in name_by_database_id.items()
        }

        component_by_database_id: dict[int, int] = {}
        info_by_database_id: dict[int, dict[str, Any]] = {}

        for component, images in (
            (0, model0_images),
            (1, model1_images),
        ):
            for name, info in images.items():
                database_id = database_id_by_name.get(name)
                if database_id is None:
                    continue
                component_by_database_id[database_id] = component
                info_by_database_id[database_id] = info

        image_pairs_by_offset: dict[int, list[dict[str, Any]]] = defaultdict(list)
        point_pairs_by_offset: dict[int, dict[tuple[int, int], dict[str, Any]]] = (
            defaultdict(dict)
        )

        total_cross_pairs = 0
        total_matches = 0
        total_3d3d = 0

        query = """
            SELECT pair_id, rows, cols, data
            FROM matches
            WHERE rows > 0
        """

        for pair_id, rows, cols, data in connection.execute(query):
            database_id1, database_id2 = decode_pair_id(int(pair_id))
            component1 = component_by_database_id.get(database_id1)
            component2 = component_by_database_id.get(database_id2)

            if (
                component1 is None
                or component2 is None
                or component1 == component2
            ):
                continue

            info1 = info_by_database_id[database_id1]
            info2 = info_by_database_id[database_id2]
            matches = decode_matches(data, int(rows), int(cols))
            if not matches:
                continue

            if component1 == 0:
                anchor_info = info1
                source_info = info2
                oriented_matches = [
                    (right_index, left_index)
                    for left_index, right_index in matches
                ]
            else:
                anchor_info = info2
                source_info = info1
                oriented_matches = matches

            anchor_frame = anchor_info.get("frame_number")
            source_frame = source_info.get("frame_number")
            if anchor_frame is None or source_frame is None:
                continue

            offset = int(source_frame - anchor_frame)
            total_cross_pairs += 1
            total_matches += len(matches)

            pair_3d3d = 0

            source_point_ids = source_info["point3d_ids"]
            anchor_point_ids = anchor_info["point3d_ids"]

            for source_feature, anchor_feature in oriented_matches:
                source_point_id = (
                    source_point_ids[source_feature]
                    if source_feature < len(source_point_ids)
                    else -1
                )
                anchor_point_id = (
                    anchor_point_ids[anchor_feature]
                    if anchor_feature < len(anchor_point_ids)
                    else -1
                )

                if (
                    source_point_id < 0
                    or anchor_point_id < 0
                    or source_point_id not in model1_points
                    or anchor_point_id not in model0_points
                ):
                    continue

                pair_3d3d += 1
                total_3d3d += 1

                key = (source_point_id, anchor_point_id)
                item = point_pairs_by_offset[offset].get(key)
                if item is None:
                    item = {
                        "source_point3d_id": source_point_id,
                        "anchor_point3d_id": anchor_point_id,
                        "source_xyz": model1_points[source_point_id],
                        "anchor_xyz": model0_points[anchor_point_id],
                        "support": 0,
                        "image_pairs": set(),
                    }
                    point_pairs_by_offset[offset][key] = item

                item["support"] += 1
                item["image_pairs"].add(
                    f"{anchor_info['name']}|{source_info['name']}"
                )

            image_pairs_by_offset[offset].append(
                {
                    "offset": offset,
                    "anchor_name": anchor_info["name"],
                    "source_name": source_info["name"],
                    "anchor_frame": anchor_frame,
                    "source_frame": source_frame,
                    "anchor_center": anchor_info["center"],
                    "source_center": source_info["center"],
                    "anchor_orientation": anchor_info["camera_to_world"],
                    "source_orientation": source_info["camera_to_world"],
                    "raw_matches": len(matches),
                    "matches_3d3d": pair_3d3d,
                    "weight": (
                        1.0
                        + math.log1p(len(matches))
                        + 0.5 * math.log1p(pair_3d3d)
                    ),
                }
            )

        normalized_point_pairs: dict[int, list[dict[str, Any]]] = {}

        for offset, values in point_pairs_by_offset.items():
            items = []
            for item in values.values():
                items.append(
                    {
                        **item,
                        "image_pairs": sorted(item["image_pairs"]),
                        "image_pair_count": len(item["image_pairs"]),
                    }
                )
            items.sort(
                key=lambda item: (
                    item["support"],
                    item["image_pair_count"],
                ),
                reverse=True,
            )
            normalized_point_pairs[offset] = items

        offset_summary = []
        for offset, pairs in image_pairs_by_offset.items():
            offset_summary.append(
                {
                    "offset": offset,
                    "image_pairs": len(pairs),
                    "raw_matches": sum(
                        item["raw_matches"] for item in pairs
                    ),
                    "matches_3d3d": sum(
                        item["matches_3d3d"] for item in pairs
                    ),
                    "unique_3d3d": len(
                        normalized_point_pairs.get(offset, [])
                    ),
                }
            )

        offset_summary.sort(
            key=lambda item: (
                item["matches_3d3d"],
                item["raw_matches"],
                item["image_pairs"],
            ),
            reverse=True,
        )

        stats = {
            "total_cross_component_image_pairs": total_cross_pairs,
            "total_raw_matches": total_matches,
            "total_raw_3d3d_matches": total_3d3d,
            "offsets": offset_summary,
        }

        return (
            dict(image_pairs_by_offset),
            normalized_point_pairs,
            stats,
        )
    finally:
        connection.close()


def fit_offset_candidate(
    offset: int,
    image_pairs: list[dict[str, Any]],
    point_pairs: list[dict[str, Any]],
    expected_scale: float,
    scale_factor: float,
    sparse_diagonal: float,
    iterations: int,
    seed: int,
) -> dict[str, Any]:
    source_centers = np.asarray(
        [item["source_center"] for item in image_pairs],
        dtype=float,
    )
    anchor_centers = np.asarray(
        [item["anchor_center"] for item in image_pairs],
        dtype=float,
    )
    source_orientations = np.asarray(
        [item["source_orientation"] for item in image_pairs],
        dtype=float,
    )
    anchor_orientations = np.asarray(
        [item["anchor_orientation"] for item in image_pairs],
        dtype=float,
    )
    weights = np.asarray(
        [item["weight"] for item in image_pairs],
        dtype=float,
    )

    camera_span = robust_diagonal(anchor_centers)

    order = np.argsort(
        [item["anchor_frame"] for item in image_pairs]
    )
    ordered_centers = anchor_centers[order]
    steps = np.linalg.norm(
        np.diff(ordered_centers, axis=0),
        axis=1,
    )
    steps = steps[np.isfinite(steps) & (steps > 1e-12)]
    median_step = float(np.median(steps)) if len(steps) else 0.0

    center_threshold = max(
        camera_span * 0.08,
        median_step * 2.5,
        sparse_diagonal * 0.0015,
    )
    orientation_threshold = 35.0

    scale_min = expected_scale / scale_factor
    scale_max = expected_scale * scale_factor

    probabilities = np.sqrt(weights)
    probabilities /= probabilities.sum()

    rng = np.random.default_rng(seed + abs(offset) * 1009)

    best: dict[str, Any] | None = None

    for iteration in range(iterations):
        sample = rng.choice(
            len(image_pairs),
            size=3,
            replace=False,
            p=probabilities,
        )

        if not nondegenerate_triplet(source_centers[sample]):
            continue
        if not nondegenerate_triplet(anchor_centers[sample]):
            continue

        try:
            scale, rotation, translation = weighted_umeyama(
                source_centers[sample],
                anchor_centers[sample],
            )
        except Exception:
            continue

        if not (scale_min <= scale <= scale_max):
            continue

        transformed = apply_sim3(
            source_centers,
            scale,
            rotation,
            translation,
        )
        center_residuals = np.linalg.norm(
            transformed - anchor_centers,
            axis=1,
        )
        orientation_errors = orientation_errors_degrees(
            rotation,
            source_orientations,
            anchor_orientations,
        )

        inliers = (
            (center_residuals <= center_threshold)
            & (orientation_errors <= orientation_threshold)
        )

        inlier_count = int(inliers.sum())
        if inlier_count < 3:
            continue

        weighted_support = float(weights[inliers].sum())
        median_center = float(
            np.median(center_residuals[inliers])
        )
        median_angle = float(
            np.median(orientation_errors[inliers])
        )

        score = (
            weighted_support
            + 1.5 * inlier_count
            - 2.0 * median_center
            / max(center_threshold, 1e-12)
            - 0.03 * median_angle
        )

        candidate = {
            "score": score,
            "iteration": iteration,
            "scale": scale,
            "rotation": rotation,
            "translation": translation,
            "inliers": inliers,
            "center_residuals": center_residuals,
            "orientation_errors": orientation_errors,
        }

        if best is None or (
            candidate["score"],
            int(candidate["inliers"].sum()),
        ) > (
            best["score"],
            int(best["inliers"].sum()),
        ):
            best = candidate

    if best is None:
        return {
            "offset": offset,
            "status": "NO_CAMERA_SIM3",
            "image_pair_count": len(image_pairs),
            "point_pair_count": len(point_pairs),
            "expected_scale": expected_scale,
            "scale_range": [scale_min, scale_max],
            "camera_span": camera_span,
            "median_camera_step": median_step,
            "center_threshold": center_threshold,
            "orientation_threshold_degrees": orientation_threshold,
        }

    inliers = best["inliers"].copy()

    # Robustly refit camera-center Sim(3).
    for _ in range(10):
        if int(inliers.sum()) < 3:
            break

        scale, rotation, translation = weighted_umeyama(
            source_centers[inliers],
            anchor_centers[inliers],
            weights[inliers],
        )

        if not (scale_min <= scale <= scale_max):
            break

        transformed = apply_sim3(
            source_centers,
            scale,
            rotation,
            translation,
        )
        center_residuals = np.linalg.norm(
            transformed - anchor_centers,
            axis=1,
        )
        orientation_errors = orientation_errors_degrees(
            rotation,
            source_orientations,
            anchor_orientations,
        )
        new_inliers = (
            (center_residuals <= center_threshold)
            & (orientation_errors <= orientation_threshold)
        )

        if np.array_equal(new_inliers, inliers):
            inliers = new_inliers
            break

        inliers = new_inliers

    if int(inliers.sum()) < 3:
        return {
            "offset": offset,
            "status": "CAMERA_REFIT_FAILED",
            "image_pair_count": len(image_pairs),
            "point_pair_count": len(point_pairs),
        }

    scale, rotation, translation = weighted_umeyama(
        source_centers[inliers],
        anchor_centers[inliers],
        weights[inliers],
    )

    transformed_centers = apply_sim3(
        source_centers,
        scale,
        rotation,
        translation,
    )
    center_residuals = np.linalg.norm(
        transformed_centers - anchor_centers,
        axis=1,
    )
    orientation_errors = orientation_errors_degrees(
        rotation,
        source_orientations,
        anchor_orientations,
    )

    camera_inlier_count = int(inliers.sum())
    camera_inlier_ratio = camera_inlier_count / len(image_pairs)

    point_threshold = max(
        sparse_diagonal * 0.02,
        center_threshold * 2.0,
    )

    point_inlier_count = 0
    point_inlier_ratio = 0.0
    point_median_residual = float("inf")
    point_p90_residual = float("inf")
    point_residuals: np.ndarray | None = None
    point_inliers: np.ndarray | None = None

    if point_pairs:
        source_points = np.asarray(
            [item["source_xyz"] for item in point_pairs],
            dtype=float,
        )
        anchor_points = np.asarray(
            [item["anchor_xyz"] for item in point_pairs],
            dtype=float,
        )

        transformed_points = apply_sim3(
            source_points,
            scale,
            rotation,
            translation,
        )
        point_residuals = np.linalg.norm(
            transformed_points - anchor_points,
            axis=1,
        )
        point_inliers = point_residuals <= point_threshold
        point_inlier_count = int(point_inliers.sum())
        point_inlier_ratio = point_inlier_count / len(point_pairs)

        if point_inlier_count:
            point_median_residual = float(
                np.median(point_residuals[point_inliers])
            )
            point_p90_residual = float(
                np.quantile(point_residuals[point_inliers], 0.90)
            )

    camera_median_residual = float(
        np.median(center_residuals[inliers])
    )
    camera_p90_residual = float(
        np.quantile(center_residuals[inliers], 0.90)
    )
    orientation_median = float(
        np.median(orientation_errors[inliers])
    )
    orientation_p90 = float(
        np.quantile(orientation_errors[inliers], 0.90)
    )

    # Score prioritizes a consistent bridge trajectory, then sparse point
    # validation. A large point score cannot rescue a bad camera alignment.
    score = (
        8.0 * camera_inlier_ratio
        + 0.15 * camera_inlier_count
        + 2.0 * point_inlier_ratio
        + 0.03 * point_inlier_count
        - 1.5 * camera_median_residual
        / max(center_threshold, 1e-12)
        - 0.02 * orientation_median
    )

    return {
        "offset": offset,
        "status": "CANDIDATE",
        "score": score,
        "image_pair_count": len(image_pairs),
        "camera_inlier_count": camera_inlier_count,
        "camera_inlier_ratio": camera_inlier_ratio,
        "camera_span": camera_span,
        "median_camera_step": median_step,
        "center_threshold": center_threshold,
        "camera_median_residual": camera_median_residual,
        "camera_p90_residual": camera_p90_residual,
        "orientation_threshold_degrees": orientation_threshold,
        "orientation_median_degrees": orientation_median,
        "orientation_p90_degrees": orientation_p90,
        "point_pair_count": len(point_pairs),
        "point_threshold": point_threshold,
        "point_inlier_count": point_inlier_count,
        "point_inlier_ratio": point_inlier_ratio,
        "point_median_residual": point_median_residual,
        "point_p90_residual": point_p90_residual,
        "expected_scale": expected_scale,
        "scale_range": [scale_min, scale_max],
        "scale": scale,
        "rotation_matrix": rotation.tolist(),
        "translation": translation.tolist(),
        "matrix_4x4": transform_matrix(
            scale,
            rotation,
            translation,
        ).tolist(),
        "camera_pairs": [
            {
                "anchor_name": item["anchor_name"],
                "source_name": item["source_name"],
                "raw_matches": item["raw_matches"],
                "matches_3d3d": item["matches_3d3d"],
                "inlier": bool(inliers[index]),
                "center_residual": float(center_residuals[index]),
                "orientation_error_degrees": float(
                    orientation_errors[index]
                ),
            }
            for index, item in enumerate(image_pairs)
        ],
        "point_pairs": (
            [
                {
                    "source_point3d_id": item["source_point3d_id"],
                    "anchor_point3d_id": item["anchor_point3d_id"],
                    "support": item["support"],
                    "image_pair_count": item["image_pair_count"],
                    "inlier": bool(point_inliers[index]),
                    "residual": float(point_residuals[index]),
                }
                for index, item in enumerate(point_pairs)
            ]
            if point_residuals is not None
            and point_inliers is not None
            else []
        ),
    }


def parse_ply_header(path: Path) -> dict[str, Any]:
    header_lines: list[bytes] = []
    properties: list[tuple[str, str]] = []
    format_name: str | None = None
    vertex_count: int | None = None
    face_count = 0
    in_vertex = False
    header_bytes = 0

    with path.open("rb") as handle:
        while True:
            line = handle.readline()
            if not line:
                raise RuntimeError(f"Invalid PLY header: {path}")

            header_bytes += len(line)
            header_lines.append(line)
            text = line.decode("ascii", "replace").strip()

            if text.startswith("format "):
                format_name = text.split()[1]
            elif text.startswith("element "):
                parts = text.split()
                name = parts[1]
                count = int(parts[2])
                in_vertex = name == "vertex"

                if name == "vertex":
                    vertex_count = count
                elif name == "face":
                    face_count = count
                elif count > 0:
                    raise RuntimeError(
                        f"Unsupported non-empty PLY element {name}"
                    )
            elif in_vertex and text.startswith("property "):
                parts = text.split()
                if len(parts) != 3 or parts[1] == "list":
                    raise RuntimeError(
                        f"Unsupported PLY property: {text}"
                    )
                if parts[1] not in PLY_TYPES:
                    raise RuntimeError(
                        f"Unsupported PLY type: {parts[1]}"
                    )
                properties.append((parts[1], parts[2]))

            if text == "end_header":
                break

    if format_name not in ("ascii", "binary_little_endian"):
        raise RuntimeError(
            f"Unsupported PLY format {format_name}: {path}"
        )
    if vertex_count is None:
        raise RuntimeError(f"No PLY vertex count: {path}")
    if face_count > 0:
        raise RuntimeError(
            f"PLY contains faces; point cloud required: {path}"
        )

    names = [name for _, name in properties]
    for required in ("x", "y", "z"):
        if required not in names:
            raise RuntimeError(
                f"PLY missing {required} coordinate: {path}"
            )

    return {
        "path": path,
        "format": format_name,
        "vertex_count": vertex_count,
        "properties": properties,
        "property_names": names,
        "header_lines": header_lines,
        "header_bytes": header_bytes,
    }


def ply_dtype(properties: list[tuple[str, str]]) -> np.dtype:
    return np.dtype(
        [
            (name, PLY_TYPES[property_type][1])
            for property_type, name in properties
        ]
    )


def read_binary_vertices(info: dict[str, Any]) -> np.ndarray:
    dtype = ply_dtype(info["properties"])
    with info["path"].open("rb") as handle:
        handle.seek(info["header_bytes"])
        data = np.fromfile(
            handle,
            dtype=dtype,
            count=info["vertex_count"],
        )

    if len(data) != info["vertex_count"]:
        raise RuntimeError(f"Truncated PLY: {info['path']}")

    return data


def transform_binary_vertices(
    data: np.ndarray,
    scale: float,
    rotation: np.ndarray,
    translation: np.ndarray,
) -> np.ndarray:
    output = data.copy()

    points = np.column_stack(
        [
            data["x"].astype(float),
            data["y"].astype(float),
            data["z"].astype(float),
        ]
    )
    transformed = apply_sim3(
        points,
        scale,
        rotation,
        translation,
    )

    output["x"] = transformed[:, 0]
    output["y"] = transformed[:, 1]
    output["z"] = transformed[:, 2]

    names = set(data.dtype.names or ())
    if {"nx", "ny", "nz"}.issubset(names):
        normals = np.column_stack(
            [
                data["nx"].astype(float),
                data["ny"].astype(float),
                data["nz"].astype(float),
            ]
        )
        rotated = (rotation @ normals.T).T
        lengths = np.linalg.norm(rotated, axis=1)
        valid = lengths > 1e-12
        rotated[valid] /= lengths[valid, None]

        output["nx"] = rotated[:, 0]
        output["ny"] = rotated[:, 1]
        output["nz"] = rotated[:, 2]

    return output


def rewrite_header(
    header_lines: list[bytes],
    vertex_count: int,
) -> list[bytes]:
    output = []
    for line in header_lines:
        text = line.decode("ascii", "replace").strip()
        if text.startswith("element vertex "):
            output.append(
                f"element vertex {vertex_count}\n".encode("ascii")
            )
        else:
            output.append(line)
    return output


def write_binary_ply(
    path: Path,
    header_lines: list[bytes],
    arrays: Iterable[np.ndarray],
    vertex_count: int,
) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("wb") as handle:
        for line in rewrite_header(header_lines, vertex_count):
            handle.write(line)
        for array in arrays:
            array.tofile(handle)


def read_ascii_vertices(info: dict[str, Any]) -> list[list[str]]:
    rows = []
    with info["path"].open(
        "r",
        encoding="ascii",
        errors="replace",
    ) as handle:
        for _ in info["header_lines"]:
            handle.readline()
        for _ in range(info["vertex_count"]):
            line = handle.readline()
            if not line:
                raise RuntimeError(f"Truncated PLY: {info['path']}")
            rows.append(line.strip().split())
    return rows


def transform_ascii_vertices(
    rows: list[list[str]],
    property_names: list[str],
    scale: float,
    rotation: np.ndarray,
    translation: np.ndarray,
) -> list[list[str]]:
    x_index = property_names.index("x")
    y_index = property_names.index("y")
    z_index = property_names.index("z")

    normal_indexes = (
        (
            property_names.index("nx"),
            property_names.index("ny"),
            property_names.index("nz"),
        )
        if all(
            name in property_names
            for name in ("nx", "ny", "nz")
        )
        else None
    )

    output = []

    for row in rows:
        new_row = row.copy()

        point = np.array(
            [
                float(row[x_index]),
                float(row[y_index]),
                float(row[z_index]),
            ],
            dtype=float,
        )
        transformed = scale * (rotation @ point) + translation

        new_row[x_index] = format(float(transformed[0]), ".9g")
        new_row[y_index] = format(float(transformed[1]), ".9g")
        new_row[z_index] = format(float(transformed[2]), ".9g")

        if normal_indexes is not None:
            nx_index, ny_index, nz_index = normal_indexes
            normal = np.array(
                [
                    float(row[nx_index]),
                    float(row[ny_index]),
                    float(row[nz_index]),
                ],
                dtype=float,
            )
            normal = rotation @ normal
            length = float(np.linalg.norm(normal))
            if length > 1e-12:
                normal /= length

            new_row[nx_index] = format(float(normal[0]), ".9g")
            new_row[ny_index] = format(float(normal[1]), ".9g")
            new_row[nz_index] = format(float(normal[2]), ".9g")

        output.append(new_row)

    return output


def write_ascii_ply(
    path: Path,
    header_lines: list[bytes],
    row_sets: Iterable[list[list[str]]],
    vertex_count: int,
) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("wb") as handle:
        for line in rewrite_header(header_lines, vertex_count):
            handle.write(line)
        for rows in row_sets:
            for row in rows:
                handle.write(
                    (" ".join(row) + "\n").encode("ascii")
                )


def transform_and_merge_ply(
    anchor_path: Path,
    source_path: Path,
    aligned_path: Path,
    merged_path: Path,
    scale: float,
    rotation: np.ndarray,
    translation: np.ndarray,
) -> dict[str, Any]:
    anchor_info = parse_ply_header(anchor_path)
    source_info = parse_ply_header(source_path)

    if anchor_info["format"] != source_info["format"]:
        raise RuntimeError("PLY formats differ")
    if anchor_info["properties"] != source_info["properties"]:
        raise RuntimeError("PLY vertex layouts differ")

    anchor_count = int(anchor_info["vertex_count"])
    source_count = int(source_info["vertex_count"])
    total_count = anchor_count + source_count

    if anchor_info["format"] == "binary_little_endian":
        anchor_data = read_binary_vertices(anchor_info)
        source_data = read_binary_vertices(source_info)
        aligned_data = transform_binary_vertices(
            source_data,
            scale,
            rotation,
            translation,
        )

        write_binary_ply(
            aligned_path,
            source_info["header_lines"],
            [aligned_data],
            source_count,
        )
        write_binary_ply(
            merged_path,
            anchor_info["header_lines"],
            [anchor_data, aligned_data],
            total_count,
        )
    else:
        anchor_rows = read_ascii_vertices(anchor_info)
        source_rows = read_ascii_vertices(source_info)
        aligned_rows = transform_ascii_vertices(
            source_rows,
            source_info["property_names"],
            scale,
            rotation,
            translation,
        )

        write_ascii_ply(
            aligned_path,
            source_info["header_lines"],
            [aligned_rows],
            source_count,
        )
        write_ascii_ply(
            merged_path,
            anchor_info["header_lines"],
            [anchor_rows, aligned_rows],
            total_count,
        )

    aligned_check = parse_ply_header(aligned_path)
    merged_check = parse_ply_header(merged_path)

    if aligned_check["vertex_count"] != source_count:
        raise RuntimeError("Aligned PLY point count mismatch")
    if merged_check["vertex_count"] != total_count:
        raise RuntimeError("Merged PLY point count mismatch")

    return {
        "format": anchor_info["format"],
        "properties": anchor_info["properties"],
        "anchor_points": anchor_count,
        "source_points": source_count,
        "aligned_source_points": int(
            aligned_check["vertex_count"]
        ),
        "total_points": int(merged_check["vertex_count"]),
    }


def write_camera_pairs_csv(
    path: Path,
    candidate: dict[str, Any],
) -> None:
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(
            handle,
            fieldnames=[
                "inlier",
                "anchor_name",
                "source_name",
                "raw_matches",
                "matches_3d3d",
                "center_residual",
                "orientation_error_degrees",
            ],
        )
        writer.writeheader()
        writer.writerows(candidate["camera_pairs"])


def main() -> int:
    parser = argparse.ArgumentParser()

    parser.add_argument("--sparse-job-dir", required=True, type=Path)
    parser.add_argument("--anchor-ply", required=True, type=Path)
    parser.add_argument("--source-ply", required=True, type=Path)
    parser.add_argument("--output-dir", required=True, type=Path)

    parser.add_argument("--iterations-per-offset", type=int, default=30_000)
    parser.add_argument("--minimum-image-pairs", type=int, default=6)
    parser.add_argument("--minimum-camera-inliers", type=int, default=5)
    parser.add_argument(
        "--minimum-camera-inlier-ratio",
        type=float,
        default=0.45,
    )
    parser.add_argument("--minimum-point-inliers", type=int, default=8)
    parser.add_argument("--scale-factor", type=float, default=3.0)
    parser.add_argument("--seed", type=int, default=42)

    args = parser.parse_args()
    started = time.time()

    args.output_dir.mkdir(parents=True, exist_ok=True)

    aligned_path = (
        args.output_dir
        / "model1_aligned_to_model0_bridge_poses.ply"
    )
    merged_path = (
        args.output_dir
        / "bridge_pose_merged_dense_cloud.ply"
    )
    result_path = args.output_dir / "merge_result.json"
    camera_csv_path = args.output_dir / "camera_pair_inliers.csv"

    result: dict[str, Any] = {
        "status": "ERROR",
        "started_at": utc_now(),
        "alignment_method": (
            "cross_component_bridge_frame_camera_pose_"
            "ransac_umeyama_sim3"
        ),
        "merge_type": "aligned_bridge_camera_poses_dense_ply",
        "anchor_model_id": 0,
        "source_model_id": 1,
        "sparse_job_dir": str(args.sparse_job_dir),
        "anchor_ply": str(args.anchor_ply),
        "source_ply": str(args.source_ply),
        "aligned_source_ply": str(aligned_path),
        "output_ply": str(merged_path),
        "result_json": str(result_path),
    }

    try:
        database_path = args.sparse_job_dir / "colmap/database.db"
        model0_images_path = (
            args.sparse_job_dir
            / "colmap/sparse/0/txt/images.txt"
        )
        model1_images_path = (
            args.sparse_job_dir
            / "colmap/sparse/1/txt/images.txt"
        )
        model0_points_path = (
            args.sparse_job_dir
            / "colmap/sparse/0/txt/points3D.txt"
        )
        model1_points_path = (
            args.sparse_job_dir
            / "colmap/sparse/1/txt/points3D.txt"
        )

        for path in (
            database_path,
            model0_images_path,
            model1_images_path,
            model0_points_path,
            model1_points_path,
            args.anchor_ply,
            args.source_ply,
        ):
            if not path.is_file() or path.stat().st_size <= 0:
                raise RuntimeError(f"Missing required file: {path}")

        model0_images = parse_images_txt(model0_images_path)
        model1_images = parse_images_txt(model1_images_path)
        model0_points = parse_points3d_txt(model0_points_path)
        model1_points = parse_points3d_txt(model1_points_path)

        model0_xyz = np.asarray(
            list(model0_points.values()),
            dtype=float,
        )
        model1_xyz = np.asarray(
            list(model1_points.values()),
            dtype=float,
        )

        model0_diagonal = robust_diagonal(model0_xyz)
        model1_diagonal = robust_diagonal(model1_xyz)
        expected_scale = model0_diagonal / model1_diagonal

        (
            image_pairs_by_offset,
            point_pairs_by_offset,
            match_stats,
        ) = collect_cross_pairs(
            database_path=database_path,
            model0_images=model0_images,
            model1_images=model1_images,
            model0_points=model0_points,
            model1_points=model1_points,
        )

        print(
            "[sparse] "
            f"model0_images={len(model0_images)} "
            f"model1_images={len(model1_images)} "
            f"model0_points={len(model0_points)} "
            f"model1_points={len(model1_points)} "
            f"expected_scale={expected_scale:.9g}",
            flush=True,
        )

        print(
            "[offsets] "
            + ", ".join(
                f"{item['offset']}:"
                f"pairs={item['image_pairs']},"
                f"matches={item['raw_matches']},"
                f"3d3d={item['matches_3d3d']}"
                for item in match_stats["offsets"][:10]
            ),
            flush=True,
        )

        candidates = []

        for summary in match_stats["offsets"]:
            offset = int(summary["offset"])
            image_pairs = image_pairs_by_offset.get(offset, [])
            point_pairs = point_pairs_by_offset.get(offset, [])

            if len(image_pairs) < args.minimum_image_pairs:
                continue

            print(
                "[candidate] "
                f"offset={offset} "
                f"image_pairs={len(image_pairs)} "
                f"point_pairs={len(point_pairs)}",
                flush=True,
            )

            candidate = fit_offset_candidate(
                offset=offset,
                image_pairs=image_pairs,
                point_pairs=point_pairs,
                expected_scale=expected_scale,
                scale_factor=args.scale_factor,
                sparse_diagonal=model0_diagonal,
                iterations=args.iterations_per_offset,
                seed=args.seed,
            )
            candidates.append(candidate)

            if candidate.get("status") == "CANDIDATE":
                print(
                    "[candidate-result] "
                    f"offset={offset} "
                    f"scale={candidate['scale']:.9g} "
                    f"camera={candidate['camera_inlier_count']}/"
                    f"{candidate['image_pair_count']} "
                    f"camera_ratio={candidate['camera_inlier_ratio']:.4f} "
                    f"angle={candidate['orientation_median_degrees']:.3f}deg "
                    f"points={candidate['point_inlier_count']}/"
                    f"{candidate['point_pair_count']} "
                    f"score={candidate['score']:.6f}",
                    flush=True,
                )
            else:
                print(
                    f"[candidate-result] offset={offset} "
                    f"status={candidate.get('status')}",
                    flush=True,
                )

        valid_candidates = [
            candidate
            for candidate in candidates
            if candidate.get("status") == "CANDIDATE"
            and candidate["camera_inlier_count"]
            >= args.minimum_camera_inliers
            and candidate["camera_inlier_ratio"]
            >= args.minimum_camera_inlier_ratio
            and candidate["point_inlier_count"]
            >= args.minimum_point_inliers
        ]

        if not valid_candidates:
            result.update(
                {
                    "parameters": {
                        "iterations_per_offset": (
                            args.iterations_per_offset
                        ),
                        "minimum_image_pairs": (
                            args.minimum_image_pairs
                        ),
                        "minimum_camera_inliers": (
                            args.minimum_camera_inliers
                        ),
                        "minimum_camera_inlier_ratio": (
                            args.minimum_camera_inlier_ratio
                        ),
                        "minimum_point_inliers": (
                            args.minimum_point_inliers
                        ),
                        "scale_factor": args.scale_factor,
                        "seed": args.seed,
                    },
                    "sparse": {
                        "model0_registered_images": len(model0_images),
                        "model1_registered_images": len(model1_images),
                        "model0_points3D": len(model0_points),
                        "model1_points3D": len(model1_points),
                        "model0_robust_diagonal": model0_diagonal,
                        "model1_robust_diagonal": model1_diagonal,
                        "expected_scale": expected_scale,
                    },
                    "cross_component_matches": match_stats,
                    "offset_candidates": candidates,
                }
            )
            raise RuntimeError(
                "No frame-offset camera-pose candidate passed "
                "camera and sparse-point validation"
            )

        best = max(
            valid_candidates,
            key=lambda candidate: (
                candidate["score"],
                candidate["camera_inlier_count"],
                candidate["point_inlier_count"],
            ),
        )

        scale = float(best["scale"])
        rotation = np.asarray(
            best["rotation_matrix"],
            dtype=float,
        )
        translation = np.asarray(
            best["translation"],
            dtype=float,
        )

        print(
            "[selected] "
            f"offset={best['offset']} "
            f"scale={scale:.12g} "
            f"camera={best['camera_inlier_count']}/"
            f"{best['image_pair_count']} "
            f"points={best['point_inlier_count']}/"
            f"{best['point_pair_count']} "
            f"camera_median={best['camera_median_residual']:.9g} "
            f"angle_median={best['orientation_median_degrees']:.6g}",
            flush=True,
        )

        write_camera_pairs_csv(camera_csv_path, best)

        ply_result = transform_and_merge_ply(
            anchor_path=args.anchor_ply,
            source_path=args.source_ply,
            aligned_path=aligned_path,
            merged_path=merged_path,
            scale=scale,
            rotation=rotation,
            translation=translation,
        )

        anchor_md5 = md5_file(args.anchor_ply)
        source_md5 = md5_file(args.source_ply)
        aligned_md5 = md5_file(aligned_path)
        merged_md5 = md5_file(merged_path)

        if merged_md5 in {anchor_md5, source_md5}:
            raise RuntimeError(
                "Merged PLY is identical to a source PLY"
            )

        result.update(
            {
                "status": "DONE",
                "parameters": {
                    "iterations_per_offset": (
                        args.iterations_per_offset
                    ),
                    "minimum_image_pairs": (
                        args.minimum_image_pairs
                    ),
                    "minimum_camera_inliers": (
                        args.minimum_camera_inliers
                    ),
                    "minimum_camera_inlier_ratio": (
                        args.minimum_camera_inlier_ratio
                    ),
                    "minimum_point_inliers": (
                        args.minimum_point_inliers
                    ),
                    "scale_factor": args.scale_factor,
                    "seed": args.seed,
                },
                "sparse": {
                    "model0_registered_images": len(model0_images),
                    "model1_registered_images": len(model1_images),
                    "model0_points3D": len(model0_points),
                    "model1_points3D": len(model1_points),
                    "model0_robust_diagonal": model0_diagonal,
                    "model1_robust_diagonal": model1_diagonal,
                    "expected_scale": expected_scale,
                },
                "cross_component_matches": match_stats,
                "offset_candidates": candidates,
                "selected_offset": best["offset"],
                "selected_candidate": best,
                "transform_source_to_anchor": {
                    "uniform_scale": scale,
                    "rotation_matrix": rotation.tolist(),
                    "translation": translation.tolist(),
                    "matrix_4x4": transform_matrix(
                        scale,
                        rotation,
                        translation,
                    ).tolist(),
                    "linear_determinant": float(
                        np.linalg.det(scale * rotation)
                    ),
                },
                "included": [
                    {
                        "job": 654,
                        "remote_job_id": 860990938,
                        "model": 0,
                        "points": ply_result["anchor_points"],
                        "status": "anchor",
                        "path": str(args.anchor_ply),
                    },
                    {
                        "job": 655,
                        "remote_job_id": 917339860,
                        "model": 1,
                        "points": ply_result["source_points"],
                        "status": (
                            "aligned_from_bridge_camera_poses"
                        ),
                        "path": str(args.source_ply),
                    },
                ],
                "excluded": [],
                "included_count": 2,
                "excluded_count": 0,
                "anchor_points": ply_result["anchor_points"],
                "source_points": ply_result["source_points"],
                "aligned_source_points": (
                    ply_result["aligned_source_points"]
                ),
                "sum_source_points": (
                    ply_result["anchor_points"]
                    + ply_result["source_points"]
                ),
                "total_points": ply_result["total_points"],
                "files": {
                    "aligned_source": {
                        "path": str(aligned_path),
                        "size_bytes": aligned_path.stat().st_size,
                        "md5": aligned_md5,
                    },
                    "merged": {
                        "path": str(merged_path),
                        "size_bytes": merged_path.stat().st_size,
                        "md5": merged_md5,
                    },
                    "camera_pair_inliers_csv": {
                        "path": str(camera_csv_path),
                        "size_bytes": camera_csv_path.stat().st_size,
                    },
                },
                "validation": {
                    "camera_inliers_passed": (
                        best["camera_inlier_count"]
                        >= args.minimum_camera_inliers
                    ),
                    "camera_inlier_ratio_passed": (
                        best["camera_inlier_ratio"]
                        >= args.minimum_camera_inlier_ratio
                    ),
                    "point_inliers_passed": (
                        best["point_inlier_count"]
                        >= args.minimum_point_inliers
                    ),
                    "point_count_is_exact_sum": (
                        ply_result["total_points"]
                        == ply_result["anchor_points"]
                        + ply_result["source_points"]
                    ),
                    "merged_md5_differs_from_anchor": (
                        merged_md5 != anchor_md5
                    ),
                    "merged_md5_differs_from_source": (
                        merged_md5 != source_md5
                    ),
                    "requires_visual_review": True,
                },
                "finished_at": utc_now(),
                "duration_sec": round(time.time() - started, 3),
            }
        )

        write_json_atomic(result_path, result)

        print(
            "DONE "
            f"offset={best['offset']} "
            f"scale={scale:.12g} "
            f"camera_inliers={best['camera_inlier_count']}/"
            f"{best['image_pair_count']} "
            f"point_inliers={best['point_inlier_count']}/"
            f"{best['point_pair_count']} "
            f"aligned_points={ply_result['aligned_source_points']} "
            f"merged_points={ply_result['total_points']} "
            f"merged_md5={merged_md5}",
            flush=True,
        )
        return 0

    except Exception as exc:
        result.update(
            {
                "status": "ERROR",
                "message": str(exc),
                "traceback": traceback.format_exc(),
                "finished_at": utc_now(),
                "duration_sec": round(time.time() - started, 3),
            }
        )
        write_json_atomic(result_path, result)
        print(f"ERROR: {exc}", file=sys.stderr, flush=True)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
