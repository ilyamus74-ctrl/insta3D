#!/usr/bin/env python3
import argparse
import json
import math
import random
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


def average_ranks(values):
    indexed = sorted(
        enumerate(float(value) for value in values),
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


def spearman(xs, ys):
    if len(xs) != len(ys) or len(xs) < 3:
        return None
    return pearson(average_ranks(xs), average_ranks(ys))


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


def residualize_zone_distance(rows, value_field):
    grouped = defaultdict(list)
    for row in rows:
        value = row.get(value_field)
        if not finite(value):
            continue
        key = (
            str(row.get("zone_index")),
            distance_bucket(row.get("distance_mm")),
        )
        grouped[key].append(float(value))

    medians = {
        key: statistics.median(values)
        for key, values in grouped.items()
        if values
    }

    output = []
    for row in rows:
        value = row.get(value_field)
        if not finite(value):
            continue
        key = (
            str(row.get("zone_index")),
            distance_bucket(row.get("distance_mm")),
        )
        center = medians.get(key)
        if not finite(center):
            continue
        output.append(float(value) - float(center))
    return output, medians


def prepare_metric_rows(rows, metric):
    usable = [
        row for row in rows
        if finite(row.get(metric))
        and finite(row.get("absolute_log_residual"))
        and row.get("image") not in (None, "")
        and row.get("zone_index") is not None
        and finite(row.get("distance_mm"))
    ]

    _, metric_medians = residualize_zone_distance(usable, metric)
    _, error_medians = residualize_zone_distance(
        usable, "absolute_log_residual"
    )

    prepared = []
    for row in usable:
        key = (
            str(row.get("zone_index")),
            distance_bucket(row.get("distance_mm")),
        )
        metric_center = metric_medians.get(key)
        error_center = error_medians.get(key)
        if not finite(metric_center) or not finite(error_center):
            continue
        prepared.append({
            "image": str(row.get("image")),
            "time_quartile": str(row.get("time_quartile", "unknown")),
            "zone_index": int(row.get("zone_index")),
            "distance_mm": float(row.get("distance_mm")),
            "metric_residual": float(row[metric]) - float(metric_center),
            "error_residual": (
                float(row["absolute_log_residual"]) - float(error_center)
            ),
        })
    return prepared


def per_image_correlations(prepared, minimum_rows_per_image):
    grouped = defaultdict(list)
    for row in prepared:
        grouped[row["image"]].append(row)

    results = []
    for image, values in sorted(grouped.items()):
        if len(values) < minimum_rows_per_image:
            continue
        xs = [row["metric_residual"] for row in values]
        ys = [row["error_residual"] for row in values]
        correlation = spearman(xs, ys)
        if not finite(correlation):
            continue
        quartiles = [
            row["time_quartile"]
            for row in values
            if row["time_quartile"] != "unknown"
        ]
        quartile = (
            max(set(quartiles), key=quartiles.count)
            if quartiles
            else "unknown"
        )
        results.append({
            "image": image,
            "count": len(values),
            "time_quartile": quartile,
            "within_image_spearman": float(correlation),
        })
    return results


def bootstrap_median_ci(values, iterations, seed):
    data = [float(value) for value in values if finite(value)]
    if len(data) < 2:
        return None
    rng = random.Random(seed)
    medians = []
    for _ in range(iterations):
        sample = [data[rng.randrange(len(data))] for _ in range(len(data))]
        medians.append(statistics.median(sample))
    return {
        "iterations": iterations,
        "seed": seed,
        "p2p5": percentile(medians, 0.025),
        "p50": percentile(medians, 0.50),
        "p97p5": percentile(medians, 0.975),
    }


def summarize_metric(
    rows,
    metric,
    minimum_rows_per_image,
    minimum_images,
    sign_consistency_threshold,
    sign_test_p_threshold,
    bootstrap_iterations,
    seed,
):
    prepared = prepare_metric_rows(rows, metric)
    images = per_image_correlations(prepared, minimum_rows_per_image)
    correlations = [
        item["within_image_spearman"]
        for item in images
        if finite(item.get("within_image_spearman"))
    ]

    positive = sum(value > 0.0 for value in correlations)
    negative = sum(value < 0.0 for value in correlations)
    nonzero = positive + negative
    consistency = positive / nonzero if nonzero > 0 else None
    sign_p = one_sided_sign_test_pvalue(positive, nonzero)
    ci = bootstrap_median_ci(
        correlations,
        bootstrap_iterations,
        seed,
    )

    by_quartile = {}
    positive_quartiles = 0
    tested_quartiles = 0
    for quartile in ("Q1", "Q2", "Q3", "Q4"):
        values = [
            item["within_image_spearman"]
            for item in images
            if item.get("time_quartile") == quartile
        ]
        if not values:
            continue
        median_corr = statistics.median(values)
        by_quartile[quartile] = {
            "image_count": len(values),
            "median_within_image_spearman": median_corr,
            "positive_image_fraction": (
                sum(value > 0.0 for value in values) / len(values)
            ),
        }
        tested_quartiles += 1
        if median_corr > 0.0:
            positive_quartiles += 1

    quartile_direction_supported = (
        tested_quartiles >= 3
        and positive_quartiles >= 3
    )

    supported = (
        len(correlations) >= minimum_images
        and finite(consistency)
        and float(consistency) >= sign_consistency_threshold
        and finite(sign_p)
        and float(sign_p) <= sign_test_p_threshold
        and isinstance(ci, dict)
        and finite(ci.get("p2p5"))
        and float(ci["p2p5"]) > 0.0
        and quartile_direction_supported
    )

    return {
        "metric": metric,
        "conditioning": (
            "zone+distance median residualization, then exact-image "
            "within-frame Spearman"
        ),
        "prepared_row_count": len(prepared),
        "supported_image_count": len(correlations),
        "minimum_rows_per_image": minimum_rows_per_image,
        "median_within_image_spearman": (
            statistics.median(correlations) if correlations else None
        ),
        "mean_within_image_spearman": (
            statistics.mean(correlations) if correlations else None
        ),
        "positive_images": positive,
        "negative_images": negative,
        "positive_image_consistency": consistency,
        "one_sided_image_sign_test_pvalue": sign_p,
        "bootstrap_median_ci95": ci,
        "by_time_quartile": by_quartile,
        "positive_time_quartiles": positive_quartiles,
        "tested_time_quartiles": tested_quartiles,
        "time_quartile_direction_supported": quartile_direction_supported,
        "frame_fixed_effect_signal": supported,
        "per_image": images,
    }


def base_report(args):
    return {
        "schema_version": 1,
        "stage": "SFM-S01H2.7",
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
            "minimum_rows_per_image": args.minimum_rows_per_image,
            "minimum_images": args.minimum_images,
            "sign_consistency_threshold": args.sign_consistency_threshold,
            "sign_test_p_threshold": args.sign_test_p_threshold,
            "bootstrap_iterations": args.bootstrap_iterations,
            "bootstrap_seed": args.bootstrap_seed,
        },
        "purpose": (
            "Resolve H2.6 partial support without lowering thresholds: remove "
            "zone/distance baselines and test dense-local residual association "
            "within each exact RGB frame. This controls time/scene at frame "
            "resolution rather than fragmenting observations into time quartiles."
        ),
        "next_gate": (
            "If at least two independent dense-local metrics show consistent "
            "positive within-frame association, investigate PatchMatch/dense "
            "depth deformation. Otherwise keep root cause open. S01H.3 remains "
            "closed."
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
            "stage": "SFM-S01H2.7",
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
    parser.add_argument("--h26-rows", required=True)
    parser.add_argument("--h26-report", required=True)
    parser.add_argument("--output-jsonl", required=True)
    parser.add_argument("--report-json", required=True)
    parser.add_argument("--minimum-rows-per-image", type=int, default=16)
    parser.add_argument("--minimum-images", type=int, default=40)
    parser.add_argument("--sign-consistency-threshold", type=float, default=0.60)
    parser.add_argument("--sign-test-p-threshold", type=float, default=0.01)
    parser.add_argument("--bootstrap-iterations", type=int, default=2000)
    parser.add_argument("--bootstrap-seed", type=int, default=2507)
    args = parser.parse_args()

    if args.minimum_rows_per_image < 8:
        raise SystemExit("minimum-rows-per-image must be >= 8")
    if args.minimum_images < 10:
        raise SystemExit("minimum-images must be >= 10")
    if not (0.5 < args.sign_consistency_threshold <= 1.0):
        raise SystemExit("sign-consistency-threshold must be in (0.5,1]")
    if not (0.0 < args.sign_test_p_threshold <= 0.1):
        raise SystemExit("sign-test-p-threshold must be in (0,0.1]")
    if args.bootstrap_iterations < 200:
        raise SystemExit("bootstrap-iterations must be >= 200")

    report = base_report(args)
    h26_report = load_json(args.h26_report)
    if h26_report.get("status") != "MEASURED":
        report["status"] = "SKIPPED_H26_UNAVAILABLE"
        report["skip_reason"] = "S01H.2.6 report is unavailable or not MEASURED."
        write_outputs(args, report, [])
        print(
            "INFO | TOF_DENSE_H27 | status=SKIPPED_H26_UNAVAILABLE "
            "measurement_only=yes geometry_mutation=OFF fusion=OFF"
        )
        return 0

    rows = load_jsonl(args.h26_rows)
    if not rows:
        report["status"] = "SKIPPED_H26_ROWS_UNAVAILABLE"
        report["skip_reason"] = "S01H.2.6 conditioned rows are unavailable."
        write_outputs(args, report, [])
        print(
            "INFO | TOF_DENSE_H27 | status=SKIPPED_H26_ROWS_UNAVAILABLE "
            "measurement_only=yes geometry_mutation=OFF fusion=OFF"
        )
        return 0

    report["input_counts"] = {
        "h26_conditioned_rows": len(rows),
    }
    report["h26_previous_decision"] = h26_report.get("decision")

    metrics = {}
    supported_metrics = []
    per_image_rows = []

    for index, metric in enumerate(METRICS):
        summary = summarize_metric(
            rows,
            metric,
            args.minimum_rows_per_image,
            args.minimum_images,
            args.sign_consistency_threshold,
            args.sign_test_p_threshold,
            args.bootstrap_iterations,
            args.bootstrap_seed + index,
        )
        per_image = summary.pop("per_image")
        for item in per_image:
            per_image_rows.append({
                "type": "tof_dense_h27_image_effect",
                "schema_version": 1,
                "stage": "SFM-S01H2.7",
                "metric": metric,
                **item,
            })
        metrics[metric] = summary
        if summary.get("frame_fixed_effect_signal"):
            supported_metrics.append(metric)

    frame_supported = len(supported_metrics) >= 2
    if frame_supported:
        classification = "DENSE_LOCAL_STRUCTURE_FRAME_FIXED_EFFECT_SUPPORTED"
    elif supported_metrics:
        classification = "DENSE_LOCAL_STRUCTURE_FRAME_PARTIAL_SUPPORT"
    else:
        classification = "INSUFFICIENT_SUPPORT"

    report["frame_fixed_effect_dense_structure"] = {
        "metrics": metrics,
        "supported_metrics": supported_metrics,
        "required_metric_signal_count": 2,
        "dense_local_structure_frame_fixed_effect_supported": frame_supported,
    }
    report["decision"] = {
        "classification": classification,
        "dense_local_structure_frame_fixed_effect_supported": frame_supported,
        "required_metric_signal_count": 2,
        "note": (
            "H2.7 does not lower H2.5/H2.6 thresholds. It replaces coarse "
            "time-quartile splitting with an exact-frame blocked test after "
            "zone/distance residualization."
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

    write_outputs(args, report, per_image_rows)

    print(
        "INFO | TOF_DENSE_H27 | "
        f"status=MEASURED decision={classification} "
        f"supported_metrics={len(supported_metrics)} "
        "geometry_mutation=OFF fusion=OFF"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
