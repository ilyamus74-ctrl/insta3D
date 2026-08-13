# LM03.4 — CAMERA_A / ToF extrinsics

## Status

```text
REPOSITORY BASELINE: e125fcc11ed3db9d3a361b8cb13d704f5b7b584c

LM03.3.2:  CLOSED
LM03.4:    IN PROGRESS
LM03.4A:   IN PROGRESS
LM03.4B:   PLANNED
LM03.4C:   PLANNED
```

## Goal

Establish the metric spatial mapping:

```text
VL53L8CX zone + range
        |
        v
ToF optical frame
        |
        |  R_tof_to_camera, t_tof_to_camera_mm
        v
CAMERA_A optical frame
        |
        |  CAMERA_A intrinsics/distortion
        v
RGB pixel
```

LM03.4 must not rely on an assumed physical mounting offset.

## Coordinate contract

The solved transform is defined as:

```text
P_camera_mm = R_tof_to_camera * P_tof_mm + t_tof_to_camera_mm
```

`R_tof_to_camera` is row-major 3x3.

`t_tof_to_camera_mm` has three elements in millimetres.

The ToF zone ray model is represented by four calibrated parameters in zone
coordinates:

```text
fx_zones
fy_zones
cx_zones
cy_zones
```

For zone centre `(column, row)`:

```text
x = (column - cx_zones) / fx_zones
y = (row    - cy_zones) / fy_zones
ray = normalize([x, y, 1])
P_tof_mm = range_mm * ray
```

The returned range is treated as distance along the calibrated zone ray.

## Sensor geometry source and orientation rule

ST DS14161 / UM3109 define VL53L8CX as an 8x8 multizone sensor with a nominal
45 x 45 degree square field of view. Zone IDs increment across a row before the
next row. The device receive optics also flip the captured target image
horizontally and vertically.

LM03.4 keeps the firmware/ULD zone order as the raw zone-index contract. It does
not apply an ad-hoc RGB mirror/flip before calibration. The final orientation is
owned by the solved ToF ray model and `R_tof_to_camera`, then verified by
hold-out reprojection.

## Why nominal FoV is not the final calibration

The nominal 45 x 45 degree geometry is useful only as an optimizer seed.

Production registration must solve/refine the ToF zone-ray model together with
the rigid ToF-to-CAMERA_A transform. No final 64-zone RGB projection is accepted
from a hard-coded nominal FoV alone.

## LM03.4A — profile and projection scaffold

Add a persisted-calibration-compatible data model containing:

```text
schema_version
rig_id
rig_mount_revision
master_device_id
master_camera_id
camera_calibration_profile_id
tof_slot
tof_width
tof_height
tof_intrinsics {fx_zones, fy_zones, cx_zones, cy_zones}
rotation_tof_to_camera[9]
translation_tof_to_camera_mm[3]
sample_count
plane_rms_mm
image_reprojection_rms_px
solver
created_at_epoch_ms
status
```

The profile is bound to `rig_mount_revision`. Any physical movement of the ToF
module relative to CAMERA_A invalidates the extrinsics profile.

The projector must support:

```text
zone index + range
    -> P_tof_mm
    -> P_camera_mm
    -> CAMERA_A pixel
```

using the existing CAMERA_A intrinsics (`fx`, `fy`, `cx`, `cy`, `k1`, `k2`).

LM03.4A does not invent solved calibration values.

## LM03.4B — planned planar calibration solver

Use the existing ChArUco CAMERA_A calibration path and the LM03.3.2 accepted
nearest ToF pairing.

For each accepted sample:

```text
CAMERA_A frame
    -> detect ChArUco
    -> estimate calibration-board plane in CAMERA_A coordinates

paired ToF frame
    -> valid 8x8 zone ranges
```

Collect multiple board poses that cover the ToF field at different:

```text
distance
yaw
pitch
image position
```

Optimize:

```text
ToF fx_zones/fy_zones/cx_zones/cy_zones
R_tof_to_camera
t_tof_to_camera_mm
```

against point-to-board-plane residuals across all valid ToF zones and captures.

Do not set the final residual threshold before measuring the real calibration
distribution.

## LM03.4C — planned hold-out validation

Validation must use captures not used by the solver.

Required telemetry:

```text
sample count
valid ToF zones
plane residual RMS mm
plane residual p95 mm
RGB reprojection RMS px
RGB reprojection p95 px
coverage by ToF zone
coverage by board pose
```

Only after hold-out validation passes may LM03.5 consume the profile for 64 ToF
anchors on Registered RGB.

## Lighting note

LM03.3.2 timing passed in a poorly illuminated room because timing uses sensor
event timestamps rather than image content.

LM03.4 optical calibration is different: ChArUco corner detection and camera pose
estimation require clear target contrast. Extrinsics calibration should therefore
be captured under stable, adequate illumination.

## Canonical dependencies

```text
docs/llm/tasks/APP-TOF-LM03.3.2-SENSOR-TIMELINE.md
app/MaklerTour/docs/APP_TOF_USB_CONTRACT.md
app/MaklerTour/app/src/main/java/com/example/maklertour/data/calibration/DualPhoneLiveIntrinsicsEstimator.kt
```
