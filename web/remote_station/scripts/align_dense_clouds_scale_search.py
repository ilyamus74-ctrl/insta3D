#!/usr/bin/env python3
"""Scale-search FPFH/RANSAC + ICP for two ready-made dense PLY clouds."""

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

import numpy as np


def now() -> str:
    return dt.datetime.now(dt.timezone.utc).isoformat()


def save_json(path: Path, data: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix(path.suffix + ".tmp")
    tmp.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")
    os.replace(tmp, path)


def md5(path: Path) -> str:
    h = hashlib.md5()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(8 * 1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def clone(o3d, cloud):
    return copy.deepcopy(cloud)


def load_cloud(o3d, path: Path, label: str):
    if not path.is_file() or path.stat().st_size <= 256:
        raise RuntimeError(f"{label} PLY missing or empty: {path}")
    cloud = o3d.io.read_point_cloud(
        str(path),
        remove_nan_points=False,
        remove_infinite_points=False,
        print_progress=False,
    )
    pts = np.asarray(cloud.points)
    if len(pts) < 100:
        raise RuntimeError(f"{label} has too few points: {len(pts)}")
    if not np.all(np.isfinite(pts)):
        raise RuntimeError(f"{label} contains NaN/Inf coordinates")
    return cloud


def geometry_stats(points: np.ndarray, rng: np.random.Generator) -> dict:
    if len(points) > 200_000:
        points = points[rng.choice(len(points), 200_000, replace=False)]
    q01 = np.quantile(points, 0.01, axis=0)
    q99 = np.quantile(points, 0.99, axis=0)
    diagonal = float(np.linalg.norm(q99 - q01))
    if not np.isfinite(diagonal) or diagonal <= 0:
        raise RuntimeError("Invalid robust cloud diagonal")
    return {
        "q01": q01.tolist(),
        "q99": q99.tolist(),
        "diagonal": diagonal,
    }


def median_nn(cloud) -> float:
    d = np.asarray(cloud.compute_nearest_neighbor_distance(), dtype=float)
    d = d[np.isfinite(d) & (d > 0)]
    if not len(d):
        raise RuntimeError("Cannot estimate point spacing")
    return float(np.median(d))


def scale_matrix(scale: float) -> np.ndarray:
    m = np.eye(4)
    m[:3, :3] *= scale
    return m


def sim3_scale(transform: np.ndarray) -> float:
    det = float(np.linalg.det(transform[:3, :3]))
    return float(np.cbrt(det)) if np.isfinite(det) and det > 0 else float("nan")


def ensure_normals(o3d, cloud, voxel: float) -> None:
    cloud.estimate_normals(
        o3d.geometry.KDTreeSearchParamHybrid(radius=voxel * 3.0, max_nn=60)
    )
    try:
        cloud.normalize_normals()
    except Exception:
        pass


def preprocess(o3d, cloud, voxel: float, max_points: int):
    reg = o3d.pipelines.registration
    down = cloud.voxel_down_sample(voxel)
    if len(down.points) > max_points:
        stride = max(1, math.ceil(len(down.points) / max_points))
        down = down.uniform_down_sample(stride)
    if len(down.points) < 200:
        raise RuntimeError(f"Too few downsampled points: {len(down.points)}")
    ensure_normals(o3d, down, voxel)
    fpfh = reg.compute_fpfh_feature(
        down,
        o3d.geometry.KDTreeSearchParamHybrid(radius=voxel * 6.0, max_nn=120),
    )
    return down, fpfh


def evaluate(o3d, source, target, transform: np.ndarray, threshold: float) -> dict:
    reg = o3d.pipelines.registration
    fwd = reg.evaluate_registration(source, target, threshold, transform)
    rev = reg.evaluate_registration(target, source, threshold, np.linalg.inv(transform))
    ff, rf = float(fwd.fitness), float(rev.fitness)
    fr, rr = float(fwd.inlier_rmse), float(rev.inlier_rmse)
    if not np.isfinite(fr) or not np.isfinite(rr) or (ff <= 0 and rf <= 0):
        score = float("-inf")
        nrmse = float("inf")
    else:
        nrmse = 0.5 * (fr + rr) / max(threshold, 1e-12)
        score = math.sqrt(max(0.0, ff * rf)) + 0.12 * ff + 0.05 * rf - 0.04 * nrmse
    return {
        "fitness_forward": ff,
        "fitness_reverse": rf,
        "inlier_rmse_forward": fr,
        "inlier_rmse_reverse": rr,
        "normalized_rmse": nrmse,
        "score": float(score),
    }


def scale_hypotheses(expected: float, diagonal_hint: float, factor: float) -> list[float]:
    factor = max(1.05, factor)
    raw = [
        expected * float(v)
        for v in np.logspace(-math.log10(factor), math.log10(factor), 9)
    ]
    raw += [expected, diagonal_hint, math.sqrt(expected * diagonal_hint), 1.0]
    out: list[float] = []
    for value in sorted(raw):
        if not np.isfinite(value) or value <= 1e-6 or value >= 1e6:
            continue
        if any(abs(math.log(value / old)) < 0.035 for old in out):
            continue
        out.append(float(value))
    return out


def run_ransac(
    o3d,
    source_scaled,
    target,
    voxel: float,
    max_points: int,
    iterations: int,
    seed: int,
    mutual: bool,
) -> dict:
    reg = o3d.pipelines.registration
    src, src_f = preprocess(o3d, source_scaled, voxel, max_points)
    dst, dst_f = preprocess(o3d, target, voxel, max_points)
    try:
        o3d.utility.random.seed(seed)
    except Exception:
        pass
    threshold = voxel * 2.5
    r = reg.registration_ransac_based_on_feature_matching(
        src,
        dst,
        src_f,
        dst_f,
        mutual,
        threshold,
        reg.TransformationEstimationPointToPoint(False),
        4,
        [
            reg.CorrespondenceCheckerBasedOnEdgeLength(0.85),
            reg.CorrespondenceCheckerBasedOnDistance(threshold),
        ],
        reg.RANSACConvergenceCriteria(
            max_iteration=max(1000, iterations),
            confidence=0.999,
        ),
    )
    transform = np.asarray(r.transformation, dtype=float)
    return {
        "voxel_size": voxel,
        "threshold": threshold,
        "source_down_points": len(src.points),
        "target_down_points": len(dst.points),
        "mutual_filter": mutual,
        "reported_fitness": float(r.fitness),
        "reported_rmse": float(r.inlier_rmse),
        "rigid_transform": transform.tolist(),
        "metrics": evaluate(o3d, src, dst, transform, threshold),
    }


def rank(candidate: dict) -> tuple:
    m = candidate["registration"]["metrics"]
    score = m["score"] if np.isfinite(m["score"]) else -1e30
    return score, m["fitness_forward"], candidate["registration"]["reported_fitness"]


def global_registration(
    o3d,
    source,
    target,
    target_diagonal: float,
    expected_scale: float,
    diagonal_hint: float,
    factor: float,
    divisors: list[float],
    max_points: int,
    iterations: int,
    seed: int,
) -> tuple[np.ndarray, dict]:
    scales = scale_hypotheses(expected_scale, diagonal_hint, factor)
    print(
        "[scale] expected="
        f"{expected_scale:.9g} diagonal_hint={diagonal_hint:.9g} "
        f"hypotheses={','.join(f'{s:.9g}' for s in scales)}",
        flush=True,
    )

    coarse_voxel = target_diagonal / min(divisors)
    coarse: list[dict] = []
    for i, scale in enumerate(scales):
        scaled = clone(o3d, source)
        scaled.transform(scale_matrix(scale))
        try:
            registration = run_ransac(
                o3d,
                scaled,
                target,
                coarse_voxel,
                min(max_points, 35_000),
                max(15_000, min(40_000, iterations // 4)),
                seed + i * 101,
                False,
            )
            item = {"scale": scale, "registration": registration}
        except Exception as exc:
            item = {
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
        coarse.append(item)
        m = item["registration"]["metrics"]
        print(
            f"[coarse] scale={scale:.9g} "
            f"fitness={m['fitness_forward']:.6f}/{m['fitness_reverse']:.6f} "
            f"score={m['score']:.6f}",
            flush=True,
        )

    selected = [x["scale"] for x in sorted(coarse, key=rank, reverse=True)[:4]]
    if not any(abs(math.log(expected_scale / x)) < 0.02 for x in selected):
        selected.append(expected_scale)
    selected = list(dict.fromkeys(selected))

    fine: list[dict] = []
    attempt = 0
    for scale in selected:
        scaled = clone(o3d, source)
        scaled.transform(scale_matrix(scale))
        for divisor in divisors:
            voxel = target_diagonal / divisor
            for mutual in (False, True):
                attempt += 1
                try:
                    registration = run_ransac(
                        o3d,
                        scaled,
                        target,
                        voxel,
                        max_points,
                        iterations,
                        seed + 10_000 + attempt * 103,
                        mutual,
                    )
                    item = {
                        "scale": scale,
                        "divisor": divisor,
                        "registration": registration,
                    }
                except Exception as exc:
                    item = {
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
                fine.append(item)
                m = item["registration"]["metrics"]
                print(
                    f"[global] scale={scale:.9g} divisor={divisor:g} "
                    f"mutual={int(mutual)} "
                    f"fitness={m['fitness_forward']:.6f}/{m['fitness_reverse']:.6f} "
                    f"score={m['score']:.6f}",
                    flush=True,
                )
                if (
                    not mutual
                    and m["fitness_forward"] >= 0.08
                    and m["fitness_reverse"] >= 0.01
                ):
                    break

    valid = [
        x
        for x in fine
        if np.isfinite(x["registration"]["metrics"]["score"])
        and x["registration"]["metrics"]["fitness_forward"] >= 0.003
        and x["registration"]["metrics"]["fitness_reverse"] >= 0.0005
    ]
    if not valid:
        raise RuntimeError(
            "Scale-search FPFH/RANSAC found no candidate with bidirectional overlap"
        )

    best = max(valid, key=rank)
    rigid = np.asarray(best["registration"]["rigid_transform"], dtype=float)
    total = rigid @ scale_matrix(best["scale"])
    return total, {
        "method": "scale_search_then_rigid_fpfh_ransac",
        "expected_scale_from_point_spacing": expected_scale,
        "diagonal_scale_hint": diagonal_hint,
        "scale_hypotheses": scales,
        "selected_scales": selected,
        "coarse_candidates": coarse,
        "fine_candidates": fine,
        "best_candidate": best,
    }


def refine(o3d, source, target, initial: np.ndarray, base_voxel: float):
    reg = o3d.pipelines.registration
    aligned = clone(o3d, source)
    aligned.transform(initial)
    correction = np.eye(4)
    report = []

    for level, voxel in enumerate(
        [base_voxel * 2.0, base_voxel, base_voxel * 0.5]
    ):
        src = aligned.voxel_down_sample(voxel)
        dst = target.voxel_down_sample(voxel)
        if len(src.points) < 100 or len(dst.points) < 100:
            continue
        ensure_normals(o3d, src, voxel)
        ensure_normals(o3d, dst, voxel)
        threshold = voxel * (3.0 if level == 0 else 2.0)
        before = evaluate(o3d, src, dst, correction, threshold)
        candidates = []

        for name, fn in (
            (
                "generalized_icp",
                lambda: reg.registration_generalized_icp(
                    src,
                    dst,
                    threshold,
                    correction,
                    reg.TransformationEstimationForGeneralizedICP(),
                    reg.ICPConvergenceCriteria(max_iteration=80),
                ),
            ),
            (
                "point_to_plane_icp",
                lambda: reg.registration_icp(
                    src,
                    dst,
                    threshold,
                    correction,
                    reg.TransformationEstimationPointToPlane(),
                    reg.ICPConvergenceCriteria(max_iteration=100),
                ),
            ),
        ):
            try:
                r = fn()
                t = np.asarray(r.transformation, dtype=float)
                candidates.append(
                    {
                        "method": name,
                        "transform": t.tolist(),
                        "reported_fitness": float(r.fitness),
                        "reported_rmse": float(r.inlier_rmse),
                        "metrics": evaluate(o3d, src, dst, t, threshold),
                    }
                )
            except Exception as exc:
                candidates.append({"method": name, "error": str(exc)})

        if src.has_colors() and dst.has_colors():
            try:
                r = reg.registration_colored_icp(
                    src,
                    dst,
                    threshold,
                    correction,
                    reg.TransformationEstimationForColoredICP(),
                    reg.ICPConvergenceCriteria(max_iteration=80),
                )
                t = np.asarray(r.transformation, dtype=float)
                candidates.append(
                    {
                        "method": "colored_icp",
                        "transform": t.tolist(),
                        "reported_fitness": float(r.fitness),
                        "reported_rmse": float(r.inlier_rmse),
                        "metrics": evaluate(o3d, src, dst, t, threshold),
                    }
                )
            except Exception as exc:
                candidates.append({"method": "colored_icp", "error": str(exc)})

        good = [
            x
            for x in candidates
            if "transform" in x and np.isfinite(x["metrics"]["score"])
        ]
        accepted = "keep_previous"
        after = before
        if good:
            best = max(good, key=lambda x: x["metrics"]["score"])
            if (
                best["metrics"]["score"] >= before["score"] - 0.005
                and best["metrics"]["fitness_forward"]
                >= before["fitness_forward"] * 0.90
            ):
                correction = np.asarray(best["transform"], dtype=float)
                accepted = best["method"]
                after = best["metrics"]

        report.append(
            {
                "level": level,
                "voxel_size": voxel,
                "threshold": threshold,
                "before": before,
                "candidates": candidates,
                "accepted_method": accepted,
                "after": after,
                "correction": correction.tolist(),
            }
        )
        print(
            f"[refine] level={level} method={accepted} "
            f"fitness={after['fitness_forward']:.6f}/"
            f"{after['fitness_reverse']:.6f} score={after['score']:.6f}",
            flush=True,
        )

    return correction @ initial, {
        "method": "multiscale_rigid_icp",
        "levels": report,
    }


def compatible(o3d, anchor, source) -> None:
    if anchor.has_colors() or source.has_colors():
        if not anchor.has_colors():
            anchor.colors = o3d.utility.Vector3dVector(
                np.full((len(anchor.points), 3), 0.5)
            )
        if not source.has_colors():
            source.colors = o3d.utility.Vector3dVector(
                np.full((len(source.points), 3), 0.5)
            )
    if anchor.has_normals() != source.has_normals():
        anchor.normals = o3d.utility.Vector3dVector(np.empty((0, 3)))
        source.normals = o3d.utility.Vector3dVector(np.empty((0, 3)))


def parse_divisors(value: str) -> list[float]:
    values = [float(x.strip()) for x in value.split(",") if x.strip()]
    if not values or any(x <= 0 for x in values):
        raise argparse.ArgumentTypeError("Invalid voxel divisors")
    return values


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--anchor", required=True, type=Path)
    ap.add_argument("--source", required=True, type=Path)
    ap.add_argument("--output-dir", required=True, type=Path)
    ap.add_argument("--anchor-model-id", type=int, default=0)
    ap.add_argument("--source-model-id", type=int, default=1)
    ap.add_argument("--anchor-db-job-id", type=int, default=654)
    ap.add_argument("--source-db-job-id", type=int, default=655)
    ap.add_argument("--anchor-remote-job-id", type=int, default=860990938)
    ap.add_argument("--source-remote-job-id", type=int, default=917339860)
    ap.add_argument("--voxel-divisors", type=parse_divisors, default=[100, 150, 220])
    ap.add_argument("--max-feature-points", type=int, default=80_000)
    ap.add_argument("--ransac-iterations", type=int, default=150_000)
    ap.add_argument("--scale-bound-factor", type=float, default=10.0)
    ap.add_argument("--seed", type=int, default=42)
    a = ap.parse_args()

    started = time.time()
    a.output_dir.mkdir(parents=True, exist_ok=True)
    aligned_path = a.output_dir / "model1_aligned_to_model0.ply"
    merged_path = a.output_dir / "icp_merged_dense_cloud.ply"
    result_path = a.output_dir / "merge_result.json"

    result = {
        "status": "ERROR",
        "started_at": now(),
        "alignment_method": "scale_search_fpfh_ransac_then_multiscale_icp",
        "merge_type": "open3d_scale_search_fpfh_ransac_icp_dense_ply",
        "anchor_ply": str(a.anchor),
        "source_ply": str(a.source),
        "aligned_source_ply": str(aligned_path),
        "output_ply": str(merged_path),
        "result_json": str(result_path),
    }

    try:
        import open3d as o3d

        random.seed(a.seed)
        np.random.seed(a.seed)
        try:
            o3d.utility.random.seed(a.seed)
        except Exception:
            pass
        rng = np.random.default_rng(a.seed)

        anchor = load_cloud(o3d, a.anchor, "Anchor")
        source = load_cloud(o3d, a.source, "Source")
        anchor_points, source_points = len(anchor.points), len(source.points)
        expected_total = anchor_points + source_points

        ast = geometry_stats(np.asarray(anchor.points), rng)
        sst = geometry_stats(np.asarray(source.points), rng)
        ann, snn = median_nn(anchor), median_nn(source)
        expected_scale = ann / snn
        diagonal_hint = ast["diagonal"] / sst["diagonal"]

        result["open3d_version"] = o3d.__version__
        result["parameters"] = vars(a).copy()
        for key in ("anchor", "source", "output_dir"):
            result["parameters"][key] = str(result["parameters"][key])
        result["inputs"] = {
            "anchor": {
                "model_id": a.anchor_model_id,
                "db_job_id": a.anchor_db_job_id,
                "remote_job_id": a.anchor_remote_job_id,
                "path": str(a.anchor),
                "points": anchor_points,
                "md5": md5(a.anchor),
                "geometry": ast,
                "median_neighbor_distance": ann,
            },
            "source": {
                "model_id": a.source_model_id,
                "db_job_id": a.source_db_job_id,
                "remote_job_id": a.source_remote_job_id,
                "path": str(a.source),
                "points": source_points,
                "md5": md5(a.source),
                "geometry": sst,
                "median_neighbor_distance": snn,
            },
        }
        result["scale_priors"] = {
            "expected_scale_from_point_spacing": expected_scale,
            "diagonal_scale_hint": diagonal_hint,
        }

        print(
            f"Open3D {o3d.__version__}\n"
            f"Input points: anchor={anchor_points}, source={source_points}, "
            f"expected_total={expected_total}\n"
            f"[geometry] anchor_diagonal={ast['diagonal']:.9g} "
            f"source_diagonal={sst['diagonal']:.9g} "
            f"anchor_nn={ann:.9g} source_nn={snn:.9g}",
            flush=True,
        )

        initial, global_report = global_registration(
            o3d,
            source,
            anchor,
            ast["diagonal"],
            expected_scale,
            diagonal_hint,
            a.scale_bound_factor,
            a.voxel_divisors,
            a.max_feature_points,
            a.ransac_iterations,
            a.seed,
        )
        result["global_registration"] = global_report
        base_voxel = global_report["best_candidate"]["registration"]["voxel_size"]
        total, refine_report = refine(o3d, source, anchor, initial, base_voxel)
        result["refinement"] = refine_report

        aligned = clone(o3d, source)
        aligned.transform(total)
        compatible(o3d, anchor, aligned)
        merged = anchor + aligned

        if len(aligned.points) != source_points or len(merged.points) != expected_total:
            raise RuntimeError("Point count changed before writing output")

        if not o3d.io.write_point_cloud(str(aligned_path), aligned, compressed=False):
            raise RuntimeError("Cannot write aligned source")
        if not o3d.io.write_point_cloud(str(merged_path), merged, compressed=False):
            raise RuntimeError("Cannot write merged cloud")

        aligned_check = o3d.io.read_point_cloud(str(aligned_path))
        merged_check = o3d.io.read_point_cloud(str(merged_path))
        if len(aligned_check.points) != source_points:
            raise RuntimeError("Written aligned source point count mismatch")
        if len(merged_check.points) != expected_total:
            raise RuntimeError("Written merged point count mismatch")

        merged_md5 = md5(merged_path)
        if merged_md5 in {
            result["inputs"]["anchor"]["md5"],
            result["inputs"]["source"]["md5"],
        }:
            raise RuntimeError("Merged output equals one input by MD5")

        result.update(
            {
                "status": "DONE",
                "anchor_model_id": a.anchor_model_id,
                "source_model_id": a.source_model_id,
                "included_count": 2,
                "excluded_count": 0,
                "included": [
                    {
                        "job": a.anchor_db_job_id,
                        "remote_job_id": a.anchor_remote_job_id,
                        "model": a.anchor_model_id,
                        "points": anchor_points,
                        "status": "anchor",
                    },
                    {
                        "job": a.source_db_job_id,
                        "remote_job_id": a.source_remote_job_id,
                        "model": a.source_model_id,
                        "points": source_points,
                        "status": "aligned",
                    },
                ],
                "total_points": expected_total,
                "sum_source_points": expected_total,
                "transform_source_to_anchor": {
                    "matrix_4x4": total.tolist(),
                    "uniform_scale": sim3_scale(total),
                    "translation": total[:3, 3].tolist(),
                },
                "files": {
                    "aligned_source": {
                        "path": str(aligned_path),
                        "points": source_points,
                        "size_bytes": aligned_path.stat().st_size,
                        "md5": md5(aligned_path),
                    },
                    "merged": {
                        "path": str(merged_path),
                        "points": expected_total,
                        "size_bytes": merged_path.stat().st_size,
                        "md5": merged_md5,
                    },
                },
                "validation": {
                    "point_count_is_exact_sum": True,
                    "merged_md5_differs_from_anchor": True,
                    "merged_md5_differs_from_source": True,
                    "requires_visual_review": True,
                },
                "finished_at": now(),
                "duration_sec": round(time.time() - started, 3),
            }
        )
        save_json(result_path, result)
        print(
            f"DONE aligned_points={source_points} merged_points={expected_total} "
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
                "finished_at": now(),
                "duration_sec": round(time.time() - started, 3),
            }
        )
        save_json(result_path, result)
        print(f"ERROR: {exc}", file=sys.stderr, flush=True)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
