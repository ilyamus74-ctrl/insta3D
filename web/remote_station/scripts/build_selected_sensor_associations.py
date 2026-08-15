#!/usr/bin/env python3
import argparse
import bisect
import json
import math
import statistics
from pathlib import Path

from imu_utils import frame_motion_at, parse_imu_jsonl

VALID_TOF_STATUSES = {5, 6, 9}


def load_jsonl(path, wanted_type=None):
    rows = []
    if not path or not Path(path).is_file():
        return rows
    with open(path, "r", encoding="utf-8", errors="replace") as handle:
        for line in handle:
            try:
                obj = json.loads(line)
            except Exception:
                continue
            if wanted_type is None or obj.get("type") == wanted_type:
                rows.append(obj)
    return rows


def first_jsonl_metadata(path):
    rows = load_jsonl(path, "metadata")
    return rows[0] if rows else {}


def percentile(values, fraction):
    if not values:
        return None
    data = sorted(float(v) for v in values)
    pos = (len(data) - 1) * fraction
    lo = math.floor(pos)
    hi = math.ceil(pos)
    if lo == hi:
        return data[lo]
    return data[lo] * (hi - pos) + data[hi] * (pos - lo)


def linear_fit(xs, ys):
    if len(xs) < 2 or len(xs) != len(ys):
        return None
    mx = statistics.fmean(xs)
    my = statistics.fmean(ys)
    var = sum((x - mx) ** 2 for x in xs)
    if var <= 0:
        return None
    slope = sum((x - mx) * (y - my) for x, y in zip(xs, ys)) / var
    intercept = my - slope * mx
    residuals = [
        abs(y - (slope * x + intercept)) / 1000.0
        for x, y in zip(xs, ys)
    ]
    return {
        "slope": slope,
        "intercept_ns": intercept,
        "drift_ppm": (slope - 1.0) * 1_000_000.0,
        "residual_p50_us": percentile(residuals, 0.50),
        "residual_p95_us": percentile(residuals, 0.95),
        "residual_max_us": max(residuals) if residuals else None,
    }


def nearest_by(sorted_rows, sorted_values, target):
    if not sorted_rows:
        return None
    idx = bisect.bisect_left(sorted_values, target)
    choices = []
    if idx < len(sorted_rows):
        choices.append(sorted_rows[idx])
    if idx > 0:
        choices.append(sorted_rows[idx - 1])
    return min(
        choices,
        key=lambda row: abs(float(row["_sort_value"]) - target),
    )


def nearest_imu_record(imu, sensor, timestamp_sec):
    if not imu:
        return None
    rows = imu.by_sensor(sensor)
    if not rows:
        return None
    row = min(rows, key=lambda item: abs(item["t_sec"] - timestamp_sec))
    payload = {
        "timestamp_sec": row["t_sec"],
        "delta_ms": (row["t_sec"] - timestamp_sec) * 1000.0,
    }
    if row.get("gyro") is not None:
        payload["values"] = row["gyro"]
    elif row.get("accel") is not None:
        payload["values"] = row["accel"]
    elif row.get("gravity") is not None:
        payload["values"] = row["gravity"]
    elif row.get("quaternion") is not None:
        payload["quaternion_wxyz"] = row["quaternion"]
    return payload


def tof_valid_zone_count(row):
    distances = row.get("distance_mm") or []
    statuses = row.get("target_status") or []
    targets = row.get("nb_target_detected") or []
    count = 0
    for index, distance in enumerate(distances):
        status = statuses[index] if index < len(statuses) else None
        detected = targets[index] if index < len(targets) else 0
        if (
            detected
            and status in VALID_TOF_STATUSES
            and float(distance or 0) > 0
        ):
            count += 1
    return count


def calibration_status(path, camera_info_path, tof_rows=None):
    result = {
        "snapshot_available": False,
        "profile_count": 0,
        "slots": [],
        "observed_tof_slots": [],
        "camera_calibration_profile_id": None,
        "camera_capture_identity": None,
        "snapshot_capture_identity": None,
        "identity_match": False,
        "matching_profile_count": 0,
        "binding_status": "NO_SNAPSHOT",
    }

    camera_info = {}
    if camera_info_path and Path(camera_info_path).is_file():
        try:
            camera_info = json.loads(
                Path(camera_info_path).read_text(encoding="utf-8")
            )
        except Exception:
            camera_info = {}

    camera_profile_id = camera_info.get("calibration_profile_id")
    camera_identity = camera_info.get("capture_rig_identity")
    if not isinstance(camera_identity, dict):
        camera_identity = None

    result["camera_calibration_profile_id"] = camera_profile_id
    result["camera_capture_identity"] = camera_identity

    if not path or not Path(path).is_file():
        return result

    try:
        snapshot = json.loads(Path(path).read_text(encoding="utf-8"))
    except Exception:
        result["binding_status"] = "INVALID_SNAPSHOT"
        return result

    snapshot_identity = snapshot.get("capture_identity")
    if not isinstance(snapshot_identity, dict):
        snapshot_identity = None
    result["snapshot_capture_identity"] = snapshot_identity

    profiles = (
        snapshot.get("profiles")
        if isinstance(snapshot.get("profiles"), list)
        else []
    )
    result["snapshot_available"] = True
    result["profile_count"] = len(profiles)
    result["slots"] = sorted({
        int(profile.get("tof_slot"))
        for profile in profiles
        if isinstance(profile, dict)
        and isinstance(profile.get("tof_slot"), int)
    })

    observed_slots = sorted({
        int(row.get("slot"))
        for row in (tof_rows or [])
        if isinstance(row, dict)
        and isinstance(row.get("slot"), int)
    })
    result["observed_tof_slots"] = observed_slots

    if not camera_identity or not snapshot_identity:
        result["binding_status"] = "CAPTURE_IDENTITY_MISSING"
        return result

    identity_keys = (
        "device_id",
        "rig_id",
        "rig_mount_revision",
        "selected_camera_id",
    )
    identity_match = all(
        camera_identity.get(key) is not None
        and camera_identity.get(key) == snapshot_identity.get(key)
        for key in identity_keys
    )
    result["identity_match"] = identity_match
    if not identity_match:
        result["binding_status"] = "CAPTURE_IDENTITY_MISMATCH"
        return result

    selected_camera_id = camera_info.get("selected_camera_id")
    if (
        selected_camera_id is not None
        and selected_camera_id
            != camera_identity.get("selected_camera_id")
    ):
        result["binding_status"] = "CAMERA_SELECTION_MISMATCH"
        return result

    matching = [
        profile
        for profile in profiles
        if isinstance(profile, dict)
        and profile.get("status") == "solved"
        and profile.get("rig_id") == camera_identity.get("rig_id")
        and profile.get("rig_mount_revision")
            == camera_identity.get("rig_mount_revision")
        and profile.get("master_device_id")
            == camera_identity.get("device_id")
        and profile.get("master_camera_id")
            == camera_identity.get("selected_camera_id")
        and (
            not observed_slots
            or profile.get("tof_slot") in observed_slots
        )
    ]

    result["matching_profile_count"] = len(matching)
    result["binding_status"] = (
        "MATCHED_CAPTURE_IDENTITY"
        if matching
        else "PROFILE_IDENTITY_MISMATCH"
    )
    return result


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--selected-frames", required=True)
    parser.add_argument("--camera-frames", required=True)
    parser.add_argument("--encoder-pts", required=True)
    parser.add_argument("--tof-frames")
    parser.add_argument("--tof-calibration")
    parser.add_argument("--camera-info")
    parser.add_argument("--imu")
    parser.add_argument("--output-jsonl", required=True)
    parser.add_argument("--report-json", required=True)
    args = parser.parse_args()

    selected_doc = json.loads(
        Path(args.selected_frames).read_text(encoding="utf-8")
    )
    selected = selected_doc.get("frames") or []
    camera_metadata = first_jsonl_metadata(args.camera_frames)
    encoder_metadata = first_jsonl_metadata(args.encoder_pts)
    camera_rows = load_jsonl(args.camera_frames, "frame")
    encoder_rows = load_jsonl(args.encoder_pts, "sample")
    tof_rows = load_jsonl(args.tof_frames, "tof_frame")

    camera_rows = [
        row
        for row in camera_rows
        if isinstance(row.get("sensor_timestamp_ns"), int)
    ]
    camera_rows.sort(key=lambda row: int(row.get("frame_index", 0)))
    encoder_rows = [
        row
        for row in encoder_rows
        if isinstance(row.get("pts_us"), int)
    ]
    encoder_rows.sort(key=lambda row: int(row["pts_us"]))

    # Keep an ordinal fit only as a secondary diagnostic. It is not the
    # authoritative mapping because Camera2 callbacks may begin before the
    # encoder and continue after it.
    pair_count = min(len(camera_rows), len(encoder_rows))
    ordinal_fit = linear_fit(
        [
            float(row["pts_us"]) * 1000.0
            for row in encoder_rows[:pair_count]
        ],
        [
            float(row["sensor_timestamp_ns"])
            for row in camera_rows[:pair_count]
        ],
    )

    camera_timestamp_source = camera_metadata.get(
        "sensor_timestamp_source_name"
    )
    camera_x_start_ns = encoder_metadata.get(
        "camera_x_start_elapsed_realtime_ns"
    )
    first_encoder_pts_us = (
        int(encoder_rows[0]["pts_us"]) if encoder_rows else None
    )
    anchor_available = (
        camera_timestamp_source == "REALTIME"
        and isinstance(camera_x_start_ns, int)
        and first_encoder_pts_us is not None
    )

    encoder_pts = [int(row["pts_us"]) for row in encoder_rows]
    for row, value in zip(encoder_rows, encoder_pts):
        row["_sort_value"] = value

    camera_by_ts = sorted(
        camera_rows,
        key=lambda row: int(row["sensor_timestamp_ns"]),
    )
    camera_ts = [
        int(row["sensor_timestamp_ns"])
        for row in camera_by_ts
    ]
    for row, value in zip(camera_by_ts, camera_ts):
        row["_sort_value"] = value

    tof_by_sequence = {}
    for row in tof_rows:
        sequence = row.get("sequence")
        if isinstance(sequence, int):
            tof_by_sequence[
                (int(row.get("slot", 0)), sequence)
            ] = row
            tof_by_sequence.setdefault((None, sequence), row)

    imu = (
        parse_imu_jsonl(args.imu)
        if args.imu and Path(args.imu).is_file()
        else None
    )
    associations = []
    selected_camera_errors_us = []
    selected_camera_signed_errors_us = []
    selected_frame_index_offsets = []
    selected_with_accepted_tof = 0
    selected_with_raw_tof = 0

    for entry in selected:
        video_t = float(entry.get("timestamp_sec") or 0.0)
        target_pts_us = int(
            entry.get("video_pts_us")
            or round(video_t * 1_000_000.0)
        )
        encoder = nearest_by(encoder_rows, encoder_pts, target_pts_us)
        predicted_sensor_ns = None
        camera = None
        camera_error_us = None

        if encoder and anchor_available:
            encoder_pts_us = int(encoder["pts_us"])
            predicted_sensor_ns = (
                int(camera_x_start_ns)
                + (encoder_pts_us - first_encoder_pts_us) * 1000
            )
            camera = nearest_by(
                camera_by_ts,
                camera_ts,
                predicted_sensor_ns,
            )
            if camera:
                signed_error_us = (
                    int(camera["sensor_timestamp_ns"])
                    - predicted_sensor_ns
                ) / 1000.0
                camera_error_us = abs(signed_error_us)
                selected_camera_errors_us.append(camera_error_us)
                selected_camera_signed_errors_us.append(signed_error_us)
                if isinstance(encoder.get("sample_index"), int) and isinstance(camera.get("frame_index"), int):
                    selected_frame_index_offsets.append(
                        int(camera["frame_index"]) - int(encoder["sample_index"])
                    )

        tof_payload = {
            "pair_present": False,
            "accepted": False,
            "raw_frame_found": False,
        }
        if camera and isinstance(camera.get("tof_pair"), dict):
            pair = camera["tof_pair"]
            sequence = pair.get("sequence")
            accepted = bool(pair.get("accepted"))
            raw = (
                tof_by_sequence.get((None, sequence))
                if isinstance(sequence, int)
                else None
            )
            tof_payload = {
                "pair_present": True,
                "sequence": sequence,
                "mapped_elapsed_realtime_ns": pair.get(
                    "mapped_elapsed_realtime_ns"
                ),
                "signed_delta_us": pair.get("signed_delta_us"),
                "abs_delta_us": pair.get("abs_delta_us"),
                "threshold_us": pair.get("threshold_us"),
                "accepted": accepted,
                "raw_frame_found": raw is not None,
            }
            if accepted:
                selected_with_accepted_tof += 1
            if accepted and raw is not None:
                selected_with_raw_tof += 1
            if raw is not None:
                tof_payload.update({
                    "slot": raw.get("slot"),
                    "width": raw.get("width"),
                    "height": raw.get("height"),
                    "frequency_hz": raw.get("frequency_hz"),
                    "valid_zone_count": tof_valid_zone_count(raw),
                    "raw_mapped_elapsed_realtime_ns": raw.get(
                        "mapped_elapsed_realtime_ns"
                    ),
                    "raw_host_received_elapsed_realtime_ns": raw.get(
                        "host_received_elapsed_realtime_ns"
                    ),
                })

        imu_payload = {
            "available": bool(imu and imu.records),
            "sync_method": (
                imu.sync_info.get("method")
                if imu else "unavailable"
            ),
            "sync_quality": (
                imu.sync_info.get("quality")
                if imu else "unavailable"
            ),
        }
        if imu and imu.records:
            imu_payload["motion"] = frame_motion_at(imu, video_t)
            imu_payload["nearest"] = {
                sensor: nearest_imu_record(imu, sensor, video_t)
                for sensor in (
                    "gyro",
                    "accel",
                    "gravity",
                    "rotation_vector",
                )
            }

        associations.append({
            "type": "selected_sensor_association",
            "schema_version": 1,
            "image": entry.get("output"),
            "candidate": entry.get("candidate"),
            "video_timestamp_sec": video_t,
            "video_pts_target_us": target_pts_us,
            "encoder_sample_index": (
                encoder.get("sample_index") if encoder else None
            ),
            "encoder_pts_us": (
                encoder.get("pts_us") if encoder else None
            ),
            "camera_frame_index": (
                camera.get("frame_index") if camera else None
            ),
            "camera_frame_number": (
                camera.get("camera_frame_number") if camera else None
            ),
            "camera_sensor_timestamp_ns": (
                camera.get("sensor_timestamp_ns") if camera else None
            ),
            "predicted_camera_sensor_timestamp_ns": (
                predicted_sensor_ns
            ),
            "camera_association_error_us": camera_error_us,
            "camera_association_signed_error_us": (
                (int(camera["sensor_timestamp_ns"]) - predicted_sensor_ns) / 1000.0
                if camera and predicted_sensor_ns is not None else None
            ),
            "frame_index_offset": (
                int(camera["frame_index"]) - int(encoder["sample_index"])
                if camera and encoder
                and isinstance(camera.get("frame_index"), int)
                and isinstance(encoder.get("sample_index"), int)
                else None
            ),
            "tof": tof_payload,
            "imu": imu_payload,
        })

    selected_error_p95 = percentile(
        selected_camera_errors_us, 0.95
    )
    selected_signed_error_median = percentile(
        selected_camera_signed_errors_us, 0.50
    )
    frame_offset_span = (
        max(selected_frame_index_offsets) - min(selected_frame_index_offsets)
        if selected_frame_index_offsets else None
    )
    camera_span_ns = (
        int(camera_rows[-1]["sensor_timestamp_ns"])
        - int(camera_rows[0]["sensor_timestamp_ns"])
        if len(camera_rows) > 1 else None
    )
    encoder_span_ns = (
        (int(encoder_rows[-1]["pts_us"]) - int(encoder_rows[0]["pts_us"])) * 1000
        if len(encoder_rows) > 1 else None
    )
    span_delta_ms = (
        abs(camera_span_ns - encoder_span_ns) / 1_000_000.0
        if camera_span_ns is not None and encoder_span_ns is not None else None
    )

    ordinal_fit_residual_p95_us = (
        ordinal_fit.get("residual_p95_us")
        if isinstance(ordinal_fit, dict)
        else None
    )
    ordinal_fit_drift_abs_ppm = (
        abs(float(ordinal_fit.get("drift_ppm")))
        if isinstance(ordinal_fit, dict)
        and ordinal_fit.get("drift_ppm") is not None
        else None
    )
    ordinal_fit_ok = bool(
        ordinal_fit_residual_p95_us is not None
        and ordinal_fit_residual_p95_us <= 5000.0
        and ordinal_fit_drift_abs_ppm is not None
        and ordinal_fit_drift_abs_ppm <= 1000.0
    )

    # Camera2 callbacks intentionally cover pre-roll/post-roll around the
    # encoded MP4. Therefore full Camera2-span vs MP4-span delta is a
    # diagnostic only, not a temporal gate. Stable selected-frame mapping is
    # judged from the CameraX anchor, nearest Camera2 timestamps, constant
    # frame-index offset and the independent ordinal-fit clock sanity check.
    selected_mapping_ok = bool(
        anchor_available
        and selected_error_p95 is not None
        and selected_error_p95 <= 10000.0
        and len(selected_camera_errors_us)
        >= max(1, int(len(selected) * 0.95))
        and frame_offset_span is not None
        and frame_offset_span <= 3
        and ordinal_fit_ok
    )

    raw_tof_coverage = (
        selected_with_raw_tof / selected_with_accepted_tof
        if selected_with_accepted_tof
        else 0.0
    )
    calibration = calibration_status(
        args.tof_calibration,
        args.camera_info,
        tof_rows,
    )

    report = {
        "schema_version": 1,
        "status": "DONE",
        "mapping_status": (
            "CANDIDATE_CAMERAX_START_REALTIME"
            if selected_mapping_ok
            else "UNVERIFIED"
        ),
        "temporal_candidate_pass": selected_mapping_ok,
        "fusion_enabled": False,
        "camera_encoder": {
            "camera_timestamp_source": camera_timestamp_source,
            "camera_x_start_elapsed_realtime_ns": camera_x_start_ns,
            "first_encoder_pts_us": first_encoder_pts_us,
            "anchor_available": anchor_available,
            "camera_frame_count": len(camera_rows),
            "encoder_sample_count": len(encoder_rows),
            "ordinal_pair_count": pair_count,
            "ordinal_fit_diagnostic_only": ordinal_fit,
            "ordinal_fit_pass": ordinal_fit_ok,
            "ordinal_fit_residual_p95_us": ordinal_fit_residual_p95_us,
            "ordinal_fit_drift_abs_ppm": ordinal_fit_drift_abs_ppm,
            "selected_association_count": len(
                selected_camera_errors_us
            ),
            "selected_association_error_p50_us": percentile(
                selected_camera_errors_us, 0.50
            ),
            "selected_association_error_p95_us": selected_error_p95,
            "selected_association_signed_error_median_us": selected_signed_error_median,
            "frame_index_offset_min": (min(selected_frame_index_offsets) if selected_frame_index_offsets else None),
            "frame_index_offset_median": percentile(selected_frame_index_offsets, 0.50),
            "frame_index_offset_max": (max(selected_frame_index_offsets) if selected_frame_index_offsets else None),
            "frame_index_offset_span": frame_offset_span,
            "camera_timestamp_span_ns": camera_span_ns,
            "encoder_pts_span_ns": encoder_span_ns,
            "span_delta_ms": span_delta_ms,
            "full_stream_span_delta_diagnostic_only": True,
            "selected_association_error_max_us": (
                max(selected_camera_errors_us)
                if selected_camera_errors_us
                else None
            ),
            "acceptance_thresholds": {
                "selected_association_error_p95_us_max": 10000.0,
                "selected_association_coverage_min": 0.95,
                "frame_index_offset_span_max": 3,
                "ordinal_fit_residual_p95_us_max": 5000.0,
                "ordinal_clock_drift_abs_ppm_max": 1000.0,
            },
        },
        "tof": {
            "raw_sidecar_available": bool(tof_rows),
            "raw_frame_count": len(tof_rows),
            "selected_with_accepted_pair": (
                selected_with_accepted_tof
            ),
            "selected_with_accepted_pair_and_raw_frame": (
                selected_with_raw_tof
            ),
            "accepted_pair_raw_coverage": raw_tof_coverage,
        },
        "imu": {
            "available": bool(imu and imu.records),
            "sync_method": (
                imu.sync_info.get("method")
                if imu else "unavailable"
            ),
            "sync_quality": (
                imu.sync_info.get("quality")
                if imu else "unavailable"
            ),
            "counts": imu.counts() if imu else {},
            "timeline_anchor_source": (
                imu.metadata.get("video_timeline_anchor_source")
                if imu else None
            ),
            "timeline_rebased": bool(
                imu and imu.metadata.get("video_timeline_rebased")
            ),
        },
        "tof_calibration": calibration,
        "ready_for_tof_geometry": False,
        "geometry_gate_reason": (
            "S01G_MEASUREMENT_ONLY_REVIEW_TEMPORAL_REPORT_BEFORE_S01H"
        ),
        "next_gate": (
            "Review temporal_candidate_pass, Camera2/encoder error, ToF raw coverage, "
            "IMU sync and frozen calibration binding. SFM-S01H remains disabled in S01G."
        ),
    }

    output_path = Path(args.output_jsonl)
    output_path.parent.mkdir(parents=True, exist_ok=True)
    with output_path.open("w", encoding="utf-8") as handle:
        handle.write(json.dumps({
            "type": "metadata",
            "schema_version": 1,
            "mapping_status": report["mapping_status"],
            "fusion_enabled": False,
        }) + "\n")
        for row in associations:
            handle.write(
                json.dumps(row, ensure_ascii=False) + "\n"
            )

    Path(args.report_json).write_text(
        json.dumps(report, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )

    print(
        "INFO | SENSOR_ASSOC | "
        f"mapping={report['mapping_status']} "
        f"selected={len(selected)} "
        f"camera_p95_us={selected_error_p95} "
        f"tof_raw_coverage={raw_tof_coverage:.3f} "
        f"imu={report['imu']['sync_quality']} "
        f"calibration={calibration['binding_status']} "
        "temporal_candidate_pass="
        f"{report['temporal_candidate_pass']} "
        "ready_for_tof_geometry="
        f"{report['ready_for_tof_geometry']}"
    )


if __name__ == "__main__":
    main()
