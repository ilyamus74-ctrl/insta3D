#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
import json
import tempfile
from pathlib import Path

import numpy as np


ROOT = Path(__file__).resolve().parents[1]
MODULE_PATH = (
    ROOT
    / "remote_station"
    / "scripts"
    / "stereo_global_fusion.py"
)
SPEC = importlib.util.spec_from_file_location(
    "stereo_global_fusion",
    MODULE_PATH,
)
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(MODULE)


def check(condition, message):
    if not condition:
        raise AssertionError(message)


identity = np.eye(4, dtype=np.float64)
translated = np.eye(4, dtype=np.float64)
translated[:3, 3] = [100.0, 20.0, -10.0]

sample = np.array(
    [
        [0.0, 0.0, 1000.0],
        [10.0, 0.0, 1000.0],
    ],
    dtype=np.float32,
)
transformed = MODULE.transform_points(sample, translated)
np.testing.assert_allclose(
    transformed,
    [
        [100.0, 20.0, 990.0],
        [110.0, 20.0, 990.0],
    ],
    atol=1e-6,
)

voxel_points = np.array(
    [
        [0.0, 0.0, 1000.0],
        [4.0, 2.0, 1002.0],
        [30.0, 0.0, 1000.0],
    ],
    dtype=np.float32,
)
voxel_colors = np.array(
    [
        [10, 20, 30],
        [30, 40, 50],
        [100, 110, 120],
    ],
    dtype=np.uint8,
)
down_points, down_colors = MODULE.voxel_downsample(
    voxel_points,
    voxel_colors,
    10.0,
)
check(len(down_points) == 2, "voxel reduction")
np.testing.assert_allclose(
    down_points[0],
    [2.0, 1.0, 1001.0],
    atol=1e-6,
)
check(
    down_colors[0].tolist() == [20, 30, 40],
    "voxel mean color",
)

with tempfile.TemporaryDirectory() as temp_dir:
    dense = Path(temp_dir) / "dense"
    cloud_dir = dense / "pair_clouds"
    cloud_dir.mkdir(parents=True)

    cloud0_points = np.array(
        [
            [0.0, 0.0, 1000.0],
            [10.0, 0.0, 1000.0],
        ],
        dtype=np.float32,
    )
    cloud0_colors = np.array(
        [
            [255, 0, 0],
            [0, 255, 0],
        ],
        dtype=np.uint8,
    )
    cloud1_points = np.array(
        [
            [0.0, 0.0, 1000.0],
            [0.0, 10.0, 1000.0],
        ],
        dtype=np.float32,
    )
    cloud1_colors = np.array(
        [
            [0, 0, 255],
            [255, 255, 0],
        ],
        dtype=np.uint8,
    )
    cloud2_points = np.array(
        [[0.0, 0.0, 500.0]],
        dtype=np.float32,
    )
    cloud2_colors = np.array(
        [[255, 0, 255]],
        dtype=np.uint8,
    )

    MODULE.write_binary_colored_ply(
        cloud_dir / "dense_pair_0000_cloud.ply",
        cloud0_points,
        cloud0_colors,
    )
    MODULE.write_binary_colored_ply(
        cloud_dir / "dense_pair_0001_cloud.ply",
        cloud1_points,
        cloud1_colors,
    )
    MODULE.write_binary_colored_ply(
        cloud_dir / "dense_pair_0002_cloud.ply",
        cloud2_points,
        cloud2_colors,
    )

    pair_manifest = {
        "schema_version": 1,
        "coordinate_system": "rectified_cam0_pair_local",
        "units": "mm",
        "pair_cloud_count": 3,
        "global_fusion_complete": False,
        "pair_clouds": [
            {
                "pair_index": 0,
                "cloud_file": (
                    "pair_clouds/dense_pair_0000_cloud.ply"
                ),
                "point_count": 2,
            },
            {
                "pair_index": 1,
                "cloud_file": (
                    "pair_clouds/dense_pair_0001_cloud.ply"
                ),
                "point_count": 2,
            },
            {
                "pair_index": 2,
                "cloud_file": (
                    "pair_clouds/dense_pair_0002_cloud.ply"
                ),
                "point_count": 1,
            },
        ],
    }
    trajectory = {
        "schema_version": 1,
        "coordinate_system": "stereo_f01_world",
        "units": "mm",
        "pose_convention": "transform_cam0_to_world",
        "pair_count": 3,
        "accepted_pose_count": 2,
        "rejected_pose_count": 1,
        "trajectory_status": "partial",
        "global_fusion_complete": False,
        "poses": [
            {
                "pair_index": 0,
                "accepted": True,
                "status": "origin",
                "transform_cam0_to_world": identity.tolist(),
            },
            {
                "pair_index": 1,
                "accepted": True,
                "status": "accepted",
                "transform_cam0_to_world": translated.tolist(),
            },
            {
                "pair_index": 2,
                "accepted": False,
                "status": "rejected",
                "transform_cam0_to_world": None,
            },
        ],
    }

    (dense / "pair_cloud_manifest.json").write_text(
        json.dumps(pair_manifest),
        encoding="utf-8",
    )
    (dense / "stereo_trajectory.json").write_text(
        json.dumps(trajectory),
        encoding="utf-8",
    )

    manifest = MODULE.run_fusion(
        dense,
        voxel_size_mm=1.0,
    )
    check(
        manifest["fusion_stage"] == "initial_no_icp",
        "fusion stage",
    )
    check(
        manifest["included_cloud_count"] == 2,
        "accepted clouds included",
    )
    check(
        manifest["excluded_cloud_count"] == 1,
        "rejected cloud excluded",
    )
    check(
        manifest["fused_points_before_voxel"] == 4,
        "source points fused",
    )
    check(
        manifest["fused_points_after_voxel"] == 4,
        "small voxel preserves points",
    )
    check(
        manifest["icp_applied"] is False,
        "ICP remains false",
    )
    check(
        manifest["global_fusion_complete"] is False,
        "final fusion is not claimed",
    )

    output_ply = dense / manifest["output_ply"]
    check(output_ply.is_file(), "global PLY exists")
    fused_points, fused_colors = MODULE.read_colored_ply(output_ply)
    check(len(fused_points) == 4, "global PLY vertex count")

    expected = np.array(
        [
            [0.0, 0.0, 1000.0],
            [10.0, 0.0, 1000.0],
            [100.0, 20.0, 990.0],
            [100.0, 30.0, 990.0],
        ],
        dtype=np.float32,
    )
    actual_sorted = fused_points[
        np.lexsort(
            (
                fused_points[:, 2],
                fused_points[:, 1],
                fused_points[:, 0],
            )
        )
    ]
    expected_sorted = expected[
        np.lexsort(
            (
                expected[:, 2],
                expected[:, 1],
                expected[:, 0],
            )
        )
    ]
    np.testing.assert_allclose(
        actual_sorted,
        expected_sorted,
        atol=1e-5,
    )
    check(
        not np.any(
            np.all(
                np.isclose(fused_points, [0.0, 0.0, 500.0]),
                axis=1,
            )
        ),
        "rejected pose cloud absent",
    )
    check(
        fused_colors.shape == fused_points.shape,
        "global colors preserved",
    )

source = MODULE_PATH.read_text(encoding="utf-8")
check(
    '"fusion_stage": "initial_no_icp"' in source,
    "initial no-ICP contract",
)
check(
    '"icp_applied": False' in source,
    "ICP false contract",
)
check(
    '"global_fusion_complete": False' in source,
    "no premature completion claim",
)

print("OK")
