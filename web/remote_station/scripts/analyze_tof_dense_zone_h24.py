#!/usr/bin/env python3
import argparse
import copy
import json
import math
import statistics
from collections import defaultdict
from pathlib import Path

import numpy as np

import analyze_tof_dense_error_h23 as h23
import measure_tof_dense_depth_h22 as h22
import measure_tof_sparse_scale as h2
import measure_tof_sparse_scale_h21 as h21


def observation_key(row):
    try:
        return (
            str(row.get("image")),
            int(row.get("tof_sequence")),
            int(row.get("zone_index")),
        )
    except Exception:
        return None


def fixed_axis_bucket(value):
    if not h2.finite(value):
        return "unknown"
    value = float(value)
    if value < -0.5:
        return "neg_edge"
    if value < 0.0:
        return "neg_mid"
    if value < 0.5:
        return "pos_mid"
    return "pos_edge"


def pearson(rows, x_field, y_field):
    pairs = [
        (float(row[x_field]), float(row[y_field]))
        for row in rows
        if h2.finite(row.get(x_field)) and h2.finite(row.get(y_field))
    ]
    if len(pairs) < 3:
        return None
    xs = [x for x, _ in pairs]
    ys = [y for _, y in pairs]
    mx = statistics.mean(xs)
    my = statistics.mean(ys)
    numerator = sum((x - mx) * (y - my) for x, y in pairs)
    dx = math.sqrt(sum((x - mx) ** 2 for x in xs))
    dy = math.sqrt(sum((y - my) ** 2 for y in ys))
    if dx <= 1e-12 or dy <= 1e-12:
        return None
    return numerator / (dx * dy)


def signed_group_summary(rows, field, minimum_count):
    grouped = defaultdict(list)
    for row in rows:
        ratio = row.get("distance_normalized_ratio")
        if h2.finite(ratio) and float(ratio) > 0.0:
            grouped[str(row.get(field, "unknown"))].append(float(ratio))

    result = {}
    supported_medians = []
    supported_signed = []
    for key in sorted(grouped):
        ratios = grouped[key]
        signed = [value - 1.0 for value in ratios]
        item = {
            "count": len(ratios),
            "supported": len(ratios) >= minimum_count,
            "ratio_median": statistics.median(ratios),
            "signed_ratio_residual_p25": h2.percentile(signed, 0.25),
            "signed_ratio_residual_p50": h2.percentile(signed, 0.50),
            "signed_ratio_residual_p75": h2.percentile(signed, 0.75),
            "absolute_ratio_residual_p95": h2.percentile(
                [abs(value) for value in signed], 0.95
            ),
        }
        result[key] = item
        if item["supported"] and item["ratio_median"] > 0.0:
            supported_medians.append(item["ratio_median"])
            supported_signed.append(item["signed_ratio_residual_p50"])

    spread = None
    if len(supported_medians) >= 2 and min(supported_medians) > 0.0:
        spread = max(supported_medians) / min(supported_medians)

    signed_range = None
    if len(supported_signed) >= 2:
        signed_range = max(supported_signed) - min(supported_signed)

    return result, spread, signed_range


def zone_grid(zone_summary):
    grid = []
    for row in range(8):
        line = []
        for column in range(8):
            key = str(row * 8 + column)
            item = zone_summary.get(key)
            line.append(
                item.get("signed_ratio_residual_p50")
                if isinstance(item, dict) and item.get("supported")
                else None
            )
        grid.append(line)
    return grid


def find_single_sparse_camera(model_dir):
    cameras_path = h23.find_cameras_txt(model_dir)
    if cameras_path is None:
        return None, None, 0
    cameras = h2.parse_cameras(cameras_path)
    supported = [
        camera for camera in cameras.values()
        if camera.get("model") in h2.SUPPORTED_CAMERA_MODELS
    ]
    if len(supported) != 1:
        return None, str(cameras_path), len(supported)
    return supported[0], str(cameras_path), len(supported)


def matrix3(flat):
    return [
        [float(flat[0]), float(flat[1]), float(flat[2])],
        [float(flat[3]), float(flat[4]), float(flat[5])],
        [float(flat[6]), float(flat[7]), float(flat[8])],
    ]


def flatten3(matrix):
    return [float(matrix[r][c]) for r in range(3) for c in range(3)]


def matmul3(a, b):
    return [
        [
            sum(float(a[r][k]) * float(b[k][c]) for k in range(3))
            for c in range(3)
        ]
        for r in range(3)
    ]


def delta_rotation(axis, angle_deg):
    angle = math.radians(float(angle_deg))
    c = math.cos(angle)
    s = math.sin(angle)
    if axis == "x":
        return [[1.0, 0.0, 0.0], [0.0, c, -s], [0.0, s, c]]
    if axis == "y":
        return [[c, 0.0, s], [0.0, 1.0, 0.0], [-s, 0.0, c]]
    if axis == "z":
        return [[c, -s, 0.0], [s, c, 0.0], [0.0, 0.0, 1.0]]
    raise ValueError(f"unsupported axis: {axis}")


def make_variant(profile, name, family, description):
    return {
        "name": name,
        "family": family,
        "description": description,
        "profile": copy.deepcopy(profile),
    }


def build_variants(profile, args):
    variants = [
        make_variant(profile, "baseline", "baseline", "accepted frozen calibration")
    ]

    for key, delta in (
        ("cx_zones", args.perturbation_cx_zones),
        ("cy_zones", args.perturbation_cy_zones),
    ):
        for sign, label in ((-1.0, "minus"), (1.0, "plus")):
            variant = make_variant(
                profile,
                f"{key}_{label}",
                "tof_intrinsics_center",
                f"{key} {sign * delta:+.6f} zones",
            )
            variant["profile"]["tof_intrinsics"][key] = (
                float(profile["tof_intrinsics"][key]) + sign * delta
            )
            variants.append(variant)

    for key in ("fx_zones", "fy_zones"):
        for sign, label in ((-1.0, "minus"), (1.0, "plus")):
            fraction = sign * args.perturbation_focal_fraction
            variant = make_variant(
                profile,
                f"{key}_{label}",
                "tof_intrinsics_focal",
                f"{key} {fraction * 100:+.3f} percent",
            )
            variant["profile"]["tof_intrinsics"][key] = (
                float(profile["tof_intrinsics"][key]) * (1.0 + fraction)
            )
            variants.append(variant)

    base_rotation = matrix3(profile["rotation_tof_to_camera"])
    for axis in ("x", "y", "z"):
        for sign, label in ((-1.0, "minus"), (1.0, "plus")):
            degrees = sign * args.perturbation_rotation_deg
            variant = make_variant(
                profile,
                f"rotation_{axis}_{label}",
                "tof_to_rgb_rotation",
                f"camera-frame {axis} rotation {degrees:+.6f} deg",
            )
            rotated = matmul3(delta_rotation(axis, degrees), base_rotation)
            variant["profile"]["rotation_tof_to_camera"] = flatten3(rotated)
            variants.append(variant)

    for axis, index in (("x", 0), ("y", 1), ("z", 2)):
        for sign, label in ((-1.0, "minus"), (1.0, "plus")):
            delta_mm = sign * args.perturbation_translation_mm
            variant = make_variant(
                profile,
                f"translation_{axis}_{label}",
                "tof_to_rgb_translation",
                f"{axis} translation {delta_mm:+.6f} mm",
            )
            translation = [
                float(value)
                for value in profile["translation_tof_to_camera_mm"]
            ]
            translation[index] += delta_mm
            variant["profile"]["translation_tof_to_camera_mm"] = translation
            variants.append(variant)

    return variants


def variant_structure_summary(rows, minimum_distance_count, minimum_zone_count):
    distance_buckets, knots = h23.build_distance_model(
        rows, minimum_distance_count
    )
    if len(knots) < 2:
        return {
            "status": "INSUFFICIENT_DISTANCE_SUPPORT",
            "candidate_count": len(rows),
            "distance_buckets": distance_buckets,
        }

    normalized = []
    for source in rows:
        expected = h23.interpolate_knots(knots, source.get("distance_mm"))
        if not h2.finite(expected) or float(expected) <= 0.0:
            continue
        row = dict(source)
        ratio = float(row["scale_mm_per_colmap_unit"]) / float(expected)
        if not math.isfinite(ratio) or ratio <= 0.0:
            continue
        row["distance_normalized_ratio"] = ratio
        normalized.append(row)

    _, row_spread = h23.group_summary(
        normalized,
        "zone_row",
        "distance_normalized_ratio",
        minimum_zone_count,
    )
    _, column_spread = h23.group_summary(
        normalized,
        "zone_column",
        "distance_normalized_ratio",
        minimum_zone_count,
    )
    absolute = [
        abs(float(row["distance_normalized_ratio"]) - 1.0)
        for row in normalized
    ]
    score = None
    if (
        h2.finite(row_spread)
        and float(row_spread) > 0.0
        and h2.finite(column_spread)
        and float(column_spread) > 0.0
    ):
        score = math.hypot(
            math.log(float(row_spread)),
            math.log(float(column_spread)),
        )

    return {
        "status": "MEASURED",
        "candidate_count": len(normalized),
        "zone_row_spread_ratio": row_spread,
        "zone_column_spread_ratio": column_spread,
        "zone_structure_score_log": score,
        "absolute_ratio_error_p50": h2.percentile(absolute, 0.50),
        "absolute_ratio_error_p95": h2.percentile(absolute, 0.95),
        "distance_buckets": distance_buckets,
    }


def run_active_perturbations(
    dense_job_dir,
    variants,
    observations_by_image,
    baseline_keys,
    minimum_distance_count,
    minimum_zone_count,
):
    report = {
        "status": "STARTING",
        "map_type": "geometric",
        "sampling": "projected ToF zone footprint p50",
        "calibration_mutation_enabled": False,
        "dense_depth_modified": False,
        "variant_count": len(variants),
        "variants": {},
    }

    workspaces = h22.discover_dense_workspaces(dense_job_dir, ["geometric"])
    if not workspaces:
        report["status"] = "SKIPPED_DENSE_UNAVAILABLE"
        return report

    print(
        "INFO | TOF_DENSE_H24 | "
        f"active_perturbation workspaces={len(workspaces)} "
        f"variants={len(variants)}"
    )

    depths_by_variant = {
        variant["name"]: defaultdict(list) for variant in variants
    }
    camera_z_by_variant = {
        variant["name"]: {} for variant in variants
    }
    metadata_by_key = {}
    processed_maps = 0
    map_errors = []

    for workspace in workspaces:
        for image_name, maps in workspace["depth_index"].items():
            if "geometric" not in maps:
                continue
            observations = observations_by_image.get(image_name)
            image = workspace["images"].get(image_name)
            if not observations or image is None:
                continue
            camera = workspace["cameras"].get(image["camera_id"])
            if camera is None or camera.get("model") not in h2.SUPPORTED_CAMERA_MODELS:
                continue

            try:
                depth = h22.load_depth_map(maps["geometric"])
            except Exception as exc:
                map_errors.append({
                    "path": maps["geometric"],
                    "error": str(exc),
                })
                continue
            if depth.ndim != 2:
                map_errors.append({
                    "path": maps["geometric"],
                    "error": f"expected single-channel depth, got {depth.shape}",
                })
                continue
            processed_maps += 1
            if processed_maps % 25 == 0:
                print(
                    "INFO | TOF_DENSE_H24 | "
                    f"active_perturbation processed_maps={processed_maps} "
                    f"variants={len(variants)}"
                )

            for observation in observations:
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

                metadata_by_key[key] = {
                    "image": observation.get("image"),
                    "tof_sequence": observation.get("tof_sequence"),
                    "zone_index": observation.get("zone_index"),
                    "zone_row": row_index,
                    "zone_column": column_index,
                    "distance_mm": float(distance_mm),
                }

                for variant in variants:
                    profile = variant["profile"]
                    camera_xyz = h21.tof_point_to_camera(
                        profile,
                        column_index,
                        row_index,
                        float(distance_mm),
                    )
                    if camera_xyz is None:
                        continue
                    polygon_camera = h21.zone_footprint_polygon(
                        profile, camera, observation
                    )
                    if not polygon_camera:
                        continue
                    polygon_depth = h22.scale_polygon_to_depth(
                        polygon_camera, camera, depth.shape
                    )
                    values = h22.polygon_depth_values(depth, polygon_depth)
                    if values.size == 0:
                        continue
                    sampled_depth = float(np.percentile(values, 50))
                    if not math.isfinite(sampled_depth) or sampled_depth <= 0.0:
                        continue
                    name = variant["name"]
                    depths_by_variant[name][key].append(sampled_depth)
                    camera_z_by_variant[name][key] = float(camera_xyz[2])

    report["workspace_count"] = len(workspaces)
    report["processed_map_count"] = processed_maps
    report["map_errors"] = map_errors[:50]
    if processed_maps == 0:
        report["status"] = "SKIPPED_DENSE_UNAVAILABLE"
        return report

    for variant in variants:
        name = variant["name"]
        rows = []
        for key, depth_values in depths_by_variant[name].items():
            metadata = metadata_by_key.get(key)
            camera_z = camera_z_by_variant[name].get(key)
            if (
                metadata is None
                or not depth_values
                or not h2.finite(camera_z)
                or float(camera_z) <= 0.0
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
            rows.append(row)

        summary = variant_structure_summary(
            rows,
            minimum_distance_count,
            minimum_zone_count,
        )
        summary["family"] = variant["family"]
        summary["description"] = variant["description"]
        report["variants"][name] = summary

    baseline = report["variants"].get("baseline") or {}
    baseline_count = int(baseline.get("candidate_count") or 0)
    baseline_score = baseline.get("zone_structure_score_log")
    baseline_p95 = baseline.get("absolute_ratio_error_p95")
    best_name = None
    best_improvement = None

    for name, summary in report["variants"].items():
        if name == "baseline" or summary.get("status") != "MEASURED":
            continue
        count = int(summary.get("candidate_count") or 0)
        support_ratio = (
            count / baseline_count if baseline_count > 0 else 0.0
        )
        summary["support_ratio_vs_baseline"] = support_ratio
        score = summary.get("zone_structure_score_log")
        if (
            h2.finite(baseline_score)
            and float(baseline_score) > 1e-12
            and h2.finite(score)
        ):
            improvement = (
                float(baseline_score) - float(score)
            ) / float(baseline_score)
            summary["zone_structure_improvement_fraction"] = improvement
        else:
            improvement = None

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

    report["best_perturbation"] = (
        {
            "name": best_name,
            **report["variants"][best_name],
        }
        if best_name is not None
        else None
    )
    report["status"] = (
        "MEASURED"
        if baseline.get("status") == "MEASURED"
        else "INSUFFICIENT_SUPPORT"
    )
    return report


def base_report(args):
    return {
        "schema_version": 1,
        "stage": "SFM-S01H2.4",
        "status": "STARTING",
        "measurement_only": True,
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
            "minimum_zone_count": args.minimum_zone_count,
            "minimum_distance_count": args.minimum_distance_count,
            "direct_tof_min_mm": args.direct_tof_min_mm,
            "direct_tof_max_mm": args.direct_tof_max_mm,
            "zone_improvement_threshold": args.zone_improvement_threshold,
            "rgb_spread_threshold": args.rgb_spread_threshold,
            "perturbation_cx_zones": args.perturbation_cx_zones,
            "perturbation_cy_zones": args.perturbation_cy_zones,
            "perturbation_focal_fraction": args.perturbation_focal_fraction,
            "perturbation_rotation_deg": args.perturbation_rotation_deg,
            "perturbation_translation_mm": args.perturbation_translation_mm,
        },
        "metric_policy": {
            "within_direct_tof_range": "METRIC_REFERENCE_ELIGIBLE",
            "beyond_direct_tof_range": "APPROXIMATE_ONLY",
            "tof_extrapolation_beyond_range": False,
        },
        "next_gate": (
            "Review H2.4 signed zone/RGB localization and active perturbation "
            "sensitivity. S01H.3 remains closed."
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
            "stage": "SFM-S01H2.4",
            "status": report["status"],
            "measurement_only": True,
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
        "INFO | TOF_DENSE_H24 | "
        f"status={status} measurement_only=yes "
        "calibration_mutation=OFF geometry_mutation=OFF fusion=OFF"
    )
    return 0


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--observations", required=True)
    parser.add_argument("--observation-report", required=True)
    parser.add_argument("--tof-calibration", required=True)
    parser.add_argument("--h22-candidates", required=True)
    parser.add_argument("--h22-report", required=True)
    parser.add_argument("--h23-decomposition", required=True)
    parser.add_argument("--h23-report", required=True)
    parser.add_argument("--sparse-model-dir", required=True)
    parser.add_argument("--dense-job-dir", default="")
    parser.add_argument("--strategy", default="geometric_footprint_p50")
    parser.add_argument("--output-jsonl", required=True)
    parser.add_argument("--report-json", required=True)
    parser.add_argument("--minimum-zone-count", type=int, default=20)
    parser.add_argument("--minimum-distance-count", type=int, default=20)
    parser.add_argument("--direct-tof-min-mm", type=float, default=100.0)
    parser.add_argument("--direct-tof-max-mm", type=float, default=4000.0)
    parser.add_argument("--zone-improvement-threshold", type=float, default=0.10)
    parser.add_argument("--rgb-spread-threshold", type=float, default=1.10)
    parser.add_argument("--perturbation-cx-zones", type=float, default=0.10)
    parser.add_argument("--perturbation-cy-zones", type=float, default=0.10)
    parser.add_argument("--perturbation-focal-fraction", type=float, default=0.01)
    parser.add_argument("--perturbation-rotation-deg", type=float, default=0.25)
    parser.add_argument("--perturbation-translation-mm", type=float, default=2.0)
    args = parser.parse_args()

    if args.minimum_zone_count < 1 or args.minimum_distance_count < 1:
        raise SystemExit("minimum counts must be >= 1")
    if (
        args.direct_tof_min_mm <= 0.0
        or args.direct_tof_max_mm <= args.direct_tof_min_mm
    ):
        raise SystemExit("invalid direct ToF range")
    if not (0.0 <= args.zone_improvement_threshold < 1.0):
        raise SystemExit("zone-improvement-threshold must be in [0,1)")
    if args.rgb_spread_threshold <= 1.0:
        raise SystemExit("rgb-spread-threshold must be > 1")

    report = base_report(args)

    h1_report = h2.load_json(args.observation_report)
    h22_report = h2.load_json(args.h22_report)
    h23_report = h2.load_json(args.h23_report)

    if (
        h1_report.get("status") != "MEASURED"
        or h1_report.get("measurement_gate_pass") is not True
    ):
        return skip(
            args,
            report,
            "SKIPPED_H1_UNAVAILABLE",
            "S01H.1 metric observation set is unavailable or untrusted.",
        )
    if h22_report.get("status") != "MEASURED":
        return skip(
            args,
            report,
            "SKIPPED_H22_UNAVAILABLE",
            "S01H.2.2 report is unavailable or not MEASURED.",
        )
    if h23_report.get("status") != "MEASURED":
        return skip(
            args,
            report,
            "SKIPPED_H23_UNAVAILABLE",
            "S01H.2.3 report is unavailable or not MEASURED.",
        )
    if args.strategy not in (h22_report.get("strategies") or {}):
        return skip(
            args,
            report,
            "SKIPPED_STRATEGY_UNAVAILABLE",
            f"H2.2 strategy unavailable: {args.strategy}",
        )

    profile = h21.load_profile(args.tof_calibration, h1_report)
    if not h21.validate_profile(profile):
        return skip(
            args,
            report,
            "SKIPPED_CALIBRATION_UNAVAILABLE",
            "Unique accepted ToF calibration profile is unavailable.",
        )

    camera, cameras_path, camera_count = find_single_sparse_camera(
        args.sparse_model_dir
    )
    report["sparse_camera"] = {
        "cameras_txt": cameras_path,
        "supported_camera_count": camera_count,
        "camera": camera,
    }
    if camera is None:
        return skip(
            args,
            report,
            "SKIPPED_SPARSE_CAMERA_UNAVAILABLE",
            "Exactly one supported final sparse camera is required.",
        )

    observations = h2.load_jsonl(
        args.observations, "tof_metric_observation"
    )
    h22_candidates = [
        row
        for row in h2.load_jsonl(
            args.h22_candidates, "tof_dense_h22_candidate"
        )
        if row.get("strategy") == args.strategy
    ]
    h23_rows = [
        row
        for row in h2.load_jsonl(
            args.h23_decomposition, "tof_dense_h23_decomposition"
        )
        if row.get("strategy") == args.strategy
    ]

    observation_lookup = {
        observation_key(row): row
        for row in observations
        if observation_key(row) is not None
    }
    h22_lookup = {
        observation_key(row): row
        for row in h22_candidates
        if observation_key(row) is not None
    }

    localized = []
    xyz_delta = []
    for source in h23_rows:
        key = observation_key(source)
        observation = observation_lookup.get(key)
        h22_source = h22_lookup.get(key)
        if observation is None or h22_source is None:
            continue

        distance = source.get("distance_mm")
        ratio = source.get("distance_normalized_ratio")
        if (
            not h2.finite(distance)
            or not (
                args.direct_tof_min_mm
                <= float(distance)
                <= args.direct_tof_max_mm
            )
            or not h2.finite(ratio)
            or float(ratio) <= 0.0
        ):
            continue

        accepted_xyz = observation.get("camera_xyz_mm")
        if (
            not isinstance(accepted_xyz, list)
            or len(accepted_xyz) != 3
            or not all(h2.finite(value) for value in accepted_xyz)
        ):
            continue

        recomputed_xyz = h21.tof_point_to_camera(
            profile,
            observation.get("column"),
            observation.get("row"),
            observation.get("distance_mm"),
        )
        if recomputed_xyz is None:
            continue

        delta_mm = math.sqrt(sum(
            (float(a) - float(b)) ** 2
            for a, b in zip(accepted_xyz, recomputed_xyz)
        ))
        xyz_delta.append(delta_mm)

        uv = h2.project_camera_point(camera, recomputed_xyz)
        if uv is None:
            continue
        width = float(camera["width"])
        height = float(camera["height"])
        nx = (float(uv[0]) - width * 0.5) / max(width * 0.5, 1.0)
        ny = (float(uv[1]) - height * 0.5) / max(height * 0.5, 1.0)

        row = dict(source)
        row.update({
            "type": "tof_dense_h24_localization",
            "schema_version": 1,
            "stage": "SFM-S01H2.4",
            "signed_ratio_residual": float(ratio) - 1.0,
            "signed_log_ratio_residual": math.log(float(ratio)),
            "projected_rgb_uv": [float(uv[0]), float(uv[1])],
            "projected_rgb_nx": nx,
            "projected_rgb_ny": ny,
            "projected_rgb_radius": math.hypot(nx, ny),
            "rgb_x_bucket": fixed_axis_bucket(nx),
            "rgb_y_bucket": fixed_axis_bucket(ny),
            "calibration_recomputed_camera_xyz_mm": recomputed_xyz,
            "accepted_vs_recomputed_xyz_delta_mm": delta_mm,
            "h22_scale_mm_per_colmap_unit": h22_source.get(
                "scale_mm_per_colmap_unit"
            ),
        })
        localized.append(row)

    report["input_counts"] = {
        "h1_observations": len(observations),
        "h22_strategy_candidates": len(h22_candidates),
        "h23_strategy_rows": len(h23_rows),
        "joined_localized_rows": len(localized),
    }
    report["calibration_recompute_consistency"] = {
        "count": len(xyz_delta),
        "xyz_delta_mm_p50": h2.percentile(xyz_delta, 0.50),
        "xyz_delta_mm_p95": h2.percentile(xyz_delta, 0.95),
        "xyz_delta_mm_max": max(xyz_delta) if xyz_delta else None,
    }

    if len(localized) < args.minimum_distance_count:
        return skip(
            args,
            report,
            "SKIPPED_INSUFFICIENT_SUPPORT",
            "Insufficient joined H1/H2.2/H2.3 rows.",
        )

    zone_summary, zone_spread, zone_signed_range = signed_group_summary(
        localized, "zone_index", args.minimum_zone_count
    )
    row_summary, row_spread, row_signed_range = signed_group_summary(
        localized, "zone_row", args.minimum_zone_count
    )
    column_summary, column_spread, column_signed_range = signed_group_summary(
        localized, "zone_column", args.minimum_zone_count
    )
    rgb_x_summary, rgb_x_spread, rgb_x_signed_range = signed_group_summary(
        localized, "rgb_x_bucket", args.minimum_zone_count
    )
    rgb_y_summary, rgb_y_spread, rgb_y_signed_range = signed_group_summary(
        localized, "rgb_y_bucket", args.minimum_zone_count
    )

    report["signed_localization"] = {
        "per_zone": zone_summary,
        "zone_grid_signed_ratio_residual_p50": zone_grid(zone_summary),
        "zone_index_ratio_spread": zone_spread,
        "zone_index_signed_median_range": zone_signed_range,
        "zone_row": row_summary,
        "zone_row_ratio_spread": row_spread,
        "zone_row_signed_median_range": row_signed_range,
        "zone_column": column_summary,
        "zone_column_ratio_spread": column_spread,
        "zone_column_signed_median_range": column_signed_range,
        "rgb_x_bucket": rgb_x_summary,
        "rgb_x_ratio_spread": rgb_x_spread,
        "rgb_x_signed_median_range": rgb_x_signed_range,
        "rgb_y_bucket": rgb_y_summary,
        "rgb_y_ratio_spread": rgb_y_spread,
        "rgb_y_signed_median_range": rgb_y_signed_range,
        "correlations": {
            "signed_log_vs_zone_row": pearson(
                localized, "signed_log_ratio_residual", "zone_row"
            ),
            "signed_log_vs_zone_column": pearson(
                localized, "signed_log_ratio_residual", "zone_column"
            ),
            "signed_log_vs_rgb_nx": pearson(
                localized, "signed_log_ratio_residual", "projected_rgb_nx"
            ),
            "signed_log_vs_rgb_ny": pearson(
                localized, "signed_log_ratio_residual", "projected_rgb_ny"
            ),
            "signed_log_vs_rgb_radius": pearson(
                localized,
                "signed_log_ratio_residual",
                "projected_rgb_radius",
            ),
        },
    }

    observations_by_image = defaultdict(list)
    baseline_keys = set()
    for row in localized:
        key = observation_key(row)
        if key is not None:
            baseline_keys.add(key)
    for key in baseline_keys:
        observation = observation_lookup.get(key)
        if observation is not None:
            observations_by_image[str(observation.get("image"))].append(
                observation
            )

    if args.dense_job_dir:
        variants = build_variants(profile, args)
        perturbation = run_active_perturbations(
            args.dense_job_dir,
            variants,
            observations_by_image,
            baseline_keys,
            args.minimum_distance_count,
            args.minimum_zone_count,
        )
    else:
        perturbation = {
            "status": "SKIPPED_DENSE_UNAVAILABLE",
            "reason": (
                "--dense-job-dir was not provided; active calibration "
                "perturbation resampling was not run."
            ),
            "calibration_mutation_enabled": False,
            "dense_depth_modified": False,
        }
    report["active_perturbation_sensitivity"] = perturbation

    rgb_signal = (
        (
            h2.finite(rgb_x_spread)
            and float(rgb_x_spread) >= args.rgb_spread_threshold
        )
        or (
            h2.finite(rgb_y_spread)
            and float(rgb_y_spread) >= args.rgb_spread_threshold
        )
    )

    active_perturbation_completed = (
        perturbation.get("status") == "MEASURED"
    )

    zone_supported = False
    best = perturbation.get("best_perturbation")
    if active_perturbation_completed and isinstance(best, dict):
        improvement = best.get("zone_structure_improvement_fraction")
        p95_ratio = best.get("p95_error_ratio_vs_baseline")
        support_ratio = best.get("support_ratio_vs_baseline")
        zone_supported = (
            h2.finite(improvement)
            and float(improvement) >= args.zone_improvement_threshold
            and h2.finite(p95_ratio)
            and float(p95_ratio) <= 1.10
            and h2.finite(support_ratio)
            and float(support_ratio) >= 0.90
        )

    # Passive localization cannot distinguish ToF-zone structure from RGB-image
    # structure because the 8x8 zone coordinates project monotonically into RGB
    # coordinates. Root-cause classification requires active perturbation
    # resampling against the original dense depth maps.
    if not active_perturbation_completed:
        decision = "INSUFFICIENT_SUPPORT"
    elif zone_supported and rgb_signal:
        decision = "MIXED_PATTERN_SUPPORTED"
    elif zone_supported:
        decision = "ZONE_ANGULAR_PATTERN_SUPPORTED"
    elif rgb_signal:
        decision = "RGB_IMAGE_REGION_PATTERN_SUPPORTED"
    else:
        decision = "INSUFFICIENT_SUPPORT"

    report["decision"] = {
        "classification": decision,
        "active_perturbation_completed": active_perturbation_completed,
        "zone_angular_perturbation_supported": zone_supported,
        "passive_rgb_image_region_pattern": rgb_signal,
        "rgb_image_region_pattern_supported": (
            rgb_signal and active_perturbation_completed
        ),
        "zone_improvement_threshold": args.zone_improvement_threshold,
        "rgb_spread_threshold": args.rgb_spread_threshold,
        "note": (
            "Passive localization is correlation evidence only. Final pattern "
            "classification requires active perturbation resampling against "
            "dense depth maps. No perturbation is written back to calibration."
        ),
    }

    report["status"] = "MEASURED"
    report["measurement_only"] = True
    report["calibration_mutation_enabled"] = False
    report["geometry_mutation_enabled"] = False
    report["ready_for_geometry_mutation"] = False
    report["sparse_model_modified"] = False
    report["camera_poses_modified"] = False
    report["points3d_modified"] = False
    report["dense_input_modified"] = False
    report["dense_depth_modified"] = False
    report["fusion_enabled"] = False

    write_outputs(args, report, localized)

    print(
        "INFO | TOF_DENSE_H24 | "
        f"status=MEASURED rows={len(localized)} "
        f"zone_row_spread={row_spread} "
        f"zone_column_spread={column_spread} "
        f"rgb_x_spread={rgb_x_spread} "
        f"rgb_y_spread={rgb_y_spread} "
        f"decision={decision} "
        "calibration_mutation=OFF geometry_mutation=OFF fusion=OFF"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
