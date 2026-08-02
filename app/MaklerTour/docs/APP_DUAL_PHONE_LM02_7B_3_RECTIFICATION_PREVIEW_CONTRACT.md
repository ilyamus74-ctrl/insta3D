# APP-DUAL-PHONE-LM02.7B.3 — calibrated stereo rectification preview

Baseline: `fc234b8507818ce69f62fe318c890a801c8d6689`.

## Goal

The Linux laptop remains the only processing MASTER. Both Android phones remain
capture clients and send unmodified JPEG frames plus timing/rotation metadata.
This slice adds a bounded CPU pipeline that proves accepted calibration can be
applied to real synchronized phone frames before metric depth is implemented.

## Calibration authority and validation

`CAMERA_A` carries the accepted calibration profile in its hello. The host must
reject processing until the profile is an object with:

- `schema_version = 1` and `status = success`;
- solved `master_intrinsics` and `slave_intrinsics` with positive dimensions,
  `fx/fy`, finite `cx/cy` and finite `k1/k2`;
- solved stereo `rotation[9]`, `translation_mm[3]` and positive `baseline_mm`;
- two different non-empty `master_device_id` and `slave_device_id` values.

The connected CAMERA_A and CAMERA_B IDs must match those profile IDs. When the
runtime order is reversed, the host derives:

```text
R_runtime = transpose(R_profile)
T_runtime = -transpose(R_profile) * T_profile
```

No operator baseline or disparity heat-map value may replace accepted calibration
geometry.

## Frame geometry

The Android reduced-frame producer sends JPEG pixels without applying display
rotation. The host may apply `rotation_degrees` when that produces the calibration
orientation. Exact calibration dimensions are preferred. A pure resize with the
same aspect ratio scales the camera matrix. A crop or incompatible aspect ratio is
reported as an error instead of silently using invalid geometry.

## Processing pipeline

Each nearest-timestamp pair accepted by LM02.7B.2 is submitted to one bounded
latest-pair worker. Ingest and pairing never wait for OpenCV. If the worker is busy,
an older pending pair is replaced and `queue_replaced` is incremented.

The worker:

1. decodes both JPEG frames;
2. resolves runtime orientation and camera matrices;
3. builds `stereoRectify` and `initUndistortRectifyMap` maps once per calibration
   revision and runtime size;
4. remaps both images;
5. renders horizontal epipolar guide lines;
6. computes a CPU `StereoSGBM` diagnostic disparity image;
7. atomically publishes all three JPEG previews and processing telemetry.

The disparity sign/range follows the resolved translation direction. This output is
visual diagnostics only and is not yet metric depth.

## Dashboard and HTTP

The existing dashboard adds:

```text
/stereo/rectified_a.jpg
/stereo/rectified_b.jpg
/stereo/disparity.jpg
```

`/api/status` includes calibration state, profile/device ordering, map readiness,
processing FPS/duration, submitted/processed/failed/replaced counts, last successful
pair, disparity range, valid disparity ratio and the latest actionable error.

## Session diagnostics

The host writes:

```text
stereo_preview.jsonl
stereo_preview_status.json
```

The JSONL stream records successful, failed and stale-discarded attempts. The final
status file is written after the worker stops. Both files are included by the normal
JSON diagnostics packer.

## Scope boundary

LM02.7B.3 ends at rectified previews and an unfiltered StereoSGBM visualization. It
does not publish metric depth, confidence masks, temporal fusion, point clouds or a
room skeleton.
