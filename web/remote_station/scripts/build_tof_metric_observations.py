#!/usr/bin/env python3
import argparse
import json
import math
from pathlib import Path

VALID_TARGET_STATUSES = {5, 6, 9}


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


def finite_number(value):
    if isinstance(value, bool):
        return None
    if isinstance(value, (int, float)):
        value = float(value)
        return value if math.isfinite(value) else None
    return None


def int_value(value):
    if isinstance(value, bool):
        return None
    if isinstance(value, int):
        return value
    if isinstance(value, float) and math.isfinite(value):
        return int(value)
    return None


def base_report(args):
    return {
        "schema_version": 1,
        "stage": "SFM-S01H1",
        "status": "STARTING",
        "measurement_only": True,
        "geometry_mutation_enabled": False,
        "ready_for_geometry_mutation": False,
        "colmap_input_modified": False,
        "dense_input_modified": False,
        "fusion_enabled": False,
        "filters": {
            "valid_target_statuses": sorted(VALID_TARGET_STATUSES),
            "min_distance_mm": args.min_distance_mm,
            "max_distance_mm": args.max_distance_mm,
            "max_sigma_mm": args.max_sigma_mm,
        },
        "selected_association_count": 0,
        "selected_with_accepted_tof": 0,
        "selected_with_raw_tof": 0,
        "raw_tof_frame_count": 0,
        "frames_with_metric_observations": 0,
        "metric_observation_count": 0,
        "rejected_zones": {},
        "measurement_gate_pass": False,
        "ready_for_sparse_scale_measurement": False,
        "next_gate": "S01H.2 sparse metric comparison; geometry mutation remains disabled.",
    }


def write_outputs(output_jsonl, output_report, report, observations):
    report_path = Path(output_report)
    report_path.parent.mkdir(parents=True, exist_ok=True)
    report_path.write_text(
        json.dumps(report, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )

    output_path = Path(output_jsonl)
    output_path.parent.mkdir(parents=True, exist_ok=True)
    with output_path.open("w", encoding="utf-8") as handle:
        handle.write(json.dumps({
            "type": "metadata",
            "schema_version": 1,
            "stage": "SFM-S01H1",
            "status": report["status"],
            "measurement_only": True,
            "geometry_mutation_enabled": False,
            "fusion_enabled": False,
        }) + "\n")
        for row in observations:
            handle.write(json.dumps(row, ensure_ascii=False) + "\n")


def skip(args, report, status, reason):
    report["status"] = status
    report["skip_reason"] = reason
    write_outputs(args.output_jsonl, args.output_report, report, [])
    print(
        "INFO | TOF_METRIC | "
        f"status={status} measurement_only=yes observations=0 "
        "geometry_mutation=OFF fusion=OFF"
    )
    return 0


def choose_profile(snapshot, association_report, raw_rows):
    profiles = snapshot.get("profiles")
    if not isinstance(profiles, list):
        return None

    capture_identity = snapshot.get("capture_identity")
    if not isinstance(capture_identity, dict):
        capture_identity = {}

    observed_slots = (
        association_report.get("tof_calibration", {})
        .get("observed_tof_slots", [])
    )
    observed_slots = {
        int(slot)
        for slot in observed_slots
        if isinstance(slot, int)
    }
    if not observed_slots:
        observed_slots = {
            int(row["slot"])
            for row in raw_rows
            if isinstance(row.get("slot"), int)
        }

    active_profile_id = capture_identity.get(
        "active_calibration_profile_id"
    )

    candidates = []
    for profile in profiles:
        if not isinstance(profile, dict):
            continue
        if profile.get("status") != "solved":
            continue
        if not isinstance(profile.get("tof_slot"), int):
            continue
        if observed_slots and int(profile["tof_slot"]) not in observed_slots:
            continue
        if capture_identity:
            if profile.get("rig_id") != capture_identity.get("rig_id"):
                continue
            if (
                profile.get("rig_mount_revision")
                != capture_identity.get("rig_mount_revision")
            ):
                continue
            if (
                profile.get("master_device_id")
                != capture_identity.get("device_id")
            ):
                continue
            if (
                profile.get("master_camera_id")
                != capture_identity.get("selected_camera_id")
            ):
                continue
        if (
            active_profile_id
            and profile.get("camera_calibration_profile_id")
            != active_profile_id
        ):
            continue
        candidates.append(profile)

    return candidates[0] if len(candidates) == 1 else None


def validate_profile(profile):
    if not isinstance(profile, dict):
        return False
    width = int_value(profile.get("tof_width"))
    height = int_value(profile.get("tof_height"))
    intrinsics = profile.get("tof_intrinsics")
    rotation = profile.get("rotation_tof_to_camera")
    translation = profile.get("translation_tof_to_camera_mm")
    if not width or not height or width <= 0 or height <= 0:
        return False
    if not isinstance(intrinsics, dict):
        return False
    for key in ("fx_zones", "fy_zones", "cx_zones", "cy_zones"):
        if finite_number(intrinsics.get(key)) is None:
            return False
    if float(intrinsics["fx_zones"]) <= 0 or float(intrinsics["fy_zones"]) <= 0:
        return False
    if not isinstance(rotation, list) or len(rotation) != 9:
        return False
    if not isinstance(translation, list) or len(translation) != 3:
        return False
    if any(finite_number(value) is None for value in rotation + translation):
        return False
    return True


def transform_zone(profile, zone_index, distance_mm):
    width = int(profile["tof_width"])
    row = zone_index // width
    column = zone_index % width
    intrinsics = profile["tof_intrinsics"]

    fx = float(intrinsics["fx_zones"])
    fy = float(intrinsics["fy_zones"])
    cx = float(intrinsics["cx_zones"])
    cy = float(intrinsics["cy_zones"])

    # VL53L8CX distance is the accepted axial/perpendicular Z contract.
    z_tof = float(distance_mm)
    x_tof = z_tof * ((column - cx) / fx)
    y_tof = z_tof * ((row - cy) / fy)

    r = [float(value) for value in profile["rotation_tof_to_camera"]]
    t = [float(value) for value in profile["translation_tof_to_camera_mm"]]

    x_cam = r[0] * x_tof + r[1] * y_tof + r[2] * z_tof + t[0]
    y_cam = r[3] * x_tof + r[4] * y_tof + r[5] * z_tof + t[1]
    z_cam = r[6] * x_tof + r[7] * y_tof + r[8] * z_tof + t[2]

    if not all(math.isfinite(value) for value in (
        x_tof, y_tof, z_tof, x_cam, y_cam, z_cam
    )):
        return None
    if z_cam <= 0.0:
        return None

    return {
        "row": row,
        "column": column,
        "tof_xyz_mm": [x_tof, y_tof, z_tof],
        "camera_xyz_mm": [x_cam, y_cam, z_cam],
    }


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--associations", required=True)
    parser.add_argument("--association-report", required=True)
    parser.add_argument("--tof-frames")
    parser.add_argument("--tof-calibration")
    parser.add_argument("--output-jsonl", required=True)
    parser.add_argument("--output-report", required=True)
    parser.add_argument("--min-distance-mm", type=float, default=100.0)
    parser.add_argument("--max-distance-mm", type=float, default=4000.0)
    parser.add_argument("--max-sigma-mm", type=float, default=100.0)
    args = parser.parse_args()

    report = base_report(args)

    if (
        not Path(args.associations).is_file()
        or not Path(args.association_report).is_file()
    ):
        return skip(
            args,
            report,
            "SKIPPED_ASSOCIATION_UNAVAILABLE",
            "Selected sensor association artifacts are unavailable.",
        )

    association_report = load_json(args.association_report)
    associations = load_jsonl(
        args.associations,
        "selected_sensor_association",
    )
    report["selected_association_count"] = len(associations)

    tof_summary = (
        association_report.get("tof")
        if isinstance(association_report.get("tof"), dict)
        else {}
    )
    report["selected_with_accepted_tof"] = int(
        tof_summary.get("selected_with_accepted_pair") or 0
    )
    report["selected_with_raw_tof"] = int(
        tof_summary.get("selected_with_accepted_pair_and_raw_frame") or 0
    )

    if not tof_summary.get("raw_sidecar_available"):
        return skip(
            args,
            report,
            "SKIPPED_NO_TOF",
            "No raw ToF sidecar is available; RGB reconstruction remains authoritative.",
        )

    if not association_report.get("temporal_candidate_pass"):
        return skip(
            args,
            report,
            "SKIPPED_TEMPORAL_UNVERIFIED",
            "S01G temporal association gate did not pass.",
        )

    calibration = (
        association_report.get("tof_calibration")
        if isinstance(association_report.get("tof_calibration"), dict)
        else {}
    )
    if (
        calibration.get("binding_status") != "MATCHED_CAPTURE_IDENTITY"
        or calibration.get("identity_match") is not True
        or int(calibration.get("matching_profile_count") or 0) != 1
    ):
        return skip(
            args,
            report,
            "SKIPPED_CALIBRATION_UNBOUND",
            "Frozen ToF calibration did not bind uniquely to this capture.",
        )

    if (
        not args.tof_frames
        or not Path(args.tof_frames).is_file()
        or not args.tof_calibration
        or not Path(args.tof_calibration).is_file()
    ):
        return skip(
            args,
            report,
            "SKIPPED_NO_TOF",
            "ToF raw/calibration files are missing; RGB reconstruction continues.",
        )

    raw_rows = load_jsonl(args.tof_frames, "tof_frame")
    report["raw_tof_frame_count"] = len(raw_rows)
    snapshot = load_json(args.tof_calibration)
    profile = choose_profile(snapshot, association_report, raw_rows)
    if profile is None or not validate_profile(profile):
        return skip(
            args,
            report,
            "SKIPPED_PROFILE_UNAVAILABLE",
            "Exactly one solved, capture-matched ToF profile was not available.",
        )

    profile_slot = int(profile["tof_slot"])
    profile_width = int(profile["tof_width"])
    profile_height = int(profile["tof_height"])
    profile_zone_count = profile_width * profile_height

    report["profile"] = {
        "rig_id": profile.get("rig_id"),
        "rig_mount_revision": profile.get("rig_mount_revision"),
        "master_device_id": profile.get("master_device_id"),
        "master_camera_id": profile.get("master_camera_id"),
        "camera_calibration_profile_id": profile.get(
            "camera_calibration_profile_id"
        ),
        "tof_slot": profile_slot,
        "tof_width": profile_width,
        "tof_height": profile_height,
        "plane_rms_mm": profile.get("plane_rms_mm"),
        "image_reprojection_rms_px": profile.get(
            "image_reprojection_rms_px"
        ),
    }

    raw_by_key = {}
    raw_by_sequence = {}
    duplicate_sequences = set()
    for raw in raw_rows:
        sequence = int_value(raw.get("sequence"))
        slot = int_value(raw.get("slot"))
        if sequence is None or slot is None:
            continue
        raw_by_key[(slot, sequence)] = raw
        if sequence in raw_by_sequence:
            duplicate_sequences.add(sequence)
        else:
            raw_by_sequence[sequence] = raw
    for sequence in duplicate_sequences:
        raw_by_sequence.pop(sequence, None)

    rejected = {
        "no_target": 0,
        "invalid_status": 0,
        "distance_out_of_range": 0,
        "sigma_missing": 0,
        "sigma_too_high": 0,
        "profile_dimension_mismatch": 0,
        "camera_transform_invalid": 0,
    }
    observations = []
    frames_with_observations = set()
    distances = []
    sigmas = []
    camera_depths = []

    for association in associations:
        tof = association.get("tof")
        if not isinstance(tof, dict):
            continue
        if (
            tof.get("accepted") is not True
            or tof.get("raw_frame_found") is not True
        ):
            continue

        sequence = int_value(tof.get("sequence"))
        slot = int_value(tof.get("slot"))
        if sequence is None:
            continue
        if slot is None:
            slot = profile_slot

        raw = raw_by_key.get((slot, sequence))
        if raw is None:
            raw = raw_by_sequence.get(sequence)
        if raw is None:
            continue

        width = int_value(raw.get("width"))
        height = int_value(raw.get("height"))
        if width != profile_width or height != profile_height:
            rejected["profile_dimension_mismatch"] += profile_zone_count
            continue

        distance_values = raw.get("distance_mm")
        sigma_values = raw.get("sigma_mm")
        status_values = raw.get("target_status")
        target_values = raw.get("nb_target_detected")
        if not all(isinstance(values, list) for values in (
            distance_values,
            sigma_values,
            status_values,
            target_values,
        )):
            rejected["profile_dimension_mismatch"] += profile_zone_count
            continue

        for zone_index in range(profile_zone_count):
            detected = (
                int_value(target_values[zone_index])
                if zone_index < len(target_values)
                else None
            )
            if detected is None or detected <= 0:
                rejected["no_target"] += 1
                continue

            target_status = (
                int_value(status_values[zone_index])
                if zone_index < len(status_values)
                else None
            )
            if target_status not in VALID_TARGET_STATUSES:
                rejected["invalid_status"] += 1
                continue

            distance_mm = (
                finite_number(distance_values[zone_index])
                if zone_index < len(distance_values)
                else None
            )
            if (
                distance_mm is None
                or distance_mm < args.min_distance_mm
                or distance_mm > args.max_distance_mm
            ):
                rejected["distance_out_of_range"] += 1
                continue

            sigma_mm = (
                finite_number(sigma_values[zone_index])
                if zone_index < len(sigma_values)
                else None
            )
            if sigma_mm is None:
                rejected["sigma_missing"] += 1
                continue
            if sigma_mm > args.max_sigma_mm:
                rejected["sigma_too_high"] += 1
                continue

            transformed = transform_zone(
                profile,
                zone_index,
                distance_mm,
            )
            if transformed is None:
                rejected["camera_transform_invalid"] += 1
                continue

            observations.append({
                "type": "tof_metric_observation",
                "schema_version": 1,
                "stage": "SFM-S01H1",
                "image": association.get("image"),
                "video_timestamp_sec": association.get(
                    "video_timestamp_sec"
                ),
                "camera_frame_index": association.get(
                    "camera_frame_index"
                ),
                "tof_sequence": sequence,
                "tof_slot": slot,
                "zone_index": zone_index,
                "row": transformed["row"],
                "column": transformed["column"],
                "distance_mm": distance_mm,
                "sigma_mm": sigma_mm,
                "target_status": target_status,
                "nb_target_detected": detected,
                "tof_xyz_mm": transformed["tof_xyz_mm"],
                "camera_xyz_mm": transformed["camera_xyz_mm"],
            })
            frames_with_observations.add(
                (association.get("image"), sequence)
            )
            distances.append(distance_mm)
            sigmas.append(sigma_mm)
            camera_depths.append(
                transformed["camera_xyz_mm"][2]
            )

    report["status"] = (
        "MEASURED"
        if observations
        else "SKIPPED_NO_VALID_ZONES"
    )
    report["frames_with_metric_observations"] = len(
        frames_with_observations
    )
    report["metric_observation_count"] = len(observations)
    report["rejected_zones"] = rejected
    report["measurement_gate_pass"] = bool(observations)
    report["ready_for_sparse_scale_measurement"] = bool(observations)
    report["statistics"] = {
        "distance_mm_p50": percentile(distances, 0.50),
        "distance_mm_p95": percentile(distances, 0.95),
        "sigma_mm_p50": percentile(sigmas, 0.50),
        "sigma_mm_p95": percentile(sigmas, 0.95),
        "camera_z_mm_p50": percentile(camera_depths, 0.50),
        "camera_z_mm_p95": percentile(camera_depths, 0.95),
    }
    if not observations:
        report["skip_reason"] = (
            "ToF was available and bound, but no zones survived the "
            "measurement filters."
        )

    write_outputs(
        args.output_jsonl,
        args.output_report,
        report,
        observations,
    )
    print(
        "INFO | TOF_METRIC | "
        f"status={report['status']} measurement_only=yes "
        f"frames={report['frames_with_metric_observations']} "
        f"observations={report['metric_observation_count']} "
        "geometry_mutation=OFF fusion=OFF"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
