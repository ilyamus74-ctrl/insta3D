#!/usr/bin/env python3
"""
Align two already reconstructed dense point clouds without rerunning COLMAP.

Pipeline:
  1. Multi-resolution FPFH feature extraction.
  2. RANSAC global registration with Sim(3) estimation
     (uniform scale + rotation + translation).
  3. Multi-scale rigid GICP / point-to-plane / optional colored ICP refinement.
  4. Write the fully aligned source, the exact point-preserving merged cloud,
     and a machine-readable merge_result.json.

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


def md5_file(path: Path, chunk_size: int = 8 * 1024 * 1024) -> str:
    digest = hashlib.md5()
    with path.open("rb") as handle:
        while True:
            chunk = handle.read(chunk_size)
            if not chunk:
                break
            digest.update(chunk)
    return digest.hexdigest()


def robust_geometry_stats(
    points: np.ndarray,
    rng: np.random.Generator,
    sample_limit: int = 200_000,
) -> dict[str, Any]:
    if points.shape[0] > sample_limit:
        indexes = rng.choice(points.shape[0], size=sample_limit, replace=False)
        sample = points[indexes]
    else:
        sample = points

    q01 = np.quantile(sample, 0.01, axis=0)
    q99 = np.quantile(sample, 0.99, axis=0)
    center = np.median(sample, axis=0)
    extent = np.maximum(q99 - q01, 1e-12)
    diagonal = float(np.linalg.norm(extent))

    if not np.isfinite(diagonal) or diagonal <= 0:
        raise RuntimeError("Point cloud robust diagonal is invalid")

    return {
        "q01": q01.tolist(),
        "q99": q99.tolist(),
        "center": center.tolist(),
        "extent": extent.tolist(),
        "diagonal": diagonal,
    }


def copy_cloud(o3d: Any, cloud: Any) -> Any:
    try:
        return copy.deepcopy(cloud)
    except Exception:
        clone = o3d.geometry.PointCloud()
        clone.points = o3d.utility.Vector3dVector(np.asarray(cloud.points).copy())
        if cloud.has_colors():
            clone.colors = o3d.utility.Vector3dVector(np.asarray(cloud.colors).copy())
        if cloud.has_normals():
            clone.normals = o3d.utility.Vector3dVector(np.asarray(cloud.normals).copy())
        return clone


def ensure_normals(o3d: Any, cloud: Any, radius: float, max_nn: int = 60) -> None:
    if len(cloud.points) < 20:
        raise RuntimeError("Too few points to estimate normals")
    cloud.estimate_normals(
        o3d.geometry.KDTreeSearchParamHybrid(
            radius=max(float(radius), 1e-9),
            max_nn=max_nn,
        )
    )
    try:
        cloud.normalize_normals()
    except Exception:
        pass


def preprocess_for_fpfh(
    o3d: Any,
    cloud: Any,
    voxel_size: float,
    max_feature_points: int,
) -> tuple[Any, Any]:
    reg = o3d.pipelines.registration
    down = cloud.voxel_down_sample(voxel_size)

    if len(down.points) > max_feature_points:
        stride = max(1, int(math.ceil(len(down.points) / max_feature_points)))
        down = down.uniform_down_sample(stride)

    if len(down.points) < 200:
        raise RuntimeError(
            f"Too few points after downsampling: {len(down.points)} "
            f"(voxel={voxel_size:.9g})"
        )

    ensure_normals(o3d, down, radius=voxel_size * 3.0, max_nn=60)
    feature = reg.compute_fpfh_feature(
        down,
        o3d.geometry.KDTreeSearchParamHybrid(
            radius=voxel_size * 6.0,
            max_nn=120,
        ),
    )
    return down, feature


def sim3_scale(matrix: np.ndarray) -> float:
    linear = np.asarray(matrix, dtype=float)[:3, :3]
    determinant = float(np.linalg.det(linear))
    if not np.isfinite(determinant) or determinant <= 0:
        return float("nan")
    return float(np.cbrt(determinant))


def symmetric_evaluation(
    o3d: Any,
    source: Any,
    target: Any,
    source_to_target: np.ndarray,
    target_threshold: float,
    source_threshold: float,
) -> dict[str, float]:
    reg = o3d.pipelines.registration
    forward = reg.evaluate_registration(
        source,
        target,
        target_threshold,
        source_to_target,
    )

    inverse = np.linalg.inv(source_to_target)
    reverse = reg.evaluate_registration(
        target,
        source,
        source_threshold,
        inverse,
    )

    fitness_forward = float(forward.fitness)
    fitness_reverse = float(reverse.fitness)
    rmse_forward = float(forward.inlier_rmse)
    rmse_reverse = float(reverse.inlier_rmse)

    normalized_rmse = 0.5 * (
        rmse_forward / max(target_threshold, 1e-12)
        + rmse_reverse / max(source_threshold, 1e-12)
    )
    overlap_score = math.sqrt(max(0.0, fitness_forward * fitness_reverse))
    score = overlap_score + 0.05 * (
        fitness_forward + fitness_reverse
    ) - 0.05 * normalized_rmse

    return {
        "fitness_forward": fitness_forward,
        "fitness_reverse": fitness_reverse,
        "inlier_rmse_forward": rmse_forward,
        "inlier_rmse_reverse": rmse_reverse,
        "normalized_rmse": normalized_rmse,
        "overlap_score": overlap_score,
        "score": float(score),
    }


def global_sim3_registration(
    o3d: Any,
    source: Any,
    target: Any,
    source_stats: dict[str, Any],
    target_stats: dict[str, Any],
    divisors: list[float],
    max_feature_points: int,
    ransac_iterations: int,
    expected_scale: float,
    scale_bound_factor: float,
) -> tuple[np.ndarray, dict[str, Any]]:
    reg = o3d.pipelines.registration
    candidates: list[dict[str, Any]] = []

    for divisor in divisors:
        source_voxel = source_stats["diagonal"] / divisor
        target_voxel = target_stats["diagonal"] / divisor

        source_down, source_fpfh = preprocess_for_fpfh(
            o3d, source, source_voxel, max_feature_points
        )
        target_down, target_fpfh = preprocess_for_fpfh(
            o3d, target, target_voxel, max_feature_points
        )

        # The transformation estimator explicitly permits uniform scaling.
        estimator = reg.TransformationEstimationPointToPoint(True)
        max_correspondence_distance = target_voxel * 3.0

        result = reg.registration_ransac_based_on_feature_matching(
            source_down,
            target_down,
            source_fpfh,
            target_fpfh,
            False,
            max_correspondence_distance,
            estimator,
            3,
            [
                reg.CorrespondenceCheckerBasedOnDistance(
                    max_correspondence_distance
                )
            ],
            reg.RANSACConvergenceCriteria(
                max_iteration=ransac_iterations,
                confidence=0.999,
            ),
        )

        transform = np.asarray(result.transformation, dtype=float)
        scale = sim3_scale(transform)
        min_scale = expected_scale / scale_bound_factor
        max_scale = expected_scale * scale_bound_factor
        valid_scale = (
            np.isfinite(scale)
            and 0.01 <= scale <= 100.0
            and min_scale <= scale <= max_scale
        )

        if valid_scale:
            metrics = symmetric_evaluation(
                o3d,
                source_down,
                target_down,
                transform,
                target_threshold=target_voxel * 3.0,
                source_threshold=source_voxel * 3.0,
            )
        else:
            metrics = {
                "fitness_forward": 0.0,
                "fitness_reverse": 0.0,
                "inlier_rmse_forward": float("inf"),
                "inlier_rmse_reverse": float("inf"),
                "normalized_rmse": float("inf"),
                "overlap_score": 0.0,
                "score": float("-inf"),
            }

        valid_overlap = (
            metrics['fitness_forward'] >= 0.005
            and metrics['fitness_reverse'] >= 0.001
        )
        candidate = {
            "divisor": divisor,
            "source_voxel": source_voxel,
            "target_voxel": target_voxel,
            "source_down_points": len(source_down.points),
            "target_down_points": len(target_down.points),
            "ransac_reported_fitness": float(result.fitness),
            "ransac_reported_inlier_rmse": float(result.inlier_rmse),
            "scale": scale,
            "valid_scale": bool(valid_scale),
            "valid_overlap": bool(valid_overlap),
            "expected_scale_from_point_spacing": expected_scale,
            "accepted_scale_range": [min_scale, max_scale],
            "transformation": transform.tolist(),
            "metrics": metrics,
        }
        candidates.append(candidate)

        print(
            "[global] "
            f"divisor={divisor:g} "
            f"source_down={len(source_down.points)} "
            f"target_down={len(target_down.points)} "
            f"scale={scale:.9g} "
            f"fitness={metrics['fitness_forward']:.6f}/"
            f"{metrics['fitness_reverse']:.6f} "
            f"score={metrics['score']:.6f}",
            flush=True,
        )

    valid_candidates = [
        item
        for item in candidates
        if item["valid_scale"]
        and item["valid_overlap"]
        and np.isfinite(item["metrics"]["score"])
    ]
    if not valid_candidates:
        raise RuntimeError("FPFH/RANSAC did not produce a valid Sim(3) candidate")

    best = max(valid_candidates, key=lambda item: item["metrics"]["score"])
    best_transform = np.asarray(best["transformation"], dtype=float)

    return best_transform, {
        "method": "open3d_fpfh_ransac_point_to_point_with_scaling",
        "candidates": candidates,
        "best_candidate": best,
    }


def evaluate_refinement_candidate(
    o3d: Any,
    source_sim3_down: Any,
    target_down: Any,
    rigid_transform: np.ndarray,
    threshold: float,
) -> dict[str, float]:
    return symmetric_evaluation(
        o3d,
        source_sim3_down,
        target_down,
        rigid_transform,
        target_threshold=threshold,
        source_threshold=threshold,
    )


def multiscale_rigid_refinement(
    o3d: Any,
    source: Any,
    target: Any,
    sim3_transform: np.ndarray,
    base_target_voxel: float,
) -> tuple[np.ndarray, dict[str, Any]]:
    reg = o3d.pipelines.registration

    source_sim3 = copy_cloud(o3d, source)
    source_sim3.transform(sim3_transform)

    rigid = np.eye(4, dtype=float)
    levels: list[dict[str, Any]] = []

    # Coarse to fine, all in anchor/model0 units after Sim(3).
    voxel_sizes = [
        base_target_voxel * 2.0,
        base_target_voxel,
        base_target_voxel * 0.5,
    ]

    for level_index, voxel in enumerate(voxel_sizes):
        source_down = source_sim3.voxel_down_sample(voxel)
        target_down = target.voxel_down_sample(voxel)

        if len(source_down.points) < 100 or len(target_down.points) < 100:
            continue

        ensure_normals(o3d, source_down, radius=voxel * 3.0, max_nn=60)
        ensure_normals(o3d, target_down, radius=voxel * 3.0, max_nn=60)

        max_correspondence = voxel * (3.0 if level_index == 0 else 2.0)
        before = evaluate_refinement_candidate(
            o3d,
            source_down,
            target_down,
            rigid,
            max_correspondence,
        )

        methods: list[tuple[str, Any]] = []

        try:
            gicp = reg.registration_generalized_icp(
                source_down,
                target_down,
                max_correspondence,
                rigid,
                reg.TransformationEstimationForGeneralizedICP(),
                reg.ICPConvergenceCriteria(
                    relative_fitness=1e-7,
                    relative_rmse=1e-7,
                    max_iteration=80 if level_index == 0 else 60,
                ),
            )
            methods.append(("generalized_icp", gicp))
        except Exception as exc:
            methods.append(
                (
                    "generalized_icp_error",
                    {"error": str(exc)},
                )
            )

        try:
            point_to_plane = reg.registration_icp(
                source_down,
                target_down,
                max_correspondence,
                rigid,
                reg.TransformationEstimationPointToPlane(),
                reg.ICPConvergenceCriteria(
                    relative_fitness=1e-8,
                    relative_rmse=1e-8,
                    max_iteration=100,
                ),
            )
            methods.append(("point_to_plane_icp", point_to_plane))
        except Exception as exc:
            methods.append(
                (
                    "point_to_plane_icp_error",
                    {"error": str(exc)},
                )
            )

        if source_down.has_colors() and target_down.has_colors():
            try:
                colored = reg.registration_colored_icp(
                    source_down,
                    target_down,
                    max_correspondence,
                    rigid,
                    reg.TransformationEstimationForColoredICP(),
                    reg.ICPConvergenceCriteria(
                        relative_fitness=1e-8,
                        relative_rmse=1e-8,
                        max_iteration=80,
                    ),
                )
                methods.append(("colored_icp", colored))
            except Exception as exc:
                methods.append(("colored_icp_error", {"error": str(exc)}))

        evaluated: list[dict[str, Any]] = []
        for method_name, method_result in methods:
            if isinstance(method_result, dict):
                evaluated.append(
                    {
                        "method": method_name,
                        **method_result,
                    }
                )
                continue

            candidate_transform = np.asarray(
                method_result.transformation,
                dtype=float,
            )
            metrics = evaluate_refinement_candidate(
                o3d,
                source_down,
                target_down,
                candidate_transform,
                max_correspondence,
            )
            evaluated.append(
                {
                    "method": method_name,
                    "transformation": candidate_transform.tolist(),
                    "reported_fitness": float(method_result.fitness),
                    "reported_inlier_rmse": float(method_result.inlier_rmse),
                    "metrics": metrics,
                }
            )

        successful = [
            item
            for item in evaluated
            if "transformation" in item
            and np.isfinite(item["metrics"]["score"])
        ]

        accepted_method = "keep_previous"
        after = before
        if successful:
            best = max(successful, key=lambda item: item["metrics"]["score"])
            # Do not accept a refinement that clearly destroys overlap.
            if (
                best["metrics"]["score"] >= before["score"] - 0.01
                and best["metrics"]["overlap_score"]
                >= before["overlap_score"] * 0.90
            ):
                rigid = np.asarray(best["transformation"], dtype=float)
                accepted_method = best["method"]
                after = best["metrics"]

        levels.append(
            {
                "level": level_index,
                "voxel_size": voxel,
                "max_correspondence_distance": max_correspondence,
                "source_down_points": len(source_down.points),
                "target_down_points": len(target_down.points),
                "before": before,
                "candidates": evaluated,
                "accepted_method": accepted_method,
                "after": after,
                "rigid_transformation": rigid.tolist(),
            }
        )

        print(
            "[refine] "
            f"level={level_index} voxel={voxel:.9g} "
            f"method={accepted_method} "
            f"fitness={after['fitness_forward']:.6f}/"
            f"{after['fitness_reverse']:.6f} "
            f"score={after['score']:.6f}",
            flush=True,
        )

    total = rigid @ sim3_transform
    return total, {
        "method": "multiscale_rigid_gicp_point_to_plane_optional_colored_icp",
        "rigid_after_sim3": rigid.tolist(),
        "levels": levels,
    }


def make_compatible_attributes(o3d: Any, anchor: Any, source: Any) -> None:
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

    # Normals are retained only when both inputs already contain them.
    if anchor.has_normals() != source.has_normals():
        anchor.normals = o3d.utility.Vector3dVector(
            np.empty((0, 3), dtype=float)
        )
        source.normals = o3d.utility.Vector3dVector(
            np.empty((0, 3), dtype=float)
        )


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
    if not np.all(np.isfinite(points)):
        raise RuntimeError(f"{label} point cloud contains NaN or infinite coordinates")
    if len(cloud.points) < 100:
        raise RuntimeError(
            f"{label} point cloud has too few valid points: {len(cloud.points)}"
        )
    return cloud


def parse_divisors(value: str) -> list[float]:
    output = []
    for raw in value.split(","):
        number = float(raw.strip())
        if number <= 0:
            raise argparse.ArgumentTypeError("Voxel divisors must be positive")
        output.append(number)
    if not output:
        raise argparse.ArgumentTypeError("At least one voxel divisor is required")
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
        help="Comma-separated robust-diagonal divisors for global FPFH/RANSAC.",
    )
    parser.add_argument("--max-feature-points", type=int, default=80_000)
    parser.add_argument("--ransac-iterations", type=int, default=150_000)
    parser.add_argument("--scale-bound-factor", type=float, default=10.0)
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
        "alignment_method": "open3d_fpfh_ransac_sim3_then_multiscale_icp",
        "merge_type": "open3d_fpfh_ransac_sim3_icp_dense_ply",
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
            np.asarray(anchor.points), rng
        )
        source_stats = robust_geometry_stats(
            np.asarray(source.points), rng
        )

        anchor_nn = np.asarray(
            anchor.compute_nearest_neighbor_distance(), dtype=float
        )
        source_nn = np.asarray(
            source.compute_nearest_neighbor_distance(), dtype=float
        )
        anchor_nn = anchor_nn[np.isfinite(anchor_nn) & (anchor_nn > 0)]
        source_nn = source_nn[np.isfinite(source_nn) & (source_nn > 0)]
        if anchor_nn.size == 0 or source_nn.size == 0:
            raise RuntimeError("Unable to estimate point spacing for scale validation")
        anchor_median_nn = float(np.median(anchor_nn))
        source_median_nn = float(np.median(source_nn))
        expected_scale = anchor_median_nn / source_median_nn

        result["inputs"] = {
            "anchor": {
                "model_id": args.anchor_model_id,
                "db_job_id": args.anchor_db_job_id,
                "remote_job_id": args.anchor_remote_job_id,
                "path": str(args.anchor),
                "points": anchor_points,
                "has_colors": bool(anchor.has_colors()),
                "has_normals": bool(anchor.has_normals()),
                "md5": md5_file(args.anchor),
                "geometry": anchor_stats,
                "median_neighbor_distance": anchor_median_nn,
            },
            "source": {
                "model_id": args.source_model_id,
                "db_job_id": args.source_db_job_id,
                "remote_job_id": args.source_remote_job_id,
                "path": str(args.source),
                "points": source_points,
                "has_colors": bool(source.has_colors()),
                "has_normals": bool(source.has_normals()),
                "md5": md5_file(args.source),
                "geometry": source_stats,
                "median_neighbor_distance": source_median_nn,
            },
        }

        print(
            f"Input points: anchor={anchor_points}, "
            f"source={source_points}, expected_total={expected_total}",
            flush=True,
        )

        sim3, global_report = global_sim3_registration(
            o3d=o3d,
            source=source,
            target=anchor,
            source_stats=source_stats,
            target_stats=anchor_stats,
            divisors=args.voxel_divisors,
            max_feature_points=args.max_feature_points,
            ransac_iterations=args.ransac_iterations,
            expected_scale=expected_scale,
            scale_bound_factor=args.scale_bound_factor,
        )
        result["global_registration"] = global_report

        base_target_voxel = float(
            global_report["best_candidate"]["target_voxel"]
        )
        total_transform, refinement_report = multiscale_rigid_refinement(
            o3d=o3d,
            source=source,
            target=anchor,
            sim3_transform=sim3,
            base_target_voxel=base_target_voxel,
        )
        result["refinement"] = refinement_report

        final_scale = sim3_scale(total_transform)
        result["transform_source_to_anchor"] = {
            "matrix_4x4": total_transform.tolist(),
            "uniform_scale": final_scale,
            "linear_determinant": float(
                np.linalg.det(total_transform[:3, :3])
            ),
            "translation": total_transform[:3, 3].tolist(),
        }

        aligned_source = copy_cloud(o3d, source)
        aligned_source.transform(total_transform)

        make_compatible_attributes(o3d, anchor, aligned_source)
        merged = anchor + aligned_source

        if len(aligned_source.points) != source_points:
            raise RuntimeError(
                "Aligned source point count changed unexpectedly: "
                f"{len(aligned_source.points)} != {source_points}"
            )
        if len(merged.points) != expected_total:
            raise RuntimeError(
                "Merged point count is not the exact input sum: "
                f"{len(merged.points)} != {expected_total}"
            )

        if not o3d.io.write_point_cloud(
            str(aligned_path),
            aligned_source,
            write_ascii=False,
            compressed=False,
            print_progress=False,
        ):
            raise RuntimeError("Open3D failed to write aligned source PLY")

        if not o3d.io.write_point_cloud(
            str(merged_path),
            merged,
            write_ascii=False,
            compressed=False,
            print_progress=False,
        ):
            raise RuntimeError("Open3D failed to write merged PLY")

        # Read back to catch truncated or malformed output immediately.
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
                "Written aligned PLY point count mismatch: "
                f"{aligned_written_points} != {source_points}"
            )
        if merged_written_points != expected_total:
            raise RuntimeError(
                "Written merged PLY point count mismatch: "
                f"{merged_written_points} != {expected_total}"
            )

        aligned_md5 = md5_file(aligned_path)
        merged_md5 = md5_file(merged_path)
        anchor_md5 = result["inputs"]["anchor"]["md5"]
        source_md5 = result["inputs"]["source"]["md5"]

        if merged_md5 in {anchor_md5, source_md5}:
            raise RuntimeError(
                "Merged MD5 equals one of the source clouds; "
                "the output is not a real combined model"
            )

        included = [
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
        ]

        result.update(
            {
                "status": "DONE",
                "included": included,
                "excluded": [],
                "included_count": 2,
                "excluded_count": 0,
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
                        "alignment_status": "aligned_open3d_sim3_icp",
                        "transform_to_anchor": {
                            "matrix_4x4": total_transform.tolist(),
                            "scale": final_scale,
                        },
                    },
                ],
                "anchor_points": anchor_points,
                "source_points": source_points,
                "sum_source_points": expected_total,
                "total_points": merged_written_points,
                "aligned_source_points": aligned_written_points,
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
