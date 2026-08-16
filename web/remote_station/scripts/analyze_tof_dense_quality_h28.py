#!/usr/bin/env python3
import argparse
import json
import math
import statistics
from collections import defaultdict
from pathlib import Path

STAGE = "SFM-S01H2.8"
PRIMARY_STRATEGY = "geometric_footprint_p50"
QUALITY_METRICS = (
    "geometric_local_gradient_fraction",
    "geometric_photometric_relative_difference",
)
LOW_QUALITY_QUANTILE = 0.25
HIGH_QUALITY_QUANTILE = 0.75
MINIMUM_H27_CLASSIFICATION = "DENSE_LOCAL_STRUCTURE_FRAME_FIXED_EFFECT_SUPPORTED"

# Frozen result gates. They are declared before looking at H2.8 metric outcomes.
P50_ERROR_RATIO_TO_FULL_MAX = 0.90
P95_ERROR_RATIO_TO_FULL_MAX = 0.85
CONDITIONED_P50_RATIO_TO_FULL_MAX = 0.90
CONDITIONED_P95_RATIO_TO_FULL_MAX = 0.85
DEFORMATION_EXCESS_REDUCTION_MIN = 0.20


def finite(value):
    try:
        return math.isfinite(float(value))
    except Exception:
        return False


def percentile(values, q):
    data = sorted(float(v) for v in values if finite(v))
    if not data:
        return None
    if len(data) == 1:
        return data[0]
    pos = (len(data) - 1) * float(q)
    lo = int(math.floor(pos))
    hi = int(math.ceil(pos))
    if lo == hi:
        return data[lo]
    frac = pos - lo
    return data[lo] * (1.0 - frac) + data[hi] * frac


def safe_ratio(numerator, denominator):
    if not finite(numerator) or not finite(denominator):
        return None
    denominator = float(denominator)
    if denominator <= 1e-12:
        return None
    return float(numerator) / denominator


def load_json(path):
    return json.loads(Path(path).read_text(encoding="utf-8"))


def load_jsonl(path):
    rows = []
    with Path(path).open("r", encoding="utf-8") as handle:
        for line in handle:
            line = line.strip()
            if not line:
                continue
            row = json.loads(line)
            if row.get("type") == "metadata":
                continue
            rows.append(row)
    return rows


def observation_key(row):
    try:
        return (
            str(row.get("image")),
            int(row.get("tof_sequence")),
            int(row.get("zone_index")),
        )
    except Exception:
        return None


def distance_bucket(distance_mm):
    if not finite(distance_mm):
        return "unknown"
    value = float(distance_mm)
    if value < 500.0:
        return "0_0p5m"
    if value < 1000.0:
        return "0p5_1m"
    if value < 1500.0:
        return "1_1p5m"
    if value < 2000.0:
        return "1p5_2m"
    if value < 3000.0:
        return "2_3m"
    if value <= 4000.0:
        return "3_4m"
    return "beyond_4m"


def quality_thresholds(rows):
    thresholds = {}
    for metric in QUALITY_METRICS:
        values = [float(row[metric]) for row in rows if finite(row.get(metric))]
        thresholds[metric] = {
            "count": len(values),
            "low_q25": percentile(values, LOW_QUALITY_QUANTILE),
            "high_q75": percentile(values, HIGH_QUALITY_QUANTILE),
        }
    return thresholds


def quality_thresholds_valid(thresholds):
    for metric in QUALITY_METRICS:
        item = thresholds.get(metric) or {}
        low = item.get("low_q25")
        high = item.get("high_q75")
        if not finite(low) or not finite(high):
            return False
        if float(high) <= float(low) + 1e-12:
            return False
    return True


def assign_quality_groups(rows, thresholds):
    """Freeze membership from Dense-quality metrics only; no metric outcome leakage."""
    assigned = []
    for source in rows:
        if not all(finite(source.get(metric)) for metric in QUALITY_METRICS):
            continue
        is_clean = all(
            float(source[metric]) <= float(thresholds[metric]["low_q25"])
            for metric in QUALITY_METRICS
        )
        is_unstable = any(
            float(source[metric]) >= float(thresholds[metric]["high_q75"])
            for metric in QUALITY_METRICS
        )
        if is_clean:
            group = "CLEAN_DENSE"
        elif is_unstable:
            group = "UNSTABLE_DENSE"
        else:
            group = "MIDDLE_DENSE"
        row = dict(source)
        row["quality_group"] = group
        assigned.append(row)
    return assigned


def group_scale_summary(rows, field, minimum_group_count):
    grouped = defaultdict(list)
    for row in rows:
        key = str(row.get(field, "unknown"))
        if key == "unknown":
            continue
        if finite(row.get("scale_mm_per_colmap_unit")):
            grouped[key].append(float(row["scale_mm_per_colmap_unit"]))
    result = {}
    for key in sorted(grouped):
        values = grouped[key]
        result[key] = {
            "count": len(values),
            "scale_median_mm_per_colmap_unit": statistics.median(values),
            "eligible_for_spread": len(values) >= minimum_group_count,
        }
    return result


def spread_ratio(grouped):
    medians = [
        float(item["scale_median_mm_per_colmap_unit"])
        for item in grouped.values()
        if item.get("eligible_for_spread")
        and finite(item.get("scale_median_mm_per_colmap_unit"))
    ]
    if len(medians) < 2 or min(medians) <= 0.0:
        return None
    return max(medians) / min(medians)


def evaluate_subset(rows, minimum_group_count):
    valid = [
        row for row in rows
        if finite(row.get("scale_mm_per_colmap_unit"))
        and float(row["scale_mm_per_colmap_unit"]) > 0.0
        and finite(row.get("dense_depth_units"))
        and float(row["dense_depth_units"]) > 0.0
        and finite(row.get("tof_camera_z_mm"))
        and float(row["tof_camera_z_mm"]) > 0.0
    ]
    if not valid:
        return {
            "count": 0,
            "robust_scale_mm_per_colmap_unit": None,
            "metric_error": {},
            "conditioned_error": {},
            "deformation": {},
        }

    scales = [float(row["scale_mm_per_colmap_unit"]) for row in valid]
    robust_scale = statistics.median(scales)
    scale_deviation = [abs(value - robust_scale) / robust_scale for value in scales]
    errors_mm = []
    relative_errors = []
    conditioned_errors = []
    absolute_log_errors = []
    for row in valid:
        predicted = float(row["dense_depth_units"]) * robust_scale
        error = abs(predicted - float(row["tof_camera_z_mm"]))
        errors_mm.append(error)
        relative_errors.append(error / float(row["tof_camera_z_mm"]))
        if finite(row.get("zone_distance_absolute_log_residual")):
            conditioned_errors.append(float(row["zone_distance_absolute_log_residual"]))
        if finite(row.get("absolute_log_residual")):
            absolute_log_errors.append(float(row["absolute_log_residual"]))

    distance = group_scale_summary(valid, "distance_bucket", minimum_group_count)
    zone_row = group_scale_summary(valid, "zone_row", minimum_group_count)
    zone_column = group_scale_summary(valid, "zone_column", minimum_group_count)

    return {
        "count": len(valid),
        "robust_scale_mm_per_colmap_unit": robust_scale,
        "scale_mad_relative": statistics.median(scale_deviation),
        "metric_error": {
            "depth_error_p50_mm": percentile(errors_mm, 0.50),
            "depth_error_p95_mm": percentile(errors_mm, 0.95),
            "relative_error_p50": percentile(relative_errors, 0.50),
            "relative_error_p95": percentile(relative_errors, 0.95),
        },
        "conditioned_error": {
            "absolute_log_residual_p50": percentile(absolute_log_errors, 0.50),
            "absolute_log_residual_p95": percentile(absolute_log_errors, 0.95),
            "zone_distance_absolute_log_residual_p50": percentile(conditioned_errors, 0.50),
            "zone_distance_absolute_log_residual_p95": percentile(conditioned_errors, 0.95),
            "zone_distance_supported_count": len(conditioned_errors),
        },
        "deformation": {
            "distance_scale_spread_ratio": spread_ratio(distance),
            "zone_row_scale_spread_ratio": spread_ratio(zone_row),
            "zone_column_scale_spread_ratio": spread_ratio(zone_column),
        },
        "decomposition": {
            "distance": distance,
            "zone_row": zone_row,
            "zone_column": zone_column,
        },
    }


def build_zone_distance_baseline(rows, minimum_stratum_count=6):
    grouped = defaultdict(list)
    for row in rows:
        if not finite(row.get("distance_normalized_ratio")):
            continue
        value = float(row["distance_normalized_ratio"])
        if value <= 0.0:
            continue
        key = (
            str(row.get("distance_bucket", distance_bucket(row.get("distance_mm")))),
            str(row.get("zone_index")),
        )
        grouped[key].append(math.log(value))
    baseline = {}
    for key, values in grouped.items():
        if len(values) >= minimum_stratum_count:
            baseline[key] = {
                "count": len(values),
                "signed_log_median": statistics.median(values),
            }
    return baseline


def attach_zone_distance_residuals(rows, baseline):
    result = []
    supported = 0
    for source in rows:
        row = dict(source)
        key = (
            str(row.get("distance_bucket", distance_bucket(row.get("distance_mm")))),
            str(row.get("zone_index")),
        )
        item = baseline.get(key)
        value = row.get("distance_normalized_ratio")
        if item is not None and finite(value) and float(value) > 0.0:
            residual = math.log(float(value)) - float(item["signed_log_median"])
            row["zone_distance_absolute_log_residual"] = abs(residual)
            supported += 1
        else:
            row["zone_distance_absolute_log_residual"] = None
        result.append(row)
    return result, supported


def excess_reduction(full_spread, clean_spread):
    if not finite(full_spread) or not finite(clean_spread):
        return None
    full_excess = max(float(full_spread) - 1.0, 0.0)
    clean_excess = max(float(clean_spread) - 1.0, 0.0)
    if full_excess <= 1e-9:
        return None
    return 1.0 - clean_excess / full_excess


def compare_evaluations(full, clean, unstable):
    full_error = full.get("metric_error", {})
    clean_error = clean.get("metric_error", {})
    unstable_error = unstable.get("metric_error", {})
    p50_full_ratio = safe_ratio(clean_error.get("depth_error_p50_mm"), full_error.get("depth_error_p50_mm"))
    p95_full_ratio = safe_ratio(clean_error.get("depth_error_p95_mm"), full_error.get("depth_error_p95_mm"))
    p50_unstable_ratio = safe_ratio(clean_error.get("depth_error_p50_mm"), unstable_error.get("depth_error_p50_mm"))
    p95_unstable_ratio = safe_ratio(clean_error.get("depth_error_p95_mm"), unstable_error.get("depth_error_p95_mm"))

    full_cond = full.get("conditioned_error", {})
    clean_cond = clean.get("conditioned_error", {})
    unstable_cond = unstable.get("conditioned_error", {})
    cond_p50_full_ratio = safe_ratio(clean_cond.get("zone_distance_absolute_log_residual_p50"), full_cond.get("zone_distance_absolute_log_residual_p50"))
    cond_p95_full_ratio = safe_ratio(clean_cond.get("zone_distance_absolute_log_residual_p95"), full_cond.get("zone_distance_absolute_log_residual_p95"))
    cond_p50_unstable_ratio = safe_ratio(clean_cond.get("zone_distance_absolute_log_residual_p50"), unstable_cond.get("zone_distance_absolute_log_residual_p50"))
    cond_p95_unstable_ratio = safe_ratio(clean_cond.get("zone_distance_absolute_log_residual_p95"), unstable_cond.get("zone_distance_absolute_log_residual_p95"))

    raw_error_improved = (
        finite(p50_full_ratio) and float(p50_full_ratio) <= P50_ERROR_RATIO_TO_FULL_MAX
        and finite(p95_full_ratio) and float(p95_full_ratio) <= P95_ERROR_RATIO_TO_FULL_MAX
        and finite(p50_unstable_ratio) and float(p50_unstable_ratio) < 1.0
        and finite(p95_unstable_ratio) and float(p95_unstable_ratio) < 1.0
    )
    conditioned_error_improved = (
        finite(cond_p50_full_ratio) and float(cond_p50_full_ratio) <= CONDITIONED_P50_RATIO_TO_FULL_MAX
        and finite(cond_p95_full_ratio) and float(cond_p95_full_ratio) <= CONDITIONED_P95_RATIO_TO_FULL_MAX
        and finite(cond_p50_unstable_ratio) and float(cond_p50_unstable_ratio) < 1.0
        and finite(cond_p95_unstable_ratio) and float(cond_p95_unstable_ratio) < 1.0
    )

    deformation = {}
    deformation_signal_count = 0
    available_deformation_count = 0
    for name in (
        "distance_scale_spread_ratio",
        "zone_row_scale_spread_ratio",
        "zone_column_scale_spread_ratio",
    ):
        full_spread = full.get("deformation", {}).get(name)
        clean_spread = clean.get("deformation", {}).get(name)
        reduction = excess_reduction(full_spread, clean_spread)
        supported = finite(reduction) and float(reduction) >= DEFORMATION_EXCESS_REDUCTION_MIN
        if finite(reduction):
            available_deformation_count += 1
        if supported:
            deformation_signal_count += 1
        deformation[name] = {
            "full": full_spread,
            "clean": clean_spread,
            "unstable": unstable.get("deformation", {}).get(name),
            "clean_excess_reduction_fraction_vs_full": reduction,
            "reduction_signal": supported,
        }

    return {
        "raw_metric_error": {
            "clean_to_full_p50_ratio": p50_full_ratio,
            "clean_to_full_p95_ratio": p95_full_ratio,
            "clean_to_unstable_p50_ratio": p50_unstable_ratio,
            "clean_to_unstable_p95_ratio": p95_unstable_ratio,
            "improvement_signal": raw_error_improved,
        },
        "zone_distance_conditioned_error": {
            "clean_to_full_p50_ratio": cond_p50_full_ratio,
            "clean_to_full_p95_ratio": cond_p95_full_ratio,
            "clean_to_unstable_p50_ratio": cond_p50_unstable_ratio,
            "clean_to_unstable_p95_ratio": cond_p95_unstable_ratio,
            "improvement_signal": conditioned_error_improved,
        },
        "deformation": deformation,
        "deformation_signal_count": deformation_signal_count,
        "available_deformation_count": available_deformation_count,
    }


def classify_gate(comparison, support_ok):
    if not support_ok:
        return "INSUFFICIENT_SUPPORT"
    raw = comparison["raw_metric_error"]["improvement_signal"]
    conditioned = comparison["zone_distance_conditioned_error"]["improvement_signal"]
    signals = int(comparison["deformation_signal_count"])
    available = int(comparison["available_deformation_count"])
    if raw and conditioned and available == 3 and signals == 3:
        return "DENSE_QUALITY_GATE_SUPPORTED"
    if raw and conditioned and available >= 2 and signals >= 2:
        return "DENSE_QUALITY_GATE_PARTIAL_SUPPORT"
    if (raw or conditioned) and signals >= 1:
        return "DENSE_QUALITY_GATE_PARTIAL_SUPPORT"
    return "DENSE_QUALITY_GATE_NOT_SUPPORTED"


def base_report(args):
    return {
        "schema_version": 1,
        "stage": STAGE,
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
        "selection_uses_metric_residuals": False,
        "parameters": {
            "strategy": args.strategy,
            "quality_low_quantile": LOW_QUALITY_QUANTILE,
            "quality_high_quantile": HIGH_QUALITY_QUANTILE,
            "minimum_subset_count": args.minimum_subset_count,
            "minimum_group_count": args.minimum_group_count,
            "minimum_join_ratio": args.minimum_join_ratio,
            "zone_distance_baseline_minimum_stratum_count": args.minimum_zone_distance_stratum_count,
        },
        "purpose": (
            "Preselect CLEAN_DENSE and UNSTABLE_DENSE using Dense-quality metrics only, "
            "then independently test metric error and scale deformation. This is a "
            "quality-gating experiment, not a post-hoc residual split."
        ),
        "selection_contract": {
            "quality_metrics": list(QUALITY_METRICS),
            "clean_rule": "both quality metrics <= their frozen global Q25 thresholds",
            "unstable_rule": "either quality metric >= its frozen global Q75 threshold",
            "middle_rule": "all other quality-complete rows",
            "metric_residual_fields_forbidden_from_membership": [
                "absolute_log_residual",
                "distance_normalized_ratio",
                "scale_mm_per_colmap_unit",
                "depth_residual_mm",
                "depth_relative_error",
                "inlier",
            ],
            "selection_uses_metric_residuals": False,
        },
        "success_gate": {
            "p50_error_ratio_to_full_max": P50_ERROR_RATIO_TO_FULL_MAX,
            "p95_error_ratio_to_full_max": P95_ERROR_RATIO_TO_FULL_MAX,
            "conditioned_p50_ratio_to_full_max": CONDITIONED_P50_RATIO_TO_FULL_MAX,
            "conditioned_p95_ratio_to_full_max": CONDITIONED_P95_RATIO_TO_FULL_MAX,
            "deformation_excess_reduction_min": DEFORMATION_EXCESS_REDUCTION_MIN,
            "supported": (
                "raw error improves AND zone+distance-conditioned error improves AND "
                "distance/zone-row/zone-column deformation all reduce by the predeclared minimum"
            ),
        },
        "next_gate": (
            "H2.8 is measurement-only. Even DENSE_QUALITY_GATE_SUPPORTED does not open "
            "S01H.3 automatically and does not permit ToF geometry mutation or fusion."
        ),
    }


def write_outputs(args, report, rows):
    report_path = Path(args.report_json)
    report_path.parent.mkdir(parents=True, exist_ok=True)
    report_path.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    output_path = Path(args.output_jsonl)
    output_path.parent.mkdir(parents=True, exist_ok=True)
    with output_path.open("w", encoding="utf-8") as handle:
        handle.write(json.dumps({
            "type": "metadata",
            "schema_version": 1,
            "stage": STAGE,
            "status": report["status"],
            "measurement_only": True,
            "selection_uses_metric_residuals": False,
            "geometry_mutation_enabled": False,
            "dense_depth_modified": False,
            "fusion_enabled": False,
        }) + "\n")
        for row in rows:
            compact = {
                "type": "tof_dense_h28_quality_gate",
                "schema_version": 1,
                "stage": STAGE,
                "image": row.get("image"),
                "tof_sequence": row.get("tof_sequence"),
                "zone_index": row.get("zone_index"),
                "zone_row": row.get("zone_row"),
                "zone_column": row.get("zone_column"),
                "distance_mm": row.get("distance_mm"),
                "distance_bucket": row.get("distance_bucket"),
                "quality_group": row.get("quality_group"),
                "geometric_local_gradient_fraction": row.get("geometric_local_gradient_fraction"),
                "geometric_photometric_relative_difference": row.get("geometric_photometric_relative_difference"),
                "scale_mm_per_colmap_unit": row.get("scale_mm_per_colmap_unit"),
                "dense_depth_units": row.get("dense_depth_units"),
                "tof_camera_z_mm": row.get("tof_camera_z_mm"),
                "absolute_log_residual": row.get("absolute_log_residual"),
                "zone_distance_absolute_log_residual": row.get("zone_distance_absolute_log_residual"),
            }
            handle.write(json.dumps(compact, ensure_ascii=False) + "\n")


def skip(args, report, status, reason):
    report["status"] = status
    report["skip_reason"] = reason
    write_outputs(args, report, [])
    print(
        "INFO | TOF_DENSE_H28 | "
        f"status={status} measurement_only=yes selection_metric_leakage=NO "
        "geometry_mutation=OFF fusion=OFF"
    )
    return 0


def h27_quality_support(report):
    if report.get("status") != "MEASURED":
        return False, []
    decision = report.get("decision") or {}
    if decision.get("classification") != MINIMUM_H27_CLASSIFICATION:
        return False, []
    structure = report.get("frame_fixed_effect_dense_structure") or {}
    supported = list(structure.get("supported_metrics") or [])
    return all(metric in supported for metric in QUALITY_METRICS), supported


def join_quality_and_h22(quality_rows, h22_rows, strategy):
    h22_lookup = {}
    duplicate_h22_keys = 0
    for row in h22_rows:
        if row.get("strategy") != strategy:
            continue
        key = observation_key(row)
        if key is None:
            continue
        if key in h22_lookup:
            duplicate_h22_keys += 1
            continue
        h22_lookup[key] = row

    joined = []
    missing_h22 = 0
    for quality in quality_rows:
        key = observation_key(quality)
        if key is None:
            continue
        candidate = h22_lookup.get(key)
        if candidate is None:
            missing_h22 += 1
            continue
        row = dict(quality)
        for field in (
            "strategy",
            "distance_bucket",
            "scale_mm_per_colmap_unit",
            "dense_depth_units",
            "tof_camera_z_mm",
        ):
            if field in candidate:
                row[field] = candidate[field]
        joined.append(row)

    return joined, {
        "strategy_candidate_count": len(h22_lookup),
        "joined_count": len(joined),
        "quality_rows_missing_h22": missing_h22,
        "duplicate_h22_keys_ignored": duplicate_h22_keys,
    }


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--h26-rows", required=True)
    parser.add_argument("--h26-report", required=True)
    parser.add_argument("--h27-report", required=True)
    parser.add_argument("--h22-candidates", required=True)
    parser.add_argument("--output-jsonl", required=True)
    parser.add_argument("--report-json", required=True)
    parser.add_argument("--strategy", default=PRIMARY_STRATEGY)
    parser.add_argument("--minimum-subset-count", type=int, default=150)
    parser.add_argument("--minimum-group-count", type=int, default=15)
    parser.add_argument("--minimum-join-ratio", type=float, default=0.90)
    parser.add_argument("--minimum-zone-distance-stratum-count", type=int, default=6)
    args = parser.parse_args()

    if args.minimum_subset_count < 50:
        raise SystemExit("minimum-subset-count must be >= 50")
    if args.minimum_group_count < 5:
        raise SystemExit("minimum-group-count must be >= 5")
    if not (0.50 <= args.minimum_join_ratio <= 1.0):
        raise SystemExit("minimum-join-ratio must be in [0.50,1.0]")
    if args.minimum_zone_distance_stratum_count < 4:
        raise SystemExit("minimum-zone-distance-stratum-count must be >= 4")

    report = base_report(args)
    h26_report = load_json(args.h26_report)
    if h26_report.get("status") != "MEASURED":
        return skip(args, report, "SKIPPED_H26_UNAVAILABLE", "S01H.2.6 report is unavailable or not MEASURED.")

    h27_report = load_json(args.h27_report)
    h27_supported, h27_metrics = h27_quality_support(h27_report)
    report["h27_supported_metrics"] = h27_metrics
    if not h27_supported:
        return skip(
            args,
            report,
            "SKIPPED_H27_QUALITY_SUPPORT_UNAVAILABLE",
            "H2.8 requires H2.7 frame-fixed-effect support for both frozen Dense-quality selection metrics.",
        )

    h26_rows = load_jsonl(args.h26_rows)
    h22_rows = load_jsonl(args.h22_candidates)
    if not h26_rows or not h22_rows:
        return skip(args, report, "SKIPPED_INPUT_ROWS_UNAVAILABLE", "H2.6 conditioned rows or H2.2 candidates are unavailable.")

    thresholds = quality_thresholds(h26_rows)
    report["quality_selection"] = {
        "quality_metrics": list(QUALITY_METRICS),
        "low_quantile": LOW_QUALITY_QUANTILE,
        "high_quantile": HIGH_QUALITY_QUANTILE,
        "thresholds": thresholds,
        "selection_uses_metric_residuals": False,
    }
    if not quality_thresholds_valid(thresholds):
        return skip(
            args,
            report,
            "SKIPPED_QUALITY_VARIATION_INSUFFICIENT",
            "Frozen Dense-quality metrics do not have usable Q25<Q75 variation.",
        )

    quality_assigned = assign_quality_groups(h26_rows, thresholds)
    joined, join_info = join_quality_and_h22(quality_assigned, h22_rows, args.strategy)
    quality_complete_count = len(quality_assigned)
    join_ratio = len(joined) / quality_complete_count if quality_complete_count > 0 else 0.0
    join_info["quality_complete_count"] = quality_complete_count
    join_info["join_ratio"] = join_ratio
    report["input_counts"] = {
        "h26_rows": len(h26_rows),
        "h22_rows_all_strategies": len(h22_rows),
        **join_info,
    }
    if join_ratio < args.minimum_join_ratio:
        return skip(
            args,
            report,
            "SKIPPED_JOIN_SUPPORT_INSUFFICIENT",
            f"H2.6<->H2.2 join ratio {join_ratio:.6f} is below {args.minimum_join_ratio:.6f}.",
        )

    baseline = build_zone_distance_baseline(joined, args.minimum_zone_distance_stratum_count)
    joined, conditioned_count = attach_zone_distance_residuals(joined, baseline)
    report["zone_distance_evaluation_baseline"] = {
        "stratum_count": len(baseline),
        "supported_row_count": conditioned_count,
        "minimum_stratum_count": args.minimum_zone_distance_stratum_count,
        "note": (
            "This baseline is used only for outcome evaluation after quality membership is frozen. "
            "It cannot affect CLEAN/UNSTABLE membership."
        ),
    }

    groups = defaultdict(list)
    for row in joined:
        groups[row["quality_group"]].append(row)

    full_eval = evaluate_subset(joined, args.minimum_group_count)
    clean_eval = evaluate_subset(groups.get("CLEAN_DENSE", []), args.minimum_group_count)
    unstable_eval = evaluate_subset(groups.get("UNSTABLE_DENSE", []), args.minimum_group_count)
    middle_eval = evaluate_subset(groups.get("MIDDLE_DENSE", []), args.minimum_group_count)

    support_ok = (
        clean_eval.get("count", 0) >= args.minimum_subset_count
        and unstable_eval.get("count", 0) >= args.minimum_subset_count
        and conditioned_count >= args.minimum_subset_count
    )
    comparison = compare_evaluations(full_eval, clean_eval, unstable_eval)
    classification = classify_gate(comparison, support_ok)

    report["quality_selection"]["group_counts"] = {
        "CLEAN_DENSE": len(groups.get("CLEAN_DENSE", [])),
        "MIDDLE_DENSE": len(groups.get("MIDDLE_DENSE", [])),
        "UNSTABLE_DENSE": len(groups.get("UNSTABLE_DENSE", [])),
    }
    report["evaluation"] = {
        "FULL": full_eval,
        "CLEAN_DENSE": clean_eval,
        "MIDDLE_DENSE": middle_eval,
        "UNSTABLE_DENSE": unstable_eval,
        "comparison": comparison,
    }
    report["decision"] = {
        "classification": classification,
        "support_gate_pass": support_ok,
        "selection_uses_metric_residuals": False,
        "dense_quality_gate_supported": classification == "DENSE_QUALITY_GATE_SUPPORTED",
        "dense_quality_gate_partial_support": classification == "DENSE_QUALITY_GATE_PARTIAL_SUPPORT",
        "note": (
            "Quality membership is frozen before metric evaluation. H2.8 can support high-confidence "
            "Dense<->ToF anchors, but it cannot modify geometry or open S01H.3 automatically."
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
    report["selection_uses_metric_residuals"] = False

    write_outputs(args, report, joined)
    print(
        "INFO | TOF_DENSE_H28 | "
        f"status=MEASURED decision={classification} full={full_eval.get('count',0)} "
        f"clean={clean_eval.get('count',0)} unstable={unstable_eval.get('count',0)} "
        f"raw_error_signal={comparison['raw_metric_error']['improvement_signal']} "
        f"conditioned_error_signal={comparison['zone_distance_conditioned_error']['improvement_signal']} "
        f"deformation_signals={comparison['deformation_signal_count']}/{comparison['available_deformation_count']} "
        "selection_metric_leakage=NO geometry_mutation=OFF fusion=OFF"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
