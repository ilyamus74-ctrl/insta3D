#!/usr/bin/env python3
import argparse
import json
import math
import statistics
from pathlib import Path

SUPPORTED_CAMERA_MODELS = {
    "SIMPLE_PINHOLE",
    "PINHOLE",
    "SIMPLE_RADIAL",
    "RADIAL",
    "OPENCV",
    "FULL_OPENCV",
}


def load_json(path):
    if not path or not Path(path).is_file():
        return {}
    try:
        value = json.loads(Path(path).read_text(encoding="utf-8"))
        return value if isinstance(value, dict) else {}
    except Exception:
        return {}


def load_jsonl(path, wanted_type=None):
    rows = []
    if not path or not Path(path).is_file():
        return rows
    with open(path, "r", encoding="utf-8", errors="replace") as handle:
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


def finite(value):
    return (
        isinstance(value, (int, float))
        and not isinstance(value, bool)
        and math.isfinite(float(value))
    )


def base_report(args):
    return {
        "schema_version": 1,
        "stage": "SFM-S01H2",
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
            "max_pixel_radius": args.max_pixel_radius,
            "minimum_scale_candidates": args.minimum_scale_candidates,
        },
        "selected_model_id": Path(args.model_dir).name,
        "tof_observation_count": 0,
        "registered_tof_observation_count": 0,
        "scale_candidate_count": 0,
        "inlier_count": 0,
        "inlier_ratio": 0.0,
        "scale_candidate_available": False,
        "scale": {},
        "residuals": {},
        "pixel_match": {},
        "distance_buckets": {},
        "next_gate": (
            "Review S01H.2 scale stability/residuals before any S01H.3 "
            "metric sparse derivation."
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
            "stage": "SFM-S01H2",
            "status": report["status"],
            "measurement_only": True,
            "geometry_mutation_enabled": False,
            "sparse_model_modified": False,
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
        "INFO | TOF_SPARSE_SCALE | "
        f"status={status} measurement_only=yes candidates=0 "
        "geometry_mutation=OFF fusion=OFF"
    )
    return 0


def find_text_model_dir(model_dir):
    for candidate in (Path(model_dir), Path(model_dir) / "txt"):
        if all((candidate / name).is_file() for name in (
            "cameras.txt", "images.txt", "points3D.txt"
        )):
            return candidate
    return None


def parse_cameras(path):
    cameras = {}
    for line in Path(path).read_text(
        encoding="utf-8", errors="replace"
    ).splitlines():
        stripped = line.strip()
        if not stripped or stripped.startswith("#"):
            continue
        parts = stripped.split()
        if len(parts) < 5:
            continue
        try:
            camera_id = int(parts[0])
            width = int(parts[2])
            height = int(parts[3])
            params = [float(value) for value in parts[4:]]
        except Exception:
            continue
        cameras[camera_id] = {
            "camera_id": camera_id,
            "model": parts[1],
            "width": width,
            "height": height,
            "params": params,
        }
    return cameras


def parse_points3d(path):
    points = {}
    for line in Path(path).read_text(
        encoding="utf-8", errors="replace"
    ).splitlines():
        stripped = line.strip()
        if not stripped or stripped.startswith("#"):
            continue
        parts = stripped.split()
        if len(parts) < 8:
            continue
        try:
            point_id = int(parts[0])
            xyz = [float(parts[1]), float(parts[2]), float(parts[3])]
        except Exception:
            continue
        if all(math.isfinite(value) for value in xyz):
            points[point_id] = xyz
    return points


def parse_images(path):
    raw_lines = [
        line.rstrip("\n")
        for line in Path(path).read_text(
            encoding="utf-8", errors="replace"
        ).splitlines(True)
        if not line.lstrip().startswith("#")
    ]
    images = {}
    index = 0
    while index < len(raw_lines):
        while index < len(raw_lines) and not raw_lines[index].strip():
            index += 1
        if index >= len(raw_lines):
            break
        header = raw_lines[index].strip()
        index += 1
        points_line = raw_lines[index].strip() if index < len(raw_lines) else ""
        index += 1
        parts = header.split()
        if len(parts) < 10:
            continue
        try:
            image_id = int(parts[0])
            qvec = [float(value) for value in parts[1:5]]
            tvec = [float(value) for value in parts[5:8]]
            camera_id = int(parts[8])
        except Exception:
            continue
        points2d = []
        point_parts = points_line.split()
        for offset in range(0, len(point_parts) - 2, 3):
            try:
                x = float(point_parts[offset])
                y = float(point_parts[offset + 1])
                point3d_id = int(point_parts[offset + 2])
            except Exception:
                continue
            if point3d_id >= 0 and math.isfinite(x) and math.isfinite(y):
                points2d.append((x, y, point3d_id))
        images[parts[9]] = {
            "image_id": image_id,
            "name": parts[9],
            "qvec": qvec,
            "tvec": tvec,
            "camera_id": camera_id,
            "points2d": points2d,
        }
    return images


def qvec_to_rotmat(qvec):
    qw, qx, qy, qz = qvec
    norm = math.sqrt(qw * qw + qx * qx + qy * qy + qz * qz)
    if norm <= 0.0:
        return None
    qw /= norm
    qx /= norm
    qy /= norm
    qz /= norm
    return [
        [
            1 - 2 * qy * qy - 2 * qz * qz,
            2 * qx * qy - 2 * qz * qw,
            2 * qx * qz + 2 * qy * qw,
        ],
        [
            2 * qx * qy + 2 * qz * qw,
            1 - 2 * qx * qx - 2 * qz * qz,
            2 * qy * qz - 2 * qx * qw,
        ],
        [
            2 * qx * qz - 2 * qy * qw,
            2 * qy * qz + 2 * qx * qw,
            1 - 2 * qx * qx - 2 * qy * qy,
        ],
    ]


def world_to_camera(image, xyz):
    rotation = qvec_to_rotmat(image["qvec"])
    if rotation is None:
        return None
    x, y, z = xyz
    tx, ty, tz = image["tvec"]
    result = [
        rotation[0][0] * x + rotation[0][1] * y + rotation[0][2] * z + tx,
        rotation[1][0] * x + rotation[1][1] * y + rotation[1][2] * z + ty,
        rotation[2][0] * x + rotation[2][1] * y + rotation[2][2] * z + tz,
    ]
    return result if all(math.isfinite(value) for value in result) else None


def project_camera_point(camera, xyz):
    x, y, z = xyz
    if not all(finite(value) for value in xyz) or z <= 0.0:
        return None
    xn = x / z
    yn = y / z
    r2 = xn * xn + yn * yn
    model = camera["model"]
    params = camera["params"]
    try:
        if model == "SIMPLE_PINHOLE":
            f, cx, cy = params[:3]
            fx = fy = f
            xd, yd = xn, yn
        elif model == "PINHOLE":
            fx, fy, cx, cy = params[:4]
            xd, yd = xn, yn
        elif model == "SIMPLE_RADIAL":
            f, cx, cy, k = params[:4]
            fx = fy = f
            radial = 1.0 + k * r2
            xd, yd = xn * radial, yn * radial
        elif model == "RADIAL":
            f, cx, cy, k1, k2 = params[:5]
            fx = fy = f
            radial = 1.0 + k1 * r2 + k2 * r2 * r2
            xd, yd = xn * radial, yn * radial
        elif model in {"OPENCV", "FULL_OPENCV"}:
            fx, fy, cx, cy, k1, k2, p1, p2 = params[:8]
            k3 = params[8] if model == "FULL_OPENCV" and len(params) > 8 else 0.0
            k4 = params[9] if model == "FULL_OPENCV" and len(params) > 9 else 0.0
            k5 = params[10] if model == "FULL_OPENCV" and len(params) > 10 else 0.0
            k6 = params[11] if model == "FULL_OPENCV" and len(params) > 11 else 0.0
            numerator = 1.0 + k1 * r2 + k2 * r2 * r2 + k3 * r2 * r2 * r2
            denominator = 1.0 + k4 * r2 + k5 * r2 * r2 + k6 * r2 * r2 * r2
            if abs(denominator) < 1e-12:
                return None
            radial = numerator / denominator
            xd = xn * radial + 2.0 * p1 * xn * yn + p2 * (r2 + 2.0 * xn * xn)
            yd = yn * radial + p1 * (r2 + 2.0 * yn * yn) + 2.0 * p2 * xn * yn
        else:
            return None
    except Exception:
        return None
    u = fx * xd + cx
    v = fy * yd + cy
    return [u, v] if math.isfinite(u) and math.isfinite(v) else None


def nearest_sparse_point(image, points3d, target_uv, max_radius):
    u, v = target_uv
    radius2 = max_radius * max_radius
    best = None
    for x, y, point_id in image["points2d"]:
        point = points3d.get(point_id)
        if point is None:
            continue
        dx = x - u
        dy = y - v
        distance2 = dx * dx + dy * dy
        if distance2 > radius2:
            continue
        if best is None or distance2 < best["distance2"]:
            best = {
                "point3d_id": point_id,
                "feature_xy": [x, y],
                "distance2": distance2,
                "world_xyz": point,
            }
    if best is not None:
        best["pixel_distance"] = math.sqrt(best.pop("distance2"))
    return best


def robust_scale(matches):
    scales = [float(row["scale_mm_per_colmap_unit"]) for row in matches]
    if not scales:
        return None
    initial = statistics.median(scales)
    rel_dev = [abs(value - initial) / initial for value in scales]
    mad_relative = statistics.median(rel_dev)
    threshold_relative = max(0.05, min(0.25, 3.0 * 1.4826 * mad_relative))
    inliers = [
        row for row in matches
        if abs(float(row["scale_mm_per_colmap_unit"]) - initial) / initial
        <= threshold_relative
    ]
    if not inliers:
        return None
    robust = statistics.median([
        float(row["scale_mm_per_colmap_unit"]) for row in inliers
    ])
    for row in matches:
        scale_value = float(row["scale_mm_per_colmap_unit"])
        row["relative_scale_deviation"] = abs(scale_value - robust) / robust
        row["inlier"] = (
            abs(scale_value - initial) / initial <= threshold_relative
        )
        row["predicted_tof_z_mm_from_sparse"] = (
            float(row["sparse_camera_xyz_units"][2]) * robust
        )
        row["depth_residual_mm"] = abs(
            row["predicted_tof_z_mm_from_sparse"]
            - float(row["tof_camera_xyz_mm"][2])
        )
        row["depth_relative_error"] = (
            row["depth_residual_mm"] / float(row["tof_camera_xyz_mm"][2])
        )
    return {
        "initial_median_mm_per_colmap_unit": initial,
        "mad_relative": mad_relative,
        "inlier_threshold_relative": threshold_relative,
        "robust_mm_per_colmap_unit": robust,
        "robust_m_per_colmap_unit": robust / 1000.0,
    }


def bucket_name(distance_mm):
    if distance_mm < 1000.0:
        return "0_1m"
    if distance_mm < 2000.0:
        return "1_2m"
    if distance_mm < 3000.0:
        return "2_3m"
    if distance_mm <= 4000.0:
        return "3_4m"
    return "over_4m"


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--observations", required=True)
    parser.add_argument("--observation-report", required=True)
    parser.add_argument("--model-dir", required=True)
    parser.add_argument("--output-jsonl", required=True)
    parser.add_argument("--report-json", required=True)
    parser.add_argument("--max-pixel-radius", type=float, default=24.0)
    parser.add_argument("--minimum-scale-candidates", type=int, default=30)
    args = parser.parse_args()

    report = base_report(args)
    h1_report = load_json(args.observation_report)
    if (
        h1_report.get("status") != "MEASURED"
        or h1_report.get("measurement_gate_pass") is not True
        or h1_report.get("ready_for_sparse_scale_measurement") is not True
    ):
        return skip(
            args, report, "SKIPPED_NO_TOF_MEASUREMENT",
            "S01H.1 did not produce an accepted metric observation set."
        )

    observations = load_jsonl(args.observations, "tof_metric_observation")
    report["tof_observation_count"] = len(observations)
    if not observations:
        return skip(
            args, report, "SKIPPED_NO_TOF_MEASUREMENT",
            "S01H.1 observation JSONL contains no metric observations."
        )

    text_dir = find_text_model_dir(args.model_dir)
    if text_dir is None:
        return skip(
            args, report, "SKIPPED_SPARSE_TEXT_UNAVAILABLE",
            "COLMAP TXT model is unavailable."
        )

    cameras = parse_cameras(text_dir / "cameras.txt")
    images = parse_images(text_dir / "images.txt")
    points3d = parse_points3d(text_dir / "points3D.txt")
    report["registered_images"] = len(images)
    report["points3d"] = len(points3d)
    report["camera_models"] = sorted({c["model"] for c in cameras.values()})

    unsupported = sorted({
        c["model"] for c in cameras.values()
        if c["model"] not in SUPPORTED_CAMERA_MODELS
    })
    report["unsupported_camera_models"] = unsupported

    matches = []
    registered_observations = 0
    no_neighbor = 0
    invalid_projection = 0
    for observation in observations:
        image = images.get(observation.get("image"))
        if image is None:
            continue
        registered_observations += 1
        camera = cameras.get(image["camera_id"])
        if camera is None or camera["model"] not in SUPPORTED_CAMERA_MODELS:
            continue
        tof_xyz = observation.get("camera_xyz_mm")
        if (
            not isinstance(tof_xyz, list)
            or len(tof_xyz) != 3
            or not all(finite(value) for value in tof_xyz)
            or float(tof_xyz[2]) <= 0.0
        ):
            invalid_projection += 1
            continue
        projected_uv = project_camera_point(camera, [float(value) for value in tof_xyz])
        if projected_uv is None:
            invalid_projection += 1
            continue
        neighbor = nearest_sparse_point(
            image, points3d, projected_uv, args.max_pixel_radius
        )
        if neighbor is None:
            no_neighbor += 1
            continue
        sparse_camera = world_to_camera(image, neighbor["world_xyz"])
        if sparse_camera is None or sparse_camera[2] <= 0.0:
            continue
        tof_z_mm = float(tof_xyz[2])
        sparse_z_units = float(sparse_camera[2])
        scale_mm_per_unit = tof_z_mm / sparse_z_units
        if not math.isfinite(scale_mm_per_unit) or scale_mm_per_unit <= 0.0:
            continue
        matches.append({
            "type": "tof_sparse_scale_match",
            "schema_version": 1,
            "stage": "SFM-S01H2",
            "image": observation.get("image"),
            "tof_sequence": observation.get("tof_sequence"),
            "tof_slot": observation.get("tof_slot"),
            "zone_index": observation.get("zone_index"),
            "distance_mm": observation.get("distance_mm"),
            "sigma_mm": observation.get("sigma_mm"),
            "target_status": observation.get("target_status"),
            "tof_camera_xyz_mm": [float(value) for value in tof_xyz],
            "tof_projected_uv": projected_uv,
            "sparse_feature_uv": neighbor["feature_xy"],
            "pixel_distance": neighbor["pixel_distance"],
            "point3d_id": neighbor["point3d_id"],
            "sparse_world_xyz": neighbor["world_xyz"],
            "sparse_camera_xyz_units": sparse_camera,
            "scale_mm_per_colmap_unit": scale_mm_per_unit,
            "inlier": False,
        })

    report["registered_tof_observation_count"] = registered_observations
    report["scale_candidate_count"] = len(matches)
    report["matching_diagnostics"] = {
        "invalid_projection_count": invalid_projection,
        "no_sparse_neighbor_count": no_neighbor,
    }
    if not matches:
        return skip(
            args, report, "SKIPPED_NO_SPARSE_CORRESPONDENCES",
            "No sparse feature was found near projected ToF observations."
        )

    scale = robust_scale(matches)
    if scale is None:
        report["status"] = "MEASURED_UNSTABLE"
        write_outputs(args, report, matches)
        return 0

    inliers = [row for row in matches if row.get("inlier")]
    residuals = [float(row["depth_residual_mm"]) for row in inliers]
    relative_errors = [float(row["depth_relative_error"]) for row in inliers]
    pixel_distances = [float(row["pixel_distance"]) for row in matches]

    report["status"] = "MEASURED"
    report["inlier_count"] = len(inliers)
    report["inlier_ratio"] = len(inliers) / len(matches)
    report["scale_candidate_available"] = (
        len(matches) >= args.minimum_scale_candidates
    )
    report["scale"] = scale
    report["residuals"] = {
        "inlier_depth_error_p50_mm": percentile(residuals, 0.50),
        "inlier_depth_error_p95_mm": percentile(residuals, 0.95),
        "inlier_depth_error_max_mm": max(residuals) if residuals else None,
        "inlier_relative_error_p50": percentile(relative_errors, 0.50),
        "inlier_relative_error_p95": percentile(relative_errors, 0.95),
    }
    report["pixel_match"] = {
        "radius_px": args.max_pixel_radius,
        "p50_px": percentile(pixel_distances, 0.50),
        "p95_px": percentile(pixel_distances, 0.95),
        "max_px": max(pixel_distances) if pixel_distances else None,
    }

    buckets = {}
    for name in ("0_1m", "1_2m", "2_3m", "3_4m", "over_4m"):
        group = [
            row for row in matches
            if finite(row.get("distance_mm"))
            and bucket_name(float(row["distance_mm"])) == name
        ]
        group_inliers = [row for row in group if row.get("inlier")]
        group_residuals = [
            float(row["depth_residual_mm"]) for row in group_inliers
        ]
        buckets[name] = {
            "count": len(group),
            "inliers": len(group_inliers),
            "scale_median_mm_per_colmap_unit": (
                statistics.median([
                    float(row["scale_mm_per_colmap_unit"]) for row in group
                ]) if group else None
            ),
            "inlier_residual_p50_mm": percentile(group_residuals, 0.50),
            "inlier_residual_p95_mm": percentile(group_residuals, 0.95),
        }
    report["distance_buckets"] = buckets

    per_image = {}
    for row in matches:
        per_image.setdefault(row["image"], []).append(
            float(row["scale_mm_per_colmap_unit"])
        )
    image_medians = [statistics.median(values) for values in per_image.values()]
    robust_value = scale["robust_mm_per_colmap_unit"]
    image_deviations = [
        abs(value - robust_value) / robust_value for value in image_medians
    ]
    report["per_image_scale"] = {
        "images_with_scale_candidates": len(image_medians),
        "median_mm_per_colmap_unit_p50": percentile(image_medians, 0.50),
        "median_mm_per_colmap_unit_p95": percentile(image_medians, 0.95),
        "relative_deviation_p50": percentile(image_deviations, 0.50),
        "relative_deviation_p95": percentile(image_deviations, 0.95),
    }

    # Measurement only: H2 can never open H3 automatically.
    report["ready_for_geometry_mutation"] = False
    report["geometry_mutation_enabled"] = False
    report["sparse_model_modified"] = False
    report["camera_poses_modified"] = False
    report["points3d_modified"] = False
    report["dense_input_modified"] = False
    report["fusion_enabled"] = False

    write_outputs(args, report, matches)
    print(
        "INFO | TOF_SPARSE_SCALE | "
        f"status=MEASURED measurement_only=yes "
        f"model={report['selected_model_id']} "
        f"candidates={len(matches)} inliers={len(inliers)} "
        f"inlier_ratio={report['inlier_ratio']:.3f} "
        f"scale_mm_per_unit={robust_value:.6f} "
        f"residual_p95_mm={report['residuals']['inlier_depth_error_p95_mm']} "
        "geometry_mutation=OFF fusion=OFF"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
