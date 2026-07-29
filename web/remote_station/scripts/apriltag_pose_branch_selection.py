#!/usr/bin/env python3
from __future__ import annotations

import math
from typing import Any

import numpy as np


def project_rotation(matrix: np.ndarray) -> np.ndarray:
    value = np.asarray(matrix, dtype=np.float64)
    if value.shape != (3, 3) or not np.all(np.isfinite(value)):
        raise ValueError("rotation matrix must be finite 3x3")
    u, _, vt = np.linalg.svd(value)
    rotation = u @ vt
    if np.linalg.det(rotation) < 0:
        u[:, -1] *= -1
        rotation = u @ vt
    return rotation


def average_rotations(rotations: list[np.ndarray]) -> np.ndarray:
    if not rotations:
        raise ValueError("cannot average an empty rotation list")
    return project_rotation(
        np.sum([np.asarray(rotation, dtype=np.float64) for rotation in rotations], axis=0)
    )


def rotation_distance_deg(left: np.ndarray, right: np.ndarray) -> float:
    relative = project_rotation(left) @ project_rotation(right).T
    cosine = max(-1.0, min(1.0, (float(np.trace(relative)) - 1.0) / 2.0))
    return math.degrees(math.acos(cosine))


def _select_nearest_candidates(
    observations: list[dict[str, Any]],
    consensus: np.ndarray,
) -> list[dict[str, Any]]:
    selected: list[dict[str, Any]] = []
    for observation in observations:
        candidates = observation.get("pnp_candidates")
        if not isinstance(candidates, list) or not candidates:
            raise ValueError(
                f"observation {observation.get('image_name', '?')} has no PnP candidates"
            )
        candidate = min(
            candidates,
            key=lambda item: (
                rotation_distance_deg(
                    np.asarray(item["rotation_tag_from_component"], dtype=np.float64),
                    consensus,
                ),
                float(item["pnp_reprojection_px"]),
                int(item["branch_index"]),
            ),
        )
        selected.append(candidate)
    return selected


def select_consistent_pnp_branches(
    observations: list[dict[str, Any]],
    minimum_observations: int,
    max_orientation_error_deg: float,
) -> dict[str, Any]:
    if minimum_observations < 3:
        raise ValueError("minimum_observations must be at least 3")
    if max_orientation_error_deg <= 0:
        raise ValueError("max_orientation_error_deg must be positive")
    if len(observations) < minimum_observations:
        raise ValueError("not enough observations for PnP branch selection")

    seed_rotations: list[np.ndarray] = []
    total_candidates = 0
    for observation in observations:
        candidates = observation.get("pnp_candidates")
        if not isinstance(candidates, list) or not candidates:
            raise ValueError(
                f"observation {observation.get('image_name', '?')} has no PnP candidates"
            )
        total_candidates += len(candidates)
        seed_rotations.extend(
            np.asarray(candidate["rotation_tag_from_component"], dtype=np.float64)
            for candidate in candidates
        )

    best: dict[str, Any] | None = None
    best_score: tuple[Any, ...] | None = None
    for seed_index, seed in enumerate(seed_rotations):
        consensus = project_rotation(seed)
        selected: list[dict[str, Any]] = []
        for _ in range(8):
            selected = _select_nearest_candidates(observations, consensus)
            residuals = [
                rotation_distance_deg(
                    np.asarray(candidate["rotation_tag_from_component"], dtype=np.float64),
                    consensus,
                )
                for candidate in selected
            ]
            inlier_indices = [
                index
                for index, residual in enumerate(residuals)
                if residual <= max_orientation_error_deg
            ]
            if len(inlier_indices) < minimum_observations:
                inlier_indices = sorted(
                    range(len(residuals)),
                    key=lambda index: residuals[index],
                )[:minimum_observations]
            updated = average_rotations(
                [
                    np.asarray(
                        selected[index]["rotation_tag_from_component"],
                        dtype=np.float64,
                    )
                    for index in inlier_indices
                ]
            )
            if rotation_distance_deg(updated, consensus) < 1.0e-7:
                consensus = updated
                break
            consensus = updated

        selected = _select_nearest_candidates(observations, consensus)
        residuals = [
            rotation_distance_deg(
                np.asarray(candidate["rotation_tag_from_component"], dtype=np.float64),
                consensus,
            )
            for candidate in selected
        ]
        inlier_indices = [
            index
            for index, residual in enumerate(residuals)
            if residual <= max_orientation_error_deg
        ]
        if len(inlier_indices) < minimum_observations:
            continue
        inlier_residuals = [residuals[index] for index in inlier_indices]
        reprojections = [
            float(selected[index]["pnp_reprojection_px"])
            for index in inlier_indices
        ]
        score = (
            -len(inlier_indices),
            float(np.median(inlier_residuals)),
            float(np.max(inlier_residuals)),
            float(np.median(reprojections)),
            seed_index,
        )
        if best_score is not None and score >= best_score:
            continue

        selected_observations: list[dict[str, Any]] = []
        rejected_images: list[str] = []
        branch_by_image: dict[str, int] = {}
        for index, observation in enumerate(observations):
            image_name = str(observation["image_name"])
            candidate = selected[index]
            branch_by_image[image_name] = int(candidate["branch_index"])
            if index not in inlier_indices:
                rejected_images.append(image_name)
                continue
            selected_observations.append(
                {
                    **observation,
                    "tag_center_m": np.asarray(
                        candidate["tag_center_m"],
                        dtype=np.float64,
                    ),
                    "pnp_reprojection_px": float(
                        candidate["pnp_reprojection_px"]
                    ),
                    "selected_pnp_branch": int(candidate["branch_index"]),
                    "orientation_error_deg": float(residuals[index]),
                    "rotation_tag_from_component": np.asarray(
                        candidate["rotation_tag_from_component"],
                        dtype=np.float64,
                    ),
                }
            )

        best_score = score
        best = {
            "consensus_rotation_tag_from_component": consensus,
            "selected_observations": selected_observations,
            "branch_by_image": branch_by_image,
            "orientation_inliers": len(inlier_indices),
            "orientation_rejected_images": rejected_images,
            "orientation_median_error_deg": float(np.median(inlier_residuals)),
            "orientation_max_error_deg": float(np.max(inlier_residuals)),
            "pnp_reprojection_median_px": float(np.median(reprojections)),
            "candidate_count": total_candidates,
        }

    if best is None:
        raise ValueError(
            "PnP branches are not orientation-consistent across enough observations"
        )
    return best
