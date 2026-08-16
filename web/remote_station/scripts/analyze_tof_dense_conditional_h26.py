#!/usr/bin/env python3
import argparse
import json
import math
import statistics
from collections import defaultdict
from pathlib import Path


METRICS = (
    "geometric_footprint_iqr_fraction",
    "geometric_local_gradient_fraction",
    "geometric_photometric_relative_difference",
)


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


def load_json(path):
    return json.loads(Path(path).read_text(encoding="utf-8"))


def load_jsonl(path, expected_type=None):
    rows = []
    with Path(path).open("r", encoding="utf-8") as handle:
        for line in handle:
            line = line.strip()
            if not line:
                continue
            row = json.loads(line)
            if row.get("type") == "metadata":
                continue
            if expected_type and row.get("type") != expected_type:
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


def average_ranks(values):
    indexed = sorted(
        enumerate(float(v) for v in values),
        key=lambda item: item[1],
    )
    ranks = [0.0] * len(indexed)
    start = 0
    while start < len(indexed):
        end = start + 1
        while end < len(indexed) and indexed[end][1] == indexed[start][1]:
            end += 1
        rank = (start + 1 + end) * 0.5
        for index in range(start, end):
            ranks[indexed[index][0]] = rank
        start = end
    return ranks


def pearson(xs, ys):
    if len(xs) != len(ys) or len(xs) < 3:
        return None
    mx = statistics.mean(xs)
    my = statistics.mean(ys)
    numerator = sum((x - mx) * (y - my) for x, y in zip(xs, ys))
    dx = math.sqrt(sum((x - mx) ** 2 for x in xs))
    dy = math.sqrt(sum((y - my) ** 2 for y in ys))
    if dx <= 1e-12 or dy <= 1e-12:
        return None
    return numerator / (dx * dy)


def one_sided_sign_test_pvalue(positive, nonzero):
    if nonzero <= 0:
        return None
    positive = int(positive)
    nonzero = int(nonzero)
    numerator = sum(
        math.comb(nonzero, k)
        for k in range(positive, nonzero + 1)
    )
    return numerator / (2 ** nonzero)


def stratum_key(row, include_time):
    parts = [
        distance_bucket(row.get("distance_mm")),
        str(row.get("zone_index")),
    ]
    if include_time:
        parts.append(str(row.get("time_quartile", "unknown")))
    return "|".join(parts)


def analyze_metric(
    rows,
    metric,
    minimum_stratum_count,
    minimum_strata,
    effect_ratio_threshold,
    consistency_threshold,
    sign_test_p_threshold,
    include_time,
):
    grouped = defaultdict(list)
    for row in rows:
        if not finite(row.get(metric)):
            continue
        if not finite(row.get("absolute_log_residual")):
            continue
        grouped[stratum_key(row, include_time)].append(row)

    strata = []
    pooled_metric_rank = []
    pooled_error_rank = []

    for key, values in sorted(grouped.items()):
        if len(values) < minimum_stratum_count:
            continue

        metric_values = [float(row[metric]) for row in values]
        error_values = [float(row["absolute_log_residual"]) for row in values]
        threshold = statistics.median(metric_values)

        low = [
            error
            for metric_value, error in zip(metric_values, error_values)
            if metric_value <= threshold
        ]
        high = [
            error
            for metric_value, error in zip(metric_values, error_values)
            if metric_value > threshold
        ]
        if len(low) < 3 or len(high) < 3:
            continue

        low_p50 = statistics.median(low)
        high_p50 = statistics.median(high)
        ratio = (
            high_p50 / low_p50
            if low_p50 > 1e-12
            else None
        )
        log_effect = (
            math.log(ratio)
            if finite(ratio) and float(ratio) > 0.0
            else None
        )

        metric_ranks = average_ranks(metric_values)
        error_ranks = average_ranks(error_values)
        n = float(len(values))
        pooled_metric_rank.extend(
            (rank - (n + 1.0) * 0.5) / max(n, 1.0)
            for rank in metric_ranks
        )
        pooled_error_rank.extend(
            (rank - (n + 1.0) * 0.5) / max(n, 1.0)
            for rank in error_ranks
        )

        strata.append({
            "stratum": key,
            "count": len(values),
            "metric_median": threshold,
            "low_count": len(low),
            "high_count": len(high),
            "low_error_p50": low_p50,
            "high_error_p50": high_p50,
            "high_to_low_error_ratio": ratio,
            "log_effect": log_effect,
        })

    effects = [
        float(item["log_effect"])
        for item in strata
        if finite(item.get("log_effect"))
    ]
    positive = sum(value > 0.0 for value in effects)
    negative = sum(value < 0.0 for value in effects)
    nonzero = positive + negative
    consistency = positive / nonzero if nonzero > 0 else None
    aggregate_ratio = (
        math.exp(statistics.median(effects))
        if effects
        else None
    )
    sign_p = one_sided_sign_test_pvalue(positive, nonzero)
    within_stratum_spearman = pearson(
        pooled_metric_rank,
        pooled_error_rank,
    )

    supported = (
        len(strata) >= minimum_strata
        and finite(aggregate_ratio)
        and float(aggregate_ratio) >= effect_ratio_threshold
        and finite(consistency)
        and float(consistency) >= consistency_threshold
        and finite(sign_p)
        and float(sign_p) <= sign_test_p_threshold
    )

    return {
        "metric": metric,
        "conditioning": (
            "distance_bucket+zone_index+time_quartile"
            if include_time
            else "distance_bucket+zone_index"
        ),
        "supported_stratum_count": len(strata),
        "row_count_in_supported_strata": sum(item["count"] for item in strata),
        "aggregate_high_to_low_error_ratio": aggregate_ratio,
        "positive_effect_strata": positive,
        "negative_effect_strata": negative,
        "positive_effect_consistency": consistency,
        "one_sided_sign_test_pvalue": sign_p,
        "pooled_within_stratum_spearman": within_stratum_spearman,
        "conditional_signal": supported,
        "strata": strata,
    }


def base_report(args):
    return {
        "schema_version": 1,
        "stage": "SFM-S01H2.6",
        "status": "STARTING",
        "measurement_only": True,
        "geometry_mutation_enabled": False,
        "ready_for_geometry_mutation": False,
        "camera_model_mutation_enabled": False,
        "calibration_mutation_enabled": False,
        "sparse_model_modified": False,
        "camera_poses_modified": False,
        "points3d_modified": False,
        "dense_input_modified": False,
        "dense_depth_modified": False,
        "fusion_enabled": False,
        "parameters": {
            "minimum_stratum_count": args.minimum_stratum_count,
            "minimum_strata": args.minimum_strata,
            "effect_ratio_threshold": args.effect_ratio_threshold,
            "consistency_threshold": args.consistency_threshold,
            "sign_test_p_threshold": args.sign_test_p_threshold,
        },
        "purpose": (
            "Test whether H2.5 dense-local metrics remain associated with residual "
            "magnitude after conditioning on ToF distance and zone, instead of "
            "lowering H2.5 thresholds post hoc."
        ),
        "next_gate": (
            "If conditional dense-local structure is supported, investigate "
            "PatchMatch/dense depth deformation. Otherwise keep root cause open. "
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
            "stage": "SFM-S01H2.6",
            "status": report["status"],
            "measurement_only": True,
            "geometry_mutation_enabled": False,
            "dense_depth_modified": False,
            "fusion_enabled": False,
        }) + "\n")
        for row in rows:
            handle.write(json.dumps(row, ensure_ascii=False) + "\n")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--h25-structure", required=True)
    parser.add_argument("--h25-report", required=True)
    parser.add_argument("--h23-decomposition", required=True)
    parser.add_argument("--output-jsonl", required=True)
    parser.add_argument("--report-json", required=True)
    parser.add_argument("--minimum-stratum-count", type=int, default=12)
    parser.add_argument("--minimum-strata", type=int, default=12)
    parser.add_argument("--effect-ratio-threshold", type=float, default=1.15)
    parser.add_argument("--consistency-threshold", type=float, default=0.65)
    parser.add_argument("--sign-test-p-threshold", type=float, default=0.01)
    args = parser.parse_args()

    if args.minimum_stratum_count < 6:
        raise SystemExit("minimum-stratum-count must be >= 6")
    if args.minimum_strata < 3:
        raise SystemExit("minimum-strata must be >= 3")
    if args.effect_ratio_threshold <= 1.0:
        raise SystemExit("effect-ratio-threshold must be > 1")
    if not (0.5 < args.consistency_threshold <= 1.0):
        raise SystemExit("consistency-threshold must be in (0.5,1]")
    if not (0.0 < args.sign_test_p_threshold <= 0.1):
        raise SystemExit("sign-test-p-threshold must be in (0,0.1]")

    report = base_report(args)
    h25_report = load_json(args.h25_report)
    if h25_report.get("status") != "MEASURED":
        report["status"] = "SKIPPED_H25_UNAVAILABLE"
        report["skip_reason"] = "S01H.2.5 report is unavailable or not MEASURED."
        write_outputs(args, report, [])
        print(
            "INFO | TOF_DENSE_H26 | status=SKIPPED_H25_UNAVAILABLE "
            "measurement_only=yes geometry_mutation=OFF fusion=OFF"
        )
        return 0

    h25_rows = load_jsonl(
        args.h25_structure,
        "tof_dense_h25_dense_structure",
    )
    h23_rows = load_jsonl(
        args.h23_decomposition,
        "tof_dense_h23_decomposition",
    )
    h23_lookup = {
        observation_key(row): row
        for row in h23_rows
        if observation_key(row) is not None
    }

    enriched = []
    for source in h25_rows:
        key = observation_key(source)
        h23 = h23_lookup.get(key)
        row = dict(source)
        if h23 is not None:
            row["time_quartile"] = h23.get("time_quartile")
            row["image_region"] = h23.get("image_region")
            row["distance_bucket_h23"] = h23.get("distance_bucket_h23")
        enriched.append(row)

    report["input_counts"] = {
        "h25_structure_rows": len(h25_rows),
        "h23_decomposition_rows": len(h23_rows),
        "enriched_rows": len(enriched),
    }

    analyses = {}
    signal_metrics = []
    time_conditioned_signal_metrics = []

    for metric in METRICS:
        primary = analyze_metric(
            enriched,
            metric,
            args.minimum_stratum_count,
            args.minimum_strata,
            args.effect_ratio_threshold,
            args.consistency_threshold,
            args.sign_test_p_threshold,
            False,
        )
        time_conditioned = analyze_metric(
            enriched,
            metric,
            args.minimum_stratum_count,
            args.minimum_strata,
            args.effect_ratio_threshold,
            args.consistency_threshold,
            args.sign_test_p_threshold,
            True,
        )
        analyses[metric] = {
            "distance_zone_conditioned": primary,
            "distance_zone_time_conditioned": time_conditioned,
        }
        if primary["conditional_signal"]:
            signal_metrics.append(metric)
        if time_conditioned["conditional_signal"]:
            time_conditioned_signal_metrics.append(metric)

    primary_supported = len(signal_metrics) >= 2
    time_supported = len(time_conditioned_signal_metrics) >= 2
    dense_conditional_supported = primary_supported and time_supported

    if dense_conditional_supported:
        classification = "DENSE_LOCAL_STRUCTURE_CONDITIONAL_SUPPORTED"
    elif primary_supported or time_supported:
        classification = "DENSE_LOCAL_STRUCTURE_PARTIAL_SUPPORT"
    else:
        classification = "INSUFFICIENT_SUPPORT"

    report["conditional_dense_structure"] = {
        "metrics": analyses,
        "distance_zone_signal_metrics": signal_metrics,
        "distance_zone_time_signal_metrics": time_conditioned_signal_metrics,
        "distance_zone_supported": primary_supported,
        "distance_zone_time_supported": time_supported,
        "dense_local_structure_conditional_supported": dense_conditional_supported,
    }
    report["decision"] = {
        "classification": classification,
        "dense_local_structure_conditional_supported": dense_conditional_supported,
        "required_metric_signal_count": 2,
        "note": (
            "H2.6 does not change H2.5 thresholds. It asks whether the raw H2.5 "
            "high/low effects survive matched conditioning on distance and zone "
            "(and separately time quartile)."
        ),
    }

    report["status"] = "MEASURED"
    report["measurement_only"] = True
    report["geometry_mutation_enabled"] = False
    report["ready_for_geometry_mutation"] = False
    report["camera_model_mutation_enabled"] = False
    report["calibration_mutation_enabled"] = False
    report["sparse_model_modified"] = False
    report["camera_poses_modified"] = False
    report["points3d_modified"] = False
    report["dense_input_modified"] = False
    report["dense_depth_modified"] = False
    report["fusion_enabled"] = False

    write_outputs(args, report, enriched)

    print(
        "INFO | TOF_DENSE_H26 | "
        f"status=MEASURED decision={classification} "
        f"distance_zone_signals={len(signal_metrics)} "
        f"distance_zone_time_signals={len(time_conditioned_signal_metrics)} "
        "geometry_mutation=OFF fusion=OFF"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
