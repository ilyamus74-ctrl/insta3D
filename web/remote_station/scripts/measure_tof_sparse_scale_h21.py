#!/usr/bin/env python3
import argparse
import json
import math
import statistics
from pathlib import Path

import measure_tof_sparse_scale as h2


def load_profile(calibration_path, h1_report):
    snapshot = h2.load_json(calibration_path)
    profiles = snapshot.get("profiles")
    if not isinstance(profiles, list):
        return None

    wanted = h1_report.get("profile")
    if not isinstance(wanted, dict):
        wanted = {}

    candidates = []
    for profile in profiles:
        if not isinstance(profile, dict):
            continue
        if profile.get("status") != "solved":
            continue
        if (
            wanted.get("camera_calibration_profile_id")
            and profile.get("camera_calibration_profile_id")
            != wanted.get("camera_calibration_profile_id")
        ):
            continue
        if (
            wanted.get("tof_slot") is not None
            and profile.get("tof_slot") != wanted.get("tof_slot")
        ):
            continue
        if (
            wanted.get("rig_id")
            and profile.get("rig_id") != wanted.get("rig_id")
        ):
            continue
        if (
            wanted.get("rig_mount_revision")
            and profile.get("rig_mount_revision")
            != wanted.get("rig_mount_revision")
        ):
            continue
        candidates.append(profile)

    return candidates[0] if len(candidates) == 1 else None


def validate_profile(profile):
    if not isinstance(profile, dict):
        return False
    intrinsics = profile.get("tof_intrinsics")
    rotation = profile.get("rotation_tof_to_camera")
    translation = profile.get("translation_tof_to_camera_mm")
    if not isinstance(intrinsics, dict):
        return False
    for key in ("fx_zones", "fy_zones", "cx_zones", "cy_zones"):
        if not h2.finite(intrinsics.get(key)):
            return False
    if float(intrinsics["fx_zones"]) <= 0.0:
        return False
    if float(intrinsics["fy_zones"]) <= 0.0:
        return False
    if not isinstance(rotation, list) or len(rotation) != 9:
        return False
    if not isinstance(translation, list) or len(translation) != 3:
        return False
    if not all(h2.finite(value) for value in rotation + translation):
        return False
    width = profile.get("tof_width")
    height = profile.get("tof_height")
    if not isinstance(width, int) or width <= 0:
        return False
    if not isinstance(height, int) or height <= 0:
        return False
    return True


def tof_point_to_camera(profile, column, row, distance_mm):
    intrinsics = profile["tof_intrinsics"]
    fx = float(intrinsics["fx_zones"])
    fy = float(intrinsics["fy_zones"])
    cx = float(intrinsics["cx_zones"])
    cy = float(intrinsics["cy_zones"])

    z = float(distance_mm)
    x = z * ((float(column) - cx) / fx)
    y = z * ((float(row) - cy) / fy)

    r = [float(value) for value in profile["rotation_tof_to_camera"]]
    t = [float(value) for value in profile["translation_tof_to_camera_mm"]]

    camera = [
        r[0] * x + r[1] * y + r[2] * z + t[0],
        r[3] * x + r[4] * y + r[5] * z + t[1],
        r[6] * x + r[7] * y + r[8] * z + t[2],
    ]
    if not all(math.isfinite(value) for value in camera):
        return None
    if camera[2] <= 0.0:
        return None
    return camera


def convex_hull(points):
    unique = sorted({
        (float(point[0]), float(point[1]))
        for point in points
        if (
            isinstance(point, (list, tuple))
            and len(point) == 2
            and h2.finite(point[0])
            and h2.finite(point[1])
        )
    })
    if len(unique) <= 2:
        return [list(point) for point in unique]

    def cross(origin, a, b):
        return (
            (a[0] - origin[0]) * (b[1] - origin[1])
            - (a[1] - origin[1]) * (b[0] - origin[0])
        )

    lower = []
    for point in unique:
        while (
            len(lower) >= 2
            and cross(lower[-2], lower[-1], point) <= 0.0
        ):
            lower.pop()
        lower.append(point)

    upper = []
    for point in reversed(unique):
        while (
            len(upper) >= 2
            and cross(upper[-2], upper[-1], point) <= 0.0
        ):
            upper.pop()
        upper.append(point)

    return [
        list(point)
        for point in (lower[:-1] + upper[:-1])
    ]


def polygon_area(polygon):
    if len(polygon) < 3:
        return 0.0
    area = 0.0
    for index, point in enumerate(polygon):
        nxt = polygon[(index + 1) % len(polygon)]
        area += point[0] * nxt[1] - nxt[0] * point[1]
    return abs(area) * 0.5


def point_in_polygon(x, y, polygon):
    if len(polygon) < 3:
        return False

    inside = False
    j = len(polygon) - 1
    for i in range(len(polygon)):
        xi, yi = polygon[i]
        xj, yj = polygon[j]

        dx = xj - xi
        dy = yj - yi
        cross = (x - xi) * dy - (y - yi) * dx
        if abs(cross) <= 1e-7:
            dot = (x - xi) * dx + (y - yi) * dy
            if dot >= -1e-7:
                length2 = dx * dx + dy * dy
                if dot <= length2 + 1e-7:
                    return True

        intersects = (
            (yi > y) != (yj > y)
            and x
            < (
                (xj - xi) * (y - yi)
                / ((yj - yi) if abs(yj - yi) > 1e-12 else 1e-12)
                + xi
            )
        )
        if intersects:
            inside = not inside
        j = i

    return inside


def zone_footprint_polygon(profile, camera, observation):
    row = observation.get("row")
    column = observation.get("column")
    distance_mm = observation.get("distance_mm")
    if (
        not isinstance(row, int)
        or not isinstance(column, int)
        or not h2.finite(distance_mm)
        or float(distance_mm) <= 0.0
    ):
        return None

    c0 = float(column) - 0.5
    c1 = float(column) + 0.5
    r0 = float(row) - 0.5
    r1 = float(row) + 0.5
    cm = float(column)
    rm = float(row)

    # Eight samples approximate the distorted zone boundary more safely
    # than a four-corner rectangle.
    boundary = [
        (c0, r0),
        (cm, r0),
        (c1, r0),
        (c1, rm),
        (c1, r1),
        (cm, r1),
        (c0, r1),
        (c0, rm),
    ]

    projected = []
    for col_coord, row_coord in boundary:
        camera_xyz = tof_point_to_camera(
            profile,
            col_coord,
            row_coord,
            float(distance_mm),
        )
        if camera_xyz is None:
            return None
        uv = h2.project_camera_point(camera, camera_xyz)
        if uv is None:
            return None
        projected.append(uv)

    polygon = convex_hull(projected)
    if len(polygon) < 3 or polygon_area(polygon) <= 0.0:
        return None
    return polygon


def sparse_points_in_polygon(image, points3d, polygon):
    rows = []
    for x, y, point_id in image["points2d"]:
        if not point_in_polygon(x, y, polygon):
            continue
        world_xyz = points3d.get(point_id)
        if world_xyz is None:
            continue
        camera_xyz = h2.world_to_camera(image, world_xyz)
        if camera_xyz is None or camera_xyz[2] <= 0.0:
            continue
        rows.append({
            "point3d_id": point_id,
            "feature_xy": [x, y],
            "world_xyz": world_xyz,
            "camera_xyz_units": camera_xyz,
        })
    return rows


def depth_clusters(points, relative_gap=0.10):
    ordered = sorted(
        points,
        key=lambda row: float(row["camera_xyz_units"][2]),
    )
    if not ordered:
        return []

    clusters = [[ordered[0]]]
    for row in ordered[1:]:
        previous_z = float(
            clusters[-1][-1]["camera_xyz_units"][2]
        )
        current_z = float(row["camera_xyz_units"][2])
        relative = (
            (current_z - previous_z) / max(previous_z, 1e-12)
        )
        if relative > relative_gap:
            clusters.append([row])
        else:
            clusters[-1].append(row)
    return clusters


def quantiles(values):
    if not values:
        return {
            "min": None,
            "p25": None,
            "p50": None,
            "p75": None,
            "max": None,
        }
    return {
        "min": min(values),
        "p25": h2.percentile(values, 0.25),
        "p50": h2.percentile(values, 0.50),
        "p75": h2.percentile(values, 0.75),
        "max": max(values),
    }


def make_scale_row(
    strategy,
    observation,
    sparse_z_units,
    extra=None,
):
    tof_xyz = observation.get("camera_xyz_mm")
    if (
        not isinstance(tof_xyz, list)
        or len(tof_xyz) != 3
        or not all(h2.finite(value) for value in tof_xyz)
        or float(tof_xyz[2]) <= 0.0
        or not h2.finite(sparse_z_units)
        or float(sparse_z_units) <= 0.0
    ):
        return None

    tof_z_mm = float(tof_xyz[2])
    sparse_z_units = float(sparse_z_units)
    scale = tof_z_mm / sparse_z_units
    if not math.isfinite(scale) or scale <= 0.0:
        return None

    row = {
        "strategy": strategy,
        "image": observation.get("image"),
        "tof_sequence": observation.get("tof_sequence"),
        "tof_slot": observation.get("tof_slot"),
        "zone_index": observation.get("zone_index"),
        "row": observation.get("row"),
        "column": observation.get("column"),
        "distance_mm": observation.get("distance_mm"),
        "sigma_mm": observation.get("sigma_mm"),
        "target_status": observation.get("target_status"),
        "tof_camera_z_mm": tof_z_mm,
        "sparse_z_units": sparse_z_units,
        "scale_mm_per_colmap_unit": scale,
    }
    if isinstance(extra, dict):
        row.update(extra)
    return row


def robust_summary(rows):
    if not rows:
        return {
            "candidate_count": 0,
            "inlier_count": 0,
            "inlier_ratio": 0.0,
            "scale": {},
            "residuals": {},
            "per_image_scale": {},
            "distance_buckets": {},
        }

    scales = [
        float(row["scale_mm_per_colmap_unit"])
        for row in rows
    ]
    initial = statistics.median(scales)
    relative = [
        abs(value - initial) / initial
        for value in scales
    ]
    mad_relative = statistics.median(relative)
    threshold = max(
        0.05,
        min(0.25, 3.0 * 1.4826 * mad_relative),
    )

    initial_inliers = [
        row
        for row in rows
        if (
            abs(
                float(row["scale_mm_per_colmap_unit"])
                - initial
            )
            / initial
            <= threshold
        )
    ]
    if not initial_inliers:
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
            "per_image_scale": {},
            "distance_buckets": {},
        }

    robust = statistics.median([
        float(row["scale_mm_per_colmap_unit"])
        for row in initial_inliers
    ])

    inliers = []
    for row in rows:
        scale_value = float(row["scale_mm_per_colmap_unit"])
        row["relative_scale_deviation"] = (
            abs(scale_value - robust) / robust
        )
        row["inlier"] = (
            abs(scale_value - initial) / initial
            <= threshold
        )
        predicted = float(row["sparse_z_units"]) * robust
        row["predicted_tof_z_mm"] = predicted
        row["depth_residual_mm"] = abs(
            predicted - float(row["tof_camera_z_mm"])
        )
        row["depth_relative_error"] = (
            row["depth_residual_mm"]
            / float(row["tof_camera_z_mm"])
        )
        if row["inlier"]:
            inliers.append(row)

    residuals = [
        float(row["depth_residual_mm"])
        for row in inliers
    ]
    relative_errors = [
        float(row["depth_relative_error"])
        for row in inliers
    ]

    per_image = {}
    for row in rows:
        per_image.setdefault(
            str(row["image"]),
            [],
        ).append(float(row["scale_mm_per_colmap_unit"]))
    image_medians = [
        statistics.median(values)
        for values in per_image.values()
        if values
    ]
    image_deviation = [
        abs(value - robust) / robust
        for value in image_medians
    ]

    buckets = {}
    for name in (
        "0_1m",
        "1_2m",
        "2_3m",
        "3_4m",
        "over_4m",
    ):
        group = [
            row
            for row in rows
            if (
                h2.finite(row.get("distance_mm"))
                and h2.bucket_name(
                    float(row["distance_mm"])
                ) == name
            )
        ]
        group_inliers = [
            row for row in group if row.get("inlier")
        ]
        group_residuals = [
            float(row["depth_residual_mm"])
            for row in group_inliers
        ]
        buckets[name] = {
            "count": len(group),
            "inliers": len(group_inliers),
            "scale_median_mm_per_colmap_unit": (
                statistics.median([
                    float(row["scale_mm_per_colmap_unit"])
                    for row in group
                ])
                if group else None
            ),
            "inlier_residual_p50_mm":
                h2.percentile(group_residuals, 0.50),
            "inlier_residual_p95_mm":
                h2.percentile(group_residuals, 0.95),
        }

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
            "inlier_depth_error_p50_mm":
                h2.percentile(residuals, 0.50),
            "inlier_depth_error_p95_mm":
                h2.percentile(residuals, 0.95),
            "inlier_relative_error_p50":
                h2.percentile(relative_errors, 0.50),
            "inlier_relative_error_p95":
                h2.percentile(relative_errors, 0.95),
        },
        "per_image_scale": {
            "images_with_candidates": len(image_medians),
            "median_mm_per_colmap_unit_p50":
                h2.percentile(image_medians, 0.50),
            "median_mm_per_colmap_unit_p95":
                h2.percentile(image_medians, 0.95),
            "relative_deviation_p50":
                h2.percentile(image_deviation, 0.50),
            "relative_deviation_p95":
                h2.percentile(image_deviation, 0.95),
        },
        "distance_buckets": buckets,
    }


def parse_radii(value):
    radii = []
    for part in str(value).split(","):
        part = part.strip()
        if not part:
            continue
        radius = float(part)
        if radius <= 0.0:
            raise ValueError("radius must be positive")
        radii.append(radius)
    if not radii:
        raise ValueError("at least one radius is required")
    return sorted(set(radii))


def base_report(args, radii):
    return {
        "schema_version": 1,
        "stage": "SFM-S01H2.1",
        "status": "STARTING",
        "measurement_only": True,
        "geometry_mutation_enabled": False,
        "ready_for_geometry_mutation": False,
        "sparse_model_modified": False,
        "camera_poses_modified": False,
        "points3d_modified": False,
        "dense_input_modified": False,
        "fusion_enabled": False,
        "parameters": {
            "nearest_radius_sweep_px": radii,
            "minimum_footprint_points":
                args.minimum_footprint_points,
            "front_cluster_relative_gap":
                args.front_cluster_relative_gap,
        },
        "strategies": {},
        "footprint": {},
        "next_gate": (
            "Compare nearest-radius and ToF-zone-footprint scale stability. "
            "Do not open S01H.3 from this report automatically."
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
            "stage": "SFM-S01H2.1",
            "status": report["status"],
            "measurement_only": True,
            "geometry_mutation_enabled": False,
            "fusion_enabled": False,
        }) + "\n")
        for row in rows:
            handle.write(json.dumps(row, ensure_ascii=False) + "\n")


def skip(args, report, status, reason):
    report["status"] = status
    report["skip_reason"] = reason
    write_outputs(args, report, [])
    print(
        "INFO | TOF_SPARSE_H21 | "
        f"status={status} measurement_only=yes "
        "geometry_mutation=OFF fusion=OFF"
    )
    return 0


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--observations", required=True)
    parser.add_argument("--observation-report", required=True)
    parser.add_argument("--tof-calibration", required=True)
    parser.add_argument("--model-dir", required=True)
    parser.add_argument("--output-jsonl", required=True)
    parser.add_argument("--report-json", required=True)
    parser.add_argument("--nearest-radii", default="4,8,12,24")
    parser.add_argument(
        "--minimum-footprint-points",
        type=int,
        default=2,
    )
    parser.add_argument(
        "--front-cluster-relative-gap",
        type=float,
        default=0.10,
    )
    args = parser.parse_args()

    try:
        radii = parse_radii(args.nearest_radii)
    except ValueError as exc:
        raise SystemExit(str(exc))

    if args.minimum_footprint_points < 1:
        raise SystemExit("minimum-footprint-points must be >= 1")
    if not (0.01 <= args.front_cluster_relative_gap <= 1.0):
        raise SystemExit(
            "front-cluster-relative-gap must be in [0.01, 1.0]"
        )

    report = base_report(args, radii)

    h1_report = h2.load_json(args.observation_report)
    if (
        h1_report.get("status") != "MEASURED"
        or h1_report.get("measurement_gate_pass") is not True
        or h1_report.get(
            "ready_for_sparse_scale_measurement"
        ) is not True
    ):
        return skip(
            args,
            report,
            "SKIPPED_NO_TOF_MEASUREMENT",
            "S01H.1 did not produce an accepted ToF metric set.",
        )

    observations = h2.load_jsonl(
        args.observations,
        "tof_metric_observation",
    )
    report["tof_observation_count"] = len(observations)
    if not observations:
        return skip(
            args,
            report,
            "SKIPPED_NO_TOF_MEASUREMENT",
            "No ToF metric observations are available.",
        )

    profile = load_profile(
        args.tof_calibration,
        h1_report,
    )
    if not validate_profile(profile):
        return skip(
            args,
            report,
            "SKIPPED_CALIBRATION_UNAVAILABLE",
            "A unique valid ToF calibration profile was not available.",
        )

    text_dir = h2.find_text_model_dir(args.model_dir)
    if text_dir is None:
        return skip(
            args,
            report,
            "SKIPPED_SPARSE_TEXT_UNAVAILABLE",
            "COLMAP TXT model is unavailable.",
        )

    cameras = h2.parse_cameras(text_dir / "cameras.txt")
    images = h2.parse_images(text_dir / "images.txt")
    points3d = h2.parse_points3d(text_dir / "points3D.txt")

    report["registered_images"] = len(images)
    report["points3d"] = len(points3d)
    report["camera_models"] = sorted({
        camera["model"]
        for camera in cameras.values()
    })

    unsupported = sorted({
        camera["model"]
        for camera in cameras.values()
        if camera["model"] not in h2.SUPPORTED_CAMERA_MODELS
    })
    if unsupported:
        return skip(
            args,
            report,
            "SKIPPED_UNSUPPORTED_CAMERA_MODEL",
            "Unsupported COLMAP camera model: "
            + ",".join(unsupported),
        )

    nearest_rows = {radius: [] for radius in radii}
    footprint_median_rows = []
    footprint_front_rows = []
    output_rows = []

    registered_observations = 0
    footprint_valid = 0
    footprint_no_points = 0
    footprint_insufficient_points = 0
    footprint_point_counts = []
    footprint_areas = []

    for observation in observations:
        image = images.get(observation.get("image"))
        if image is None:
            continue
        registered_observations += 1

        camera = cameras.get(image["camera_id"])
        if camera is None:
            continue

        tof_xyz = observation.get("camera_xyz_mm")
        if (
            not isinstance(tof_xyz, list)
            or len(tof_xyz) != 3
            or not all(h2.finite(value) for value in tof_xyz)
            or float(tof_xyz[2]) <= 0.0
        ):
            continue

        center_uv = h2.project_camera_point(
            camera,
            [float(value) for value in tof_xyz],
        )
        if center_uv is not None:
            for radius in radii:
                neighbor = h2.nearest_sparse_point(
                    image,
                    points3d,
                    center_uv,
                    radius,
                )
                if neighbor is None:
                    continue
                sparse_camera = h2.world_to_camera(
                    image,
                    neighbor["world_xyz"],
                )
                if (
                    sparse_camera is None
                    or sparse_camera[2] <= 0.0
                ):
                    continue
                strategy = f"nearest_{radius:g}px"
                row = make_scale_row(
                    strategy,
                    observation,
                    sparse_camera[2],
                    {
                        "tof_projected_uv": center_uv,
                        "sparse_feature_uv":
                            neighbor["feature_xy"],
                        "pixel_distance":
                            neighbor["pixel_distance"],
                        "point3d_id":
                            neighbor["point3d_id"],
                    },
                )
                if row is not None:
                    nearest_rows[radius].append(row)
                    output_rows.append({
                        "type": "tof_sparse_h21_candidate",
                        "schema_version": 1,
                        "stage": "SFM-S01H2.1",
                        **row,
                    })

        polygon = zone_footprint_polygon(
            profile,
            camera,
            observation,
        )
        if polygon is None:
            continue

        footprint_valid += 1
        area = polygon_area(polygon)
        footprint_areas.append(area)

        sparse_rows = sparse_points_in_polygon(
            image,
            points3d,
            polygon,
        )
        footprint_point_counts.append(len(sparse_rows))
        if not sparse_rows:
            footprint_no_points += 1
            continue
        if len(sparse_rows) < args.minimum_footprint_points:
            footprint_insufficient_points += 1
            continue

        sparse_z = [
            float(row["camera_xyz_units"][2])
            for row in sparse_rows
        ]
        sparse_z_stats = quantiles(sparse_z)

        median_row = make_scale_row(
            "footprint_median_all",
            observation,
            statistics.median(sparse_z),
            {
                "footprint_polygon": polygon,
                "footprint_area_px2": area,
                "sparse_points_in_footprint": len(sparse_rows),
                "sparse_z_stats": sparse_z_stats,
            },
        )
        if median_row is not None:
            footprint_median_rows.append(median_row)
            output_rows.append({
                "type": "tof_sparse_h21_candidate",
                "schema_version": 1,
                "stage": "SFM-S01H2.1",
                **median_row,
            })

        clusters = depth_clusters(
            sparse_rows,
            args.front_cluster_relative_gap,
        )
        if clusters:
            front_cluster = clusters[0]
            front_z = [
                float(row["camera_xyz_units"][2])
                for row in front_cluster
            ]
            front_row = make_scale_row(
                "footprint_front_cluster",
                observation,
                statistics.median(front_z),
                {
                    "footprint_polygon": polygon,
                    "footprint_area_px2": area,
                    "sparse_points_in_footprint":
                        len(sparse_rows),
                    "depth_cluster_count": len(clusters),
                    "front_cluster_points":
                        len(front_cluster),
                    "front_cluster_z_stats":
                        quantiles(front_z),
                    "sparse_z_stats": sparse_z_stats,
                },
            )
            if front_row is not None:
                footprint_front_rows.append(front_row)
                output_rows.append({
                    "type": "tof_sparse_h21_candidate",
                    "schema_version": 1,
                    "stage": "SFM-S01H2.1",
                    **front_row,
                })

    report["registered_tof_observation_count"] = (
        registered_observations
    )

    strategies = {}
    for radius in radii:
        key = f"nearest_{radius:g}px"
        strategies[key] = robust_summary(nearest_rows[radius])

    strategies["footprint_median_all"] = robust_summary(
        footprint_median_rows
    )
    strategies["footprint_front_cluster"] = robust_summary(
        footprint_front_rows
    )

    report["strategies"] = strategies
    report["footprint"] = {
        "valid_polygon_count": footprint_valid,
        "no_sparse_points_count": footprint_no_points,
        "insufficient_sparse_points_count":
            footprint_insufficient_points,
        "sparse_points_per_zone_p50":
            h2.percentile(footprint_point_counts, 0.50),
        "sparse_points_per_zone_p95":
            h2.percentile(footprint_point_counts, 0.95),
        "polygon_area_px2_p50":
            h2.percentile(footprint_areas, 0.50),
        "polygon_area_px2_p95":
            h2.percentile(footprint_areas, 0.95),
    }

    candidate_total = sum(
        summary.get("candidate_count", 0)
        for summary in strategies.values()
    )
    report["status"] = (
        "MEASURED"
        if candidate_total > 0
        else "MEASURED_NO_CORRESPONDENCES"
    )

    # H2.1 is diagnostic only. It cannot open S01H.3.
    report["geometry_mutation_enabled"] = False
    report["ready_for_geometry_mutation"] = False
    report["sparse_model_modified"] = False
    report["camera_poses_modified"] = False
    report["points3d_modified"] = False
    report["dense_input_modified"] = False
    report["fusion_enabled"] = False

    write_outputs(args, report, output_rows)

    compact = []
    for name, summary in strategies.items():
        scale = (
            summary.get("scale", {})
            .get("robust_mm_per_colmap_unit")
        )
        p95 = (
            summary.get("residuals", {})
            .get("inlier_depth_error_p95_mm")
        )
        compact.append(
            f"{name}:n={summary.get('candidate_count',0)}"
            f",scale={scale if scale is not None else 'n/a'}"
            f",p95={p95 if p95 is not None else 'n/a'}"
        )

    print(
        "INFO | TOF_SPARSE_H21 | "
        f"status={report['status']} measurement_only=yes "
        + " | ".join(compact)
        + " | geometry_mutation=OFF fusion=OFF"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
