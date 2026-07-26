#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import math
from pathlib import Path
from typing import Any

import numpy as np

from manual_pointcloud_correspondence_merge import (
    PlyCloud,
    md5_file,
    read_ply,
    schema_equal,
    standardize_cloud,
    write_binary_ply,
)


def load_transform(path: Path) -> tuple[np.ndarray, float, np.ndarray, np.ndarray, dict[str, Any]]:
    payload = json.loads(path.read_text(encoding="utf-8"))
    raw = payload.get("matrix4")
    if not isinstance(raw, list):
        raw = payload.get("transform", {}).get("matrix4")

    matrix = np.asarray(raw, dtype=np.float64)
    if matrix.shape != (4, 4):
        raise ValueError("matrix4 must have shape 4x4")
    if not np.isfinite(matrix).all():
        raise ValueError("matrix4 contains non-finite values")
    if not np.allclose(matrix[3], [0.0, 0.0, 0.0, 1.0], atol=1e-7):
        raise ValueError("matrix4 last row must be [0,0,0,1]")

    linear = matrix[:3, :3]
    norms = np.linalg.norm(linear, axis=0)
    scale = float(np.mean(norms))
    if not math.isfinite(scale) or not 0.0001 <= scale <= 10000.0:
        raise ValueError("uniform scale is outside 0.0001..10000")
    if float(np.max(np.abs(norms - scale))) > max(1e-7, scale * 0.002):
        raise ValueError("matrix4 contains non-uniform scale or shear")

    rotation = linear / scale
    if not np.allclose(rotation.T @ rotation, np.eye(3), atol=0.01):
        raise ValueError("rotation matrix is not orthonormal")
    determinant = float(np.linalg.det(rotation))
    if abs(determinant - 1.0) > 0.01:
        raise ValueError(f"rotation determinant is invalid: {determinant}")

    translation = matrix[:3, 3]
    return matrix, scale, rotation, translation, payload


def apply_matrix(points: np.ndarray, matrix: np.ndarray) -> np.ndarray:
    return points @ matrix[:3, :3].T + matrix[:3, 3]


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--anchor", required=True)
    parser.add_argument("--source", required=True)
    parser.add_argument("--transform-json", required=True)
    parser.add_argument("--output-dir", required=True)
    args = parser.parse_args()

    anchor_path = Path(args.anchor).resolve()
    source_path = Path(args.source).resolve()
    transform_path = Path(args.transform_json).resolve()
    output_dir = Path(args.output_dir).resolve()
    output_dir.mkdir(parents=True, exist_ok=True)

    matrix, scale, rotation, translation, transform_payload = load_transform(
        transform_path
    )

    anchor = read_ply(anchor_path)
    source = read_ply(source_path)

    transformed_source = source.vertices.copy()
    source_xyz = np.column_stack(
        [
            source.vertices["x"].astype(np.float64),
            source.vertices["y"].astype(np.float64),
            source.vertices["z"].astype(np.float64),
        ]
    )
    transformed_xyz = apply_matrix(source_xyz, matrix)
    transformed_source["x"] = transformed_xyz[:, 0]
    transformed_source["y"] = transformed_xyz[:, 1]
    transformed_source["z"] = transformed_xyz[:, 2]

    names = set(transformed_source.dtype.names or ())
    if {"nx", "ny", "nz"}.issubset(names):
        normals = np.column_stack(
            [
                source.vertices["nx"].astype(np.float64),
                source.vertices["ny"].astype(np.float64),
                source.vertices["nz"].astype(np.float64),
            ]
        )
        normals = normals @ rotation.T
        lengths = np.linalg.norm(normals, axis=1)
        valid = lengths > np.finfo(np.float64).eps
        normals[valid] /= lengths[valid, None]
        transformed_source["nx"] = normals[:, 0]
        transformed_source["ny"] = normals[:, 1]
        transformed_source["nz"] = normals[:, 2]

    aligned_path = output_dir / "source_visual_aligned_to_anchor.ply"
    merged_path = output_dir / "manual_visual_merged_dense_cloud.ply"
    result_path = output_dir / "visual_merge_result.json"

    write_binary_ply(aligned_path, transformed_source)

    color_fields = {"red", "green", "blue"}
    both_have_color = (
        color_fields.issubset(anchor.vertices.dtype.names or ())
        and color_fields.issubset(source.vertices.dtype.names or ())
    )

    if schema_equal(anchor, source):
        merged = np.concatenate([anchor.vertices, transformed_source])
    else:
        anchor_standard = standardize_cloud(anchor, both_have_color)
        source_standard = standardize_cloud(
            PlyCloud(
                source.path,
                source.format_name,
                source.properties,
                transformed_source,
            ),
            both_have_color,
        )
        merged = np.concatenate([anchor_standard, source_standard])

    write_binary_ply(merged_path, merged)

    warnings: list[str] = []
    if scale < 0.1 or scale > 10.0:
        warnings.append(
            f"scale {scale:.6g} is outside broad plausibility range 0.1..10"
        )
    if not schema_equal(anchor, source):
        warnings.append(
            "input PLY schemas differed; merged output was standardized"
        )

    result = {
        "schema_version": 1,
        "status": "DRAFT",
        "method": "manual_visual_transform_sim3",
        "icp_applied": False,
        "scale": scale,
        "rotation": rotation.tolist(),
        "translation": translation.tolist(),
        "matrix4": matrix.tolist(),
        "rotation_determinant": float(np.linalg.det(rotation)),
        "anchor_points": anchor.count,
        "source_points": source.count,
        "merged_points": int(len(merged)),
        "anchor_md5": md5_file(anchor_path),
        "source_md5": md5_file(source_path),
        "aligned_source_md5": md5_file(aligned_path),
        "merged_md5": md5_file(merged_path),
        "aligned_source_path": str(aligned_path),
        "merged_path": str(merged_path),
        "visual_transform_path": str(transform_path),
        "transform_sha256": transform_payload.get("transform_sha256"),
        "warnings": warnings,
    }

    temporary = result_path.with_suffix(".json.tmp")
    temporary.write_text(
        json.dumps(result, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )
    temporary.replace(result_path)

    print(
        json.dumps(
            {
                "ok": True,
                "scale": scale,
                "merged_points": result["merged_points"],
                "result_path": str(result_path),
            }
        )
    )


if __name__ == "__main__":
    main()
