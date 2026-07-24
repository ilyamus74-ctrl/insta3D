#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
import math
from pathlib import Path

import cv2
import numpy as np


ROOT = Path(__file__).resolve().parents[1]
MODULE_PATH = (
    ROOT
    / "remote_station"
    / "scripts"
    / "stereo_visual_odometry.py"
)
SPEC = importlib.util.spec_from_file_location(
    "stereo_visual_odometry",
    MODULE_PATH,
)
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(MODULE)


def check(condition, message):
    if not condition:
        raise AssertionError(message)


camera_matrix = np.array(
    [
        [800.0, 0.0, 320.0],
        [0.0, 810.0, 240.0],
        [0.0, 0.0, 1.0],
    ],
    dtype=np.float64,
)

point = MODULE.backproject_pixel(
    400.0,
    280.0,
    2000.0,
    camera_matrix,
)
np.testing.assert_allclose(
    point,
    [200.0, 98.76543, 2000.0],
    atol=1e-4,
)

depth = np.zeros((5, 5), dtype=np.float32)
depth[3, 2] = 1234.0
sample = MODULE.sample_metric_depth(depth, 2.0, 2.0, radius=1)
check(sample == (1234.0, 2, 3), "nearest valid depth sample")

rotation_vector = np.array([0.02, -0.04, 0.01], dtype=np.float64)
rotation, _ = cv2.Rodrigues(rotation_vector)
translation = np.array([55.0, -18.0, 32.0], dtype=np.float64)
known = MODULE.rigid_transform(rotation, translation)
inverse = MODULE.invert_rigid(known)
np.testing.assert_allclose(
    known @ inverse,
    np.eye(4),
    atol=1e-10,
)

rng = np.random.default_rng(7)
object_points = np.column_stack(
    (
        rng.uniform(-450.0, 450.0, 120),
        rng.uniform(-300.0, 300.0, 120),
        rng.uniform(1200.0, 3500.0, 120),
    )
).astype(np.float32)

image_points, _ = cv2.projectPoints(
    object_points,
    rotation_vector,
    translation.reshape(3, 1),
    camera_matrix,
    np.zeros((4, 1), dtype=np.float64),
)
image_points = image_points.reshape(-1, 2).astype(np.float32)

result = MODULE.solve_metric_pnp(
    object_points,
    image_points,
    camera_matrix,
    min_correspondences=20,
    min_inliers=15,
    min_inlier_ratio=0.5,
    ransac_reprojection_error_px=1.0,
    max_median_reprojection_error_px=0.5,
    max_translation_mm=500.0,
    max_rotation_deg=15.0,
    min_positive_depth_ratio=0.99,
)
check(result["accepted"] is True, f"synthetic PnP accepted: {result}")

estimated = np.asarray(
    result["transform_reference_to_current_camera"],
    dtype=np.float64,
)
translation_error = float(
    np.linalg.norm(estimated[:3, 3] - translation)
)
rotation_error = MODULE.rotation_angle_degrees(
    estimated[:3, :3] @ rotation.T
)
check(translation_error < 0.05, f"translation error: {translation_error}")
check(rotation_error < 0.01, f"rotation error: {rotation_error}")
check(
    result["pnp_inlier_count"] >= 100,
    "synthetic PnP inlier count",
)
check(
    result["median_reprojection_error_px"] < 0.1,
    "synthetic reprojection error",
)

too_few = MODULE.solve_metric_pnp(
    object_points[:4],
    image_points[:4],
    camera_matrix,
    min_correspondences=20,
)
check(too_few["accepted"] is False, "too few points rejected")
check(
    too_few["rejection_reason"] == "too_few_correspondences",
    "too few rejection reason",
)

source = MODULE_PATH.read_text(encoding="utf-8")
check("cv2.solvePnPRansac" in source, "PnP RANSAC implementation")
check(
    '"transform_cam0_to_world"' in source,
    "global pose convention",
)
check(
    '"global_fusion_complete": False' in source,
    "no global fusion claim",
)

print("OK")
