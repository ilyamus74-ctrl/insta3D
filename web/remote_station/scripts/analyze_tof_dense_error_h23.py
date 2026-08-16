#!/usr/bin/env python3
import argparse
import json
import math
import statistics
from collections import defaultdict
from pathlib import Path

DISTANCE_BINS = (
    ("0_0p5m", 100.0, 500.0),
    ("0p5_1m", 500.0, 1000.0),
    ("1_1p5m", 1000.0, 1500.0),
    ("1p5_2m", 1500.0, 2000.0),
    ("2_3m", 2000.0, 3000.0),
    ("3_4m", 3000.0, 4000.000001),
)

def finite(value):
    return isinstance(value, (int, float)) and not isinstance(value, bool) and math.isfinite(float(value))

def percentile(values, fraction):
    if not values:
        return None
    data = sorted(float(value) for value in values)
    pos = (len(data) - 1) * fraction
    lo = math.floor(pos)
    hi = math.ceil(pos)
    if lo == hi:
        return data[lo]
    return data[lo] * (hi - pos) + data[hi] * (pos - lo)

def median(values):
    return statistics.median(values) if values else None

def load_json(path):
    try:
        value = json.loads(Path(path).read_text(encoding="utf-8"))
    except Exception:
        return {}
    return value if isinstance(value, dict) else {}

def load_jsonl(path, wanted_type=None):
    rows = []
    if not Path(path).is_file():
        return rows
    with Path(path).open("r", encoding="utf-8", errors="replace") as handle:
        for line in handle:
            try:
                row = json.loads(line)
            except Exception:
                continue
            if not isinstance(row, dict):
                continue
            if wanted_type is None or row.get("type") == wanted_type:
                rows.append(row)
    return rows

def distance_bucket(distance_mm):
    if not finite(distance_mm):
        return "unknown"
    value = float(distance_mm)
    for name, low, high in DISTANCE_BINS:
        if low <= value < high:
            return name
    return "over_4m" if value >= 4000.0 else "under_0p1m"

def group_summary(rows, field, value_field, minimum_count, scale_label=False):
    grouped = defaultdict(list)
    for row in rows:
        value = row.get(value_field)
        if finite(value):
            grouped[str(row.get(field, "unknown"))].append(float(value))
    result = {}
    supported = []
    for key in sorted(grouped):
        values = grouped[key]
        med = median(values)
        item = {
            "count": len(values),
            "median": med,
            "p25": percentile(values, 0.25),
            "p75": percentile(values, 0.75),
            "p95": percentile(values, 0.95),
            "supported": len(values) >= minimum_count,
        }
        if scale_label:
            item["scale_median_mm_per_colmap_unit"] = item.pop("median")
            med = item["scale_median_mm_per_colmap_unit"]
        result[key] = item
        if item["supported"] and finite(med) and float(med) > 0.0:
            supported.append(float(med))
    spread = None
    if len(supported) >= 2 and min(supported) > 0.0:
        spread = max(supported) / min(supported)
    return result, spread

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
        med = median(values)
        ok = len(values) >= minimum_count
        buckets[name] = {
            "count": len(values),
            "center_mm": center,
            "scale_median_mm_per_colmap_unit": med,
            "supported": ok,
        }
        if ok and finite(med) and float(med) > 0.0:
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
            f = (x - x0) / max(x1 - x0, 1e-12)
            return y0 + (y1 - y0) * f
    return None

def weighted_effect_center(effects, counts):
    values = []
    for key, effect in effects.items():
        values.extend([float(effect)] * max(0, int(counts.get(key, 0))))
    return statistics.median(values) if values else 0.0

def estimate_row_column_effects(rows, minimum_count, iterations):
    usable = [
        row for row in rows
        if finite(row.get("distance_normalized_ratio"))
        and float(row["distance_normalized_ratio"]) > 0.0
        and row.get("zone_row") is not None
        and row.get("zone_column") is not None
    ]
    row_counts = defaultdict(int)
    col_counts = defaultdict(int)
    for row in usable:
        row_counts[str(row["zone_row"])] += 1
        col_counts[str(row["zone_column"])] += 1
    row_effects = {k: 0.0 for k, c in row_counts.items() if c >= minimum_count}
    col_effects = {k: 0.0 for k, c in col_counts.items() if c >= minimum_count}
    for _ in range(max(1, iterations)):
        new_rows = {}
        for key in row_effects:
            vals = [
                math.log(float(row["distance_normalized_ratio"])) - col_effects.get(str(row["zone_column"]), 0.0)
                for row in usable if str(row["zone_row"]) == key
            ]
            if vals:
                new_rows[key] = statistics.median(vals)
        if new_rows:
            center = weighted_effect_center(new_rows, row_counts)
            row_effects = {k: v - center for k, v in new_rows.items()}
        new_cols = {}
        for key in col_effects:
            vals = [
                math.log(float(row["distance_normalized_ratio"])) - row_effects.get(str(row["zone_row"]), 0.0)
                for row in usable if str(row["zone_column"]) == key
            ]
            if vals:
                new_cols[key] = statistics.median(vals)
        if new_cols:
            center = weighted_effect_center(new_cols, col_counts)
            col_effects = {k: v - center for k, v in new_cols.items()}
    return (
        {k: math.exp(v) for k, v in row_effects.items()},
        {k: math.exp(v) for k, v in col_effects.items()},
        {"iterations": max(1, iterations), "supported_rows": sorted(row_effects), "supported_columns": sorted(col_effects)},
    )

def parse_cameras_txt(path):
    cameras = []
    for line in Path(path).read_text(encoding="utf-8", errors="replace").splitlines():
        stripped = line.strip()
        if not stripped or stripped.startswith("#"):
            continue
        parts = stripped.split()
        if len(parts) < 5:
            continue
        try:
            cameras.append({
                "camera_id": int(parts[0]), "model": parts[1],
                "width": int(parts[2]), "height": int(parts[3]),
                "params": [float(value) for value in parts[4:]],
            })
        except Exception:
            continue
    return cameras

def find_cameras_txt(model_dir):
    root = Path(model_dir)
    for candidate in (root / "cameras.txt", root / "txt" / "cameras.txt"):
        if candidate.is_file():
            return candidate
    return None

def prior_for_camera_size(prior, width, height):
    if not isinstance(prior, dict) or prior.get("usable_for_colmap") is not True or prior.get("model") != "SIMPLE_RADIAL":
        return None
    params = prior.get("params")
    source_resolution = prior.get("source_resolution")
    if not isinstance(params, list) or len(params) < 4 or not all(finite(v) for v in params[:4]):
        return None
    if not isinstance(source_resolution, list) or len(source_resolution) < 2 or not all(finite(v) for v in source_resolution[:2]):
        return None
    sw, sh = float(source_resolution[0]), float(source_resolution[1])
    if sw <= 0.0 or sh <= 0.0:
        return None
    sx, sy = float(width) / sw, float(height) / sh
    f, cx, cy, k = [float(v) for v in params[:4]]
    return {
        "model": "SIMPLE_RADIAL",
        "params": [f * ((sx + sy) * 0.5), cx * sx, cy * sy, k],
        "source_resolution": [int(round(sw)), int(round(sh))],
        "target_resolution": [int(width), int(height)],
        "scale_x": sx, "scale_y": sy,
        "aspect_scale_mismatch_ratio": max(sx, sy) / min(sx, sy) if min(sx, sy) > 0 else None,
        "source": prior.get("source"),
    }

def focal_fov_deg(size_px, focal_px):
    if not finite(size_px) or not finite(focal_px) or float(focal_px) <= 0.0:
        return None
    return math.degrees(2.0 * math.atan(float(size_px) / (2.0 * float(focal_px))))

def optics_audit(camera_metadata_path, sparse_model_dir):
    metadata = load_json(camera_metadata_path)
    prior = metadata.get("colmap_camera_prior")
    cameras_path = find_cameras_txt(sparse_model_dir)
    result = {
        "camera_metadata_available": bool(metadata),
        "colmap_prior_available": isinstance(prior, dict),
        "sparse_cameras_txt": str(cameras_path) if cameras_path else None,
        "camera_count": 0,
        "cameras": [],
        "signals": {
            "focal_drift_gt_2pct": False,
            "principal_point_shift_gt_1pct_diagonal": False,
            "aspect_scale_mismatch_gt_0p5pct": False,
        },
    }
    if cameras_path is None:
        result["status"] = "SPARSE_CAMERA_UNAVAILABLE"
        return result
    cameras = parse_cameras_txt(cameras_path)
    result["camera_count"] = len(cameras)
    if not cameras:
        result["status"] = "SPARSE_CAMERA_UNAVAILABLE"
        return result
    for camera in cameras:
        item = {
            "camera_id": camera["camera_id"], "final_model": camera["model"],
            "final_resolution": [camera["width"], camera["height"]],
            "final_params": camera["params"],
        }
        scaled_prior = prior_for_camera_size(prior, camera["width"], camera["height"])
        item["scaled_camera2_colmap_prior"] = scaled_prior
        if camera["model"] == "SIMPLE_RADIAL" and len(camera["params"]) >= 4 and scaled_prior is not None:
            final_f, final_cx, final_cy, final_k = camera["params"][:4]
            prior_f, prior_cx, prior_cy, prior_k = scaled_prior["params"]
            focal_delta_pct = ((final_f - prior_f) / prior_f * 100.0) if prior_f > 0 else None
            cx_delta, cy_delta = final_cx - prior_cx, final_cy - prior_cy
            pp_shift = math.hypot(cx_delta, cy_delta)
            diagonal = math.hypot(camera["width"], camera["height"])
            pp_fraction = pp_shift / diagonal if diagonal > 0 else None
            item["comparison"] = {
                "focal_delta_pct": focal_delta_pct,
                "cx_delta_px": cx_delta, "cy_delta_px": cy_delta,
                "principal_point_shift_px": pp_shift,
                "principal_point_shift_fraction_diagonal": pp_fraction,
                "k_delta": final_k - prior_k,
                "prior_horizontal_fov_deg": focal_fov_deg(camera["width"], prior_f),
                "final_horizontal_fov_deg": focal_fov_deg(camera["width"], final_f),
                "prior_vertical_fov_deg": focal_fov_deg(camera["height"], prior_f),
                "final_vertical_fov_deg": focal_fov_deg(camera["height"], final_f),
            }
            if finite(focal_delta_pct) and abs(float(focal_delta_pct)) > 2.0:
                result["signals"]["focal_drift_gt_2pct"] = True
            if finite(pp_fraction) and float(pp_fraction) > 0.01:
                result["signals"]["principal_point_shift_gt_1pct_diagonal"] = True
            mismatch = scaled_prior.get("aspect_scale_mismatch_ratio")
            if finite(mismatch) and float(mismatch) > 1.005:
                result["signals"]["aspect_scale_mismatch_gt_0p5pct"] = True
        else:
            item["comparison_status"] = "UNSUPPORTED_MODEL_PAIR_FOR_NUMERIC_AUDIT"
        result["cameras"].append(item)
    result["status"] = "MEASURED"
    result["camera_optics_drift_signal"] = any(result["signals"].values())
    return result

def base_report(args):
    return {
        "schema_version": 1,
        "stage": "SFM-S01H2.3",
        "status": "STARTING",
        "measurement_only": True,
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
            "row_column_iterations": args.row_column_iterations,
            "direct_tof_min_mm": args.direct_tof_min_mm,
            "direct_tof_max_mm": args.direct_tof_max_mm,
        },
        "metric_policy": {
            "within_direct_tof_range": "METRIC_REFERENCE_ELIGIBLE",
            "beyond_direct_tof_range": "APPROXIMATE_ONLY",
            "tof_extrapolation_beyond_range": False,
        },
        "next_gate": "Use H2.3 controlled decomposition and optics audit to choose calibration work versus depth-deformation correction. S01H.3 remains closed.",
    }

def write_outputs(args, report, rows):
    rp = Path(args.report_json)
    rp.parent.mkdir(parents=True, exist_ok=True)
    rp.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    op = Path(args.output_jsonl)
    op.parent.mkdir(parents=True, exist_ok=True)
    with op.open("w", encoding="utf-8") as handle:
        handle.write(json.dumps({
            "type": "metadata", "schema_version": 1, "stage": "SFM-S01H2.3",
            "status": report["status"], "measurement_only": True,
            "geometry_mutation_enabled": False, "dense_input_modified": False,
            "fusion_enabled": False,
        }) + "\n")
        for row in rows:
            handle.write(json.dumps(row, ensure_ascii=False) + "\n")

def skip(args, report, status, reason):
    report["status"] = status
    report["skip_reason"] = reason
    write_outputs(args, report, [])
    print(f"INFO | TOF_DENSE_H23 | status={status} measurement_only=yes geometry_mutation=OFF fusion=OFF")
    return 0

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--h22-candidates", required=True)
    parser.add_argument("--h22-report", required=True)
    parser.add_argument("--strategy", default="geometric_footprint_p50")
    parser.add_argument("--camera-metadata", required=True)
    parser.add_argument("--sparse-model-dir", required=True)
    parser.add_argument("--output-jsonl", required=True)
    parser.add_argument("--report-json", required=True)
    parser.add_argument("--minimum-group-count", type=int, default=20)
    parser.add_argument("--row-column-iterations", type=int, default=8)
    parser.add_argument("--direct-tof-min-mm", type=float, default=100.0)
    parser.add_argument("--direct-tof-max-mm", type=float, default=4000.0)
    args = parser.parse_args()
    if args.minimum_group_count < 1 or args.row_column_iterations < 1:
        raise SystemExit("group count and iterations must be >= 1")
    if args.direct_tof_min_mm <= 0 or args.direct_tof_max_mm <= args.direct_tof_min_mm:
        raise SystemExit("invalid direct ToF range")
    report = base_report(args)
    h22_report = load_json(args.h22_report)
    if h22_report.get("status") != "MEASURED":
        return skip(args, report, "SKIPPED_H22_UNAVAILABLE", "S01H.2.2 report is unavailable or not MEASURED.")
    if args.strategy not in (h22_report.get("strategies") or {}):
        return skip(args, report, "SKIPPED_STRATEGY_UNAVAILABLE", f"H2.2 strategy unavailable: {args.strategy}")
    candidates = [
        row for row in load_jsonl(args.h22_candidates, "tof_dense_h22_candidate")
        if row.get("strategy") == args.strategy
    ]
    report["strategy_candidate_count"] = len(candidates)
    rows = []
    for source in candidates:
        scale, distance = source.get("scale_mm_per_colmap_unit"), source.get("distance_mm")
        if not finite(scale) or float(scale) <= 0 or not finite(distance):
            continue
        distance = float(distance)
        if not (args.direct_tof_min_mm <= distance <= args.direct_tof_max_mm):
            continue
        row = dict(source)
        row.update({
            "type": "tof_dense_h23_decomposition", "schema_version": 1,
            "stage": "SFM-S01H2.3", "metric_range_status": "DIRECT_TOF_RANGE",
            "distance_bucket_h23": distance_bucket(distance),
        })
        rows.append(row)
    report["direct_tof_candidate_count"] = len(rows)
    if len(rows) < args.minimum_group_count:
        return skip(args, report, "SKIPPED_INSUFFICIENT_DIRECT_TOF_SUPPORT", "Insufficient H2.2 candidates inside direct ToF range.")
    raw_distance, raw_distance_spread = group_summary(rows, "distance_bucket_h23", "scale_mm_per_colmap_unit", args.minimum_group_count, scale_label=True)
    distance_buckets, distance_knots = build_distance_model(rows, args.minimum_group_count)
    report["distance_model"] = {
        "method": "piecewise-linear interpolation of robust distance-bin median scale; diagnostic only",
        "buckets": distance_buckets,
        "knots": [{"center_mm": c, "scale_mm_per_colmap_unit": s, "bucket": n} for c, s, n in distance_knots],
    }
    if len(distance_knots) < 2:
        return skip(args, report, "SKIPPED_INSUFFICIENT_DISTANCE_SUPPORT", "At least two supported distance bins are required.")
    normalized_rows = []
    for row in rows:
        expected = interpolate_knots(distance_knots, row["distance_mm"])
        if not finite(expected) or float(expected) <= 0:
            continue
        row["expected_scale_from_distance"] = expected
        row["distance_normalized_ratio"] = float(row["scale_mm_per_colmap_unit"]) / float(expected)
        normalized_rows.append(row)
    row_norm, row_norm_spread = group_summary(normalized_rows, "zone_row", "distance_normalized_ratio", args.minimum_group_count)
    col_norm, col_norm_spread = group_summary(normalized_rows, "zone_column", "distance_normalized_ratio", args.minimum_group_count)
    radial_norm, radial_norm_spread = group_summary(normalized_rows, "zone_radial", "distance_normalized_ratio", args.minimum_group_count)
    image_norm, image_norm_spread = group_summary(normalized_rows, "image_region", "distance_normalized_ratio", args.minimum_group_count)
    time_norm, time_norm_spread = group_summary(normalized_rows, "time_quartile", "distance_normalized_ratio", args.minimum_group_count)
    row_factors, col_factors, effect_diag = estimate_row_column_effects(normalized_rows, args.minimum_group_count, args.row_column_iterations)
    full_abs = []
    for row in normalized_rows:
        rf = row_factors.get(str(row.get("zone_row")), 1.0)
        cf = col_factors.get(str(row.get("zone_column")), 1.0)
        zone_factor = rf * cf
        row["estimated_zone_factor"] = zone_factor
        row["zone_normalized_scale_mm_per_colmap_unit"] = float(row["scale_mm_per_colmap_unit"]) / zone_factor
        expected = float(row["expected_scale_from_distance"])
        row["fully_normalized_ratio"] = float(row["scale_mm_per_colmap_unit"]) / (expected * zone_factor)
        full_abs.append(abs(float(row["fully_normalized_ratio"]) - 1.0))
    zone_distance, zone_distance_spread = group_summary(normalized_rows, "distance_bucket_h23", "zone_normalized_scale_mm_per_colmap_unit", args.minimum_group_count, scale_label=True)
    report["raw_decomposition"] = {"distance": raw_distance, "distance_scale_spread_ratio": raw_distance_spread}
    report["distance_normalized_decomposition"] = {
        "zone_row": row_norm, "zone_column": col_norm, "zone_radial": radial_norm,
        "image_region": image_norm, "time_quartile": time_norm,
        "spread_ratios": {"zone_row": row_norm_spread, "zone_column": col_norm_spread, "zone_radial": radial_norm_spread, "image_region": image_norm_spread, "time_quartile": time_norm_spread},
    }
    report["zone_model"] = {
        "method": "alternating median row/column effects in log scale after distance normalization; diagnostic only",
        "row_factors": row_factors, "column_factors": col_factors, "diagnostics": effect_diag,
    }
    report["zone_normalized_decomposition"] = {"distance": zone_distance, "distance_scale_spread_ratio": zone_distance_spread}
    report["fully_normalized_residual"] = {
        "count": len(full_abs), "absolute_ratio_error_p50": percentile(full_abs, 0.50),
        "absolute_ratio_error_p95": percentile(full_abs, 0.95),
        "absolute_ratio_error_max": max(full_abs) if full_abs else None,
    }
    optics = optics_audit(args.camera_metadata, args.sparse_model_dir)
    report["camera_optics_audit"] = optics
    signals = {
        "raw_distance_deformation_signal": finite(raw_distance_spread) and float(raw_distance_spread) >= 1.15,
        "distance_effect_persists_after_zone_normalization": finite(zone_distance_spread) and float(zone_distance_spread) >= 1.15,
        "zone_row_effect_persists_after_distance_normalization": finite(row_norm_spread) and float(row_norm_spread) >= 1.10,
        "zone_column_effect_persists_after_distance_normalization": finite(col_norm_spread) and float(col_norm_spread) >= 1.10,
        "zone_radial_effect_persists_after_distance_normalization": finite(radial_norm_spread) and float(radial_norm_spread) >= 1.10,
        "image_region_effect_persists_after_distance_normalization": finite(image_norm_spread) and float(image_norm_spread) >= 1.10,
        "time_effect_persists_after_distance_normalization": finite(time_norm_spread) and float(time_norm_spread) >= 1.10,
        "camera_optics_drift_signal": bool(optics.get("camera_optics_drift_signal", False)),
        "note": "Signals show residual correlation after controlled normalization. They are diagnostic evidence, not automatic proof of a physical root cause.",
    }
    report["controlled_signals"] = signals
    likely = []
    if signals["distance_effect_persists_after_zone_normalization"]:
        likely.append("DEPTH_GEOMETRY_DEFORMATION_REMAINS_AFTER_ZONE_CONTROL")
    if signals["zone_row_effect_persists_after_distance_normalization"] or signals["zone_column_effect_persists_after_distance_normalization"]:
        likely.append("TOF_TO_RGB_ANGULAR_OR_ZONE_CALIBRATION_RESIDUAL_REMAINS")
    if signals["image_region_effect_persists_after_distance_normalization"]:
        likely.append("RGB_IMAGE_REGION_GEOMETRY_RESIDUAL_REMAINS")
    if signals["camera_optics_drift_signal"]:
        likely.append("COLMAP_OPTIMIZED_CAMERA_DIFFERS_MATERIALLY_FROM_CAMERA2_PRIOR")
    if signals["time_effect_persists_after_distance_normalization"]:
        likely.append("TEMPORAL_OR_TRAJECTORY_CORRELATION_REMAINS")
    report["diagnostic_hypotheses"] = likely
    report["status"] = "MEASURED"
    report["geometry_mutation_enabled"] = False
    report["ready_for_geometry_mutation"] = False
    report["sparse_model_modified"] = False
    report["camera_poses_modified"] = False
    report["points3d_modified"] = False
    report["dense_input_modified"] = False
    report["dense_depth_modified"] = False
    report["fusion_enabled"] = False
    write_outputs(args, report, normalized_rows)
    print(
        "INFO | TOF_DENSE_H23 | status=MEASURED measurement_only=yes "
        f"strategy={args.strategy} direct_candidates={len(normalized_rows)} "
        f"raw_distance_spread={raw_distance_spread} "
        f"distance_normalized_row_spread={row_norm_spread} "
        f"distance_normalized_col_spread={col_norm_spread} "
        f"zone_normalized_distance_spread={zone_distance_spread} "
        f"optics_drift={bool(optics.get('camera_optics_drift_signal', False))} "
        "geometry_mutation=OFF fusion=OFF"
    )
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
