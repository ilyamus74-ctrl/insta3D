#!/usr/bin/env python3
import argparse
import json
import math
import random
import statistics
from collections import defaultdict
from pathlib import Path


STAGE = "SFM-S01H2.9"
DIRECT_TOF_MIN_MM = 100.0
DIRECT_TOF_MAX_MM = 4000.0
DEFORMATION_THRESHOLD = 1.15
MINIMUM_H28_DECISIONS = {
    "DENSE_QUALITY_GATE_SUPPORTED",
    "DENSE_QUALITY_GATE_PARTIAL_SUPPORT",
}

DISTANCE_BINS = (
    ("0_0p5m", 100.0, 500.0),
    ("0p5_1m", 500.0, 1000.0),
    ("1_1p5m", 1000.0, 1500.0),
    ("1p5_2m", 1500.0, 2000.0),
    ("2_3m", 2000.0, 3000.0),
    ("3_4m", 3000.0, 4000.000001),
)


def finite(value):
    try:
        return math.isfinite(float(value))
    except Exception:
        return False


def percentile(values, q):
    data = sorted(float(value) for value in values if finite(value))
    if not data:
        return None
    if len(data) == 1:
        return data[0]
    pos = (len(data) - 1) * float(q)
    lo = int(math.floor(pos))
    hi = int(math.ceil(pos))
    if lo == hi:
        return data[lo]
    fraction = pos - lo
    return data[lo] * (1.0 - fraction) + data[hi] * fraction


def load_json(path):
    try:
        value = json.loads(Path(path).read_text(encoding="utf-8"))
    except Exception:
        return {}
    return value if isinstance(value, dict) else {}


def load_jsonl(path):
    rows = []
    with Path(path).open("r", encoding="utf-8", errors="replace") as handle:
        for line in handle:
            line = line.strip()
            if not line:
                continue
            try:
                row = json.loads(line)
            except Exception:
                continue
            if not isinstance(row, dict) or row.get("type") == "metadata":
                continue
            rows.append(row)
    return rows


def distance_bucket(distance_mm):
    if not finite(distance_mm):
        return "unknown"
    value = float(distance_mm)
    for name, low, high in DISTANCE_BINS:
        if low <= value < high:
            return name
    return "beyond_4m" if value >= 4000.0 else "under_0p1m"


def build_distance_model(rows, minimum_count):
    buckets = {}
    knots = []
    for name, low, high in DISTANCE_BINS:
        values = [
            float(row["scale_mm_per_colmap_unit"])
            for row in rows
            if distance_bucket(row.get("distance_mm")) == name
            and finite(row.get("scale_mm_per_colmap_unit"))
            and float(row["scale_mm_per_colmap_unit"]) > 0.0
        ]
        center = (low + high) * 0.5
        med = statistics.median(values) if values else None
        supported = len(values) >= minimum_count
        buckets[name] = {
            "count": len(values),
            "center_mm": center,
            "scale_median_mm_per_colmap_unit": med,
            "supported": supported,
        }
        if supported and finite(med) and float(med) > 0.0:
            knots.append((center, float(med), name))
    return buckets, knots


def interpolate_knots(knots, distance_mm):
    if not knots or not finite(distance_mm):
        return None
    x = float(distance_mm)
    ordered = sorted(knots, key=lambda item: item[0])
    if x <= ordered[0][0]:
        return ordered[0][1]
    if x >= ordered[-1][0]:
        return ordered[-1][1]
    for left, right in zip(ordered, ordered[1:]):
        x0, y0, _ = left
        x1, y1, _ = right
        if x0 <= x <= x1:
            fraction = (x - x0) / max(x1 - x0, 1e-12)
            return y0 + (y1 - y0) * fraction
    return None


def spread_from_bucket_summary(buckets):
    medians = [
        float(item["scale_median_mm_per_colmap_unit"])
        for item in buckets.values()
        if item.get("supported")
        and finite(item.get("scale_median_mm_per_colmap_unit"))
        and float(item["scale_median_mm_per_colmap_unit"]) > 0.0
    ]
    if len(medians) < 2:
        return None
    return max(medians) / min(medians)


def grouped_ratio_spread(rows, field, value_field, minimum_count):
    grouped = defaultdict(list)
    for row in rows:
        value = row.get(value_field)
        if finite(value) and float(value) > 0.0:
            grouped[str(row.get(field, "unknown"))].append(float(value))
    summary = {}
    supported = []
    for key in sorted(grouped):
        values = grouped[key]
        med = statistics.median(values) if values else None
        ok = len(values) >= minimum_count
        summary[key] = {
            "count": len(values),
            "median": med,
            "supported": ok,
        }
        if ok and finite(med) and float(med) > 0.0:
            supported.append(float(med))
    spread = None
    if len(supported) >= 2 and min(supported) > 0.0:
        spread = max(supported) / min(supported)
    return summary, spread


def weighted_effect_center(effects, counts):
    expanded = []
    for key, effect in effects.items():
        expanded.extend([float(effect)] * max(0, int(counts.get(key, 0))))
    return statistics.median(expanded) if expanded else 0.0


def estimate_row_column_effects(rows, minimum_count, iterations):
    usable = [
        row for row in rows
        if finite(row.get("distance_normalized_ratio"))
        and float(row["distance_normalized_ratio"]) > 0.0
        and row.get("zone_row") is not None
        and row.get("zone_column") is not None
    ]
    row_counts = defaultdict(int)
    column_counts = defaultdict(int)
    for row in usable:
        row_counts[str(row["zone_row"])] += 1
        column_counts[str(row["zone_column"])] += 1

    row_effects = {
        key: 0.0 for key, count in row_counts.items()
        if count >= minimum_count
    }
    column_effects = {
        key: 0.0 for key, count in column_counts.items()
        if count >= minimum_count
    }

    for _ in range(max(1, iterations)):
        new_rows = {}
        for key in row_effects:
            values = [
                math.log(float(row["distance_normalized_ratio"]))
                - column_effects.get(str(row["zone_column"]), 0.0)
                for row in usable
                if str(row["zone_row"]) == key
            ]
            if values:
                new_rows[key] = statistics.median(values)
        if new_rows:
            center = weighted_effect_center(new_rows, row_counts)
            row_effects = {key: value - center for key, value in new_rows.items()}

        new_columns = {}
        for key in column_effects:
            values = [
                math.log(float(row["distance_normalized_ratio"]))
                - row_effects.get(str(row["zone_row"]), 0.0)
                for row in usable
                if str(row["zone_column"]) == key
            ]
            if values:
                new_columns[key] = statistics.median(values)
        if new_columns:
            center = weighted_effect_center(new_columns, column_counts)
            column_effects = {
                key: value - center for key, value in new_columns.items()
            }

    return (
        {key: math.exp(value) for key, value in row_effects.items()},
        {key: math.exp(value) for key, value in column_effects.items()},
        {
            "iterations": max(1, iterations),
            "supported_rows": sorted(row_effects),
            "supported_columns": sorted(column_effects),
        },
    )


def direct_rows(rows):
    return [
        dict(row) for row in rows
        if finite(row.get("distance_mm"))
        and DIRECT_TOF_MIN_MM <= float(row["distance_mm"]) <= DIRECT_TOF_MAX_MM
        and finite(row.get("scale_mm_per_colmap_unit"))
        and float(row["scale_mm_per_colmap_unit"]) > 0.0
    ]


def controlled_decomposition(rows, minimum_group_count, iterations):
    usable = direct_rows(rows)
    result = {
        "count": len(usable),
        "minimum_group_count": minimum_group_count,
    }
    if not usable:
        result["status"] = "INSUFFICIENT_SUPPORT"
        return result, []

    distance_buckets, distance_knots = build_distance_model(
        usable, minimum_group_count
    )
    raw_distance_spread = spread_from_bucket_summary(distance_buckets)

    transformed = []
    for source in usable:
        expected_scale = interpolate_knots(
            distance_knots, source.get("distance_mm")
        )
        if not finite(expected_scale) or float(expected_scale) <= 0.0:
            continue
        row = dict(source)
        row["distance_model_scale_mm_per_colmap_unit"] = float(expected_scale)
        row["distance_normalized_ratio"] = (
            float(row["scale_mm_per_colmap_unit"]) / float(expected_scale)
        )
        transformed.append(row)

    row_summary, row_spread = grouped_ratio_spread(
        transformed,
        "zone_row",
        "distance_normalized_ratio",
        minimum_group_count,
    )
    column_summary, column_spread = grouped_ratio_spread(
        transformed,
        "zone_column",
        "distance_normalized_ratio",
        minimum_group_count,
    )

    row_effects, column_effects, effect_support = estimate_row_column_effects(
        transformed,
        minimum_group_count,
        iterations,
    )

    zone_normalized = []
    fully_normalized_absolute_log = []
    for source in transformed:
        row_key = str(source.get("zone_row"))
        column_key = str(source.get("zone_column"))
        row_effect = row_effects.get(row_key)
        column_effect = column_effects.get(column_key)
        if not finite(row_effect) or not finite(column_effect):
            continue
        zone_factor = float(row_effect) * float(column_effect)
        if zone_factor <= 0.0:
            continue

        row = dict(source)
        row["zone_row_effect"] = float(row_effect)
        row["zone_column_effect"] = float(column_effect)
        row["zone_effect"] = zone_factor
        row["zone_normalized_scale_mm_per_colmap_unit"] = (
            float(row["scale_mm_per_colmap_unit"]) / zone_factor
        )
        row["fully_normalized_ratio"] = (
            float(row["distance_normalized_ratio"]) / zone_factor
        )
        if row["fully_normalized_ratio"] > 0.0:
            fully_normalized_absolute_log.append(
                abs(math.log(row["fully_normalized_ratio"]))
            )
        zone_normalized.append(row)

    zone_model_rows = [
        {
            **row,
            "scale_mm_per_colmap_unit": row[
                "zone_normalized_scale_mm_per_colmap_unit"
            ],
        }
        for row in zone_normalized
    ]
    zone_distance_buckets, _ = build_distance_model(
        zone_model_rows, minimum_group_count
    )
    zone_normalized_distance_spread = spread_from_bucket_summary(
        zone_distance_buckets
    )

    result.update({
        "status": "MEASURED" if len(distance_knots) >= 2 else "INSUFFICIENT_SUPPORT",
        "supported_distance_knot_count": len(distance_knots),
        "raw_distance": {
            "scale_spread_ratio": raw_distance_spread,
            "buckets": distance_buckets,
        },
        "after_distance_normalization": {
            "zone_row_ratio_spread": row_spread,
            "zone_column_ratio_spread": column_spread,
            "zone_row": row_summary,
            "zone_column": column_summary,
        },
        "row_column_effects": {
            "zone_row": row_effects,
            "zone_column": column_effects,
            **effect_support,
        },
        "after_zone_control": {
            "distance_scale_spread_ratio": zone_normalized_distance_spread,
            "distance": zone_distance_buckets,
        },
        "fully_normalized_residual": {
            "count": len(fully_normalized_absolute_log),
            "absolute_log_ratio_p50": percentile(
                fully_normalized_absolute_log, 0.50
            ),
            "absolute_log_ratio_p95": percentile(
                fully_normalized_absolute_log, 0.95
            ),
        },
    })
    return result, zone_normalized


def image_bootstrap(rows, minimum_group_count, iterations, seed, rc_iterations):
    images = sorted({str(row.get("image")) for row in rows if row.get("image")})
    if len(images) < 10 or iterations < 1:
        return {
            "status": "INSUFFICIENT_SUPPORT",
            "image_count": len(images),
            "iterations": iterations,
        }

    by_image = defaultdict(list)
    for row in rows:
        image = str(row.get("image"))
        if image:
            by_image[image].append(row)

    rng = random.Random(seed)
    metrics = defaultdict(list)
    completed = 0
    for _ in range(iterations):
        sampled = [images[rng.randrange(len(images))] for _ in images]
        sample_rows = []
        for image in sampled:
            sample_rows.extend(by_image[image])
        summary, _ = controlled_decomposition(
            sample_rows,
            minimum_group_count,
            rc_iterations,
        )
        if summary.get("status") != "MEASURED":
            continue
        values = {
            "raw_distance_scale_spread_ratio": summary[
                "raw_distance"
            ].get("scale_spread_ratio"),
            "distance_normalized_zone_row_spread": summary[
                "after_distance_normalization"
            ].get("zone_row_ratio_spread"),
            "distance_normalized_zone_column_spread": summary[
                "after_distance_normalization"
            ].get("zone_column_ratio_spread"),
            "zone_normalized_distance_scale_spread_ratio": summary[
                "after_zone_control"
            ].get("distance_scale_spread_ratio"),
        }
        for name, value in values.items():
            if finite(value):
                metrics[name].append(float(value))
        completed += 1

    report = {
        "status": "MEASURED" if completed else "INSUFFICIENT_SUPPORT",
        "image_count": len(images),
        "requested_iterations": iterations,
        "completed_iterations": completed,
        "seed": seed,
        "metrics": {},
    }
    for name, values in metrics.items():
        report["metrics"][name] = {
            "count": len(values),
            "p2p5": percentile(values, 0.025),
            "p50": percentile(values, 0.50),
            "p97p5": percentile(values, 0.975),
        }
    return report


def metric_signal(value, threshold=DEFORMATION_THRESHOLD):
    return finite(value) and float(value) >= float(threshold)


def bootstrap_lower_signal(bootstrap, metric, threshold=DEFORMATION_THRESHOLD):
    item = (bootstrap.get("metrics") or {}).get(metric) or {}
    return finite(item.get("p2p5")) and float(item["p2p5"]) >= float(threshold)


def classify(h28_report, clean_summary, clean_bootstrap):
    h28_decision = (h28_report.get("decision") or {}).get("classification")
    h28_local_support = h28_decision in MINIMUM_H28_DECISIONS

    clean_distance = clean_summary.get("after_zone_control", {}).get(
        "distance_scale_spread_ratio"
    )
    systematic_persists = (
        metric_signal(clean_distance)
        and bootstrap_lower_signal(
            clean_bootstrap,
            "zone_normalized_distance_scale_spread_ratio",
        )
    )

    clean_row = clean_summary.get("after_distance_normalization", {}).get(
        "zone_row_ratio_spread"
    )
    clean_column = clean_summary.get("after_distance_normalization", {}).get(
        "zone_column_ratio_spread"
    )
    clean_zone_signal_count = sum(
        metric_signal(value) for value in (clean_row, clean_column)
    )

    if h28_local_support and systematic_persists:
        classification = (
            "MIXED_LOCAL_DENSE_INSTABILITY_AND_SYSTEMATIC_DEPTH_SCALE_DEFORMATION_SUPPORTED"
        )
    elif systematic_persists:
        classification = "SYSTEMATIC_DEPTH_SCALE_DEFORMATION_PERSISTS_IN_CLEAN_DENSE"
    elif h28_local_support and clean_zone_signal_count == 0:
        classification = "QUALITY_GATING_COLLAPSES_CONTROLLED_DEFORMATION"
    elif h28_local_support:
        classification = "LOCAL_DENSE_QUALITY_SUPPORT_WITH_RESIDUAL_ZONE_STRUCTURE"
    else:
        classification = "INSUFFICIENT_SUPPORT"

    return {
        "classification": classification,
        "h28_local_dense_quality_support": h28_local_support,
        "clean_zone_normalized_distance_spread": clean_distance,
        "clean_systematic_distance_deformation_persists": systematic_persists,
        "clean_distance_normalized_zone_row_spread": clean_row,
        "clean_distance_normalized_zone_column_spread": clean_column,
        "clean_residual_zone_signal_count": clean_zone_signal_count,
        "deformation_threshold": DEFORMATION_THRESHOLD,
        "note": (
            "A persistent global distance-dependent scale term is intentionally "
            "not attributed here to COLMAP Dense or ToF range bias. H2.9 only "
            "separates the local Dense-quality component from the remaining "
            "systematic metric deformation."
        ),
    }


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
        "parameters": {
            "minimum_group_count": args.minimum_group_count,
            "row_column_iterations": args.row_column_iterations,
            "bootstrap_iterations": args.bootstrap_iterations,
            "bootstrap_seed": args.bootstrap_seed,
            "deformation_threshold": DEFORMATION_THRESHOLD,
            "direct_tof_min_mm": DIRECT_TOF_MIN_MM,
            "direct_tof_max_mm": DIRECT_TOF_MAX_MM,
        },
        "purpose": (
            "Re-run the H2.3-style controlled distance/row/column decomposition "
            "inside the H2.8 quality groups. The goal is to determine whether "
            "local Dense-quality gating explains the systematic depth-scale "
            "deformation or whether a second global component remains."
        ),
        "causal_scope": (
            "H2.9 does not identify whether a remaining distance-dependent term "
            "comes from COLMAP Dense depth or ToF range bias. An independent "
            "metric-range validation is required for that distinction."
        ),
        "next_gate": (
            "If systematic distance deformation persists in CLEAN_DENSE, keep "
            "S01H.3 closed and validate ToF range linearity versus an independent "
            "metric reference before changing Dense or ToF geometry."
        ),
    }


def write_outputs(args, report, transformed_rows):
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
            "stage": STAGE,
            "status": report["status"],
            "measurement_only": True,
            "geometry_mutation_enabled": False,
            "dense_depth_modified": False,
            "fusion_enabled": False,
        }) + "\n")
        for group, rows in transformed_rows.items():
            for row in rows:
                handle.write(json.dumps({
                    "type": "tof_dense_h29_controlled_row",
                    "schema_version": 1,
                    "stage": STAGE,
                    "quality_group": group,
                    "image": row.get("image"),
                    "tof_sequence": row.get("tof_sequence"),
                    "zone_index": row.get("zone_index"),
                    "zone_row": row.get("zone_row"),
                    "zone_column": row.get("zone_column"),
                    "distance_mm": row.get("distance_mm"),
                    "scale_mm_per_colmap_unit": row.get(
                        "scale_mm_per_colmap_unit"
                    ),
                    "distance_model_scale_mm_per_colmap_unit": row.get(
                        "distance_model_scale_mm_per_colmap_unit"
                    ),
                    "distance_normalized_ratio": row.get(
                        "distance_normalized_ratio"
                    ),
                    "zone_row_effect": row.get("zone_row_effect"),
                    "zone_column_effect": row.get("zone_column_effect"),
                    "zone_normalized_scale_mm_per_colmap_unit": row.get(
                        "zone_normalized_scale_mm_per_colmap_unit"
                    ),
                    "fully_normalized_ratio": row.get(
                        "fully_normalized_ratio"
                    ),
                }, ensure_ascii=False) + "\n")


def skip(args, report, status, reason):
    report["status"] = status
    report["skip_reason"] = reason
    write_outputs(args, report, {})
    print(
        "INFO | TOF_DENSE_H29 | "
        f"status={status} measurement_only=yes geometry_mutation=OFF fusion=OFF"
    )
    return 0


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--h28-rows", required=True)
    parser.add_argument("--h28-report", required=True)
    parser.add_argument("--output-jsonl", required=True)
    parser.add_argument("--report-json", required=True)
    parser.add_argument("--minimum-group-count", type=int, default=15)
    parser.add_argument("--minimum-clean-count", type=int, default=150)
    parser.add_argument("--row-column-iterations", type=int, default=8)
    parser.add_argument("--bootstrap-iterations", type=int, default=300)
    parser.add_argument("--bootstrap-seed", type=int, default=2909)
    args = parser.parse_args()

    if args.minimum_group_count < 5:
        raise SystemExit("minimum-group-count must be >= 5")
    if args.minimum_clean_count < 50:
        raise SystemExit("minimum-clean-count must be >= 50")
    if args.row_column_iterations < 1:
        raise SystemExit("row-column-iterations must be >= 1")
    if args.bootstrap_iterations < 50:
        raise SystemExit("bootstrap-iterations must be >= 50")

    report = base_report(args)
    h28_report = load_json(args.h28_report)
    if h28_report.get("status") != "MEASURED":
        return skip(
            args, report, "SKIPPED_H28_UNAVAILABLE",
            "S01H.2.8 report is unavailable or not MEASURED.",
        )
    h28_classification = (h28_report.get("decision") or {}).get("classification")
    if h28_classification not in MINIMUM_H28_DECISIONS:
        return skip(
            args, report, "SKIPPED_H28_QUALITY_SUPPORT_UNAVAILABLE",
            "H2.9 requires H2.8 quality-gate support or partial support.",
        )

    rows = load_jsonl(args.h28_rows)
    if not rows:
        return skip(
            args, report, "SKIPPED_H28_ROWS_UNAVAILABLE",
            "S01H.2.8 quality-gate rows are unavailable.",
        )

    groups = {
        "FULL": rows,
        "CLEAN_DENSE": [
            row for row in rows if row.get("quality_group") == "CLEAN_DENSE"
        ],
        "MIDDLE_DENSE": [
            row for row in rows if row.get("quality_group") == "MIDDLE_DENSE"
        ],
        "UNSTABLE_DENSE": [
            row for row in rows if row.get("quality_group") == "UNSTABLE_DENSE"
        ],
    }
    if len(groups["CLEAN_DENSE"]) < args.minimum_clean_count:
        return skip(
            args, report, "SKIPPED_CLEAN_SUPPORT_INSUFFICIENT",
            (
                f"CLEAN_DENSE count {len(groups['CLEAN_DENSE'])} is below "
                f"{args.minimum_clean_count}."
            ),
        )

    decompositions = {}
    transformed = {}
    for name, group_rows in groups.items():
        summary, rows_out = controlled_decomposition(
            group_rows,
            args.minimum_group_count,
            args.row_column_iterations,
        )
        decompositions[name] = summary
        transformed[name] = rows_out

    clean_bootstrap = image_bootstrap(
        groups["CLEAN_DENSE"],
        args.minimum_group_count,
        args.bootstrap_iterations,
        args.bootstrap_seed,
        args.row_column_iterations,
    )
    full_bootstrap = image_bootstrap(
        groups["FULL"],
        args.minimum_group_count,
        args.bootstrap_iterations,
        args.bootstrap_seed + 1,
        args.row_column_iterations,
    )

    decision = classify(
        h28_report,
        decompositions["CLEAN_DENSE"],
        clean_bootstrap,
    )

    report["input_counts"] = {
        "h28_rows": len(rows),
        "FULL": len(groups["FULL"]),
        "CLEAN_DENSE": len(groups["CLEAN_DENSE"]),
        "MIDDLE_DENSE": len(groups["MIDDLE_DENSE"]),
        "UNSTABLE_DENSE": len(groups["UNSTABLE_DENSE"]),
    }
    report["h28_previous_decision"] = h28_report.get("decision")
    report["controlled_decomposition"] = decompositions
    report["bootstrap"] = {
        "FULL": full_bootstrap,
        "CLEAN_DENSE": clean_bootstrap,
    }
    report["decision"] = decision
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

    write_outputs(args, report, transformed)

    clean = decompositions["CLEAN_DENSE"]
    print(
        "INFO | TOF_DENSE_H29 | "
        f"status=MEASURED decision={decision['classification']} "
        f"clean={clean.get('count',0)} "
        "clean_raw_distance_spread="
        f"{clean.get('raw_distance',{}).get('scale_spread_ratio')} "
        "clean_zone_normalized_distance_spread="
        f"{clean.get('after_zone_control',{}).get('distance_scale_spread_ratio')} "
        "clean_distance_normalized_row_spread="
        f"{clean.get('after_distance_normalization',{}).get('zone_row_ratio_spread')} "
        "clean_distance_normalized_col_spread="
        f"{clean.get('after_distance_normalization',{}).get('zone_column_ratio_spread')} "
        "geometry_mutation=OFF fusion=OFF"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
