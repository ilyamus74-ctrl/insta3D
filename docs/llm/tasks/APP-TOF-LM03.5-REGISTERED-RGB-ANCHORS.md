# LM03.5 — 64 ToF anchors on Registered RGB

## Status

```text
REPOSITORY BASELINE: 8562264865eb4520b6298476c0e1ffb18b86ffac

LM03.3.2: CLOSED
LM03.4:   CLOSED
LM03.5A:  IMPLEMENTED — real-device anchor validation pending
LM03.5B:  NEXT — diagnostic overlay
LM03.5C:  PLANNED
```

## Goal

Consume the independently validated CAMERA_A/ToF profile and turn every fresh
VL53L8CX frame into registered metric anchors in CAMERA_A image coordinates.

This milestone does not estimate calibration. It consumes the frozen profile.

LM03.5 is multi-ToF-aware from the first runtime implementation:

```text
supported logical slots: 0, 1, 2
current physical stream:  slot 0 only

slot 0 -> independent ToF intrinsics + R0/t0
slot 1 -> independent ToF intrinsics + R1/t1
slot 2 -> independent ToF intrinsics + R2/t2
```

No ToF may reuse another sensor's spatial calibration. The current RP2040
firmware command remains `stream 0`; slots 1/2 are dormant until firmware and
hardware expose them.

```text
ToF frame 8x8
    |
    | nearest event-time pairing to CAMERA_A frame
    v
64 zone measurements
    |
    | frozen ToF ray model + R/t
    v
CAMERA_A metric points
    |
    | CAMERA_A K/D
    v
registered RGB anchors
```

## Authoritative dependencies

LM03.5 may run only when all of the following are valid:

```text
LM03.3.2 event-time synchronization
active successful stereo CAMERA_A profile
active solved ToF/CAMERA_A profile
matching rig_id
matching rig_mount_revision
matching master_device_id
matching master_camera_id
matching camera_calibration_profile_id
matching tof_slot / width / height
```

LM03.4C accepted the current profile on independent data:

```text
hold-out run:
  cal-943f7076-28bd-4f7b-be12-29578d2d35dc

coverage:
  62 / 64 zones = 96.875%

plane residual:
  RMS = 11.549 mm
  p95 = 19.259 mm

RGB reprojection telemetry:
  RMS = 2.110 px
  p95 = 3.113 px
```

## Registered-anchor contract

For every valid ToF zone produce:

```text
zone_index
tof_sequence
tof_mapped_elapsed_realtime_ns
camera_elapsed_realtime_ns
pair_delta_us

distance_mm
sigma_mm
target_status
nb_target_detected

tof_x_mm
tof_y_mm
tof_z_mm

camera_x_mm
camera_y_mm
camera_z_mm

u_px
v_px

inside_image
valid
reject_reason
```

`u_px/v_px` are in the raw CAMERA_A calibration image coordinate system associated
with the active CAMERA_A intrinsics.

No display rotation, PreviewView crop, Compose transform or UI orientation may be
folded into the metric registration layer.

UI coordinates are a later presentation transform.

## Geometry

Use the exact closed LM03.4 geometry:

```text
column = zone_index % width
row    = zone_index / width

xn = (column - cx_zones) / fx_zones
yn = (row    - cy_zones) / fy_zones

Z = distance_mm
X = Z * xn
Y = Z * yn

P_camera = R_tof_to_camera * [X,Y,Z] + t_tof_to_camera_mm
```

Then apply CAMERA_A radial distortion model:

```text
x = camera_x / camera_z
y = camera_y / camera_z
r2 = x*x + y*y

radial = 1 + k1*r2 + k2*r2*r2

u = fx*x*radial + cx
v = fy*y*radial + cy
```

Reject projection when:

```text
ToF zone invalid
distance <= 0
profile mismatch
camera_z <= 0
projection non-finite
```

`inside_image=false` is not the same as `valid=false`; a physically valid point
can project outside the current CAMERA_A frame.

## LM03.5A — runtime registered-anchor producer

Implemented runtime:

```text
TofCameraCalibrationStore
  -> active_profile_slot_0.json
  -> active_profile_slot_1.json
  -> active_profile_slot_2.json
  -> legacy active_profile.json as slot-0 fallback

TofUsbRuntime
  -> shared bounded frame history
  -> slot-filtered history helpers

TofCameraFramePairer.nearestForSlot()
  -> event-time pairing cannot cross ToF slots

TofRegisteredRgbAnchorRuntime
  -> process-scoped latest StateFlow
  -> one immutable slot snapshot per configured ToF
  -> up to 64 immutable anchors per slot
```

Camera integration:

```text
CameraX CAMERA_A event timestamp
        |
        +--> TofCameraFramePairer.nearest()
                |
                +--> accepted ToF frame
                        |
                        +--> active frozen profile
                                |
                                +--> 0..64 registered anchors
```

Requirements:

- no new USB reader;
- no new clock model;
- no nearest-by-arrival-time fallback;
- no refit of ToF calibration;
- reuse `TofCameraProjector` geometry;
- preserve zone index and raw ToF telemetry;
- output an immutable per-camera-frame registered-anchor snapshot;
- support three logical ToF slots while allowing only slot 0 to be physically
  active today;
- never pair a CAMERA_A event with a frame from the wrong ToF slot.

The producer is process-scoped and exposes its latest immutable snapshot through
`StateFlow`, so LM03.5B UI and later fusion code consume exactly the same metric
registration result.

## LM03.5B — diagnostic overlay

After LM03.5A is numerically verified, draw registered anchors over CAMERA_A.

Initial diagnostic rendering:

```text
valid anchor:
  small cross/circle at (u_px, v_px)

label in detailed mode:
  zone index
  distance m
```

The overlay must transform raw CAMERA_A pixels into the actual FIT_CENTER preview
viewport using the existing preview-coordinate mapping rules. Metric registration
itself remains in raw camera coordinates.

Expected visual test:

- move a large flat object across the ToF field;
- projected anchor cloud should move with the object in CAMERA_A;
- no left/right mirror;
- no 90/180 degree rotation error;
- central anchors should remain close to the physical ToF footprint;
- edge anchors should remain geometrically ordered.

## LM03.5C — machine-readable diagnostics

Persist or log one bounded diagnostic snapshot containing:

```text
camera timestamp
ToF sequence
pair delta
valid zone count
projected anchor count
inside-image count
min/median/max depth
anchor list
```

This is diagnostic evidence, not a new calibration artifact.

## Acceptance

LM03.5 closes only after real-device evidence confirms:

```text
1. >= 60 valid/projectable anchors on a representative full-field frame
   when the scene provides valid returns.

2. anchor zone ordering is visually correct:
   no mirror / rotation / transposition defect.

3. nearest-event ToF pairing remains within LM03.3.2 acceptance.

4. repeated stationary-scene projection has no systematic pixel drift.

5. the overlay agrees with the independently measured LM03.4C reprojection
   scale; gross multi-zone displacement is absent.
```

Do not use LM03.5 to modify the accepted LM03.4 profile. If a systematic mapping
error appears, diagnose the projection implementation first; reopening LM03.4
requires explicit evidence that the frozen calibration itself is wrong.

## Boundary to LM03.6

LM03.5 outputs sparse registered metric anchors.

LM03.6 may then expose the runtime selector:

```text
STEREO
TOF
FUSED
```

and begin combining stereo dense depth with the sparse metric ToF anchors.
