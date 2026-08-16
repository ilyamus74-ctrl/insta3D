#!/usr/bin/env python3
import argparse
import json
import math
import statistics
from collections import defaultdict
from pathlib import Path

import numpy as np

import measure_tof_sparse_scale as h2
import measure_tof_sparse_scale_h21 as h21

MAP_TYPES = ("geometric", "photometric")
FOOTPRINT_STATS = ("p25", "p50", "p75", "front_cluster")


def load_depth_map(path):
    path = Path(path)
    with path.open("rb") as handle:
        header = bytearray()
        ampersands = 0
        while ampersands < 3:
            byte = handle.read(1)
            if not byte:
                raise ValueError(f"invalid COLMAP depth header: {path}")
            header.extend(byte)
            if byte == b"&":
                ampersands += 1
        parts = header.decode("ascii", errors="strict").split("&")[:3]
        width, height, channels = [int(value) for value in parts]
        data = np.fromfile(handle, dtype=np.float32)

    expected = width * height * channels
    if data.size != expected:
        raise ValueError(
            f"depth size mismatch for {path}: got={data.size} expected={expected}"
        )
    array = data.reshape((width, height, channels), order="F")
    array = np.transpose(array, (1, 0, 2))
    if channels == 1:
        array = array[:, :, 0]
    return array


def image_name_from_depth_path(path, map_type):
    suffix = f".{map_type}.bin"
    name = Path(path).name
    return name[:-len(suffix)] if name.endswith(suffix) else None


def discover_dense_workspaces(dense_job_dir, requested_types):
    dense_job_dir = Path(dense_job_dir)
    workspaces = []
    chunks_root = dense_job_dir / "chunks"
    if not chunks_root.is_dir():
        return workspaces

    for chunk_dir in sorted(
        [path for path in chunks_root.glob("chunk_*") if path.is_dir()],
        key=lambda path: path.name,
    ):
        model_dir = chunk_dir / "workspace_model_text"
        cameras_path = model_dir / "cameras.txt"
        images_path = model_dir / "images.txt"
        depth_dir = chunk_dir / "undistorted" / "stereo" / "depth_maps"
        if not cameras_path.is_file() or not images_path.is_file():
            continue
        if not depth_dir.is_dir():
            continue

        cameras = h2.parse_cameras(cameras_path)
        images = h2.parse_images(images_path)
        depth_index = defaultdict(dict)
        for map_type in requested_types:
            for path in depth_dir.glob(f"*.{map_type}.bin"):
                image_name = image_name_from_depth_path(path, map_type)
                if image_name:
                    depth_index[image_name][map_type] = str(path)

        if not depth_index:
            continue

        workspaces.append({
            "chunk": chunk_dir.name,
            "chunk_dir": str(chunk_dir),
            "cameras": cameras,
            "images": images,
            "depth_index": dict(depth_index),
        })

    return workspaces


def scale_uv_to_depth(uv, camera, depth_shape):
    if uv is None:
        return None
    height, width = int(depth_shape[0]), int(depth_shape[1])
    camera_width = float(camera.get("width") or 0)
    camera_height = float(camera.get("height") or 0)
    if camera_width <= 0.0 or camera_height <= 0.0:
        return None
    sx = width / camera_width
    sy = height / camera_height
    return [
        (float(uv[0]) + 0.5) * sx - 0.5,
        (float(uv[1]) + 0.5) * sy - 0.5,
    ]


def scale_polygon_to_depth(polygon, camera, depth_shape):
    scaled = []
    for point in polygon:
        uv = scale_uv_to_depth(point, camera, depth_shape)
        if uv is None:
            return None
        scaled.append(uv)
    return scaled


def valid_depth(value):
    return np.isfinite(value) & (value > 0.0)


def sample_center(depth, uv):
    if uv is None:
        return None
    height, width = depth.shape[:2]
    x = int(round(float(uv[0])))
    y = int(round(float(uv[1])))
    if x < 0 or y < 0 or x >= width or y >= height:
        return None
    value = float(depth[y, x])
    return value if math.isfinite(value) and value > 0.0 else None


def polygon_depth_values(depth, polygon):
    if not polygon or len(polygon) < 3:
        return np.empty((0,), dtype=np.float32)

    height, width = depth.shape[:2]
    min_x = max(0, int(math.floor(min(point[0] for point in polygon))))
    max_x = min(width - 1, int(math.ceil(max(point[0] for point in polygon))))
    min_y = max(0, int(math.floor(min(point[1] for point in polygon))))
    max_y = min(height - 1, int(math.ceil(max(point[1] for point in polygon))))
    if min_x > max_x or min_y > max_y:
        return np.empty((0,), dtype=np.float32)

    xs = np.arange(min_x, max_x + 1, dtype=np.float64)
    ys = np.arange(min_y, max_y + 1, dtype=np.float64)
    grid_x, grid_y = np.meshgrid(xs, ys)
    inside = np.zeros(grid_x.shape, dtype=bool)

    j = len(polygon) - 1
    for i in range(len(polygon)):
        xi, yi = float(polygon[i][0]), float(polygon[i][1])
        xj, yj = float(polygon[j][0]), float(polygon[j][1])
        denom = yj - yi
        if abs(denom) < 1e-12:
            denom = 1e-12
        crossing = (
            ((yi > grid_y) != (yj > grid_y))
            & (
                grid_x
                < ((xj - xi) * (grid_y - yi) / denom + xi)
            )
        )
        inside ^= crossing
        j = i

    sub = depth[min_y:max_y + 1, min_x:max_x + 1]
    mask = inside & valid_depth(sub)
    if not np.any(mask):
        return np.empty((0,), dtype=np.float32)
    return np.asarray(sub[mask], dtype=np.float32)


def front_depth_cluster(values, relative_gap=0.08, minimum_pixels=4):
    if values.size < minimum_pixels:
        return None, 0, 0
    ordered = np.sort(values.astype(np.float64, copy=False))
    clusters = []
    start = 0
    for index in range(1, ordered.size):
        previous = max(float(ordered[index - 1]), 1e-12)
        gap = (float(ordered[index]) - float(ordered[index - 1])) / previous
        if gap > relative_gap:
            clusters.append(ordered[start:index])
            start = index
    clusters.append(ordered[start:])

    required = max(minimum_pixels, int(math.ceil(ordered.size * 0.05)))
    for cluster in clusters:
        if cluster.size >= required:
            return float(np.median(cluster)), int(cluster.size), len(clusters)
    return None, 0, len(clusters)


def observation_key(observation):
    return (
        str(observation.get("image")),
        int(observation.get("tof_sequence") or -1),
        int(observation.get("zone_index") or -1),
    )


def zone_radial_bucket(row, column):
    if not isinstance(row, int) or not isinstance(column, int):
        return "unknown"
    radius = math.hypot(float(column) - 3.5, float(row) - 3.5)
    if radius <= 1.5:
        return "center"
    if radius <= 3.0:
        return "mid"
    return "edge"


def sigma_bucket(sigma):
    if not h2.finite(sigma):
        return "unknown"
    value = float(sigma)
    if value <= 5.0:
        return "0_5"
    if value <= 10.0:
        return "5_10"
    if value <= 20.0:
        return "10_20"
    if value <= 40.0:
        return "20_40"
    return "over_40"


def distance_bucket(distance):
    if not h2.finite(distance):
        return "unknown"
    value = float(distance)
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
    return "over_4m"


def image_region(uv, camera):
    if uv is None:
        return "unknown", None
    width = float(camera.get("width") or 0)
    height = float(camera.get("height") or 0)
    if width <= 0.0 or height <= 0.0:
        return "unknown", None
    nx = (float(uv[0]) - width * 0.5) / max(width * 0.5, 1.0)
    ny = (float(uv[1]) - height * 0.5) / max(height * 0.5, 1.0)
    radius = math.hypot(nx, ny)
    if radius <= 0.35:
        return "center", radius
    if radius <= 0.75:
        return "mid", radius
    return "edge", radius


def percentile(values, fraction):
    return h2.percentile(values, fraction)


def summarize_rows(rows):
    if not rows:
        return {
            "candidate_count": 0,
            "inlier_count": 0,
            "inlier_ratio": 0.0,
            "scale": {},
            "residuals": {},
        }

    scales = [float(row["scale_mm_per_colmap_unit"]) for row in rows]
    initial = statistics.median(scales)
    relative = [abs(value - initial) / initial for value in scales]
    mad_relative = statistics.median(relative)
    threshold = max(0.05, min(0.25, 3.0 * 1.4826 * mad_relative))
    preliminary = [
        row
        for row in rows
        if abs(float(row["scale_mm_per_colmap_unit"]) - initial) / initial
        <= threshold
    ]
    if not preliminary:
        return {
            "candidate_count": len(rows),
            "inlier_count": 0,
            "inlier_ratio": 0.0,
            "scale": {
                "initial_median_mm_per_colmap_unit": initial,
                "mad_relative": mad_relative,
                "inlier_threshold_relative": threshold,
            },
            "residuals": {},
        }

    robust = statistics.median([
        float(row["scale_mm_per_colmap_unit"])
        for row in preliminary
    ])
    inliers = []
    for row in rows:
        value = float(row["scale_mm_per_colmap_unit"])
        row["relative_scale_deviation"] = abs(value - robust) / robust
        row["inlier"] = abs(value - initial) / initial <= threshold
        predicted = float(row["dense_depth_units"]) * robust
        row["predicted_tof_z_mm"] = predicted
        row["depth_residual_mm"] = abs(predicted - float(row["tof_camera_z_mm"]))
        row["depth_relative_error"] = (
            row["depth_residual_mm"] / float(row["tof_camera_z_mm"])
        )
        if row["inlier"]:
            inliers.append(row)

    residuals = [float(row["depth_residual_mm"]) for row in inliers]
    relative_errors = [float(row["depth_relative_error"]) for row in inliers]
    return {
        "candidate_count": len(rows),
        "inlier_count": len(inliers),
        "inlier_ratio": len(inliers) / len(rows),
        "scale": {
            "initial_median_mm_per_colmap_unit": initial,
            "robust_mm_per_colmap_unit": robust,
            "robust_m_per_colmap_unit": robust / 1000.0,
            "mad_relative": mad_relative,
            "inlier_threshold_relative": threshold,
        },
        "residuals": {
            "inlier_depth_error_p50_mm": percentile(residuals, 0.50),
            "inlier_depth_error_p95_mm": percentile(residuals, 0.95),
            "inlier_relative_error_p50": percentile(relative_errors, 0.50),
            "inlier_relative_error_p95": percentile(relative_errors, 0.95),
        },
    }


def group_summary(rows, field):
    grouped = defaultdict(list)
    for row in rows:
        grouped[str(row.get(field, "unknown"))].append(row)
    result = {}
    for key in sorted(grouped):
        group = grouped[key]
        scales = [float(row["scale_mm_per_colmap_unit"]) for row in group]
        inliers = [row for row in group if row.get("inlier")]
        residuals = [float(row["depth_residual_mm"]) for row in inliers]
        relative = [float(row["depth_relative_error"]) for row in inliers]
        result[key] = {
            "count": len(group),
            "inliers": len(inliers),
            "scale_median_mm_per_colmap_unit": statistics.median(scales),
            "depth_error_p50_mm": percentile(residuals, 0.50),
            "depth_error_p95_mm": percentile(residuals, 0.95),
            "relative_error_p50": percentile(relative, 0.50),
            "relative_error_p95": percentile(relative, 0.95),
        }
    return result


def spread_ratio(grouped, minimum_count=20):
    medians = [
        float(value["scale_median_mm_per_colmap_unit"])
        for value in grouped.values()
        if value.get("count", 0) >= minimum_count
        and h2.finite(value.get("scale_median_mm_per_colmap_unit"))
    ]
    if len(medians) < 2 or min(medians) <= 0.0:
        return None
    return max(medians) / min(medians)


def add_time_quartiles(rows):
    times = [
        float(row["video_timestamp_sec"])
        for row in rows
        if h2.finite(row.get("video_timestamp_sec"))
    ]
    if not times:
        for row in rows:
            row["time_quartile"] = "unknown"
        return
    start = min(times)
    end = max(times)
    span = max(end - start, 1e-12)
    for row in rows:
        if not h2.finite(row.get("video_timestamp_sec")):
            row["time_quartile"] = "unknown"
            continue
        fraction = (float(row["video_timestamp_sec"]) - start) / span
        index = min(3, max(0, int(fraction * 4.0)))
        row["time_quartile"] = f"Q{index + 1}"


def finalize_strategy(rows):
    add_time_quartiles(rows)
    summary = summarize_rows(rows)
    if not rows or not summary.get("scale", {}).get("robust_mm_per_colmap_unit"):
        summary["diagnostics"] = {}
        summary["decomposition"] = {}
        return summary

    decomposition = {
        "distance": group_summary(rows, "distance_bucket"),
        "zone_row": group_summary(rows, "zone_row"),
        "zone_column": group_summary(rows, "zone_column"),
        "zone_radial": group_summary(rows, "zone_radial"),
        "sigma": group_summary(rows, "sigma_bucket"),
        "target_status": group_summary(rows, "target_status_key"),
        "time_quartile": group_summary(rows, "time_quartile"),
        "image_region": group_summary(rows, "image_region"),
    }
    summary["decomposition"] = decomposition
    summary["diagnostics"] = {
        "distance_scale_spread_ratio": spread_ratio(decomposition["distance"]),
        "zone_row_scale_spread_ratio": spread_ratio(decomposition["zone_row"]),
        "zone_column_scale_spread_ratio": spread_ratio(decomposition["zone_column"]),
        "zone_radial_scale_spread_ratio": spread_ratio(decomposition["zone_radial"]),
        "sigma_scale_spread_ratio": spread_ratio(decomposition["sigma"]),
        "time_scale_spread_ratio": spread_ratio(decomposition["time_quartile"]),
        "image_region_scale_spread_ratio": spread_ratio(decomposition["image_region"]),
    }
    return summary


def base_report(args, requested_types):
    return {
        "schema_version": 1,
        "stage": "SFM-S01H2.2",
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
            "map_types": requested_types,
            "front_cluster_relative_gap": args.front_cluster_relative_gap,
            "minimum_front_cluster_pixels": args.minimum_front_cluster_pixels,
        },
        "metric_policy": {
            "direct_tof_reference_min_mm": 100.0,
            "direct_tof_reference_max_mm": 4000.0,
            "within_range": "TOF_METRIC_REFERENCE",
            "beyond_4m": "APPROXIMATE_ONLY",
            "beyond_4m_rule": (
                "Distant geometry may inherit a scale estimated from validated <=4m "
                "anchors, but dimensions beyond direct ToF range must not be labeled "
                "as directly measured or factual."
            ),
            "tof_extrapolation_beyond_range": False,
        },
        "strategies": {},
        "dense_inventory": {},
        "next_gate": (
            "Use H2.2 to distinguish depth-dependent COLMAP geometry, ToF-zone "
            "calibration bias, image-region effects, and temporal drift. H3 remains closed."
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
            "stage": "SFM-S01H2.2",
            "status": report["status"],
            "measurement_only": True,
            "geometry_mutation_enabled": False,
            "dense_input_modified": False,
            "fusion_enabled": False,
        }) + "\n")
        for row in rows:
            handle.write(json.dumps(row, ensure_ascii=False) + "\n")


def skip(args, report, status, reason):
    report["status"] = status
    report["skip_reason"] = reason
    write_outputs(args, report, [])
    print(
        "INFO | TOF_DENSE_H22 | "
        f"status={status} measurement_only=yes geometry_mutation=OFF fusion=OFF"
    )
    return 0


def parse_map_types(value):
    requested = []
    for part in str(value).split(","):
        part = part.strip().lower()
        if not part:
            continue
        if part not in MAP_TYPES:
            raise ValueError(f"unsupported map type: {part}")
        requested.append(part)
    if not requested:
        raise ValueError("at least one depth map type is required")
    return sorted(set(requested), key=MAP_TYPES.index)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--observations", required=True)
    parser.add_argument("--observation-report", required=True)
    parser.add_argument("--tof-calibration", required=True)
    parser.add_argument("--dense-job-dir", required=True)
    parser.add_argument("--output-jsonl", required=True)
    parser.add_argument("--report-json", required=True)
    parser.add_argument("--map-types", default="geometric,photometric")
    parser.add_argument("--front-cluster-relative-gap", type=float, default=0.08)
    parser.add_argument("--minimum-front-cluster-pixels", type=int, default=4)
    args = parser.parse_args()

    try:
        requested_types = parse_map_types(args.map_types)
    except ValueError as exc:
        raise SystemExit(str(exc))
    if not (0.01 <= args.front_cluster_relative_gap <= 0.5):
        raise SystemExit("front-cluster-relative-gap must be in [0.01,0.5]")
    if args.minimum_front_cluster_pixels < 1:
        raise SystemExit("minimum-front-cluster-pixels must be >=1")

    report = base_report(args, requested_types)
    h1_report = h2.load_json(args.observation_report)
    if (
        h1_report.get("status") != "MEASURED"
        or h1_report.get("measurement_gate_pass") is not True
        or h1_report.get("ready_for_sparse_scale_measurement") is not True
    ):
        return skip(
            args,
            report,
            "SKIPPED_NO_TOF_MEASUREMENT",
            "S01H.1 did not produce an accepted ToF metric observation set.",
        )

    observations = h2.load_jsonl(args.observations, "tof_metric_observation")
    report["tof_observation_count"] = len(observations)
    if not observations:
        return skip(
            args,
            report,
            "SKIPPED_NO_TOF_MEASUREMENT",
            "No ToF metric observations are available.",
        )

    profile = h21.load_profile(args.tof_calibration, h1_report)
    if not h21.validate_profile(profile):
        return skip(
            args,
            report,
            "SKIPPED_CALIBRATION_UNAVAILABLE",
            "A unique valid ToF calibration profile was not available.",
        )

    workspaces = discover_dense_workspaces(args.dense_job_dir, requested_types)
    if not workspaces:
        return skip(
            args,
            report,
            "SKIPPED_DENSE_UNAVAILABLE",
            "No completed dense chunk workspace with requested depth maps was found.",
        )

    inventory = {
        "workspace_count": len(workspaces),
        "chunks": [],
        "map_counts": {map_type: 0 for map_type in requested_types},
    }
    for workspace in workspaces:
        counts = {map_type: 0 for map_type in requested_types}
        for maps in workspace["depth_index"].values():
            for map_type in requested_types:
                if map_type in maps:
                    counts[map_type] += 1
                    inventory["map_counts"][map_type] += 1
        inventory["chunks"].append({
            "chunk": workspace["chunk"],
            "image_count": len(workspace["images"]),
            "map_counts": counts,
        })
    report["dense_inventory"] = inventory

    observations_by_image = defaultdict(list)
    observation_lookup = {}
    for observation in observations:
        image_name = str(observation.get("image"))
        observations_by_image[image_name].append(observation)
        observation_lookup[observation_key(observation)] = observation

    # key: (observation_key, strategy) -> list of estimates from overlapping chunks.
    estimates = defaultdict(list)
    map_errors = []
    processed_maps = 0

    for workspace in workspaces:
        for image_name, maps in workspace["depth_index"].items():
            image_observations = observations_by_image.get(image_name)
            image = workspace["images"].get(image_name)
            if not image_observations or image is None:
                continue
            camera = workspace["cameras"].get(image["camera_id"])
            if camera is None or camera["model"] not in h2.SUPPORTED_CAMERA_MODELS:
                continue

            for map_type, depth_path in maps.items():
                if map_type not in requested_types:
                    continue
                try:
                    depth = load_depth_map(depth_path)
                except Exception as exc:
                    map_errors.append({
                        "path": depth_path,
                        "error": str(exc),
                    })
                    continue
                if depth.ndim != 2:
                    map_errors.append({
                        "path": depth_path,
                        "error": f"expected single-channel depth, got shape={depth.shape}",
                    })
                    continue
                processed_maps += 1

                for observation in image_observations:
                    tof_xyz = observation.get("camera_xyz_mm")
                    if (
                        not isinstance(tof_xyz, list)
                        or len(tof_xyz) != 3
                        or not all(h2.finite(value) for value in tof_xyz)
                        or float(tof_xyz[2]) <= 0.0
                    ):
                        continue
                    center_camera_uv = h2.project_camera_point(
                        camera, [float(value) for value in tof_xyz]
                    )
                    center_depth_uv = scale_uv_to_depth(
                        center_camera_uv, camera, depth.shape
                    )
                    region, region_radius = image_region(center_camera_uv, camera)
                    common = {
                        "chunk": workspace["chunk"],
                        "map_type": map_type,
                        "camera_model": camera["model"],
                        "camera_width": camera["width"],
                        "camera_height": camera["height"],
                        "depth_width": int(depth.shape[1]),
                        "depth_height": int(depth.shape[0]),
                        "center_camera_uv": center_camera_uv,
                        "center_depth_uv": center_depth_uv,
                        "image_region": region,
                        "image_region_radius": region_radius,
                    }
                    key = observation_key(observation)

                    center_value = sample_center(depth, center_depth_uv)
                    if center_value is not None:
                        estimates[(key, f"{map_type}_center")].append({
                            "depth": center_value,
                            **common,
                        })

                    polygon_camera = h21.zone_footprint_polygon(
                        profile, camera, observation
                    )
                    polygon_depth = (
                        scale_polygon_to_depth(polygon_camera, camera, depth.shape)
                        if polygon_camera else None
                    )
                    values = polygon_depth_values(depth, polygon_depth)
                    if values.size == 0:
                        continue
                    footprint_common = {
                        **common,
                        "footprint_valid_pixels": int(values.size),
                        "footprint_depth_min": float(np.min(values)),
                        "footprint_depth_p25": float(np.percentile(values, 25)),
                        "footprint_depth_p50": float(np.percentile(values, 50)),
                        "footprint_depth_p75": float(np.percentile(values, 75)),
                        "footprint_depth_max": float(np.max(values)),
                    }
                    for stat_name, percentile_value in (
                        ("p25", 25),
                        ("p50", 50),
                        ("p75", 75),
                    ):
                        value = float(np.percentile(values, percentile_value))
                        estimates[(key, f"{map_type}_footprint_{stat_name}")].append({
                            "depth": value,
                            **footprint_common,
                        })

                    front_value, front_pixels, cluster_count = front_depth_cluster(
                        values,
                        relative_gap=args.front_cluster_relative_gap,
                        minimum_pixels=args.minimum_front_cluster_pixels,
                    )
                    if front_value is not None:
                        estimates[(key, f"{map_type}_footprint_front_cluster")].append({
                            "depth": front_value,
                            "front_cluster_pixels": front_pixels,
                            "depth_cluster_count": cluster_count,
                            **footprint_common,
                        })

    report["dense_inventory"]["processed_map_count"] = processed_maps
    report["dense_inventory"]["map_errors"] = map_errors[:50]
    if processed_maps == 0:
        return skip(
            args,
            report,
            "SKIPPED_DENSE_UNAVAILABLE",
            "Dense depth map files were discovered but none could be decoded.",
        )

    rows_by_strategy = defaultdict(list)
    output_rows = []
    for (key, strategy), values in estimates.items():
        observation = observation_lookup.get(key)
        if observation is None or not values:
            continue
        depths = [float(value["depth"]) for value in values if h2.finite(value.get("depth")) and float(value["depth"]) > 0.0]
        if not depths:
            continue
        dense_depth = statistics.median(depths)
        tof_xyz = observation.get("camera_xyz_mm")
        tof_z = float(tof_xyz[2])
        scale = tof_z / dense_depth
        if not math.isfinite(scale) or scale <= 0.0:
            continue

        exemplar = values[0]
        row = {
            "type": "tof_dense_h22_candidate",
            "schema_version": 1,
            "stage": "SFM-S01H2.2",
            "strategy": strategy,
            "image": observation.get("image"),
            "video_timestamp_sec": observation.get("video_timestamp_sec"),
            "camera_frame_index": observation.get("camera_frame_index"),
            "tof_sequence": observation.get("tof_sequence"),
            "tof_slot": observation.get("tof_slot"),
            "zone_index": observation.get("zone_index"),
            "zone_row": observation.get("row"),
            "zone_column": observation.get("column"),
            "zone_radial": zone_radial_bucket(observation.get("row"), observation.get("column")),
            "distance_mm": observation.get("distance_mm"),
            "distance_bucket": distance_bucket(observation.get("distance_mm")),
            "sigma_mm": observation.get("sigma_mm"),
            "sigma_bucket": sigma_bucket(observation.get("sigma_mm")),
            "target_status": observation.get("target_status"),
            "target_status_key": str(observation.get("target_status")),
            "tof_camera_z_mm": tof_z,
            "dense_depth_units": dense_depth,
            "scale_mm_per_colmap_unit": scale,
            "overlap_chunk_count": len(depths),
            "dense_depth_across_chunks_p25": percentile(depths, 0.25),
            "dense_depth_across_chunks_p50": percentile(depths, 0.50),
            "dense_depth_across_chunks_p75": percentile(depths, 0.75),
            "image_region": exemplar.get("image_region", "unknown"),
            "image_region_radius": exemplar.get("image_region_radius"),
            "camera_model": exemplar.get("camera_model"),
            "camera_width": exemplar.get("camera_width"),
            "camera_height": exemplar.get("camera_height"),
            "depth_width": exemplar.get("depth_width"),
            "depth_height": exemplar.get("depth_height"),
        }
        footprint_counts = [
            int(value.get("footprint_valid_pixels", 0))
            for value in values
            if value.get("footprint_valid_pixels") is not None
        ]
        if footprint_counts:
            row["footprint_valid_pixels_p50"] = percentile(footprint_counts, 0.50)
        rows_by_strategy[strategy].append(row)
        output_rows.append(row)

    if not output_rows:
        report["status"] = "MEASURED_NO_CORRESPONDENCES"
        write_outputs(args, report, [])
        print(
            "INFO | TOF_DENSE_H22 | status=MEASURED_NO_CORRESPONDENCES "
            "measurement_only=yes geometry_mutation=OFF fusion=OFF"
        )
        return 0

    report["strategies"] = {
        strategy: finalize_strategy(rows)
        for strategy, rows in sorted(rows_by_strategy.items())
    }

    # Cross-strategy diagnostic hints. They are signals, not automatic conclusions.
    diagnostic_signals = {}
    preferred = None
    for candidate in (
        "geometric_footprint_p50",
        "geometric_center",
        "photometric_footprint_p50",
        "photometric_center",
    ):
        if candidate in report["strategies"] and report["strategies"][candidate].get("candidate_count", 0) > 0:
            preferred = candidate
            break
    if preferred:
        diag = report["strategies"][preferred].get("diagnostics", {})
        diagnostic_signals = {
            "reference_strategy": preferred,
            "distance_dependent_scale_signal": (
                diag.get("distance_scale_spread_ratio") is not None
                and diag["distance_scale_spread_ratio"] >= 1.15
            ),
            "zone_row_signal": (
                diag.get("zone_row_scale_spread_ratio") is not None
                and diag["zone_row_scale_spread_ratio"] >= 1.15
            ),
            "zone_column_signal": (
                diag.get("zone_column_scale_spread_ratio") is not None
                and diag["zone_column_scale_spread_ratio"] >= 1.15
            ),
            "zone_radial_signal": (
                diag.get("zone_radial_scale_spread_ratio") is not None
                and diag["zone_radial_scale_spread_ratio"] >= 1.15
            ),
            "time_drift_signal": (
                diag.get("time_scale_spread_ratio") is not None
                and diag["time_scale_spread_ratio"] >= 1.15
            ),
            "image_region_signal": (
                diag.get("image_region_scale_spread_ratio") is not None
                and diag["image_region_scale_spread_ratio"] >= 1.15
            ),
            "note": (
                "Signals identify where scale instability correlates with the data. "
                "They do not automatically prove root cause."
            ),
        }
    report["diagnostic_signals"] = diagnostic_signals
    report["status"] = "MEASURED"

    # H2.2 remains incapable of modifying geometry.
    report["geometry_mutation_enabled"] = False
    report["ready_for_geometry_mutation"] = False
    report["sparse_model_modified"] = False
    report["camera_poses_modified"] = False
    report["points3d_modified"] = False
    report["dense_input_modified"] = False
    report["dense_depth_modified"] = False
    report["fusion_enabled"] = False

    write_outputs(args, report, output_rows)

    compact = []
    for name, summary in report["strategies"].items():
        scale = summary.get("scale", {}).get("robust_mm_per_colmap_unit")
        p95 = summary.get("residuals", {}).get("inlier_depth_error_p95_mm")
        drift = summary.get("diagnostics", {}).get("distance_scale_spread_ratio")
        compact.append(
            f"{name}:n={summary.get('candidate_count',0)},"
            f"scale={scale if scale is not None else 'n/a'},"
            f"p95={p95 if p95 is not None else 'n/a'},"
            f"distance_spread={drift if drift is not None else 'n/a'}"
        )
    print(
        "INFO | TOF_DENSE_H22 | status=MEASURED measurement_only=yes | "
        + " | ".join(compact)
        + " | geometry_mutation=OFF fusion=OFF"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
