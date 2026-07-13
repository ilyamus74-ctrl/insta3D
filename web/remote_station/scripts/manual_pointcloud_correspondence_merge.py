#!/usr/bin/env python3
from __future__ import annotations

import argparse
import hashlib
import json
import math
import os
import struct
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import numpy as np


PLY_TYPES: dict[str, tuple[str, str]] = {
    "char": ("i1", "b"),
    "int8": ("i1", "b"),
    "uchar": ("u1", "B"),
    "uint8": ("u1", "B"),
    "short": ("<i2", "h"),
    "int16": ("<i2", "h"),
    "ushort": ("<u2", "H"),
    "uint16": ("<u2", "H"),
    "int": ("<i4", "i"),
    "int32": ("<i4", "i"),
    "uint": ("<u4", "I"),
    "uint32": ("<u4", "I"),
    "float": ("<f4", "f"),
    "float32": ("<f4", "f"),
    "double": ("<f8", "d"),
    "float64": ("<f8", "d"),
}


@dataclass
class PlyCloud:
    path: Path
    format_name: str
    properties: list[tuple[str, str]]
    vertices: np.ndarray

    @property
    def count(self) -> int:
        return int(len(self.vertices))


def read_ply(path: Path) -> PlyCloud:
    with path.open("rb") as fh:
        first = fh.readline()
        if first.strip() != b"ply":
            raise ValueError(f"{path}: not a PLY file")

        format_name = ""
        vertex_count: int | None = None
        properties: list[tuple[str, str]] = []
        current_element: str | None = None
        saw_non_vertex_element_with_data = False

        while True:
            raw = fh.readline()
            if not raw:
                raise ValueError(f"{path}: incomplete PLY header")
            line = raw.decode("ascii", errors="strict").strip()
            if line == "end_header":
                break
            if not line or line.startswith("comment") or line.startswith("obj_info"):
                continue

            parts = line.split()
            if parts[0] == "format":
                format_name = parts[1]
            elif parts[0] == "element":
                current_element = parts[1]
                count = int(parts[2])
                if current_element == "vertex":
                    vertex_count = count
                elif count > 0:
                    saw_non_vertex_element_with_data = True
            elif parts[0] == "property" and current_element == "vertex":
                if parts[1] == "list":
                    raise ValueError(f"{path}: list property in vertex element is unsupported")
                type_name, name = parts[1], parts[2]
                if type_name not in PLY_TYPES:
                    raise ValueError(f"{path}: unsupported PLY property type {type_name}")
                properties.append((name, type_name))

        if vertex_count is None:
            raise ValueError(f"{path}: vertex element not found")
        if not {"x", "y", "z"}.issubset({name for name, _ in properties}):
            raise ValueError(f"{path}: x/y/z properties are required")
        if saw_non_vertex_element_with_data:
            raise ValueError(f"{path}: this manual point-cloud tool expects a vertex-only PLY")

        dtype = np.dtype([(name, PLY_TYPES[type_name][0]) for name, type_name in properties])

        if format_name == "binary_little_endian":
            vertices = np.fromfile(fh, dtype=dtype, count=vertex_count)
            if len(vertices) != vertex_count:
                raise ValueError(f"{path}: expected {vertex_count} vertices, got {len(vertices)}")
        elif format_name == "ascii":
            rows: list[tuple[Any, ...]] = []
            for _ in range(vertex_count):
                line = fh.readline().decode("ascii", errors="strict").strip()
                if not line:
                    raise ValueError(f"{path}: unexpected EOF in ASCII vertices")
                values = line.split()
                if len(values) != len(properties):
                    raise ValueError(f"{path}: vertex column count mismatch")
                converted: list[Any] = []
                for value, (_, type_name) in zip(values, properties):
                    np_type = np.dtype(PLY_TYPES[type_name][0])
                    converted.append(float(value) if np.issubdtype(np_type, np.floating) else int(value))
                rows.append(tuple(converted))
            vertices = np.array(rows, dtype=dtype)
        else:
            raise ValueError(f"{path}: unsupported PLY format {format_name!r}")

    return PlyCloud(path=path, format_name=format_name, properties=properties, vertices=vertices)


def schema_equal(a: PlyCloud, b: PlyCloud) -> bool:
    return a.properties == b.properties and a.vertices.dtype == b.vertices.dtype


def standardize_cloud(cloud: PlyCloud, include_color: bool) -> np.ndarray:
    fields: list[tuple[str, str]] = [("x", "<f4"), ("y", "<f4"), ("z", "<f4")]
    if include_color:
        fields += [("red", "u1"), ("green", "u1"), ("blue", "u1")]
    out = np.empty(cloud.count, dtype=np.dtype(fields))
    for axis in ("x", "y", "z"):
        out[axis] = cloud.vertices[axis].astype(np.float32)
    if include_color:
        for color in ("red", "green", "blue"):
            out[color] = cloud.vertices[color].astype(np.uint8)
    return out


def ply_type_for_dtype(dtype: np.dtype) -> str:
    dtype = np.dtype(dtype)
    mapping = {
        ("i", 1): "char",
        ("u", 1): "uchar",
        ("i", 2): "short",
        ("u", 2): "ushort",
        ("i", 4): "int",
        ("u", 4): "uint",
        ("f", 4): "float",
        ("f", 8): "double",
    }
    key = (dtype.kind, dtype.itemsize)
    if key not in mapping:
        raise ValueError(f"Cannot serialize dtype {dtype}")
    return mapping[key]


def write_binary_ply(path: Path, vertices: np.ndarray) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    header = ["ply", "format binary_little_endian 1.0", "comment manual Sim3 alignment draft"]
    header.append(f"element vertex {len(vertices)}")
    for name in vertices.dtype.names or ():
        header.append(f"property {ply_type_for_dtype(vertices.dtype[name])} {name}")
    header.append("end_header")
    header_bytes = ("\n".join(header) + "\n").encode("ascii")

    tmp = path.with_suffix(path.suffix + ".tmp")
    with tmp.open("wb") as fh:
        fh.write(header_bytes)
        little = vertices.astype(vertices.dtype.newbyteorder("<"), copy=False)
        little.tofile(fh)
        fh.flush()
        os.fsync(fh.fileno())
    tmp.replace(path)


def md5_file(path: Path) -> str:
    digest = hashlib.md5()
    with path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def load_correspondences(path: Path) -> tuple[np.ndarray, np.ndarray, dict[str, Any]]:
    payload = json.loads(path.read_text(encoding="utf-8"))
    pairs = payload.get("pairs")
    if not isinstance(pairs, list) or len(pairs) < 4:
        raise ValueError("At least 4 correspondence pairs are required")

    target = np.asarray([pair["anchor"] for pair in pairs], dtype=np.float64)
    source = np.asarray([pair["source"] for pair in pairs], dtype=np.float64)

    if target.shape != source.shape or target.ndim != 2 or target.shape[1] != 3:
        raise ValueError("Correspondence arrays must have shape Nx3")
    if not np.isfinite(target).all() or not np.isfinite(source).all():
        raise ValueError("Correspondences contain non-finite values")
    return source, target, payload


def umeyama_similarity(source: np.ndarray, target: np.ndarray) -> tuple[float, np.ndarray, np.ndarray]:
    n = source.shape[0]
    source_mean = source.mean(axis=0)
    target_mean = target.mean(axis=0)
    source_centered = source - source_mean
    target_centered = target - target_mean

    source_rank = int(np.linalg.matrix_rank(source_centered))
    target_rank = int(np.linalg.matrix_rank(target_centered))
    if source_rank < 2 or target_rank < 2:
        raise ValueError(
            f"Degenerate correspondences: source_rank={source_rank}, target_rank={target_rank}; "
            "choose points spread over the object"
        )

    covariance = (target_centered.T @ source_centered) / float(n)
    u, singular_values, vt = np.linalg.svd(covariance)

    correction = np.eye(3)
    if np.linalg.det(u) * np.linalg.det(vt) < 0:
        correction[-1, -1] = -1.0

    rotation = u @ correction @ vt
    source_variance = float(np.sum(source_centered * source_centered) / n)
    if source_variance <= np.finfo(np.float64).eps:
        raise ValueError("Source correspondence variance is zero")

    scale = float(np.sum(singular_values * np.diag(correction)) / source_variance)
    translation = target_mean - scale * (rotation @ source_mean)

    if not math.isfinite(scale) or scale <= 0:
        raise ValueError(f"Invalid scale: {scale}")
    if not np.isfinite(rotation).all() or not np.isfinite(translation).all():
        raise ValueError("Transform contains non-finite values")
    if abs(float(np.linalg.det(rotation)) - 1.0) > 1e-5:
        raise ValueError(f"Rotation determinant is invalid: {np.linalg.det(rotation)}")

    return scale, rotation, translation


def apply_transform(points: np.ndarray, scale: float, rotation: np.ndarray, translation: np.ndarray) -> np.ndarray:
    return (scale * (rotation @ points.T)).T + translation


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--anchor", required=True)
    parser.add_argument("--source", required=True)
    parser.add_argument("--correspondences", required=True)
    parser.add_argument("--output-dir", required=True)
    args = parser.parse_args()

    anchor_path = Path(args.anchor).resolve()
    source_path = Path(args.source).resolve()
    correspondence_path = Path(args.correspondences).resolve()
    output_dir = Path(args.output_dir).resolve()
    output_dir.mkdir(parents=True, exist_ok=True)

    source_pairs, target_pairs, correspondence_payload = load_correspondences(correspondence_path)
    scale, rotation, translation = umeyama_similarity(source_pairs, target_pairs)

    predicted = apply_transform(source_pairs, scale, rotation, translation)
    residuals = np.linalg.norm(predicted - target_pairs, axis=1)

    anchor = read_ply(anchor_path)
    source = read_ply(source_path)

    transformed_source = source.vertices.copy()
    source_xyz = np.column_stack([
        source.vertices["x"].astype(np.float64),
        source.vertices["y"].astype(np.float64),
        source.vertices["z"].astype(np.float64),
    ])
    transformed_xyz = apply_transform(source_xyz, scale, rotation, translation)
    transformed_source["x"] = transformed_xyz[:, 0]
    transformed_source["y"] = transformed_xyz[:, 1]
    transformed_source["z"] = transformed_xyz[:, 2]

    names = set(transformed_source.dtype.names or ())
    if {"nx", "ny", "nz"}.issubset(names):
        normals = np.column_stack([
            source.vertices["nx"].astype(np.float64),
            source.vertices["ny"].astype(np.float64),
            source.vertices["nz"].astype(np.float64),
        ])
        normals = (rotation @ normals.T).T
        lengths = np.linalg.norm(normals, axis=1)
        valid = lengths > np.finfo(np.float64).eps
        normals[valid] /= lengths[valid, None]
        transformed_source["nx"] = normals[:, 0]
        transformed_source["ny"] = normals[:, 1]
        transformed_source["nz"] = normals[:, 2]

    aligned_path = output_dir / "source_aligned_to_anchor.ply"
    merged_path = output_dir / "manual_merged_dense_cloud.ply"

    write_binary_ply(aligned_path, transformed_source)

    color_fields = {"red", "green", "blue"}
    both_have_color = color_fields.issubset(anchor.vertices.dtype.names or ()) and color_fields.issubset(source.vertices.dtype.names or ())

    if schema_equal(anchor, source):
        merged = np.concatenate([anchor.vertices, transformed_source])
    else:
        anchor_standard = standardize_cloud(anchor, both_have_color)
        source_standard = standardize_cloud(
            PlyCloud(source.path, source.format_name, source.properties, transformed_source),
            both_have_color,
        )
        merged = np.concatenate([anchor_standard, source_standard])

    write_binary_ply(merged_path, merged)

    matrix4 = np.eye(4, dtype=np.float64)
    matrix4[:3, :3] = scale * rotation
    matrix4[:3, 3] = translation

    warnings: list[str] = []
    if scale < 0.1 or scale > 10.0:
        warnings.append(f"scale {scale:.6g} is outside broad plausibility range 0.1..10")
    if float(np.max(residuals)) > max(float(np.median(residuals)) * 5.0, 1e-9):
        warnings.append("one or more correspondence residuals are much larger than the median")
    if len(source_pairs) < 6:
        warnings.append("only 4–5 pairs were used; 6–12 distributed pairs are preferred")
    if not schema_equal(anchor, source):
        warnings.append("input PLY schemas differed; merged output was standardized")

    result = {
        "schema_version": 1,
        "status": "DRAFT",
        "method": "manual_correspondences_umeyama_sim3",
        "icp_applied": False,
        "pairs_count": int(len(source_pairs)),
        "scale": scale,
        "rotation": rotation.tolist(),
        "translation": translation.tolist(),
        "matrix4": matrix4.tolist(),
        "rotation_determinant": float(np.linalg.det(rotation)),
        "residuals": residuals.tolist(),
        "rms": float(np.sqrt(np.mean(residuals ** 2))),
        "median": float(np.median(residuals)),
        "max": float(np.max(residuals)),
        "anchor_points": anchor.count,
        "source_points": source.count,
        "merged_points": int(len(merged)),
        "anchor_md5": md5_file(anchor_path),
        "source_md5": md5_file(source_path),
        "aligned_source_md5": md5_file(aligned_path),
        "merged_md5": md5_file(merged_path),
        "aligned_source_path": str(aligned_path),
        "merged_path": str(merged_path),
        "correspondence_path": str(correspondence_path),
        "warnings": warnings,
        "correspondences": correspondence_payload.get("pairs", []),
    }

    result_tmp = output_dir / "merge_result.json.tmp"
    result_path = output_dir / "merge_result.json"
    result_tmp.write_text(
        json.dumps(result, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )
    result_tmp.replace(result_path)

    print(json.dumps({
        "ok": True,
        "scale": scale,
        "rms": result["rms"],
        "merged_points": result["merged_points"],
        "result_path": str(result_path),
    }))


if __name__ == "__main__":
    main()