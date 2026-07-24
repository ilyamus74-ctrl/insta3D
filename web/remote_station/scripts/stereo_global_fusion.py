#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import math
from pathlib import Path
from typing import Any

import numpy as np


PLY_SCALAR_TYPES: dict[str, tuple[str, int]] = {
    "char": ("i1", 1),
    "int8": ("i1", 1),
    "uchar": ("u1", 1),
    "uint8": ("u1", 1),
    "short": ("<i2", 2),
    "int16": ("<i2", 2),
    "ushort": ("<u2", 2),
    "uint16": ("<u2", 2),
    "int": ("<i4", 4),
    "int32": ("<i4", 4),
    "uint": ("<u4", 4),
    "uint32": ("<u4", 4),
    "float": ("<f4", 4),
    "float32": ("<f4", 4),
    "double": ("<f8", 8),
    "float64": ("<f8", 8),
}


def load_json(path: Path) -> dict[str, Any]:
    with path.open("r", encoding="utf-8") as handle:
        value = json.load(handle)
    if not isinstance(value, dict):
        raise ValueError(f"expected JSON object: {path}")
    return value


def write_json(path: Path, payload: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(
        json.dumps(payload, indent=2, ensure_ascii=False),
        encoding="utf-8",
    )


def parse_ply_header(path: Path) -> tuple[dict[str, Any], int]:
    with path.open("rb") as handle:
        first = handle.readline()
        if first.rstrip(b"\r\n") != b"ply":
            raise ValueError(f"not a PLY file: {path}")

        format_name: str | None = None
        vertex_count: int | None = None
        current_element: str | None = None
        vertex_properties: list[tuple[str, str]] = []

        while True:
            raw = handle.readline()
            if not raw:
                raise ValueError(f"PLY header has no end_header: {path}")
            line = raw.decode("ascii", "strict").strip()
            if not line or line.startswith("comment "):
                continue
            if line == "end_header":
                header_end = handle.tell()
                break

            parts = line.split()
            if parts[0] == "format" and len(parts) >= 3:
                format_name = parts[1]
            elif parts[0] == "element" and len(parts) == 3:
                current_element = parts[1]
                if current_element == "vertex":
                    vertex_count = int(parts[2])
            elif (
                parts[0] == "property"
                and current_element == "vertex"
            ):
                if len(parts) != 3 or parts[1] == "list":
                    raise ValueError(
                        f"unsupported vertex property in {path}: {line}"
                    )
                vertex_properties.append((parts[2], parts[1]))

    if format_name not in {"binary_little_endian", "ascii"}:
        raise ValueError(f"unsupported PLY format in {path}: {format_name}")
    if vertex_count is None or vertex_count < 0:
        raise ValueError(f"missing PLY vertex count: {path}")

    names = [name for name, _ in vertex_properties]
    required = {"x", "y", "z", "red", "green", "blue"}
    missing = sorted(required.difference(names))
    if missing:
        raise ValueError(f"PLY missing properties {missing}: {path}")

    return (
        {
            "format": format_name,
            "vertex_count": vertex_count,
            "vertex_properties": vertex_properties,
        },
        header_end,
    )


def read_colored_ply(path: Path) -> tuple[np.ndarray, np.ndarray]:
    path = Path(path)
    header, header_end = parse_ply_header(path)
    properties = header["vertex_properties"]
    count = int(header["vertex_count"])

    if header["format"] == "binary_little_endian":
        dtype_fields: list[tuple[str, str]] = []
        for name, scalar_type in properties:
            if scalar_type not in PLY_SCALAR_TYPES:
                raise ValueError(
                    f"unsupported PLY scalar type {scalar_type}: {path}"
                )
            dtype_fields.append((name, PLY_SCALAR_TYPES[scalar_type][0]))

        dtype = np.dtype(dtype_fields)
        expected_bytes = count * dtype.itemsize
        with path.open("rb") as handle:
            handle.seek(header_end)
            payload = handle.read(expected_bytes)
        if len(payload) != expected_bytes:
            raise ValueError(
                f"truncated PLY payload: expected {expected_bytes}, "
                f"got {len(payload)}: {path}"
            )
        vertices = np.frombuffer(payload, dtype=dtype, count=count)
        points = np.column_stack(
            (
                vertices["x"],
                vertices["y"],
                vertices["z"],
            )
        ).astype(np.float32, copy=False)
        colors = np.column_stack(
            (
                vertices["red"],
                vertices["green"],
                vertices["blue"],
            )
        ).astype(np.uint8, copy=False)
        return points, colors

    names = [name for name, _ in properties]
    indices = {name: names.index(name) for name in names}
    rows: list[list[float]] = []
    with path.open("rb") as handle:
        handle.seek(header_end)
        for _ in range(count):
            line = handle.readline()
            if not line:
                raise ValueError(f"truncated ASCII PLY: {path}")
            values = line.decode("ascii", "strict").split()
            if len(values) < len(properties):
                raise ValueError(f"short ASCII PLY vertex row: {path}")
            rows.append([float(value) for value in values[: len(properties)]])

    array = np.asarray(rows, dtype=np.float64)
    points = array[
        :,
        [indices["x"], indices["y"], indices["z"]],
    ].astype(np.float32)
    colors = np.clip(
        np.rint(
            array[
                :,
                [indices["red"], indices["green"], indices["blue"]],
            ]
        ),
        0,
        255,
    ).astype(np.uint8)
    return points, colors


def write_binary_colored_ply(
    path: Path,
    points: np.ndarray,
    colors: np.ndarray,
) -> None:
    path = Path(path)
    points = np.asarray(points, dtype=np.float32).reshape(-1, 3)
    colors = np.asarray(colors, dtype=np.uint8).reshape(-1, 3)
    if points.shape != colors.shape:
        raise ValueError("PLY point/color shape mismatch")
    if not np.all(np.isfinite(points)):
        raise ValueError("cannot write non-finite PLY points")

    vertices = np.empty(
        len(points),
        dtype=[
            ("x", "<f4"),
            ("y", "<f4"),
            ("z", "<f4"),
            ("red", "u1"),
            ("green", "u1"),
            ("blue", "u1"),
        ],
    )
    vertices["x"] = points[:, 0]
    vertices["y"] = points[:, 1]
    vertices["z"] = points[:, 2]
    vertices["red"] = colors[:, 0]
    vertices["green"] = colors[:, 1]
    vertices["blue"] = colors[:, 2]

    header = (
        "ply\n"
        "format binary_little_endian 1.0\n"
        f"element vertex {len(vertices)}\n"
        "property float x\n"
        "property float y\n"
        "property float z\n"
        "property uchar red\n"
        "property uchar green\n"
        "property uchar blue\n"
        "end_header\n"
    ).encode("ascii")

    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("wb") as handle:
        handle.write(header)
        vertices.tofile(handle)


def validate_transform(value: Any, pair_index: int) -> np.ndarray:
    transform = np.asarray(value, dtype=np.float64)
    if transform.shape != (4, 4):
        raise ValueError(f"pair {pair_index}: transform must be 4x4")
    if not np.all(np.isfinite(transform)):
        raise ValueError(f"pair {pair_index}: non-finite transform")
    if not np.allclose(
        transform[3],
        np.array([0.0, 0.0, 0.0, 1.0]),
        atol=1e-6,
    ):
        raise ValueError(f"pair {pair_index}: invalid homogeneous row")

    rotation = transform[:3, :3]
    if not np.allclose(rotation.T @ rotation, np.eye(3), atol=2e-3):
        raise ValueError(f"pair {pair_index}: rotation is not orthonormal")
    determinant = float(np.linalg.det(rotation))
    if abs(determinant - 1.0) > 2e-3:
        raise ValueError(
            f"pair {pair_index}: invalid rotation determinant {determinant}"
        )
    return transform


def transform_points(
    points: np.ndarray,
    transform_cam0_to_world: np.ndarray,
) -> np.ndarray:
    points = np.asarray(points, dtype=np.float64).reshape(-1, 3)
    transform = np.asarray(
        transform_cam0_to_world,
        dtype=np.float64,
    ).reshape(4, 4)
    return (
        points @ transform[:3, :3].T + transform[:3, 3]
    ).astype(np.float32)


def voxel_downsample(
    points: np.ndarray,
    colors: np.ndarray,
    voxel_size_mm: float,
) -> tuple[np.ndarray, np.ndarray]:
    points = np.asarray(points, dtype=np.float32).reshape(-1, 3)
    colors = np.asarray(colors, dtype=np.uint8).reshape(-1, 3)
    if points.shape != colors.shape:
        raise ValueError("voxel point/color shape mismatch")
    if len(points) == 0:
        return points.copy(), colors.copy()
    if not math.isfinite(voxel_size_mm) or voxel_size_mm <= 0:
        raise ValueError("voxel_size_mm must be positive")

    keys = np.floor(
        points.astype(np.float64) / float(voxel_size_mm)
    ).astype(np.int64)
    order = np.lexsort((keys[:, 2], keys[:, 1], keys[:, 0]))
    sorted_keys = keys[order]
    sorted_points = points[order].astype(np.float64)
    sorted_colors = colors[order].astype(np.float64)

    changes = np.any(sorted_keys[1:] != sorted_keys[:-1], axis=1)
    starts = np.concatenate(
        (
            np.array([0], dtype=np.int64),
            np.flatnonzero(changes).astype(np.int64) + 1,
        )
    )
    counts = np.diff(
        np.concatenate(
            (
                starts,
                np.array([len(points)], dtype=np.int64),
            )
        )
    ).astype(np.float64)

    point_sums = np.add.reduceat(sorted_points, starts, axis=0)
    color_sums = np.add.reduceat(sorted_colors, starts, axis=0)
    downsampled_points = (
        point_sums / counts[:, None]
    ).astype(np.float32)
    downsampled_colors = np.clip(
        np.rint(color_sums / counts[:, None]),
        0,
        255,
    ).astype(np.uint8)
    return downsampled_points, downsampled_colors


def bounds_payload(points: np.ndarray) -> dict[str, list[float]] | None:
    if len(points) == 0:
        return None
    return {
        "min": np.min(points, axis=0).astype(float).tolist(),
        "max": np.max(points, axis=0).astype(float).tolist(),
    }


def validate_contracts(
    cloud_manifest: dict[str, Any],
    trajectory: dict[str, Any],
) -> None:
    if (
        cloud_manifest.get("coordinate_system")
        != "rectified_cam0_pair_local"
    ):
        raise ValueError("unsupported pair cloud coordinate system")
    if cloud_manifest.get("units") != "mm":
        raise ValueError("pair cloud units must be mm")
    if trajectory.get("coordinate_system") != "stereo_f01_world":
        raise ValueError("unsupported trajectory coordinate system")
    if trajectory.get("units") != "mm":
        raise ValueError("trajectory units must be mm")
    if trajectory.get("pose_convention") != "transform_cam0_to_world":
        raise ValueError("unsupported trajectory pose convention")


def run_fusion(
    dense_dir: Path,
    output_dir: Path | None = None,
    voxel_size_mm: float = 20.0,
    strict_manifest_count: bool = True,
) -> dict[str, Any]:
    dense_dir = Path(dense_dir).resolve()
    output_dir = (
        Path(output_dir).resolve()
        if output_dir is not None
        else dense_dir / "global_fusion"
    )
    output_dir.mkdir(parents=True, exist_ok=True)

    cloud_manifest_path = dense_dir / "pair_cloud_manifest.json"
    trajectory_path = dense_dir / "stereo_trajectory.json"
    cloud_manifest = load_json(cloud_manifest_path)
    trajectory = load_json(trajectory_path)
    validate_contracts(cloud_manifest, trajectory)

    pair_clouds = cloud_manifest.get("pair_clouds")
    poses = trajectory.get("poses")
    if not isinstance(pair_clouds, list) or not pair_clouds:
        raise ValueError("pair_cloud_manifest has no pair_clouds")
    if not isinstance(poses, list) or not poses:
        raise ValueError("stereo_trajectory has no poses")

    accepted_poses: dict[int, np.ndarray] = {}
    rejected_pose_indices: set[int] = set()
    for pose in poses:
        if not isinstance(pose, dict) or "pair_index" not in pose:
            continue
        pair_index = int(pose["pair_index"])
        if pose.get("accepted") is True:
            accepted_poses[pair_index] = validate_transform(
                pose.get("transform_cam0_to_world"),
                pair_index,
            )
        else:
            rejected_pose_indices.add(pair_index)

    included: list[dict[str, Any]] = []
    excluded: list[dict[str, Any]] = []
    world_points_parts: list[np.ndarray] = []
    color_parts: list[np.ndarray] = []
    source_points_before_filter = 0
    source_points_after_filter = 0

    for item in sorted(
        (entry for entry in pair_clouds if isinstance(entry, dict)),
        key=lambda entry: int(entry.get("pair_index", -1)),
    ):
        if "pair_index" not in item or "cloud_file" not in item:
            excluded.append(
                {
                    "pair_index": item.get("pair_index"),
                    "reason": "invalid_manifest_entry",
                }
            )
            continue

        pair_index = int(item["pair_index"])
        if pair_index not in accepted_poses:
            excluded.append(
                {
                    "pair_index": pair_index,
                    "cloud_file": item["cloud_file"],
                    "reason": (
                        "trajectory_pose_rejected"
                        if pair_index in rejected_pose_indices
                        else "accepted_trajectory_pose_missing"
                    ),
                }
            )
            continue

        cloud_path = dense_dir / str(item["cloud_file"])
        if not cloud_path.is_file():
            raise FileNotFoundError(
                f"pair {pair_index}: cloud file missing: {cloud_path}"
            )

        local_points, colors = read_colored_ply(cloud_path)
        actual_count = int(len(local_points))
        declared_count = int(item.get("point_count", actual_count))
        if strict_manifest_count and actual_count != declared_count:
            raise ValueError(
                f"pair {pair_index}: manifest point_count "
                f"{declared_count} != PLY {actual_count}"
            )

        source_points_before_filter += actual_count
        valid = (
            np.all(np.isfinite(local_points), axis=1)
            & (local_points[:, 2] > 0)
        )
        local_points = local_points[valid]
        colors = colors[valid]
        valid_count = int(len(local_points))
        source_points_after_filter += valid_count
        if valid_count == 0:
            excluded.append(
                {
                    "pair_index": pair_index,
                    "cloud_file": item["cloud_file"],
                    "reason": "no_valid_positive_depth_points",
                    "source_point_count": actual_count,
                }
            )
            continue

        world_points = transform_points(
            local_points,
            accepted_poses[pair_index],
        )
        finite_world = np.all(np.isfinite(world_points), axis=1)
        world_points = world_points[finite_world]
        colors = colors[finite_world]
        if len(world_points) == 0:
            excluded.append(
                {
                    "pair_index": pair_index,
                    "cloud_file": item["cloud_file"],
                    "reason": "no_finite_world_points",
                    "source_point_count": actual_count,
                }
            )
            continue

        world_points_parts.append(world_points)
        color_parts.append(colors)
        included.append(
            {
                "pair_index": pair_index,
                "cloud_file": item["cloud_file"],
                "declared_point_count": declared_count,
                "source_point_count": actual_count,
                "valid_local_point_count": valid_count,
                "world_point_count": int(len(world_points)),
                "transform_cam0_to_world": (
                    accepted_poses[pair_index].tolist()
                ),
            }
        )

    if not world_points_parts:
        raise RuntimeError("no accepted pair clouds with usable points")

    all_points = np.concatenate(world_points_parts, axis=0)
    all_colors = np.concatenate(color_parts, axis=0)
    fused_before_voxel = int(len(all_points))
    fused_points, fused_colors = voxel_downsample(
        all_points,
        all_colors,
        voxel_size_mm,
    )
    if len(fused_points) == 0:
        raise RuntimeError("voxel fusion produced no points")

    output_ply = output_dir / "fused_global_no_icp.ply"
    write_binary_colored_ply(output_ply, fused_points, fused_colors)

    output_ply_relative = str(output_ply.relative_to(dense_dir))
    manifest = {
        "schema_version": 1,
        "fusion_stage": "initial_no_icp",
        "coordinate_system": "stereo_f01_world",
        "units": "mm",
        "pose_convention": "transform_cam0_to_world",
        "source_pair_cloud_manifest": str(cloud_manifest_path),
        "source_stereo_trajectory": str(trajectory_path),
        "trajectory_status": trajectory.get("trajectory_status"),
        "source_pair_cloud_count": int(
            cloud_manifest.get("pair_cloud_count", len(pair_clouds))
        ),
        "trajectory_pair_count": int(
            trajectory.get("pair_count", len(poses))
        ),
        "accepted_pose_count": int(
            trajectory.get("accepted_pose_count", len(accepted_poses))
        ),
        "included_cloud_count": len(included),
        "excluded_cloud_count": len(excluded),
        "source_points_before_filter": source_points_before_filter,
        "source_points_after_filter": source_points_after_filter,
        "fused_points_before_voxel": fused_before_voxel,
        "fused_points_after_voxel": int(len(fused_points)),
        "voxel_size_mm": float(voxel_size_mm),
        "bounds_world_mm": bounds_payload(fused_points),
        "output_ply": output_ply_relative,
        "included": included,
        "excluded": excluded,
        "global_alignment_available": True,
        "icp_applied": False,
        "loop_closure_applied": False,
        "global_fusion_complete": False,
    }
    write_json(output_dir / "global_fusion_manifest.json", manifest)
    return manifest


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description=(
            "Transform F01A pair-local clouds with F01B poses and "
            "export an initial global PLY without ICP"
        )
    )
    parser.add_argument("dense_dir")
    parser.add_argument("--output-dir")
    parser.add_argument("--voxel-size-mm", type=float, default=20.0)
    parser.add_argument(
        "--allow-manifest-count-mismatch",
        action="store_true",
    )
    return parser


def main() -> None:
    args = build_parser().parse_args()
    manifest = run_fusion(
        Path(args.dense_dir),
        Path(args.output_dir) if args.output_dir else None,
        args.voxel_size_mm,
        strict_manifest_count=not args.allow_manifest_count_mismatch,
    )
    print(
        json.dumps(
            {
                "fusion_stage": manifest["fusion_stage"],
                "included_cloud_count": manifest[
                    "included_cloud_count"
                ],
                "excluded_cloud_count": manifest[
                    "excluded_cloud_count"
                ],
                "fused_points_before_voxel": manifest[
                    "fused_points_before_voxel"
                ],
                "fused_points_after_voxel": manifest[
                    "fused_points_after_voxel"
                ],
                "output_ply": manifest["output_ply"],
                "global_fusion_complete": False,
            },
            ensure_ascii=False,
        )
    )


if __name__ == "__main__":
    main()
