#!/usr/bin/env python3
import argparse
import json
import math
import statistics
from collections import defaultdict
from pathlib import Path

import numpy as np

import analyze_tof_dense_error_h23 as h23
import analyze_tof_dense_zone_h24 as h24
import measure_tof_dense_depth_h22 as h22
import measure_tof_sparse_scale as h2
import measure_tof_sparse_scale_h21 as h21


def observation_key(row):
    return h24.observation_key(row)


def median(values):
    values = [float(value) for value in values if h2.finite(value)]
    return statistics.median(values) if values else None


def safe_ratio(numerator, denominator):
    if not h2.finite(numerator) or not h2.finite(denominator):
        return None
    denominator = float(denominator)
    if abs(denominator) <= 1e-12:
        return None
    value = float(numerator) / denominator
    return value if math.isfinite(value) else None


def sparse_focal(camera):
    if not isinstance(camera, dict):
        return None
    params = camera.get("params") or []
    model = camera.get("model")
    try:
        if model in {"SIMPLE_PINHOLE", "SIMPLE_RADIAL", "RADIAL"}:
            return float(params[0])
        if model in {"PINHOLE", "OPENCV", "FULL_OPENCV"}:
            return math.sqrt(float(params[0]) * float(params[1]))
    except Exception:
        return None
    return None


def projection_variant_definitions(camera2_focal_ratio, args):
    variants = [
        {
            "name": "baseline",
            "family": "baseline",
            "focal_scale": 1.0,
            "cx_shift_px": 0.0,
            "cy_shift_px": 0.0,
            "description": "frozen dense-workspace projection",
        }
    ]

    if h2.finite(camera2_focal_ratio) and float(camera2_focal_ratio) > 0.0:
        variants.append({
            "name": "camera2_focal_ratio",
            "family": "camera2_focal_reference",
            "focal_scale": float(camera2_focal_ratio),
            "cx_shift_px": 0.0,
            "cy_shift_px": 0.0,
            "description": (
                "effective focal scale corresponding to orientation-aware "
                "Camera2 prior versus final sparse focal"
            ),
        })

    for fraction in (-args.focal_fraction_2, -args.focal_fraction_1,
                     args.focal_fraction_1, args.focal_fraction_2):
        label = (
            f"{abs(fraction) * 100:.0f}pct_"
            + ("minus" if fraction < 0 else "plus")
        )
        variants.append({
            "name": f"effective_focal_{label}",
            "family": "effective_projection_focal",
            "focal_scale": 1.0 + float(fraction),
            "cx_shift_px": 0.0,
            "cy_shift_px": 0.0,
            "description": f"effective dense projection focal {fraction * 100:+.3f}%",
        })

    for axis in ("cx", "cy"):
        for pixels in (-args.principal_shift_px_2, -args.principal_shift_px_1,
                       args.principal_shift_px_1, args.principal_shift_px_2):
            label = (
                f"{abs(int(round(pixels)))}px_"
                + ("minus" if pixels < 0 else "plus")
            )
            variants.append({
                "name": f"effective_{axis}_{label}",
                "family": "effective_projection_principal_point",
                "focal_scale": 1.0,
                "cx_shift_px": float(pixels) if axis == "cx" else 0.0,
                "cy_shift_px": float(pixels) if axis == "cy" else 0.0,
                "description": f"effective dense projection {axis} {pixels:+.3f}px",
            })

    return variants


def apply_projection_variant(camera, variant):
    result = dict(camera)
    params = [float(value) for value in camera.get("params", [])]
    model = camera.get("model")
    focal_scale = float(variant.get("focal_scale", 1.0))
    cx_shift = float(variant.get("cx_shift_px", 0.0))
    cy_shift = float(variant.get("cy_shift_px", 0.0))

    if model == "SIMPLE_PINHOLE":
        if len(params) < 3:
            return None
        params[0] *= focal_scale
        params[1] += cx_shift
        params[2] += cy_shift
    elif model in {"SIMPLE_RADIAL", "RADIAL"}:
        if len(params) < 3:
            return None
        params[0] *= focal_scale
        params[1] += cx_shift
        params[2] += cy_shift
    elif model in {"PINHOLE", "OPENCV", "FULL_OPENCV"}:
        if len(params) < 4:
            return None
        params[0] *= focal_scale
        params[1] *= focal_scale
        params[2] += cx_shift
        params[3] += cy_shift
    else:
        return None

    result["params"] = params
    return result


def local_depth_gradient_fraction(depth, uv, radius):
    if uv is None or depth.ndim != 2:
        return None
    x = int(round(float(uv[0])))
    y = int(round(float(uv[1])))
    height, width = depth.shape
    x0 = max(0, x - radius)
    x1 = min(width, x + radius + 1)
    y0 = max(0, y - radius)
    y1 = min(height, y + radius + 1)
    if x0 >= x1 or y0 >= y1:
        return None
    patch = np.asarray(depth[y0:y1, x0:x1], dtype=np.float64)
    values = patch[np.isfinite(patch) & (patch > 0.0)]
    if values.size < 4:
        return None
    center = float(np.median(values))
    if center <= 0.0:
        return None
    return float((np.percentile(values, 90) - np.percentile(values, 10)) / center)


def dense_metric_diagnostic(rows, metric_field, minimum_count,
                            correlation_threshold, error_ratio_threshold):
    pairs = [
        row for row in rows
        if h2.finite(row.get(metric_field))
        and h2.finite(row.get("absolute_log_residual"))
    ]
    result = {
        "metric": metric_field,
        "count": len(pairs),
        "supported": len(pairs) >= minimum_count,
        "correlation_absolute_log_residual": None,
        "metric_median": None,
        "low_metric_error_p50": None,
        "high_metric_error_p50": None,
        "high_to_low_error_ratio": None,
        "signal": False,
    }
    if len(pairs) < minimum_count:
        return result

    metric_values = [float(row[metric_field]) for row in pairs]
    threshold = statistics.median(metric_values)
    result["metric_median"] = threshold

    corr_rows = [
        {
            "metric": float(row[metric_field]),
            "absolute_log_residual": float(row["absolute_log_residual"]),
        }
        for row in pairs
    ]
    correlation = h24.pearson(corr_rows, "metric", "absolute_log_residual")
    result["correlation_absolute_log_residual"] = correlation

    low = [
        float(row["absolute_log_residual"])
        for row in pairs
        if float(row[metric_field]) <= threshold
    ]
    high = [
        float(row["absolute_log_residual"])
        for row in pairs
        if float(row[metric_field]) > threshold
    ]
    low_p50 = h2.percentile(low, 0.50)
    high_p50 = h2.percentile(high, 0.50)
    ratio = safe_ratio(high_p50, low_p50)

    result["low_metric_error_p50"] = low_p50
    result["high_metric_error_p50"] = high_p50
    result["high_to_low_error_ratio"] = ratio
    result["signal"] = (
        h2.finite(correlation)
        and float(correlation) >= correlation_threshold
        and h2.finite(ratio)
        and float(ratio) >= error_ratio_threshold
    )
    return result


def classify(projection_supported, dense_structure_supported):
    if projection_supported and dense_structure_supported:
        return "MIXED_PATTERN_SUPPORTED"
    if projection_supported:
        return "RGB_EFFECTIVE_PROJECTION_PATTERN_SUPPORTED"
    if dense_structure_supported:
        return "DENSE_LOCAL_STRUCTURE_PATTERN_SUPPORTED"
    return "INSUFFICIENT_SUPPORT"


def base_report(args):
    return {
        "schema_version": 1,
        "stage": "SFM-S01H2.5",
        "status": "STARTING",
        "measurement_only": True,
        "camera_model_mutation_enabled": False,
        "calibration_mutation_enabled": False,
        "geometry_mutation_enabled": False,
        "ready_for_geometry_mutation": False,
        "sparse_model_modified": False,
        "camera_poses_modified": False,
        "points3d_modified": False,
        "dense_input_modified": False,
        "dense_depth_modified": False,
        "fusion_enabled": False,
        "parameters": {
            "strategy": args.strategy,
            "minimum_group_count": args.minimum_group_count,
            "minimum_dense_metric_count": args.minimum_dense_metric_count,
            "projection_improvement_threshold": args.projection_improvement_threshold,
            "dense_correlation_threshold": args.dense_correlation_threshold,
            "dense_error_ratio_threshold": args.dense_error_ratio_threshold,
            "focal_fraction_1": args.focal_fraction_1,
            "focal_fraction_2": args.focal_fraction_2,
            "principal_shift_px_1": args.principal_shift_px_1,
            "principal_shift_px_2": args.principal_shift_px_2,
            "local_gradient_radius": args.local_gradient_radius,
        },
        "scope_note": (
            "H2.5 evaluates effective projection sensitivity in the existing "
            "undistorted dense coordinate system and local dense-field structure. "
            "It does not claim to test a full alternative radial-distortion model; "
            "that would require regenerating undistortion and dense reconstruction."
        ),
        "next_gate": (
            "Use H2.5 to decide whether small effective RGB projection changes "
            "or local dense-depth structure better explain the remaining residual. "
            "S01H.3 remains closed."
        ),
    }


def write_outputs(args, report, rows):
    report_path = Path(args.report_json)
    report_path.parent.mkdir(parents=True, exist_ok=True)
    report_path.write_text(
        json.dumps(report, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    output_path = Path(args.output_jsonl)
    output_path.parent.mkdir(parents=True, exist_ok=True)
    with output_path.open("w", encoding="utf-8") as handle:
        handle.write(json.dumps({
            "type": "metadata",
            "schema_version": 1,
            "stage": "SFM-S01H2.5",
            "status": report["status"],
            "measurement_only": True,
            "camera_model_mutation_enabled": False,
            "calibration_mutation_enabled": False,
            "geometry_mutation_enabled": False,
            "dense_input_modified": False,
            "dense_depth_modified": False,
            "fusion_enabled": False,
        }) + "\n")
        for row in rows:
            handle.write(json.dumps(row, ensure_ascii=False) + "\n")


def skip(args, report, status, reason):
    report["status"] = status
    report["skip_reason"] = reason
    write_outputs(args, report, [])
    print(
        "INFO | TOF_DENSE_H25 | "
        f"status={status} measurement_only=yes "
        "camera_mutation=OFF geometry_mutation=OFF fusion=OFF"
    )
    return 0


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--observations", required=True)
    parser.add_argument("--observation-report", required=True)
    parser.add_argument("--tof-calibration", required=True)
    parser.add_argument("--camera-metadata", required=True)
    parser.add_argument("--h23-decomposition", required=True)
    parser.add_argument("--h23-report", required=True)
    parser.add_argument("--sparse-model-dir", required=True)
    parser.add_argument("--dense-job-dir", required=True)
    parser.add_argument("--strategy", default="geometric_footprint_p50")
    parser.add_argument("--output-jsonl", required=True)
    parser.add_argument("--report-json", required=True)
    parser.add_argument("--minimum-group-count", type=int, default=20)
    parser.add_argument("--minimum-dense-metric-count", type=int, default=100)
    parser.add_argument("--projection-improvement-threshold", type=float, default=0.10)
    parser.add_argument("--dense-correlation-threshold", type=float, default=0.20)
    parser.add_argument("--dense-error-ratio-threshold", type=float, default=1.20)
    parser.add_argument("--focal-fraction-1", type=float, default=0.01)
    parser.add_argument("--focal-fraction-2", type=float, default=0.02)
    parser.add_argument("--principal-shift-px-1", type=float, default=5.0)
    parser.add_argument("--principal-shift-px-2", type=float, default=10.0)
    parser.add_argument("--local-gradient-radius", type=int, default=2)
    args = parser.parse_args()

    if args.minimum_group_count < 1 or args.minimum_dense_metric_count < 1:
        raise SystemExit("minimum counts must be >= 1")
    if not (0.0 <= args.projection_improvement_threshold < 1.0):
        raise SystemExit("projection-improvement-threshold must be in [0,1)")
    if args.dense_correlation_threshold < 0.0:
        raise SystemExit("dense-correlation-threshold must be >= 0")
    if args.dense_error_ratio_threshold <= 1.0:
        raise SystemExit("dense-error-ratio-threshold must be > 1")
    if args.local_gradient_radius < 1:
        raise SystemExit("local-gradient-radius must be >= 1")

    report = base_report(args)

    h1_report = h2.load_json(args.observation_report)
    h23_report = h2.load_json(args.h23_report)
    camera_metadata = h2.load_json(args.camera_metadata)

    if (
        h1_report.get("status") != "MEASURED"
        or h1_report.get("measurement_gate_pass") is not True
    ):
        return skip(
            args, report, "SKIPPED_H1_UNAVAILABLE",
            "S01H.1 metric observation set is unavailable or untrusted."
        )
    if h23_report.get("status") != "MEASURED":
        return skip(
            args, report, "SKIPPED_H23_UNAVAILABLE",
            "S01H.2.3 report is unavailable or not MEASURED."
        )

    profile = h21.load_profile(args.tof_calibration, h1_report)
    if not h21.validate_profile(profile):
        return skip(
            args, report, "SKIPPED_CALIBRATION_UNAVAILABLE",
            "Unique accepted ToF calibration profile is unavailable."
        )

    sparse_camera, cameras_path, camera_count = h24.find_single_sparse_camera(
        args.sparse_model_dir
    )
    report["sparse_camera"] = {
        "cameras_txt": cameras_path,
        "supported_camera_count": camera_count,
        "camera": sparse_camera,
    }
    if sparse_camera is None:
        return skip(
            args, report, "SKIPPED_SPARSE_CAMERA_UNAVAILABLE",
            "Exactly one supported final sparse camera is required."
        )

    prior = camera_metadata.get("colmap_camera_prior")
    mapped_prior = (
        h23.prior_for_camera_size(
            prior, sparse_camera["width"], sparse_camera["height"]
        )
        if isinstance(prior, dict)
        else None
    )
    final_focal = sparse_focal(sparse_camera)
    prior_focal = (
        float(mapped_prior["params"][0])
        if isinstance(mapped_prior, dict)
        and isinstance(mapped_prior.get("params"), list)
        and mapped_prior["params"]
        and h2.finite(mapped_prior["params"][0])
        else None
    )
    camera2_focal_ratio = safe_ratio(prior_focal, final_focal)
    report["camera_reference"] = {
        "final_sparse_focal": final_focal,
        "orientation_aware_camera2_prior": mapped_prior,
        "camera2_to_final_focal_ratio": camera2_focal_ratio,
        "radial_distortion_perturbation_evaluated": False,
        "radial_distortion_note": (
            "The dense maps are already undistorted. Testing k=0 or alternate k "
            "as a camera-model hypothesis requires regenerating undistortion and "
            "dense reconstruction; H2.5 does not fake that by shifting fixed maps."
        ),
    }

    observations = h2.load_jsonl(args.observations, "tof_metric_observation")
    h23_rows = [
        row for row in h2.load_jsonl(
            args.h23_decomposition, "tof_dense_h23_decomposition"
        )
        if row.get("strategy") == args.strategy
    ]
    if not observations or not h23_rows:
        return skip(
            args, report, "SKIPPED_INPUT_UNAVAILABLE",
            "Required H1 observations or H2.3 rows are unavailable."
        )

    h23_lookup = {
        observation_key(row): row
        for row in h23_rows
        if observation_key(row) is not None
    }
    baseline_keys = set(h23_lookup)
    observations_by_image = defaultdict(list)
    for observation in observations:
        key = observation_key(observation)
        if key in baseline_keys:
            observations_by_image[str(observation.get("image"))].append(observation)

    variants = projection_variant_definitions(camera2_focal_ratio, args)
    report["effective_projection_sensitivity"] = {
        "status": "STARTING",
        "variant_count": len(variants),
        "variants": {},
        "dense_depth_modified": False,
    }

    workspaces = h22.discover_dense_workspaces(
        args.dense_job_dir, ["geometric", "photometric"]
    )
    if not workspaces:
        return skip(
            args, report, "SKIPPED_DENSE_UNAVAILABLE",
            "No completed dense workspace with depth maps was found."
        )

    print(
        "INFO | TOF_DENSE_H25 | "
        f"workspaces={len(workspaces)} variants={len(variants)}"
    )

    depths_by_variant = {
        variant["name"]: defaultdict(list) for variant in variants
    }
    camera_z_by_key = {}
    metadata_by_key = {}
    structure_values = defaultdict(lambda: defaultdict(list))
    processed_geometric_maps = 0
    processed_photometric_maps = 0
    map_errors = []

    for workspace in workspaces:
        for image_name, maps in workspace["depth_index"].items():
            observations_for_image = observations_by_image.get(image_name)
            image = workspace["images"].get(image_name)
            if not observations_for_image or image is None:
                continue
            workspace_camera = workspace["cameras"].get(image["camera_id"])
            if (
                workspace_camera is None
                or workspace_camera.get("model") not in h2.SUPPORTED_CAMERA_MODELS
                or "geometric" not in maps
            ):
                continue

            try:
                geometric = h22.load_depth_map(maps["geometric"])
            except Exception as exc:
                map_errors.append({"path": maps["geometric"], "error": str(exc)})
                continue
            if geometric.ndim != 2:
                map_errors.append({
                    "path": maps["geometric"],
                    "error": f"expected single-channel depth, got {geometric.shape}",
                })
                continue
            processed_geometric_maps += 1

            photometric = None
            if "photometric" in maps:
                try:
                    candidate = h22.load_depth_map(maps["photometric"])
                    if candidate.ndim == 2:
                        photometric = candidate
                        processed_photometric_maps += 1
                except Exception as exc:
                    map_errors.append({"path": maps["photometric"], "error": str(exc)})

            if processed_geometric_maps % 25 == 0:
                print(
                    "INFO | TOF_DENSE_H25 | "
                    f"processed_geometric_maps={processed_geometric_maps}"
                )

            projected_variants = {
                variant["name"]: apply_projection_variant(
                    workspace_camera, variant
                )
                for variant in variants
            }

            for observation in observations_for_image:
                key = observation_key(observation)
                if key not in baseline_keys:
                    continue
                row_index = observation.get("row")
                column_index = observation.get("column")
                distance_mm = observation.get("distance_mm")
                if (
                    not isinstance(row_index, int)
                    or not isinstance(column_index, int)
                    or not h2.finite(distance_mm)
                ):
                    continue

                camera_xyz = h21.tof_point_to_camera(
                    profile, column_index, row_index, float(distance_mm)
                )
                if camera_xyz is None or not h2.finite(camera_xyz[2]):
                    continue
                camera_z_by_key[key] = float(camera_xyz[2])
                metadata_by_key[key] = {
                    "image": observation.get("image"),
                    "tof_sequence": observation.get("tof_sequence"),
                    "zone_index": observation.get("zone_index"),
                    "zone_row": row_index,
                    "zone_column": column_index,
                    "distance_mm": float(distance_mm),
                }

                for variant in variants:
                    variant_camera = projected_variants.get(variant["name"])
                    if variant_camera is None:
                        continue
                    polygon_camera = h21.zone_footprint_polygon(
                        profile, variant_camera, observation
                    )
                    if not polygon_camera:
                        continue
                    polygon_depth = h22.scale_polygon_to_depth(
                        polygon_camera, variant_camera, geometric.shape
                    )
                    values = h22.polygon_depth_values(geometric, polygon_depth)
                    if values.size == 0:
                        continue
                    sampled = float(np.percentile(values, 50))
                    if math.isfinite(sampled) and sampled > 0.0:
                        depths_by_variant[variant["name"]][key].append(sampled)

                    if variant["name"] != "baseline":
                        continue

                    p25 = float(np.percentile(values, 25))
                    p50 = float(np.percentile(values, 50))
                    p75 = float(np.percentile(values, 75))
                    if p50 > 0.0:
                        structure_values[key][
                            "geometric_footprint_iqr_fraction"
                        ].append((p75 - p25) / p50)

                    center_uv = h2.project_camera_a_point_to_colmap_image(
                        variant_camera, camera_xyz
                    )
                    center_depth_uv = h22.scale_uv_to_depth(
                        center_uv, variant_camera, geometric.shape
                    )
                    gradient = local_depth_gradient_fraction(
                        geometric, center_depth_uv, args.local_gradient_radius
                    )
                    if h2.finite(gradient):
                        structure_values[key][
                            "geometric_local_gradient_fraction"
                        ].append(float(gradient))

                    if photometric is not None:
                        photo_polygon = h22.scale_polygon_to_depth(
                            polygon_camera, variant_camera, photometric.shape
                        )
                        photo_values = h22.polygon_depth_values(
                            photometric, photo_polygon
                        )
                        if photo_values.size > 0:
                            photo_p50 = float(np.percentile(photo_values, 50))
                            denominator = (p50 + photo_p50) * 0.5
                            if denominator > 0.0:
                                structure_values[key][
                                    "geometric_photometric_relative_difference"
                                ].append(abs(p50 - photo_p50) / denominator)

    projection = report["effective_projection_sensitivity"]
    projection["workspace_count"] = len(workspaces)
    projection["processed_geometric_map_count"] = processed_geometric_maps
    projection["processed_photometric_map_count"] = processed_photometric_maps
    projection["map_errors"] = map_errors[:50]

    if processed_geometric_maps == 0:
        return skip(
            args, report, "SKIPPED_DENSE_UNAVAILABLE",
            "No readable geometric depth maps were found."
        )

    for variant in variants:
        name = variant["name"]
        candidate_rows = []
        for key, depth_values in depths_by_variant[name].items():
            metadata = metadata_by_key.get(key)
            camera_z = camera_z_by_key.get(key)
            if (
                metadata is None or not depth_values
                or not h2.finite(camera_z) or float(camera_z) <= 0.0
            ):
                continue
            dense_depth = statistics.median(
                float(value) for value in depth_values
            )
            if dense_depth <= 0.0:
                continue
            row = dict(metadata)
            row["dense_depth_units"] = dense_depth
            row["tof_camera_z_mm"] = float(camera_z)
            row["scale_mm_per_colmap_unit"] = float(camera_z) / dense_depth
            candidate_rows.append(row)

        summary = h24.variant_structure_summary(
            candidate_rows,
            args.minimum_group_count,
            args.minimum_group_count,
        )
        summary["family"] = variant["family"]
        summary["description"] = variant["description"]
        summary["focal_scale"] = variant["focal_scale"]
        summary["cx_shift_px"] = variant["cx_shift_px"]
        summary["cy_shift_px"] = variant["cy_shift_px"]
        projection["variants"][name] = summary

    baseline = projection["variants"].get("baseline") or {}
    baseline_count = int(baseline.get("candidate_count") or 0)
    baseline_score = baseline.get("zone_structure_score_log")
    baseline_p95 = baseline.get("absolute_ratio_error_p95")
    best_name = None
    best_improvement = None

    for name, summary in projection["variants"].items():
        if name == "baseline" or summary.get("status") != "MEASURED":
            continue
        count = int(summary.get("candidate_count") or 0)
        support_ratio = count / baseline_count if baseline_count > 0 else 0.0
        summary["support_ratio_vs_baseline"] = support_ratio

        score = summary.get("zone_structure_score_log")
        improvement = None
        if (
            h2.finite(baseline_score)
            and float(baseline_score) > 1e-12
            and h2.finite(score)
        ):
            improvement = (
                float(baseline_score) - float(score)
            ) / float(baseline_score)
            summary["zone_structure_improvement_fraction"] = improvement

        p95 = summary.get("absolute_ratio_error_p95")
        if (
            h2.finite(p95)
            and h2.finite(baseline_p95)
            and float(baseline_p95) > 1e-12
        ):
            summary["p95_error_ratio_vs_baseline"] = (
                float(p95) / float(baseline_p95)
            )

        if (
            support_ratio >= 0.90
            and h2.finite(improvement)
            and (
                best_improvement is None
                or float(improvement) > float(best_improvement)
            )
        ):
            best_name = name
            best_improvement = float(improvement)

    projection["best_perturbation"] = (
        {"name": best_name, **projection["variants"][best_name]}
        if best_name is not None
        else None
    )
    projection["status"] = (
        "MEASURED"
        if baseline.get("status") == "MEASURED"
        else "INSUFFICIENT_SUPPORT"
    )

    projection_supported = False
    best = projection.get("best_perturbation")
    if isinstance(best, dict):
        improvement = best.get("zone_structure_improvement_fraction")
        p95_ratio = best.get("p95_error_ratio_vs_baseline")
        support_ratio = best.get("support_ratio_vs_baseline")
        projection_supported = (
            h2.finite(improvement)
            and float(improvement) >= args.projection_improvement_threshold
            and h2.finite(p95_ratio)
            and float(p95_ratio) <= 1.10
            and h2.finite(support_ratio)
            and float(support_ratio) >= 0.90
        )

    structure_rows = []
    for key, metrics in structure_values.items():
        source = h23_lookup.get(key)
        metadata = metadata_by_key.get(key)
        ratio = source.get("distance_normalized_ratio") if source else None
        if (
            metadata is None
            or not h2.finite(ratio)
            or float(ratio) <= 0.0
        ):
            continue
        row = {
            "type": "tof_dense_h25_dense_structure",
            "schema_version": 1,
            "stage": "SFM-S01H2.5",
            **metadata,
            "distance_normalized_ratio": float(ratio),
            "absolute_log_residual": abs(math.log(float(ratio))),
        }
        for metric_name, values in metrics.items():
            value = median(values)
            if h2.finite(value):
                row[metric_name] = float(value)
        structure_rows.append(row)

    metric_names = [
        "geometric_footprint_iqr_fraction",
        "geometric_local_gradient_fraction",
        "geometric_photometric_relative_difference",
    ]
    metric_diagnostics = {}
    signal_count = 0
    for metric_name in metric_names:
        diagnostic = dense_metric_diagnostic(
            structure_rows,
            metric_name,
            args.minimum_dense_metric_count,
            args.dense_correlation_threshold,
            args.dense_error_ratio_threshold,
        )
        metric_diagnostics[metric_name] = diagnostic
        if diagnostic.get("signal"):
            signal_count += 1

    dense_structure_supported = signal_count >= 2
    report["dense_local_structure"] = {
        "status": "MEASURED" if structure_rows else "INSUFFICIENT_SUPPORT",
        "row_count": len(structure_rows),
        "metrics": metric_diagnostics,
        "signal_count": signal_count,
        "required_signal_count": 2,
        "dense_local_structure_supported": dense_structure_supported,
    }

    decision = classify(projection_supported, dense_structure_supported)
    report["decision"] = {
        "classification": decision,
        "effective_rgb_projection_supported": projection_supported,
        "dense_local_structure_supported": dense_structure_supported,
        "projection_improvement_threshold": args.projection_improvement_threshold,
        "dense_correlation_threshold": args.dense_correlation_threshold,
        "dense_error_ratio_threshold": args.dense_error_ratio_threshold,
        "note": (
            "H2.5 is diagnostic only. Fixed-map effective projection perturbations "
            "do not replace a full alternate-camera dense reconstruction."
        ),
    }

    report["status"] = "MEASURED"
    report["measurement_only"] = True
    report["camera_model_mutation_enabled"] = False
    report["calibration_mutation_enabled"] = False
    report["geometry_mutation_enabled"] = False
    report["ready_for_geometry_mutation"] = False
    report["sparse_model_modified"] = False
    report["camera_poses_modified"] = False
    report["points3d_modified"] = False
    report["dense_input_modified"] = False
    report["dense_depth_modified"] = False
    report["fusion_enabled"] = False

    write_outputs(args, report, structure_rows)

    print(
        "INFO | TOF_DENSE_H25 | "
        f"status=MEASURED projection_supported={projection_supported} "
        f"dense_structure_supported={dense_structure_supported} "
        f"dense_structure_signals={signal_count} "
        f"decision={decision} camera_mutation=OFF geometry_mutation=OFF fusion=OFF"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
