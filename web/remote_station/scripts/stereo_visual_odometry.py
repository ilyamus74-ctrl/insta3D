#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import math
from pathlib import Path
from typing import Any

import cv2
import numpy as np


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


def undo_depth_input_rotation(image: np.ndarray, mode: str) -> np.ndarray:
    if mode == "rotate_90_ccw":
        return cv2.rotate(image, cv2.ROTATE_90_CLOCKWISE)
    if mode == "rotate_90_cw":
        return cv2.rotate(image, cv2.ROTATE_90_COUNTERCLOCKWISE)
    return image


def rigid_transform(rotation: np.ndarray, translation: np.ndarray) -> np.ndarray:
    result = np.eye(4, dtype=np.float64)
    result[:3, :3] = np.asarray(rotation, dtype=np.float64).reshape(3, 3)
    result[:3, 3] = np.asarray(translation, dtype=np.float64).reshape(3)
    return result


def invert_rigid(transform: np.ndarray) -> np.ndarray:
    transform = np.asarray(transform, dtype=np.float64).reshape(4, 4)
    rotation = transform[:3, :3]
    translation = transform[:3, 3]
    result = np.eye(4, dtype=np.float64)
    result[:3, :3] = rotation.T
    result[:3, 3] = -(rotation.T @ translation)
    return result


def rotation_angle_degrees(rotation: np.ndarray) -> float:
    rotation = np.asarray(rotation, dtype=np.float64).reshape(3, 3)
    cosine = (float(np.trace(rotation)) - 1.0) * 0.5
    cosine = max(-1.0, min(1.0, cosine))
    return math.degrees(math.acos(cosine))


def camera_matrix_from_p1(p1: np.ndarray) -> np.ndarray:
    p1 = np.asarray(p1, dtype=np.float64)
    if p1.shape not in ((3, 3), (3, 4)):
        raise ValueError("P1 must have shape 3x3 or 3x4")
    camera_matrix = p1[:, :3].copy()
    if not np.all(np.isfinite(camera_matrix)):
        raise ValueError("non-finite rectified camera matrix")
    if camera_matrix[0, 0] <= 0 or camera_matrix[1, 1] <= 0:
        raise ValueError("invalid rectified focal length")
    return camera_matrix


def sample_metric_depth(
    depth_mm: np.ndarray,
    u: float,
    v: float,
    radius: int = 1,
) -> tuple[float, int, int] | None:
    height, width = depth_mm.shape
    center_x = int(round(float(u)))
    center_y = int(round(float(v)))
    candidates: list[tuple[int, int, int]] = []

    radius = max(0, int(radius))
    for dy in range(-radius, radius + 1):
        for dx in range(-radius, radius + 1):
            x = center_x + dx
            y = center_y + dy
            if 0 <= x < width and 0 <= y < height:
                candidates.append((dx * dx + dy * dy, x, y))

    for _, x, y in sorted(candidates):
        value = float(depth_mm[y, x])
        if math.isfinite(value) and value > 0:
            return value, x, y
    return None


def backproject_pixel(
    u: float,
    v: float,
    depth_mm: float,
    camera_matrix: np.ndarray,
) -> np.ndarray:
    fx = float(camera_matrix[0, 0])
    fy = float(camera_matrix[1, 1])
    cx = float(camera_matrix[0, 2])
    cy = float(camera_matrix[1, 2])
    z = float(depth_mm)
    return np.array(
        [
            (float(u) - cx) * z / fx,
            (float(v) - cy) * z / fy,
            z,
        ],
        dtype=np.float32,
    )


def prepare_gray(image_bgr: np.ndarray) -> np.ndarray:
    gray = cv2.cvtColor(image_bgr, cv2.COLOR_BGR2GRAY)
    return cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8)).apply(gray)


def detect_orb(
    image_bgr: np.ndarray,
    nfeatures: int,
    fast_threshold: int,
) -> tuple[list[cv2.KeyPoint], np.ndarray | None]:
    orb = cv2.ORB_create(
        nfeatures=max(200, int(nfeatures)),
        scaleFactor=1.2,
        nlevels=8,
        edgeThreshold=19,
        patchSize=31,
        fastThreshold=max(1, int(fast_threshold)),
    )
    keypoints, descriptors = orb.detectAndCompute(prepare_gray(image_bgr), None)
    return list(keypoints or []), descriptors


def ratio_pass(
    pairs: list[tuple[cv2.DMatch, cv2.DMatch]],
    ratio: float,
    max_distance: float,
) -> dict[int, cv2.DMatch]:
    accepted: dict[int, cv2.DMatch] = {}
    for first, second in pairs:
        if first.distance > max_distance:
            continue
        if second.distance <= 0:
            continue
        if first.distance < ratio * second.distance:
            accepted[first.queryIdx] = first
    return accepted


def mutual_ratio_matches(
    reference_descriptors: np.ndarray | None,
    current_descriptors: np.ndarray | None,
    ratio: float,
    max_distance: float,
) -> list[cv2.DMatch]:
    if reference_descriptors is None or current_descriptors is None:
        return []
    if len(reference_descriptors) < 2 or len(current_descriptors) < 2:
        return []

    matcher = cv2.BFMatcher(cv2.NORM_HAMMING, crossCheck=False)
    forward = ratio_pass(
        matcher.knnMatch(reference_descriptors, current_descriptors, k=2),
        ratio,
        max_distance,
    )
    reverse = ratio_pass(
        matcher.knnMatch(current_descriptors, reference_descriptors, k=2),
        ratio,
        max_distance,
    )

    matches: list[cv2.DMatch] = []
    for reference_index, match in forward.items():
        reverse_match = reverse.get(match.trainIdx)
        if reverse_match is not None and reverse_match.trainIdx == reference_index:
            matches.append(match)
    matches.sort(key=lambda item: float(item.distance))
    return matches


def build_metric_correspondences(
    reference_keypoints: list[cv2.KeyPoint],
    current_keypoints: list[cv2.KeyPoint],
    matches: list[cv2.DMatch],
    reference_depth_mm: np.ndarray,
    camera_matrix: np.ndarray,
    depth_search_radius: int,
) -> tuple[np.ndarray, np.ndarray]:
    object_points: list[np.ndarray] = []
    image_points: list[tuple[float, float]] = []

    for match in matches:
        reference_point = reference_keypoints[match.queryIdx].pt
        current_point = current_keypoints[match.trainIdx].pt
        sample = sample_metric_depth(
            reference_depth_mm,
            reference_point[0],
            reference_point[1],
            depth_search_radius,
        )
        if sample is None:
            continue
        depth, sample_x, sample_y = sample
        object_points.append(
            backproject_pixel(sample_x, sample_y, depth, camera_matrix)
        )
        image_points.append((float(current_point[0]), float(current_point[1])))

    if not object_points:
        return (
            np.empty((0, 3), dtype=np.float32),
            np.empty((0, 2), dtype=np.float32),
        )

    return (
        np.asarray(object_points, dtype=np.float32),
        np.asarray(image_points, dtype=np.float32),
    )


def rejected_result(
    reason: str,
    correspondence_count: int,
    **extra: Any,
) -> dict[str, Any]:
    result: dict[str, Any] = {
        "accepted": False,
        "rejection_reason": reason,
        "correspondence_count": int(correspondence_count),
        "pnp_inlier_count": 0,
        "pnp_inlier_ratio": 0.0,
        "median_reprojection_error_px": None,
        "p90_reprojection_error_px": None,
        "relative_translation_mm": None,
        "relative_rotation_deg": None,
        "positive_depth_ratio": None,
        "transform_reference_to_current_camera": None,
        "transform_reference_from_current_camera": None,
    }
    result.update(extra)
    return result


def solve_metric_pnp(
    object_points: np.ndarray,
    image_points: np.ndarray,
    camera_matrix: np.ndarray,
    *,
    min_correspondences: int = 20,
    min_inliers: int = 15,
    min_inlier_ratio: float = 0.35,
    ransac_reprojection_error_px: float = 4.0,
    max_median_reprojection_error_px: float = 3.5,
    max_translation_mm: float = 1500.0,
    max_rotation_deg: float = 35.0,
    min_positive_depth_ratio: float = 0.90,
) -> dict[str, Any]:
    object_points = np.asarray(object_points, dtype=np.float32).reshape(-1, 3)
    image_points = np.asarray(image_points, dtype=np.float32).reshape(-1, 2)
    correspondence_count = int(len(object_points))

    if correspondence_count != len(image_points):
        raise ValueError("object/image correspondence count mismatch")
    if correspondence_count < int(min_correspondences):
        return rejected_result(
            "too_few_correspondences",
            correspondence_count,
        )

    distortion = np.zeros((4, 1), dtype=np.float64)
    success, rvec, tvec, inliers = cv2.solvePnPRansac(
        object_points,
        image_points,
        camera_matrix,
        distortion,
        iterationsCount=250,
        reprojectionError=float(ransac_reprojection_error_px),
        confidence=0.999,
        flags=cv2.SOLVEPNP_EPNP,
    )
    if not success or inliers is None:
        return rejected_result("pnp_failed", correspondence_count)

    inlier_indices = np.asarray(inliers, dtype=np.int32).reshape(-1)
    inlier_count = int(len(inlier_indices))
    inlier_ratio = inlier_count / max(1, correspondence_count)

    if inlier_count >= 4 and hasattr(cv2, "solvePnPRefineLM"):
        rvec, tvec = cv2.solvePnPRefineLM(
            object_points[inlier_indices],
            image_points[inlier_indices],
            camera_matrix,
            distortion,
            rvec,
            tvec,
        )

    projected, _ = cv2.projectPoints(
        object_points[inlier_indices],
        rvec,
        tvec,
        camera_matrix,
        distortion,
    )
    projected = projected.reshape(-1, 2)
    errors = np.linalg.norm(
        projected - image_points[inlier_indices],
        axis=1,
    )
    median_error = float(np.median(errors)) if len(errors) else math.inf
    p90_error = (
        float(np.percentile(errors, 90))
        if len(errors)
        else math.inf
    )

    rotation, _ = cv2.Rodrigues(rvec)
    transform_current_from_reference = rigid_transform(rotation, tvec)
    transform_reference_from_current = invert_rigid(
        transform_current_from_reference
    )
    relative_translation = float(
        np.linalg.norm(transform_reference_from_current[:3, 3])
    )
    relative_rotation = rotation_angle_degrees(
        transform_reference_from_current[:3, :3]
    )

    transformed = (
        rotation @ object_points[inlier_indices].astype(np.float64).T
        + np.asarray(tvec, dtype=np.float64).reshape(3, 1)
    ).T
    positive_depth_ratio = float(np.mean(transformed[:, 2] > 0))

    rejection_reasons: list[str] = []
    if inlier_count < int(min_inliers):
        rejection_reasons.append("too_few_pnp_inliers")
    if inlier_ratio < float(min_inlier_ratio):
        rejection_reasons.append("low_pnp_inlier_ratio")
    if median_error > float(max_median_reprojection_error_px):
        rejection_reasons.append("high_reprojection_error")
    if relative_translation > float(max_translation_mm):
        rejection_reasons.append("translation_jump")
    if relative_rotation > float(max_rotation_deg):
        rejection_reasons.append("rotation_jump")
    if positive_depth_ratio < float(min_positive_depth_ratio):
        rejection_reasons.append("points_behind_camera")

    return {
        "accepted": not rejection_reasons,
        "rejection_reason": (
            None if not rejection_reasons else ",".join(rejection_reasons)
        ),
        "correspondence_count": correspondence_count,
        "pnp_inlier_count": inlier_count,
        "pnp_inlier_ratio": float(inlier_ratio),
        "median_reprojection_error_px": median_error,
        "p90_reprojection_error_px": p90_error,
        "relative_translation_mm": relative_translation,
        "relative_rotation_deg": relative_rotation,
        "positive_depth_ratio": positive_depth_ratio,
        "transform_reference_to_current_camera": (
            transform_current_from_reference.tolist()
        ),
        "transform_reference_from_current_camera": (
            transform_reference_from_current.tolist()
        ),
    }


def load_frame(
    dense_dir: Path,
    pair_index: int,
    depth_input_rotation: str,
    orb_nfeatures: int,
    orb_fast_threshold: int,
) -> dict[str, Any]:
    stem = f"dense_pair_{pair_index:04d}"
    image_path = dense_dir / f"{stem}_rect_cam0.png"
    depth_path = dense_dir / f"{stem}_depth_mm.npy"

    image = cv2.imread(str(image_path), cv2.IMREAD_COLOR)
    if image is None:
        raise FileNotFoundError(f"missing rectified cam0 image: {image_path}")
    if not depth_path.is_file():
        raise FileNotFoundError(f"missing depth array: {depth_path}")

    depth = np.load(depth_path)
    depth = undo_depth_input_rotation(depth, depth_input_rotation)
    if depth.shape != image.shape[:2]:
        raise ValueError(
            f"depth/image shape mismatch for pair {pair_index}: "
            f"{depth.shape} vs {image.shape[:2]}"
        )

    keypoints, descriptors = detect_orb(
        image,
        orb_nfeatures,
        orb_fast_threshold,
    )
    return {
        "pair_index": int(pair_index),
        "image": image,
        "depth_mm": depth,
        "keypoints": keypoints,
        "descriptors": descriptors,
    }


def estimate_between_frames(
    reference: dict[str, Any],
    current: dict[str, Any],
    camera_matrix: np.ndarray,
    args: argparse.Namespace,
) -> dict[str, Any]:
    matches = mutual_ratio_matches(
        reference["descriptors"],
        current["descriptors"],
        args.match_ratio,
        args.max_hamming_distance,
    )
    object_points, image_points = build_metric_correspondences(
        reference["keypoints"],
        current["keypoints"],
        matches,
        reference["depth_mm"],
        camera_matrix,
        args.depth_search_radius,
    )

    result = solve_metric_pnp(
        object_points,
        image_points,
        camera_matrix,
        min_correspondences=args.min_correspondences,
        min_inliers=args.min_inliers,
        min_inlier_ratio=args.min_inlier_ratio,
        ransac_reprojection_error_px=args.ransac_reprojection_error_px,
        max_median_reprojection_error_px=(
            args.max_median_reprojection_error_px
        ),
        max_translation_mm=args.max_translation_mm,
        max_rotation_deg=args.max_rotation_deg,
        min_positive_depth_ratio=args.min_positive_depth_ratio,
    )
    result["raw_match_count"] = int(len(matches))
    result["reference_feature_count"] = int(len(reference["keypoints"]))
    result["current_feature_count"] = int(len(current["keypoints"]))
    return result


def quality_score(result: dict[str, Any]) -> tuple[int, float, int]:
    return (
        int(result.get("pnp_inlier_count") or 0),
        -float(result.get("median_reprojection_error_px") or math.inf),
        int(result.get("correspondence_count") or 0),
    )


def parameters_payload(args: argparse.Namespace) -> dict[str, Any]:
    return {
        "orb_nfeatures": args.orb_nfeatures,
        "orb_fast_threshold": args.orb_fast_threshold,
        "match_ratio": args.match_ratio,
        "max_hamming_distance": args.max_hamming_distance,
        "depth_search_radius": args.depth_search_radius,
        "min_correspondences": args.min_correspondences,
        "min_inliers": args.min_inliers,
        "min_inlier_ratio": args.min_inlier_ratio,
        "ransac_reprojection_error_px": (
            args.ransac_reprojection_error_px
        ),
        "max_median_reprojection_error_px": (
            args.max_median_reprojection_error_px
        ),
        "max_translation_mm": args.max_translation_mm,
        "max_rotation_deg": args.max_rotation_deg,
        "min_positive_depth_ratio": args.min_positive_depth_ratio,
        "reference_window": args.reference_window,
    }


def run(args: argparse.Namespace) -> dict[str, Any]:
    dense_dir = Path(args.dense_dir).resolve()
    output_dir = (
        Path(args.output_dir).resolve()
        if args.output_dir
        else dense_dir
    )
    output_dir.mkdir(parents=True, exist_ok=True)

    cloud_manifest = load_json(dense_dir / "pair_cloud_manifest.json")
    dense_debug = load_json(dense_dir / "dense_depth_debug.json")
    pair_clouds = cloud_manifest.get("pair_clouds", [])
    if not isinstance(pair_clouds, list) or not pair_clouds:
        raise RuntimeError("pair_cloud_manifest has no pair clouds")

    pair_indices = sorted(
        {
            int(item["pair_index"])
            for item in pair_clouds
            if isinstance(item, dict) and "pair_index" in item
        }
    )
    if not pair_indices:
        raise RuntimeError("no pair indices in pair cloud manifest")

    camera_matrix = camera_matrix_from_p1(
        np.asarray(dense_debug["P1"], dtype=np.float64)
    )
    depth_input_rotation = str(
        dense_debug.get("depth_input_rotation", "none")
    )

    accepted_references: list[dict[str, Any]] = []
    poses: list[dict[str, Any]] = []
    debug_attempts: list[dict[str, Any]] = []
    origin_pair_index: int | None = None

    for pair_index in pair_indices:
        try:
            current = load_frame(
                dense_dir,
                pair_index,
                depth_input_rotation,
                args.orb_nfeatures,
                args.orb_fast_threshold,
            )
        except Exception as exc:
            poses.append(
                {
                    "pair_index": pair_index,
                    "reference_pair_index": None,
                    "accepted": False,
                    "status": "rejected",
                    "rejection_reason": f"frame_load_error:{exc}",
                    "transform_cam0_to_world": None,
                }
            )
            debug_attempts.append(
                {
                    "pair_index": pair_index,
                    "attempts": [],
                    "error": str(exc),
                }
            )
            continue

        if not accepted_references:
            world_from_camera = np.eye(4, dtype=np.float64)
            current["transform_cam0_to_world"] = world_from_camera
            accepted_references.append(current)
            origin_pair_index = pair_index
            poses.append(
                {
                    "pair_index": pair_index,
                    "reference_pair_index": None,
                    "accepted": True,
                    "status": "origin",
                    "rejection_reason": None,
                    "transform_reference_to_current_camera": None,
                    "transform_cam0_to_world": world_from_camera.tolist(),
                    "camera_center_world_mm": [0.0, 0.0, 0.0],
                    "correspondence_count": 0,
                    "pnp_inlier_count": 0,
                    "pnp_inlier_ratio": 1.0,
                    "median_reprojection_error_px": 0.0,
                    "p90_reprojection_error_px": 0.0,
                    "relative_translation_mm": 0.0,
                    "relative_rotation_deg": 0.0,
                }
            )
            debug_attempts.append(
                {
                    "pair_index": pair_index,
                    "attempts": [],
                    "selected_reference_pair_index": None,
                    "status": "origin",
                }
            )
            continue

        attempts: list[dict[str, Any]] = []
        candidate_references = list(
            reversed(accepted_references[-args.reference_window :])
        )
        for reference in candidate_references:
            result = estimate_between_frames(
                reference,
                current,
                camera_matrix,
                args,
            )
            result["reference_pair_index"] = reference["pair_index"]
            attempts.append(result)

        accepted_attempts = [
            item for item in attempts if item.get("accepted") is True
        ]
        if accepted_attempts:
            selected = max(accepted_attempts, key=quality_score)
        else:
            selected = max(attempts, key=quality_score) if attempts else None

        debug_attempts.append(
            {
                "pair_index": pair_index,
                "attempts": attempts,
                "selected_reference_pair_index": (
                    selected.get("reference_pair_index")
                    if selected
                    else None
                ),
                "status": (
                    "accepted"
                    if selected and selected.get("accepted")
                    else "rejected"
                ),
            }
        )

        if selected is None or selected.get("accepted") is not True:
            poses.append(
                {
                    "pair_index": pair_index,
                    "reference_pair_index": (
                        selected.get("reference_pair_index")
                        if selected
                        else None
                    ),
                    "accepted": False,
                    "status": "rejected",
                    "rejection_reason": (
                        selected.get("rejection_reason")
                        if selected
                        else "no_reference_attempt"
                    ),
                    "transform_cam0_to_world": None,
                    **(
                        {
                            key: selected.get(key)
                            for key in (
                                "raw_match_count",
                                "correspondence_count",
                                "pnp_inlier_count",
                                "pnp_inlier_ratio",
                                "median_reprojection_error_px",
                                "p90_reprojection_error_px",
                                "relative_translation_mm",
                                "relative_rotation_deg",
                                "positive_depth_ratio",
                            )
                        }
                        if selected
                        else {}
                    ),
                }
            )
            continue

        reference_pair_index = int(selected["reference_pair_index"])
        reference = next(
            item
            for item in accepted_references
            if item["pair_index"] == reference_pair_index
        )
        reference_from_current = np.asarray(
            selected["transform_reference_from_current_camera"],
            dtype=np.float64,
        )
        world_from_current = (
            reference["transform_cam0_to_world"]
            @ reference_from_current
        )
        current["transform_cam0_to_world"] = world_from_current
        accepted_references.append(current)

        poses.append(
            {
                "pair_index": pair_index,
                "reference_pair_index": reference_pair_index,
                "accepted": True,
                "status": "accepted",
                "rejection_reason": None,
                "transform_reference_to_current_camera": selected[
                    "transform_reference_to_current_camera"
                ],
                "transform_cam0_to_world": world_from_current.tolist(),
                "camera_center_world_mm": (
                    world_from_current[:3, 3].astype(float).tolist()
                ),
                **{
                    key: selected.get(key)
                    for key in (
                        "raw_match_count",
                        "reference_feature_count",
                        "current_feature_count",
                        "correspondence_count",
                        "pnp_inlier_count",
                        "pnp_inlier_ratio",
                        "median_reprojection_error_px",
                        "p90_reprojection_error_px",
                        "relative_translation_mm",
                        "relative_rotation_deg",
                        "positive_depth_ratio",
                    )
                },
            }
        )

    accepted_count = sum(
        1 for item in poses if item.get("accepted") is True
    )
    rejected_count = len(poses) - accepted_count
    if accepted_count <= 1:
        trajectory_status = "origin_only"
    elif rejected_count:
        trajectory_status = "partial"
    else:
        trajectory_status = "complete_pair_sequence"

    trajectory = {
        "schema_version": 1,
        "coordinate_system": "stereo_f01_world",
        "units": "mm",
        "pose_convention": "transform_cam0_to_world",
        "relative_pnp_convention": (
            "transform_reference_to_current_camera"
        ),
        "world_origin_pair_index": origin_pair_index,
        "pair_count": len(pair_indices),
        "accepted_pose_count": accepted_count,
        "rejected_pose_count": rejected_count,
        "trajectory_status": trajectory_status,
        "global_fusion_complete": False,
        "poses": poses,
    }
    debug = {
        "schema_version": 1,
        "source_dense_dir": str(dense_dir),
        "pair_indices": pair_indices,
        "rectified_camera_matrix": camera_matrix.tolist(),
        "depth_input_rotation": depth_input_rotation,
        "parameters": parameters_payload(args),
        "attempts_by_pair": debug_attempts,
        "accepted_pose_count": accepted_count,
        "rejected_pose_count": rejected_count,
        "global_fusion_complete": False,
    }

    write_json(output_dir / "stereo_trajectory.json", trajectory)
    write_json(output_dir / "stereo_odometry_debug.json", debug)
    return trajectory


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Metric visual odometry for F01A stereo pair artifacts"
    )
    parser.add_argument("dense_dir")
    parser.add_argument("--output-dir")
    parser.add_argument("--orb-nfeatures", type=int, default=3500)
    parser.add_argument("--orb-fast-threshold", type=int, default=10)
    parser.add_argument("--match-ratio", type=float, default=0.75)
    parser.add_argument("--max-hamming-distance", type=float, default=64.0)
    parser.add_argument("--depth-search-radius", type=int, default=1)
    parser.add_argument("--min-correspondences", type=int, default=20)
    parser.add_argument("--min-inliers", type=int, default=15)
    parser.add_argument("--min-inlier-ratio", type=float, default=0.35)
    parser.add_argument(
        "--ransac-reprojection-error-px",
        type=float,
        default=4.0,
    )
    parser.add_argument(
        "--max-median-reprojection-error-px",
        type=float,
        default=3.5,
    )
    parser.add_argument("--max-translation-mm", type=float, default=1500.0)
    parser.add_argument("--max-rotation-deg", type=float, default=35.0)
    parser.add_argument(
        "--min-positive-depth-ratio",
        type=float,
        default=0.90,
    )
    parser.add_argument("--reference-window", type=int, default=3)
    return parser


def main() -> None:
    args = build_parser().parse_args()
    args.reference_window = max(1, int(args.reference_window))
    trajectory = run(args)
    print(
        json.dumps(
            {
                "trajectory_status": trajectory["trajectory_status"],
                "pair_count": trajectory["pair_count"],
                "accepted_pose_count": trajectory[
                    "accepted_pose_count"
                ],
                "rejected_pose_count": trajectory[
                    "rejected_pose_count"
                ],
                "global_fusion_complete": False,
            },
            ensure_ascii=False,
        )
    )


if __name__ == "__main__":
    main()
