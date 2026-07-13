#!/usr/bin/env python3
"""
Align disconnected COLMAP components from raw cross-component feature matches.

This script does not rerun COLMAP or dense reconstruction.

Inputs:
  - common COLMAP database.db
  - sparse model 0 images.txt + points3D.txt
  - sparse model 1 images.txt + points3D.txt
  - already generated dense PLY for model 0
  - already generated dense PLY for model 1

Method:
  1. Read raw feature matches between images registered in different components.
  2. Keep matches whose keypoints already reference sparse 3D points on both sides.
  3. Deduplicate model1_point3D -> model0_point3D candidates.
  4. Estimate model1 -> model0 Sim(3) with robust RANSAC + weighted Umeyama.
  5. Transform the ready-made model 1 dense PLY.
  6. Concatenate model 0 and aligned model 1 without dropping points.

No database rows are created.
"""

from __future__ import annotations

import argparse
import csv
import datetime as dt
import hashlib
import json
import math
import os
import random
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
    temporary = path.with_suffix(path.suffix + ".tmp")
    temporary.write_text(
        json.dumps(payload, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )
    os.replace(temporary, path)


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


def frame_number(name: str) -> int | None:
    match = re.search(r"(\d+)(?=\.[^.]+$)", Path(name).name)
    return int(match.group(1)) if match else None


def parse_images_txt(path: Path) -> dict[str, dict[str, Any]]:
    """
    COLMAP images.txt contains two lines per image:
      IMAGE_ID QW QX QY QZ TX TY TZ CAMERA_ID NAME
      X Y POINT3D_ID ...
    """
    lines = path.read_text(encoding="utf-8", errors="replace").splitlines()
    output: dict[str, dict[str, Any]] = {}

    index = 0
    while index < len(lines):
        line = lines[index].strip()
        index += 1

        if not line or line.startswith("#"):
            continue

        parts = line.split(maxsplit=9)
        if len(parts) < 10:
            continue

        try:
            sparse_image_id = int(parts[0])
        except ValueError:
            continue

        name = parts[9]

        points_line = ""
        if index < len(lines):
            points_line = lines[index].strip()
            index += 1

        point3d_ids: list[int] = []
        if points_line and not points_line.startswith("#"):
            point_parts = points_line.split()
            for offset in range(0, len(point_parts) - 2, 3):
                try:
                    point3d_ids.append(int(point_parts[offset + 2]))
                except ValueError:
                    point3d_ids.append(-1)

        output[name] = {
            "name": name,
            "sparse_image_id": sparse_image_id,
            "point3d_ids": point3d_ids,
            "frame_number": frame_number(name),
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


def decode_match_blob(
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


def collect_correspondences(
    database_path: Path,
    model0_images: dict[str, dict[str, Any]],
    model1_images: dict[str, dict[str, Any]],
    model0_points: dict[int, np.ndarray],
    model1_points: dict[int, np.ndarray],
) -> tuple[list[dict[str, Any]], dict[str, Any]]:
    connection = sqlite3.connect(
        f"file:{database_path}?mode=ro",
        uri=True,
    )

    try:
        db_name_by_id = {
            int(image_id): str(name)
            for image_id, name in connection.execute(
                "SELECT image_id, name FROM images"
            )
        }
        db_id_by_name = {
            name: image_id for image_id, name in db_name_by_id.items()
        }

        component_by_db_id: dict[int, int] = {}
        info_by_db_id: dict[int, dict[str, Any]] = {}

        for component, images in (
            (0, model0_images),
            (1, model1_images),
        ):
            for name, info in images.items():
                database_image_id = db_id_by_name.get(name)
                if database_image_id is None:
                    continue
                component_by_db_id[database_image_id] = component
                info_by_db_id[database_image_id] = info

        pair_support: dict[
            tuple[int, int],
            dict[str, Any],
        ] = {}
        cross_image_pairs = 0
        raw_cross_matches = 0
        raw_3d3d_matches = 0
        offset_counter: Counter[int] = Counter()

        query = """
            SELECT pair_id, rows, cols, data
            FROM matches
            WHERE rows > 0
        """

        for pair_id, rows, cols, data in connection.execute(query):
            database_id1, database_id2 = decode_pair_id(int(pair_id))
            component1 = component_by_db_id.get(database_id1)
            component2 = component_by_db_id.get(database_id2)

            if (
                component1 is None
                or component2 is None
                or component1 == component2
            ):
                continue

            info1 = info_by_db_id[database_id1]
            info2 = info_by_db_id[database_id2]
            name1 = db_name_by_id[database_id1]
            name2 = db_name_by_id[database_id2]

            matches = decode_match_blob(
                data,
                int(rows),
                int(cols),
            )
            if not matches:
                continue

            cross_image_pairs += 1
            raw_cross_matches += len(matches)

            if component1 == 0:
                anchor_info = info1
                source_info = info2
                anchor_name = name1
                source_name = name2
                oriented_matches = [
                    (right_index, left_index)
                    for left_index, right_index in matches
                ]
            else:
                anchor_info = info2
                source_info = info1
                anchor_name = name2
                source_name = name1
                oriented_matches = matches

            anchor_ids = anchor_info["point3d_ids"]
            source_ids = source_info["point3d_ids"]

            anchor_frame = anchor_info.get("frame_number")
            source_frame = source_info.get("frame_number")
            offset = (
                source_frame - anchor_frame
                if anchor_frame is not None and source_frame is not None
                else None
            )

            image_pair_key = f"{anchor_name}|{source_name}"
            pair_3d3d = 0

            for source_feature_index, anchor_feature_index in oriented_matches:
                source_point_id = (
                    source_ids[source_feature_index]
                    if source_feature_index < len(source_ids)
                    else -1
                )
                anchor_point_id = (
                    anchor_ids[anchor_feature_index]
                    if anchor_feature_index < len(anchor_ids)
                    else -1
                )

                if source_point_id < 0 or anchor_point_id < 0:
                    continue
                if source_point_id not in model1_points:
                    continue
                if anchor_point_id not in model0_points:
                    continue

                raw_3d3d_matches += 1
                pair_3d3d += 1

                key = (source_point_id, anchor_point_id)
                item = pair_support.get(key)
                if item is None:
                    item = {
                        "source_point3d_id": source_point_id,
                        "anchor_point3d_id": anchor_point_id,
                        "source_xyz": model1_points[source_point_id],
                        "anchor_xyz": model0_points[anchor_point_id],
                        "support": 0,
                        "image_pairs": set(),
                        "frame_offsets": [],
                    }
                    pair_support[key] = item

                item["support"] += 1
                item["image_pairs"].add(image_pair_key)
                if offset is not None:
                    item["frame_offsets"].append(offset)

            if offset is not None and pair_3d3d > 0:
                offset_counter[offset] += pair_3d3d

        correspondences: list[dict[str, Any]] = []

        for item in pair_support.values():
            offsets = item["frame_offsets"]
            correspondence = {
                "source_point3d_id": item["source_point3d_id"],
                "anchor_point3d_id": item["anchor_point3d_id"],
                "source_xyz": item["source_xyz"],
                "anchor_xyz": item["anchor_xyz"],
                "support": int(item["support"]),
                "image_pair_count": len(item["image_pairs"]),
                "image_pairs": sorted(item["image_pairs"]),
                "dominant_frame_offset": (
                    Counter(offsets).most_common(1)[0][0]
                    if offsets
                    else None
                ),
            }
            correspondences.append(correspondence)

        correspondences.sort(
            key=lambda item: (
                item["support"],
                item["image_pair_count"],
            ),
            reverse=True,
        )

        stats = {
            "cross_component_image_pairs": cross_image_pairs,
            "raw_cross_component_matches": raw_cross_matches,
            "raw_3d3d_matches": raw_3d3d_matches,
            "unique_3d3d_correspondences": len(correspondences),
            "frame_offset_support": [
                {
                    "offset": int(offset),
                    "support": int(support),
                }
                for offset, support in offset_counter.most_common(20)
            ],
        }

        return correspondences, stats
    finally:
        connection.close()


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

    if len(weights) != len(source):
        raise ValueError("Weights length mismatch")

    weights = np.maximum(weights, 0)
    total_weight = float(weights.sum())
    if total_weight <= 0:
        raise ValueError("All correspondence weights are zero")

    normalized = weights / total_weight

    source_mean = np.sum(source * normalized[:, None], axis=0)
    target_mean = np.sum(target * normalized[:, None], axis=0)

    source_centered = source - source_mean
    target_centered = target - target_mean

    covariance = (
        target_centered.T
        @ (source_centered * normalized[:, None])
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
        raise ValueError("Degenerate source correspondence geometry")

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
        raise ValueError("Invalid Sim(3) result")

    return scale, rotation, translation


def transformation_matrix(
    scale: float,
    rotation: np.ndarray,
    translation: np.ndarray,
) -> np.ndarray:
    matrix = np.eye(4, dtype=float)
    matrix[:3, :3] = scale * rotation
    matrix[:3, 3] = translation
    return matrix


def apply_sim3(
    points: np.ndarray,
    scale: float,
    rotation: np.ndarray,
    translation: np.ndarray,
) -> np.ndarray:
    return (scale * (rotation @ points.T)).T + translation


def nondegenerate_triplet(points: np.ndarray) -> bool:
    edge1 = points[1] - points[0]
    edge2 = points[2] - points[0]
    scale = max(
        float(np.linalg.norm(edge1)),
        float(np.linalg.norm(edge2)),
        1e-12,
    )
    area = float(np.linalg.norm(np.cross(edge1, edge2)))
    return area > scale * scale * 1e-4


def robust_refine(
    source: np.ndarray,
    target: np.ndarray,
    base_weights: np.ndarray,
    initial_inliers: np.ndarray,
    threshold: float,
    scale_min: float,
    scale_max: float,
) -> tuple[
    float,
    np.ndarray,
    np.ndarray,
    np.ndarray,
    np.ndarray,
]:
    inliers = initial_inliers.copy()
    scale = 1.0
    rotation = np.eye(3)
    translation = np.zeros(3)

    for _ in range(12):
        if int(inliers.sum()) < 3:
            break

        residual_weights = base_weights[inliers].copy()

        scale, rotation, translation = weighted_umeyama(
            source[inliers],
            target[inliers],
            residual_weights,
        )

        if not (scale_min <= scale <= scale_max):
            raise RuntimeError(
                f"Refined scale {scale:.9g} outside "
                f"[{scale_min:.9g}, {scale_max:.9g}]"
            )

        transformed = apply_sim3(
            source,
            scale,
            rotation,
            translation,
        )
        residuals = np.linalg.norm(transformed - target, axis=1)

        # Huber-like robust weighting for the next fit.
        robust = np.ones_like(residuals)
        mask = residuals > threshold * 0.5
        robust[mask] = (
            threshold * 0.5
            / np.maximum(residuals[mask], 1e-12)
        )

        new_inliers = residuals <= threshold

        if np.array_equal(new_inliers, inliers):
            if int(new_inliers.sum()) >= 3:
                combined_weights = (
                    base_weights[new_inliers]
                    * robust[new_inliers]
                )
                scale, rotation, translation = weighted_umeyama(
                    source[new_inliers],
                    target[new_inliers],
                    combined_weights,
                )
                transformed = apply_sim3(
                    source,
                    scale,
                    rotation,
                    translation,
                )
                residuals = np.linalg.norm(
                    transformed - target,
                    axis=1,
                )
            return (
                scale,
                rotation,
                translation,
                new_inliers,
                residuals,
            )

        inliers = new_inliers

    transformed = apply_sim3(
        source,
        scale,
        rotation,
        translation,
    )
    residuals = np.linalg.norm(transformed - target, axis=1)

    return scale, rotation, translation, inliers, residuals


def estimate_sim3_ransac(
    correspondences: list[dict[str, Any]],
    anchor_diagonal: float,
    iterations: int,
    threshold_ratio: float,
    scale_min: float,
    scale_max: float,
    seed: int,
) -> dict[str, Any]:
    if len(correspondences) < 3:
        raise RuntimeError("Fewer than three unique 3D correspondences")

    source = np.asarray(
        [item["source_xyz"] for item in correspondences],
        dtype=float,
    )
    target = np.asarray(
        [item["anchor_xyz"] for item in correspondences],
        dtype=float,
    )
    supports = np.asarray(
        [item["support"] for item in correspondences],
        dtype=float,
    )
    image_pair_counts = np.asarray(
        [item["image_pair_count"] for item in correspondences],
        dtype=float,
    )

    weights = (
        1.0
        + np.log1p(np.maximum(supports - 1.0, 0.0))
        + 0.25 * np.log1p(image_pair_counts)
    )

    probabilities = np.sqrt(weights)
    probabilities /= probabilities.sum()

    threshold = max(
        anchor_diagonal * threshold_ratio,
        anchor_diagonal * 1e-5,
    )

    rng = np.random.default_rng(seed)

    best: dict[str, Any] | None = None
    valid_hypotheses = 0

    print(
        "[ransac] "
        f"correspondences={len(correspondences)} "
        f"iterations={iterations} "
        f"threshold={threshold:.9g} "
        f"scale_range={scale_min:.6g}..{scale_max:.6g}",
        flush=True,
    )

    for iteration in range(iterations):
        sample_indices = rng.choice(
            len(source),
            size=3,
            replace=False,
            p=probabilities,
        )

        sample_source = source[sample_indices]
        sample_target = target[sample_indices]

        if not nondegenerate_triplet(sample_source):
            continue
        if not nondegenerate_triplet(sample_target):
            continue

        try:
            scale, rotation, translation = weighted_umeyama(
                sample_source,
                sample_target,
            )
        except Exception:
            continue

        if not (scale_min <= scale <= scale_max):
            continue

        valid_hypotheses += 1

        transformed = apply_sim3(
            source,
            scale,
            rotation,
            translation,
        )
        residuals = np.linalg.norm(transformed - target, axis=1)
        inliers = residuals <= threshold
        inlier_count = int(inliers.sum())

        if inlier_count < 3:
            continue

        weighted_support = float(weights[inliers].sum())
        image_pair_union: set[str] = set()
        for index in np.flatnonzero(inliers):
            image_pair_union.update(
                correspondences[int(index)]["image_pairs"]
            )

        median_residual = float(np.median(residuals[inliers]))
        p90_residual = float(np.quantile(residuals[inliers], 0.90))

        score = (
            weighted_support
            + 0.35 * inlier_count
            + 0.15 * len(image_pair_union)
            - 2.0 * median_residual / max(threshold, 1e-12)
        )

        candidate = {
            "score": score,
            "inlier_count": inlier_count,
            "weighted_support": weighted_support,
            "image_pair_coverage": len(image_pair_union),
            "median_residual": median_residual,
            "p90_residual": p90_residual,
            "scale": scale,
            "rotation": rotation,
            "translation": translation,
            "inliers": inliers,
            "residuals": residuals,
            "iteration": iteration,
        }

        if best is None or (
            candidate["score"],
            candidate["inlier_count"],
            -candidate["median_residual"],
        ) > (
            best["score"],
            best["inlier_count"],
            -best["median_residual"],
        ):
            best = candidate

            print(
                "[ransac-best] "
                f"iteration={iteration} "
                f"inliers={inlier_count}/{len(source)} "
                f"pairs={len(image_pair_union)} "
                f"scale={scale:.9g} "
                f"median={median_residual:.9g} "
                f"p90={p90_residual:.9g}",
                flush=True,
            )

    if best is None:
        raise RuntimeError("RANSAC found no valid Sim(3) hypothesis")

    (
        final_scale,
        final_rotation,
        final_translation,
        final_inliers,
        final_residuals,
    ) = robust_refine(
        source=source,
        target=target,
        base_weights=weights,
        initial_inliers=best["inliers"],
        threshold=threshold,
        scale_min=scale_min,
        scale_max=scale_max,
    )

    final_count = int(final_inliers.sum())
    final_ratio = final_count / len(source)

    image_pair_union: set[str] = set()
    frame_offsets: list[int] = []

    for index in np.flatnonzero(final_inliers):
        item = correspondences[int(index)]
        image_pair_union.update(item["image_pairs"])
        offset = item.get("dominant_frame_offset")
        if offset is not None:
            frame_offsets.append(int(offset))

    inlier_residuals = final_residuals[final_inliers]

    if final_count < 3:
        raise RuntimeError("Refinement left fewer than three inliers")

    return {
        "scale": final_scale,
        "rotation": final_rotation,
        "translation": final_translation,
        "matrix": transformation_matrix(
            final_scale,
            final_rotation,
            final_translation,
        ),
        "inliers": final_inliers,
        "residuals": final_residuals,
        "threshold": threshold,
        "anchor_diagonal": anchor_diagonal,
        "iterations": iterations,
        "valid_hypotheses": valid_hypotheses,
        "inlier_count": final_count,
        "inlier_ratio": final_ratio,
        "image_pair_coverage": len(image_pair_union),
        "median_inlier_residual": float(
            np.median(inlier_residuals)
        ),
        "mean_inlier_residual": float(
            np.mean(inlier_residuals)
        ),
        "p90_inlier_residual": float(
            np.quantile(inlier_residuals, 0.90)
        ),
        "max_inlier_residual": float(
            np.max(inlier_residuals)
        ),
        "dominant_inlier_frame_offsets": [
            {
                "offset": int(offset),
                "count": int(count),
            }
            for offset, count in Counter(frame_offsets).most_common(20)
        ],
    }


def parse_ply_header(path: Path) -> dict[str, Any]:
    header_lines: list[bytes] = []
    vertex_properties: list[tuple[str, str]] = []
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
                element_name = parts[1]
                element_count = int(parts[2])
                in_vertex = element_name == "vertex"

                if element_name == "vertex":
                    vertex_count = element_count
                elif element_name == "face":
                    face_count = element_count
                elif element_count > 0:
                    raise RuntimeError(
                        f"Unsupported non-empty PLY element "
                        f"{element_name}: {path}"
                    )
            elif in_vertex and text.startswith("property "):
                parts = text.split()
                if len(parts) != 3 or parts[1] == "list":
                    raise RuntimeError(
                        f"Unsupported PLY vertex property: {text}"
                    )
                property_type = parts[1]
                property_name = parts[2]
                if property_type not in PLY_TYPES:
                    raise RuntimeError(
                        f"Unsupported PLY type {property_type}"
                    )
                vertex_properties.append(
                    (property_type, property_name)
                )

            if text == "end_header":
                break

    if format_name not in ("ascii", "binary_little_endian"):
        raise RuntimeError(
            f"Unsupported PLY format {format_name}: {path}"
        )
    if vertex_count is None:
        raise RuntimeError(f"PLY has no vertex element: {path}")
    if face_count > 0:
        raise RuntimeError(
            f"PLY contains {face_count} faces; point cloud required: {path}"
        )

    property_names = [name for _, name in vertex_properties]
    for required in ("x", "y", "z"):
        if required not in property_names:
            raise RuntimeError(
                f"PLY is missing coordinate property {required}: {path}"
            )

    return {
        "path": path,
        "format": format_name,
        "vertex_count": vertex_count,
        "face_count": face_count,
        "properties": vertex_properties,
        "property_names": property_names,
        "header_lines": header_lines,
        "header_bytes": header_bytes,
    }


def numpy_dtype_for_ply(
    properties: list[tuple[str, str]],
) -> np.dtype:
    return np.dtype(
        [
            (name, PLY_TYPES[property_type][1])
            for property_type, name in properties
        ]
    )


def read_binary_vertices(info: dict[str, Any]) -> np.ndarray:
    dtype = numpy_dtype_for_ply(info["properties"])

    with info["path"].open("rb") as handle:
        handle.seek(info["header_bytes"])
        data = np.fromfile(
            handle,
            dtype=dtype,
            count=info["vertex_count"],
        )

    if len(data) != info["vertex_count"]:
        raise RuntimeError(
            f"Truncated binary PLY: {info['path']}"
        )

    return data


def transformed_binary_vertices(
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
    output: list[bytes] = []

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
    data_arrays: Iterable[np.ndarray],
    vertex_count: int,
) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)

    with path.open("wb") as handle:
        for line in rewrite_header(header_lines, vertex_count):
            handle.write(line)

        for data in data_arrays:
            data.tofile(handle)


def read_ascii_vertices(info: dict[str, Any]) -> list[list[str]]:
    rows: list[list[str]] = []

    with info["path"].open(
        "r",
        encoding="ascii",
        errors="replace",
    ) as handle:
        for _ in range(len(info["header_lines"])):
            handle.readline()

        for _ in range(info["vertex_count"]):
            line = handle.readline()
            if not line:
                raise RuntimeError(
                    f"Truncated ASCII PLY: {info['path']}"
                )
            rows.append(line.strip().split())

    return rows


def transformed_ascii_vertices(
    rows: list[list[str]],
    property_names: list[str],
    scale: float,
    rotation: np.ndarray,
    translation: np.ndarray,
) -> list[list[str]]:
    x_index = property_names.index("x")
    y_index = property_names.index("y")
    z_index = property_names.index("z")

    nx_index = (
        property_names.index("nx")
        if "nx" in property_names
        else None
    )
    ny_index = (
        property_names.index("ny")
        if "ny" in property_names
        else None
    )
    nz_index = (
        property_names.index("nz")
        if "nz" in property_names
        else None
    )

    output: list[list[str]] = []

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
        transformed = (
            scale * (rotation @ point)
            + translation
        )

        new_row[x_index] = format(float(transformed[0]), ".9g")
        new_row[y_index] = format(float(transformed[1]), ".9g")
        new_row[z_index] = format(float(transformed[2]), ".9g")

        if (
            nx_index is not None
            and ny_index is not None
            and nz_index is not None
        ):
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
        raise RuntimeError(
            "Anchor and source PLY formats differ"
        )
    if anchor_info["properties"] != source_info["properties"]:
        raise RuntimeError(
            "Anchor and source PLY vertex layouts differ"
        )

    anchor_count = int(anchor_info["vertex_count"])
    source_count = int(source_info["vertex_count"])
    total_count = anchor_count + source_count

    if anchor_info["format"] == "binary_little_endian":
        anchor_data = read_binary_vertices(anchor_info)
        source_data = read_binary_vertices(source_info)
        aligned_data = transformed_binary_vertices(
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
        aligned_rows = transformed_ascii_vertices(
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
        raise RuntimeError(
            "Aligned PLY point count mismatch"
        )
    if merged_check["vertex_count"] != total_count:
        raise RuntimeError(
            "Merged PLY point count mismatch"
        )

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


def write_inlier_csv(
    path: Path,
    correspondences: list[dict[str, Any]],
    inliers: np.ndarray,
    residuals: np.ndarray,
) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)

    with path.open(
        "w",
        encoding="utf-8",
        newline="",
    ) as handle:
        writer = csv.writer(handle)
        writer.writerow(
            [
                "inlier",
                "residual",
                "support",
                "image_pair_count",
                "source_point3D_id",
                "anchor_point3D_id",
                "dominant_frame_offset",
                "source_x",
                "source_y",
                "source_z",
                "anchor_x",
                "anchor_y",
                "anchor_z",
                "image_pairs",
            ]
        )

        order = np.argsort(residuals)

        for index in order:
            item = correspondences[int(index)]
            source_xyz = item["source_xyz"]
            anchor_xyz = item["anchor_xyz"]

            writer.writerow(
                [
                    int(bool(inliers[index])),
                    format(float(residuals[index]), ".12g"),
                    item["support"],
                    item["image_pair_count"],
                    item["source_point3d_id"],
                    item["anchor_point3d_id"],
                    item.get("dominant_frame_offset"),
                    *[format(float(value), ".12g") for value in source_xyz],
                    *[format(float(value), ".12g") for value in anchor_xyz],
                    ";".join(item["image_pairs"]),
                ]
            )


def main() -> int:
    parser = argparse.ArgumentParser()

    parser.add_argument("--sparse-job-dir", required=True, type=Path)
    parser.add_argument("--anchor-ply", required=True, type=Path)
    parser.add_argument("--source-ply", required=True, type=Path)
    parser.add_argument("--output-dir", required=True, type=Path)

    parser.add_argument("--anchor-model-id", type=int, default=0)
    parser.add_argument("--source-model-id", type=int, default=1)
    parser.add_argument("--anchor-db-job-id", type=int, default=654)
    parser.add_argument("--source-db-job-id", type=int, default=655)
    parser.add_argument(
        "--anchor-remote-job-id",
        type=int,
        default=860990938,
    )
    parser.add_argument(
        "--source-remote-job-id",
        type=int,
        default=917339860,
    )

    parser.add_argument("--iterations", type=int, default=80_000)
    parser.add_argument(
        "--threshold-ratio",
        type=float,
        default=0.006,
        help="RANSAC inlier threshold as a fraction of model 0 sparse diagonal.",
    )
    parser.add_argument("--scale-min", type=float, default=0.05)
    parser.add_argument("--scale-max", type=float, default=20.0)
    parser.add_argument("--min-inliers", type=int, default=12)
    parser.add_argument(
        "--min-image-pair-coverage",
        type=int,
        default=4,
    )
    parser.add_argument("--seed", type=int, default=42)

    args = parser.parse_args()
    started = time.time()

    args.output_dir.mkdir(parents=True, exist_ok=True)

    aligned_path = (
        args.output_dir
        / "model1_aligned_to_model0_colmap_matches.ply"
    )
    merged_path = (
        args.output_dir
        / "colmap_matches_merged_dense_cloud.ply"
    )
    result_path = args.output_dir / "merge_result.json"
    csv_path = args.output_dir / "inlier_correspondences.csv"

    result: dict[str, Any] = {
        "status": "ERROR",
        "started_at": utc_now(),
        "alignment_method": (
            "raw_colmap_cross_component_3d3d_matches_"
            "ransac_weighted_umeyama_sim3"
        ),
        "merge_type": (
            "aligned_colmap_cross_component_matches_dense_ply"
        ),
        "anchor_model_id": args.anchor_model_id,
        "source_model_id": args.source_model_id,
        "sparse_job_dir": str(args.sparse_job_dir),
        "anchor_ply": str(args.anchor_ply),
        "source_ply": str(args.source_ply),
        "aligned_source_ply": str(aligned_path),
        "output_ply": str(merged_path),
        "result_json": str(result_path),
        "inlier_correspondences_csv": str(csv_path),
    }

    try:
        database_path = (
            args.sparse_job_dir / "colmap/database.db"
        )
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

        required_paths = [
            database_path,
            model0_images_path,
            model1_images_path,
            model0_points_path,
            model1_points_path,
            args.anchor_ply,
            args.source_ply,
        ]
        for required_path in required_paths:
            if not required_path.is_file():
                raise RuntimeError(
                    f"Required file is missing: {required_path}"
                )

        random.seed(args.seed)
        np.random.seed(args.seed)

        print("Reading sparse models and raw matches", flush=True)

        model0_images = parse_images_txt(model0_images_path)
        model1_images = parse_images_txt(model1_images_path)
        model0_points = parse_points3d_txt(model0_points_path)
        model1_points = parse_points3d_txt(model1_points_path)

        correspondences, match_stats = collect_correspondences(
            database_path=database_path,
            model0_images=model0_images,
            model1_images=model1_images,
            model0_points=model0_points,
            model1_points=model1_points,
        )

        print(
            "[matches] "
            f"image_pairs={match_stats['cross_component_image_pairs']} "
            f"raw={match_stats['raw_cross_component_matches']} "
            f"raw_3d3d={match_stats['raw_3d3d_matches']} "
            f"unique_3d3d={match_stats['unique_3d3d_correspondences']}",
            flush=True,
        )
        print(
            "[offsets] "
            + ", ".join(
                f"{item['offset']}:{item['support']}"
                for item in match_stats["frame_offset_support"][:10]
            ),
            flush=True,
        )

        if len(correspondences) < args.min_inliers:
            raise RuntimeError(
                f"Only {len(correspondences)} unique 3D↔3D "
                f"correspondences; need at least {args.min_inliers}"
            )

        anchor_sparse_xyz = np.asarray(
            list(model0_points.values()),
            dtype=float,
        )
        q01 = np.quantile(anchor_sparse_xyz, 0.01, axis=0)
        q99 = np.quantile(anchor_sparse_xyz, 0.99, axis=0)
        anchor_diagonal = float(np.linalg.norm(q99 - q01))

        if not np.isfinite(anchor_diagonal) or anchor_diagonal <= 0:
            raise RuntimeError("Invalid model 0 sparse diagonal")

        estimate = estimate_sim3_ransac(
            correspondences=correspondences,
            anchor_diagonal=anchor_diagonal,
            iterations=args.iterations,
            threshold_ratio=args.threshold_ratio,
            scale_min=args.scale_min,
            scale_max=args.scale_max,
            seed=args.seed,
        )

        if estimate["inlier_count"] < args.min_inliers:
            raise RuntimeError(
                f"Only {estimate['inlier_count']} RANSAC inliers; "
                f"need at least {args.min_inliers}"
            )
        if (
            estimate["image_pair_coverage"]
            < args.min_image_pair_coverage
        ):
            raise RuntimeError(
                "RANSAC transform is supported by only "
                f"{estimate['image_pair_coverage']} image pairs; "
                f"need at least {args.min_image_pair_coverage}"
            )

        print(
            "[sim3] "
            f"scale={estimate['scale']:.12g} "
            f"inliers={estimate['inlier_count']}/"
            f"{len(correspondences)} "
            f"ratio={estimate['inlier_ratio']:.6f} "
            f"image_pairs={estimate['image_pair_coverage']} "
            f"median={estimate['median_inlier_residual']:.9g} "
            f"p90={estimate['p90_inlier_residual']:.9g}",
            flush=True,
        )

        write_inlier_csv(
            path=csv_path,
            correspondences=correspondences,
            inliers=estimate["inliers"],
            residuals=estimate["residuals"],
        )

        print("Transforming ready-made dense PLY files", flush=True)

        ply_result = transform_and_merge_ply(
            anchor_path=args.anchor_ply,
            source_path=args.source_ply,
            aligned_path=aligned_path,
            merged_path=merged_path,
            scale=estimate["scale"],
            rotation=estimate["rotation"],
            translation=estimate["translation"],
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
                    "iterations": args.iterations,
                    "threshold_ratio": args.threshold_ratio,
                    "scale_min": args.scale_min,
                    "scale_max": args.scale_max,
                    "min_inliers": args.min_inliers,
                    "min_image_pair_coverage": (
                        args.min_image_pair_coverage
                    ),
                    "seed": args.seed,
                },
                "sparse": {
                    "model0_registered_images": len(model0_images),
                    "model1_registered_images": len(model1_images),
                    "model0_points3D": len(model0_points),
                    "model1_points3D": len(model1_points),
                    "anchor_robust_diagonal": anchor_diagonal,
                },
                "cross_component_matches": match_stats,
                "ransac": {
                    "threshold": estimate["threshold"],
                    "iterations": estimate["iterations"],
                    "valid_hypotheses": (
                        estimate["valid_hypotheses"]
                    ),
                    "unique_correspondences": len(correspondences),
                    "inlier_count": estimate["inlier_count"],
                    "inlier_ratio": estimate["inlier_ratio"],
                    "image_pair_coverage": (
                        estimate["image_pair_coverage"]
                    ),
                    "median_inlier_residual": (
                        estimate["median_inlier_residual"]
                    ),
                    "mean_inlier_residual": (
                        estimate["mean_inlier_residual"]
                    ),
                    "p90_inlier_residual": (
                        estimate["p90_inlier_residual"]
                    ),
                    "max_inlier_residual": (
                        estimate["max_inlier_residual"]
                    ),
                    "dominant_inlier_frame_offsets": (
                        estimate[
                            "dominant_inlier_frame_offsets"
                        ]
                    ),
                },
                "transform_source_to_anchor": {
                    "uniform_scale": estimate["scale"],
                    "rotation_matrix": (
                        estimate["rotation"].tolist()
                    ),
                    "translation": (
                        estimate["translation"].tolist()
                    ),
                    "matrix_4x4": estimate["matrix"].tolist(),
                    "linear_determinant": float(
                        np.linalg.det(
                            estimate["matrix"][:3, :3]
                        )
                    ),
                },
                "included": [
                    {
                        "job": args.anchor_db_job_id,
                        "remote_job_id": (
                            args.anchor_remote_job_id
                        ),
                        "model": args.anchor_model_id,
                        "points": ply_result["anchor_points"],
                        "status": "anchor",
                        "path": str(args.anchor_ply),
                    },
                    {
                        "job": args.source_db_job_id,
                        "remote_job_id": (
                            args.source_remote_job_id
                        ),
                        "model": args.source_model_id,
                        "points": ply_result["source_points"],
                        "status": (
                            "aligned_from_colmap_3d3d_matches"
                        ),
                        "path": str(args.source_ply),
                    },
                ],
                "excluded": [],
                "included_count": 2,
                "excluded_count": 0,
                "source_jobs": [
                    {
                        "db_job_id": args.anchor_db_job_id,
                        "remote_job_id": (
                            args.anchor_remote_job_id
                        ),
                        "model_id": args.anchor_model_id,
                        "points": ply_result["anchor_points"],
                        "path": str(args.anchor_ply),
                        "alignment_status": "anchor",
                    },
                    {
                        "db_job_id": args.source_db_job_id,
                        "remote_job_id": (
                            args.source_remote_job_id
                        ),
                        "model_id": args.source_model_id,
                        "points": ply_result["source_points"],
                        "path": str(args.source_ply),
                        "alignment_status": (
                            "aligned_colmap_cross_component_"
                            "3d3d_ransac_sim3"
                        ),
                        "transform_to_anchor": {
                            "scale": estimate["scale"],
                            "rotation_matrix": (
                                estimate["rotation"].tolist()
                            ),
                            "translation": (
                                estimate["translation"].tolist()
                            ),
                            "matrix_4x4": (
                                estimate["matrix"].tolist()
                            ),
                        },
                    },
                ],
                "anchor_points": ply_result["anchor_points"],
                "source_points": ply_result["source_points"],
                "sum_source_points": (
                    ply_result["anchor_points"]
                    + ply_result["source_points"]
                ),
                "aligned_source_points": (
                    ply_result["aligned_source_points"]
                ),
                "total_points": ply_result["total_points"],
                "ply": {
                    "format": ply_result["format"],
                    "properties": ply_result["properties"],
                },
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
                    "inlier_correspondences_csv": {
                        "path": str(csv_path),
                        "size_bytes": csv_path.stat().st_size,
                    },
                },
                "validation": {
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
                    "minimum_inliers_passed": (
                        estimate["inlier_count"]
                        >= args.min_inliers
                    ),
                    "minimum_image_pair_coverage_passed": (
                        estimate["image_pair_coverage"]
                        >= args.min_image_pair_coverage
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
            f"inliers={estimate['inlier_count']}/"
            f"{len(correspondences)} "
            f"scale={estimate['scale']:.12g} "
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
