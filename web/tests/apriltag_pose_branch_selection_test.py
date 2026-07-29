#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
import math
import sys
from pathlib import Path

import numpy as np

SCRIPT = (
    Path(__file__).resolve().parents[1]
    / "remote_station"
    / "scripts"
    / "apriltag_pose_branch_selection.py"
)
spec = importlib.util.spec_from_file_location("apriltag_pose_branch_selection", SCRIPT)
module = importlib.util.module_from_spec(spec)
assert spec.loader is not None
sys.modules["apriltag_pose_branch_selection"] = module
spec.loader.exec_module(module)


def rot_y(angle: float) -> np.ndarray:
    cosine, sine = math.cos(angle), math.sin(angle)
    return np.asarray(
        [
            [cosine, 0.0, sine],
            [0.0, 1.0, 0.0],
            [-sine, 0.0, cosine],
        ],
        dtype=np.float64,
    )


def rot_z(angle: float) -> np.ndarray:
    cosine, sine = math.cos(angle), math.sin(angle)
    return np.asarray(
        [
            [cosine, -sine, 0.0],
            [sine, cosine, 0.0],
            [0.0, 0.0, 1.0],
        ],
        dtype=np.float64,
    )


expected_rotation = rot_z(0.4) @ rot_y(-0.2)
observations = []
expected_branches = {}

for index in range(8):
    image_name = f"frame_{index:06d}.jpg"
    correct_branch = index % 2
    correct_rotation = module.project_rotation(
        rot_z(math.radians((index - 3.5) * 0.25)) @ expected_rotation
    )
    mirror_rotation = module.project_rotation(
        rot_z(math.pi + math.radians(index * 11.0 - 35.0))
        @ expected_rotation
    )
    correct_candidate = {
        "branch_index": correct_branch,
        "tag_center_m": np.asarray([index * 0.08, 0.02 * index, 0.5]),
        "rotation_tag_from_component": correct_rotation,
        "pnp_reprojection_px": 0.45 + index * 0.01,
    }
    mirror_candidate = {
        "branch_index": 1 - correct_branch,
        "tag_center_m": np.asarray([-index * 0.08, 0.4, 0.5]),
        "rotation_tag_from_component": mirror_rotation,
        # The incorrect branch deliberately has lower per-frame reprojection.
        "pnp_reprojection_px": 0.20,
    }
    candidates = [correct_candidate, mirror_candidate]
    if correct_branch == 1:
        candidates.reverse()
    observations.append(
        {
            "image_name": image_name,
            "component_center": np.asarray([index * 0.1, 0.0, 0.0]),
            "pnp_candidates": candidates,
        }
    )
    expected_branches[image_name] = correct_branch

selection = module.select_consistent_pnp_branches(
    observations,
    minimum_observations=3,
    max_orientation_error_deg=5.0,
)

assert selection["branch_by_image"] == expected_branches, selection
assert selection["orientation_inliers"] == 8, selection
assert selection["orientation_rejected_images"] == [], selection
assert selection["orientation_median_error_deg"] < 1.0, selection
assert selection["orientation_max_error_deg"] < 1.0, selection
assert module.rotation_distance_deg(
    selection["consensus_rotation_tag_from_component"],
    expected_rotation,
) < 0.1

print("OK")
