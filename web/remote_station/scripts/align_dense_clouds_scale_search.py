#!/usr/bin/env python3
"""
Align two already reconstructed dense PLY point clouds without rerunning COLMAP.

Method:
  1. Estimate plausible scale from dense point spacing and robust cloud extents.
  2. Search several explicit scale hypotheses.
  3. For each scale run rigid FPFH/RANSAC in anchor units.
  4. Refine the best transform with multi-scale GICP / point-to-plane ICP.
  5. Preserve every original point in the final merged PLY.

Designed for Open3D 0.19.x.
"""

from __future__ import annotations

import argparse
import copy
import datetime as dt
import hashlib
import json
import math
import os
import random
import sys
import time
import traceback
from pathlib import Path
from typing import Any

import numpy as np


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


def clone_cloud(cloud: Any) -> Any:
    return copy.deepcopy(cloud)


def load_cloud(o3d: Any, path: Path, label: str) -> Any:
    if not path.is_file() or path.stat().st_size <= 256:
        raise RuntimeError(f"{label} PLY is missing or empty: {path}")

    cloud = o3d.io.read_point_cloud(
        str(path),
        remove_nan_points=False,
        remove_infinite_points=False,
        print_progress=False,
    )
    points = np.asarray(cloud.points)

    if len(points) < 100:
        raise RuntimeError(f"{label} has too few points: {len(points)}")
    if not np.all(np.isfinite(points)):
        raise RuntimeError(f"{label} contains NaN or infinite coordinates")

    return cloud


def robust_geometry_stats(
    points: np.ndarray,
    rng: np.random.Generator,
    sample_limit: int = 200_000,
) -> dict[str, Any]:
    sample = points
    if len(points) > sample_limit:
        sample = points[rng.choice(len(points), sample_limit, replace=False)]

    q01 = np.quantile(sample, 0.01, axis=0)
    q99 = np.quantile(sample, 0.99, axis=0)
    extent = np.maximum(q99 - q01, 1e-12)
    diagonal = float(np.linalg.norm(extent))
    center = np.median(sample, axis=0)

    if not np.isfinite(diagonal) or diagonal <= 0:
        raise RuntimeError("Invalid robust cloud diagonal")

    return {
        "q01": q01.tolist(),
        "q99": q99.tolist(),
        "extent": extent.tolist(),
        "center": center.tolist(),
        "diagonal": diagonal,
    }


def median_neighbor_distance(cloud: Any) -> float:
    distances = np.asarray(
        cloud.compute_nearest_neighbor_distance(),
        dtype=float,
    )
    distances = distances[np.isfinite(distances) & (distances > 0)]
    if distances.size == 0:
        raise RuntimeError("Cannot estimate median nearest-neighbor distance")
    return float(np.median(distances))


def scale_matrix(scale: float) -> np.ndarray:
    matrix = np.eye(4, dtype=float)
    matrix[:3, :3] *= scale
    return matrix


def transform_scale(transform: np.ndarray) -> float:
    determinant = float(np.linalg.det(transform[:3, :3]))
    if not np.isfinite(determinant) or determinant <= 0:
        return float("nan")
    return float(np.cbrt(determinant))


def estimate_normals(o3d: Any, cloud: Any, voxel: float) -> None:
    cloud.estimate_normals(
        o3d.geometry.KDTreeSearchParamHybrid(
            radius=max(voxel * 3.0, 1e-9),
            max_nn=60,
        )
    )
    try:
        cloud.normalize_normals()
    except Exception:
        pass


def preprocess(
    o3d: Any,
    cloud: Any,
    voxel: float,
    max_points: int,
) -> tuple[Any, Any]:
    registration = o3d.pipelines.registration

    down = cloud.voxel_down_sample(voxel)
    if len(down.points) > max_points:
        stride = max(1, math.ceil(len(down.points) / max_points))
        down = down.uniform_down_sample(stride)

    if len(down.points) < 200:
        raise RuntimeError(
            f"Too few points after downsampling: {len(down.points)}, "
            f"voxel={voxel:.9g}"
        )

    estimate_normals(o3d, down, voxel)

    fpfh = registration.compute_fpfh_feature(
        down,
        o3d.geometry.KDTreeSearchParamHybrid(
            radius=voxel * 6.0,
            max_nn=120,
        ),
    )
    return down, fpfh


def evaluate_bidirectional(
    o3d: Any,
    source: Any,
    target: Any,
    transform: np.ndarray,
    threshold: float,
) -> dict[str, float]:
    registration = o3d.pipelines.registration

    forward = registration.evaluate_registration(
        source,
        target,
        threshold,
        transform,
    )
    reverse = registration.evaluate_registration(
        target,
        source,
        threshold,
        np.linalg.inv(transform),
    )

    forward_fitness = float(forward.fitness)
    reverse_fitness = float(reverse.fitness)
    forward_rmse = float(forward.inlier_rmse)
    reverse_rmse = float(reverse.inlier_rmse)

    if (
        not np.isfinite(forward_rmse)
        or not np.isfinite(reverse_rmse)
        or (forward_fitness <= 0 and reverse_fitness <= 0)
    ):
        normalized_rmse = float("inf")
        score = float("-inf")
    else:
        normalized_rmse = (
            0.5 * (forward_rmse + reverse_rmse)
            / max(threshold, 1e-12)
        )
        score = (
            math.sqrt(max(0.0, forward_fitness * reverse_fitness))
            + 0.12 * forward_fitness
            + 0.05 * reverse_fitness
            - 0.04 * normalized_rmse
        )

    return {
        "fitness_forward": forward_fitness,
        "fitness_reverse": reverse_fitness,
        "inlier_rmse_forward": forward_rmse,
        "inlier_rmse_reverse": reverse_rmse,
        "normalized_rmse": normalized_rmse,
        "score": float(score),
    }


def create_scale_hypotheses(
    spacing_scale: float,
    diagonal_scale: float,
    bound_factor: float,
) -> list[float]:
    bound_factor = max(1.05, float(bound_factor))

    raw: list[float] = [
        spacing_scale * float(value)
        for value in np.logspace(
            -math.log10(bound_factor),
            math.log10(bound_factor),
            9,
        )
    ]
    raw.extend(
        [
            spacing_scale,
            diagonal_scale,
            math.sqrt(spacing_scale * diagonal_scale),
            1.0,
        ]
    )

    output: list[float] = []
    for scale in sorted(raw):
        if not np.isfinite(scale) or scale <= 1e-6 or scale >= 1e6:
            continue
        if any(abs(math.log(scale / existing)) < 0.035 for existing in output):
            continue
        output.append(float(scale))

    if not output:
        raise RuntimeError("No valid scale hypotheses were generated")

    return output


def run_rigid_ransac(
    o3d: Any,
    scaled_source: Any,
    target: Any,
    voxel: float,
    max_feature_points: int,
    iterations: int,
    seed: int,
    mutual_filter: bool,
) -> dict[str, Any]:
    registration = o3d.pipelines.registration

    source_down, source_fpfh = preprocess(
        o3d,
        scaled_source,
        voxel,
        max_feature_points,
    )
    target_down, target_fpfh = preprocess(
        o3d,
        target,
        voxel,
        max_feature_points,
    )

    try:
        o3d.utility.random.seed(seed)
    except Exception:
        pass

    threshold = voxel * 2.5

    result = registration.registration_ransac_based_on_feature_matching(
        source_down,
        target_down,
        source_fpfh,
        target_fpfh,
        mutual_filter,
        threshold,
        registration.TransformationEstimationPointToPoint(False),
        4,
        [
            registration.CorrespondenceCheckerBasedOnEdgeLength(0.85),
            registration.CorrespondenceCheckerBasedOnDistance(threshold),
        ],
        registration.RANSACConvergenceCriteria(
            max_iteration=max(1000, int(iterations)),
            confidence=0.999,
        ),
    )

    rigid_transform = np.asarray(result.transformation, dtype=float)

    return {
        "voxel_size": voxel,
        "threshold": threshold,
        "source_down_points": len(source_down.points),
        "target_down_points": len(target_down.points),
        "mutual_filter": bool(mutual_filter),
        "reported_fitness": float(result.fitness),
        "reported_inlier_rmse": float(result.inlier_rmse),
        "rigid_transform": rigid_transform.tolist(),
        "metrics": evaluate_bidirectional(
            o3d,
            source_down,
            target_down,
            rigid_transform,
            threshold,
        ),
    }


def candidate_rank(candidate: dict[str, Any]) -> tuple[float, float, float]:
    metrics = candidate["registration"]["metrics"]
    score = metrics.get("score", float("-inf"))
    if not np.isfinite(score):
        score = -1e30
    return (
        float(score),
        float(metrics.get("fitness_forward", 0.0)),
        float(candidate["registration"].get("reported_fitness", 0.0)),
    )


def global_scale_search_registration(
    o3d: Any,
    source: Any,
    target: Any,
    target_diagonal: float,
    spacing_scale: float,
    diagonal_scale: float,
    scale_bound_factor: float,
    divisors: list[float],
    max_feature_points: int,
    ransac_iterations: int,
    seed: int,
) -> tuple[np.ndarray, dict[str, Any]]:
    scales = create_scale_hypotheses(
        spacing_scale,
        diagonal_scale,
        scale_bound_factor,
    )

    print(
        "[scale] "
        f"spacing_hint={spacing_scale:.9g} "
        f"diagonal_hint={diagonal_scale:.9g} "
        f"hypotheses={','.join(f'{item:.9g}' for item in scales)}",
        flush=True,
    )

    coarse_voxel = target_diagonal / min(divisors)
    coarse_candidates: list[dict[str, Any]] = []

    for index, scale in enumerate(scales):
        scaled_source = clone_cloud(source)
        scaled_source.transform(scale_matrix(scale))

        try:
            registration = run_rigid_ransac(
                o3d=o3d,
                scaled_source=scaled_source,
                target=target,
                voxel=coarse_voxel,
                max_feature_points=min(max_feature_points, 35_000),
                iterations=max(
                    15_000,
                    min(40_000, ransac_iterations // 4),
                ),
                seed=seed + index * 101,
                mutual_filter=False,
            )
            candidate = {
                "scale": scale,
                "registration": registration,
            }
        except Exception as exc:
            candidate = {
                "scale": scale,
                "error": str(exc),
                "registration": {
                    "reported_fitness": 0.0,
                    "metrics": {
                        "fitness_forward": 0.0,
                        "fitness_reverse": 0.0,
                        "score": float("-inf"),
                    },
                },
            }

        coarse_candidates.append(candidate)
        metrics = candidate["registration"]["metrics"]

        print(
            "[coarse] "
            f"scale={scale:.9g} "
            f"fitness={metrics['fitness_forward']:.6f}/"
            f"{metrics['fitness_reverse']:.6f} "
            f"score={metrics['score']:.6f}",
            flush=True,
        )

    selected_scales = [
        candidate["scale"]
        for candidate in sorted(
            coarse_candidates,
            key=candidate_rank,
            reverse=True,
        )[:4]
    ]

    for mandatory_scale in (spacing_scale, diagonal_scale, 1.0):
        if not any(
            abs(math.log(mandatory_scale / selected)) < 0.02
            for selected in selected_scales
        ):
            selected_scales.append(mandatory_scale)

    selected_scales = list(dict.fromkeys(selected_scales))

    fine_candidates: list[dict[str, Any]] = []
    attempt = 0

    for scale in selected_scales:
        scaled_source = clone_cloud(source)
        scaled_source.transform(scale_matrix(scale))

        for divisor in divisors:
            voxel = target_diagonal / divisor

            for mutual_filter in (False, True):
                attempt += 1

                try:
                    registration = run_rigid_ransac(
                        o3d=o3d,
                        scaled_source=scaled_source,
                        target=target,
                        voxel=voxel,
                        max_feature_points=max_feature_points,
                        iterations=ransac_iterations,
                        seed=seed + 10_000 + attempt * 103,
                        mutual_filter=mutual_filter,
                    )
                    candidate = {
                        "scale": scale,
                        "divisor": divisor,
                        "registration": registration,
                    }
                except Exception as exc:
                    candidate = {
                        "scale": scale,
                        "divisor": divisor,
                        "error": str(exc),
                        "registration": {
                            "reported_fitness": 0.0,
                            "metrics": {
                                "fitness_forward": 0.0,
                                "fitness_reverse": 0.0,
                                "score": float("-inf"),
                            },
                        },
                    }

                fine_candidates.append(candidate)
                metrics = candidate["registration"]["metrics"]

                print(
                    "[global] "
                    f"scale={scale:.9g} "
                    f"divisor={divisor:g} "
                    f"mutual={int(mutual_filter)} "
                    f"fitness={metrics['fitness_forward']:.6f}/"
                    f"{metrics['fitness_reverse']:.6f} "
                    f"score={metrics['score']:.6f}",
                    flush=True,
                )

                if (
                    not mutual_filter
                    and metrics["fitness_forward"] >= 0.08
                    and metrics["fitness_reverse"] >= 0.01
                ):
                    break

    valid_candidates = [
        candidate
        for candidate in fine_candidates
        if np.isfinite(candidate["registration"]["metrics"]["score"])
        and candidate["registration"]["metrics"]["fitness_forward"] >= 0.003
        and candidate["registration"]["metrics"]["fitness_reverse"] >= 0.0005
    ]

    if not valid_candidates:
        raise RuntimeError(
            "Scale-search FPFH/RANSAC found no candidate "
            "with bidirectional overlap"
        )

    best = max(valid_candidates, key=candidate_rank)
    rigid = np.asarray(
        best["registration"]["rigid_transform"],
        dtype=float,
    )
    combined_transform = rigid @ scale_matrix(float(best["scale"]))

    return combined_transform, {
        "method": "explicit_scale_search_then_rigid_fpfh_ransac",
        "spacing_scale_hint": spacing_scale,
        "diagonal_scale_hint": diagonal_scale,
        "scale_hypotheses": scales,
        "selected_scales": selected_scales,
        "coarse_candidates": coarse_candidates,
        "fine_candidates": fine_candidates,
        "best_candidate": best,
    }


def refine_transform(
    o3d: Any,
    source: Any,
    target: Any,
    initial_transform: np.ndarray,
    base_voxel: float,
) -> tuple[np.ndarray, dict[str, Any]]:
    registration = o3d.pipelines.registration

    source_initially_aligned = clone_cloud(source)
    source_initially_aligned.transform(initial_transform)

    correction = np.eye(4, dtype=float)
    levels: list[dict[str, Any]] = []

    voxel_sizes = [
        base_voxel * 2.0,
        base_voxel,
        base_voxel * 0.5,
    ]

    for level_index, voxel in enumerate(voxel_sizes):
        source_down = source_initially_aligned.voxel_down_sample(voxel)
        target_down = target.voxel_down_sample(voxel)

        if len(source_down.points) < 100 or len(target_down.points) < 100:
            continue

        estimate_normals(o3d, source_down, voxel)
        estimate_normals(o3d, target_down, voxel)

        threshold = voxel * (3.0 if level_index == 0 else 2.0)

        before = evaluate_bidirectional(
            o3d,
            source_down,
            target_down,
            correction,
            threshold,
        )

        candidates: list[dict[str, Any]] = []

        try:
            result = registration.registration_generalized_icp(
                source_down,
                target_down,
                threshold,
                correction,
                registration.TransformationEstimationForGeneralizedICP(),
                registration.ICPConvergenceCriteria(
                    relative_fitness=1e-7,
                    relative_rmse=1e-7,
                    max_iteration=80 if level_index == 0 else 60,
                ),
            )
            transform = np.asarray(result.transformation, dtype=float)
            candidates.append(
                {
                    "method": "generalized_icp",
                    "transform": transform.tolist(),
                    "reported_fitness": float(result.fitness),
                    "reported_inlier_rmse": float(result.inlier_rmse),
                    "metrics": evaluate_bidirectional(
                        o3d,
                        source_down,
                        target_down,
                        transform,
                        threshold,
                    ),
                }
            )
        except Exception as exc:
            candidates.append(
                {
                    "method": "generalized_icp",
                    "error": str(exc),
                }
            )

        try:
            result = registration.registration_icp(
                source_down,
                target_down,
                threshold,
                correction,
                registration.TransformationEstimationPointToPlane(),
                registration.ICPConvergenceCriteria(
                    relative_fitness=1e-8,
                    relative_rmse=1e-8,
                    max_iteration=100,
                ),
            )
            transform = np.asarray(result.transformation, dtype=float)
            candidates.append(
                {
                    "method": "point_to_plane_icp",
                    "transform": transform.tolist(),
                    "reported_fitness": float(result.fitness),
                    "reported_inlier_rmse": float(result.inlier_rmse),
                    "metrics": evaluate_bidirectional(
                        o3d,
                        source_down,
                        target_down,
                        transform,
                        threshold,
                    ),
                }
            )
        except Exception as exc:
            candidates.append(
                {
                    "method": "point_to_plane_icp",
                    "error": str(exc),
                }
            )

        if source_down.has_colors() and target_down.has_colors():
            try:
                result = registration.registration_colored_icp(
                    source_down,
                    target_down,
                    threshold,
                    correction,
                    registration.TransformationEstimationForColoredICP(),
                    registration.ICPConvergenceCriteria(
                        relative_fitness=1e-8,
                        relative_rmse=1e-8,
                        max_iteration=80,
                    ),
                )
                transform = np.asarray(result.transformation, dtype=float)
                candidates.append(
                    {
                        "method": "colored_icp",
                        "transform": transform.tolist(),
                        "reported_fitness": float(result.fitness),
                        "reported_inlier_rmse": float(result.inlier_rmse),
                        "metrics": evaluate_bidirectional(
                            o3d,
                            source_down,
                            target_down,
                            transform,
                            threshold,
                        ),
                    }
                )
            except Exception as exc:
                candidates.append(
                    {
                        "method": "colored_icp",
                        "error": str(exc),
                    }
                )

        successful = [
            candidate
            for candidate in candidates
            if "transform" in candidate
            and np.isfinite(candidate["metrics"]["score"])
        ]

        accepted_method = "keep_previous"
        after = before

        if successful:
            best = max(
                successful,
                key=lambda item: item["metrics"]["score"],
            )

            if (
                best["metrics"]["score"] >= before["score"] - 0.01
                and best["metrics"]["fitness_forward"]
                >= before["fitness_forward"] * 0.90
            ):
                correction = np.asarray(best["transform"], dtype=float)
                accepted_method = best["method"]
                after = best["metrics"]

        levels.append(
            {
                "level": level_index,
                "voxel_size": voxel,
                "threshold": threshold,
                "source_down_points": len(source_down.points),
                "target_down_points": len(target_down.points),
                "before": before,
                "candidates": candidates,
                "accepted_method": accepted_method,
                "after": after,
                "correction_transform": correction.tolist(),
            }
        )

        print(
            "[refine] "
            f"level={level_index} "
            f"voxel={voxel:.9g} "
            f"method={accepted_method} "
            f"fitness={after['fitness_forward']:.6f}/"
            f"{after['fitness_reverse']:.6f} "
            f"score={after['score']:.6f}",
            flush=True,
        )

    final_transform = correction @ initial_transform

    return final_transform, {
        "method": "multiscale_gicp_point_to_plane_optional_colored_icp",
        "correction_after_global": correction.tolist(),
        "levels": levels,
    }


def make_cloud_attributes_compatible(
    o3d: Any,
    anchor: Any,
    source: Any,
) -> None:
    anchor_count = len(anchor.points)
    source_count = len(source.points)

    if anchor.has_colors() or source.has_colors():
        if not anchor.has_colors():
            anchor.colors = o3d.utility.Vector3dVector(
                np.full((anchor_count, 3), 0.5, dtype=float)
            )
        if not source.has_colors():
            source.colors = o3d.utility.Vector3dVector(
                np.full((source_count, 3), 0.5, dtype=float)
            )

    if anchor.has_normals() != source.has_normals():
        anchor.normals = o3d.utility.Vector3dVector(
            np.empty((0, 3), dtype=float)
        )
        source.normals = o3d.utility.Vector3dVector(
            np.empty((0, 3), dtype=float)
        )


def parse_divisors(value: str) -> list[float]:
    output: list[float] = []

    for raw in value.split(","):
        number = float(raw.strip())
        if number <= 0:
            raise argparse.ArgumentTypeError(
                "Voxel divisors must be positive"
            )
        output.append(number)

    if not output:
        raise argparse.ArgumentTypeError(
            "At least one voxel divisor is required"
        )

    return output


def main() -> int:
    parser = argparse.ArgumentParser()

    parser.add_argument("--anchor", required=True, type=Path)
    parser.add_argument("--source", required=True, type=Path)
    parser.add_argument("--output-dir", required=True, type=Path)

    parser.add_argument("--anchor-model-id", type=int, default=0)
    parser.add_argument("--source-model-id", type=int, default=1)
    parser.add_argument("--anchor-db-job-id", type=int, default=654)
    parser.add_argument("--source-db-job-id", type=int, default=655)
    parser.add_argument("--anchor-remote-job-id", type=int, default=860990938)
    parser.add_argument("--source-remote-job-id", type=int, default=917339860)

    parser.add_argument(
        "--voxel-divisors",
        type=parse_divisors,
        default=parse_divisors("100,150,220"),
    )
    parser.add_argument(
        "--max-feature-points",
        type=int,
        default=80_000,
    )
    parser.add_argument(
        "--ransac-iterations",
        type=int,
        default=150_000,
    )
    parser.add_argument(
        "--scale-bound-factor",
        type=float,
        default=10.0,
    )
    parser.add_argument("--seed", type=int, default=42)

    args = parser.parse_args()

    started = time.time()
    args.output_dir.mkdir(parents=True, exist_ok=True)

    aligned_path = args.output_dir / "model1_aligned_to_model0.ply"
    merged_path = args.output_dir / "icp_merged_dense_cloud.ply"
    result_path = args.output_dir / "merge_result.json"

    result: dict[str, Any] = {
        "status": "ERROR",
        "started_at": utc_now(),
        "alignment_method": (
            "explicit_scale_search_fpfh_ransac_then_multiscale_icp"
        ),
        "merge_type": "open3d_scale_search_fpfh_ransac_icp_dense_ply",
        "anchor_model_id": args.anchor_model_id,
        "source_model_id": args.source_model_id,
        "anchor_ply": str(args.anchor),
        "source_ply": str(args.source),
        "aligned_source_ply": str(aligned_path),
        "output_ply": str(merged_path),
        "result_json": str(result_path),
    }

    try:
        import open3d as o3d

        random.seed(args.seed)
        np.random.seed(args.seed)
        try:
            o3d.utility.random.seed(args.seed)
        except Exception:
            pass

        rng = np.random.default_rng(args.seed)

        result["open3d_version"] = o3d.__version__
        result["parameters"] = {
            "voxel_divisors": args.voxel_divisors,
            "max_feature_points": args.max_feature_points,
            "ransac_iterations": args.ransac_iterations,
            "scale_bound_factor": args.scale_bound_factor,
            "seed": args.seed,
        }

        print(f"Open3D {o3d.__version__}", flush=True)
        print(f"Loading anchor: {args.anchor}", flush=True)
        anchor = load_cloud(o3d, args.anchor, "Anchor")

        print(f"Loading source: {args.source}", flush=True)
        source = load_cloud(o3d, args.source, "Source")

        anchor_points = len(anchor.points)
        source_points = len(source.points)
        expected_total = anchor_points + source_points

        anchor_stats = robust_geometry_stats(
            np.asarray(anchor.points),
            rng,
        )
        source_stats = robust_geometry_stats(
            np.asarray(source.points),
            rng,
        )

        anchor_median_nn = median_neighbor_distance(anchor)
        source_median_nn = median_neighbor_distance(source)

        spacing_scale = anchor_median_nn / source_median_nn
        diagonal_scale = (
            anchor_stats["diagonal"] / source_stats["diagonal"]
        )

        print(
            "[geometry] "
            f"anchor_diagonal={anchor_stats['diagonal']:.9g} "
            f"source_diagonal={source_stats['diagonal']:.9g} "
            f"anchor_median_nn={anchor_median_nn:.9g} "
            f"source_median_nn={source_median_nn:.9g}",
            flush=True,
        )
        print(
            f"Input points: anchor={anchor_points}, "
            f"source={source_points}, expected_total={expected_total}",
            flush=True,
        )

        result["inputs"] = {
            "anchor": {
                "model_id": args.anchor_model_id,
                "db_job_id": args.anchor_db_job_id,
                "remote_job_id": args.anchor_remote_job_id,
                "path": str(args.anchor),
                "points": anchor_points,
                "md5": md5_file(args.anchor),
                "has_colors": bool(anchor.has_colors()),
                "has_normals": bool(anchor.has_normals()),
                "geometry": anchor_stats,
                "median_neighbor_distance": anchor_median_nn,
            },
            "source": {
                "model_id": args.source_model_id,
                "db_job_id": args.source_db_job_id,
                "remote_job_id": args.source_remote_job_id,
                "path": str(args.source),
                "points": source_points,
                "md5": md5_file(args.source),
                "has_colors": bool(source.has_colors()),
                "has_normals": bool(source.has_normals()),
                "geometry": source_stats,
                "median_neighbor_distance": source_median_nn,
            },
        }

        global_transform, global_report = (
            global_scale_search_registration(
                o3d=o3d,
                source=source,
                target=anchor,
                target_diagonal=anchor_stats["diagonal"],
                spacing_scale=spacing_scale,
                diagonal_scale=diagonal_scale,
                scale_bound_factor=args.scale_bound_factor,
                divisors=args.voxel_divisors,
                max_feature_points=args.max_feature_points,
                ransac_iterations=args.ransac_iterations,
                seed=args.seed,
            )
        )
        result["global_registration"] = global_report

        best_voxel = float(
            global_report["best_candidate"]["registration"]["voxel_size"]
        )

        final_transform, refinement_report = refine_transform(
            o3d=o3d,
            source=source,
            target=anchor,
            initial_transform=global_transform,
            base_voxel=best_voxel,
        )
        result["refinement"] = refinement_report

        final_scale = transform_scale(final_transform)

        result["transform_source_to_anchor"] = {
            "matrix_4x4": final_transform.tolist(),
            "uniform_scale": final_scale,
            "linear_determinant": float(
                np.linalg.det(final_transform[:3, :3])
            ),
            "translation": final_transform[:3, 3].tolist(),
        }

        aligned_source = clone_cloud(source)
        aligned_source.transform(final_transform)

        make_cloud_attributes_compatible(
            o3d,
            anchor,
            aligned_source,
        )
        merged = anchor + aligned_source

        if len(aligned_source.points) != source_points:
            raise RuntimeError(
                "Aligned source point count changed unexpectedly: "
                f"{len(aligned_source.points)} != {source_points}"
            )
        if len(merged.points) != expected_total:
            raise RuntimeError(
                "Merged point count is not the exact source sum: "
                f"{len(merged.points)} != {expected_total}"
            )

        if not o3d.io.write_point_cloud(
            str(aligned_path),
            aligned_source,
            write_ascii=False,
            compressed=False,
            print_progress=False,
        ):
            raise RuntimeError("Failed to write aligned source PLY")

        if not o3d.io.write_point_cloud(
            str(merged_path),
            merged,
            write_ascii=False,
            compressed=False,
            print_progress=False,
        ):
            raise RuntimeError("Failed to write merged PLY")

        aligned_check = o3d.io.read_point_cloud(
            str(aligned_path),
            remove_nan_points=False,
            remove_infinite_points=False,
            print_progress=False,
        )
        merged_check = o3d.io.read_point_cloud(
            str(merged_path),
            remove_nan_points=False,
            remove_infinite_points=False,
            print_progress=False,
        )

        aligned_written_points = len(aligned_check.points)
        merged_written_points = len(merged_check.points)

        if aligned_written_points != source_points:
            raise RuntimeError(
                "Written aligned point count mismatch: "
                f"{aligned_written_points} != {source_points}"
            )
        if merged_written_points != expected_total:
            raise RuntimeError(
                "Written merged point count mismatch: "
                f"{merged_written_points} != {expected_total}"
            )

        aligned_md5 = md5_file(aligned_path)
        merged_md5 = md5_file(merged_path)
        anchor_md5 = result["inputs"]["anchor"]["md5"]
        source_md5 = result["inputs"]["source"]["md5"]

        if merged_md5 in {anchor_md5, source_md5}:
            raise RuntimeError(
                "Merged PLY is identical to one of the source models"
            )

        result.update(
            {
                "status": "DONE",
                "included": [
                    {
                        "job": args.anchor_db_job_id,
                        "remote_job_id": args.anchor_remote_job_id,
                        "model": args.anchor_model_id,
                        "points": anchor_points,
                        "status": "anchor",
                        "path": str(args.anchor),
                    },
                    {
                        "job": args.source_db_job_id,
                        "remote_job_id": args.source_remote_job_id,
                        "model": args.source_model_id,
                        "points": source_points,
                        "status": "aligned",
                        "path": str(args.source),
                    },
                ],
                "excluded": [],
                "included_count": 2,
                "excluded_count": 0,
                "anchor_points": anchor_points,
                "source_points": source_points,
                "sum_source_points": expected_total,
                "aligned_source_points": aligned_written_points,
                "total_points": merged_written_points,
                "source_jobs": [
                    {
                        "db_job_id": args.anchor_db_job_id,
                        "remote_job_id": args.anchor_remote_job_id,
                        "model_id": args.anchor_model_id,
                        "points": anchor_points,
                        "path": str(args.anchor),
                        "alignment_status": "anchor",
                        "transform_to_anchor": {
                            "matrix_4x4": np.eye(4).tolist(),
                            "scale": 1.0,
                        },
                    },
                    {
                        "db_job_id": args.source_db_job_id,
                        "remote_job_id": args.source_remote_job_id,
                        "model_id": args.source_model_id,
                        "points": source_points,
                        "path": str(args.source),
                        "alignment_status": (
                            "aligned_open3d_scale_search_fpfh_icp"
                        ),
                        "transform_to_anchor": {
                            "matrix_4x4": final_transform.tolist(),
                            "scale": final_scale,
                        },
                    },
                ],
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
                },
                "validation": {
                    "point_count_is_exact_sum": (
                        merged_written_points == expected_total
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
            f"aligned_points={aligned_written_points} "
            f"merged_points={merged_written_points} "
            f"merged_md5={merged_md5} "
            f"scale={final_scale:.9g}",
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
