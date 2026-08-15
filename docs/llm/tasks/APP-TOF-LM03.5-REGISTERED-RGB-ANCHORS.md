# LM03.5 — 64 ToF anchors on Registered RGB

## Status

```text
REPOSITORY BASELINE: d57d8a8b7218a657bbb7b4b3ae5f8e97a4e626ab

LM03.3.2: CLOSED
LM03.4:   CLOSED
LM03.5:   CLOSED — registered ToF -> CAMERA_A path accepted
LM03.5A:  CLOSED — slot0 runtime pairing/projection verified on device
LM03.5B:  CLOSED — laptop/web registered overlay verified on real scenes
LM03.5C:  CLOSED — bounded machine-readable host diagnostic verified
LM03.5D:  DEFERRED — optional ToF sidecars are integrated after SINGLE RGB baseline
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

## LM03.5B — laptop/web diagnostic overlay

The operator view lives on the laptop host, not on the phone.

CAMERA_A attaches the exact same-frame `tof_registered` metadata to its existing
laptop JPEG header. The C++ host exposes it through the existing status JSON and
the browser draws a canvas directly over raw CAMERA_A.

Initial diagnostic rendering:

```text
valid anchor:
  small circle at (u_px, v_px)

grid:
  connect native neighbouring zone indexes

label:
  zone index
  distance m
```

The canvas has raw CAMERA_A dimensions internally and receives the exact same CSS
size/rotation transform as the CAMERA_A JPEG. Metric registration itself remains
in raw camera coordinates; browser code must not recompute R2P, R/t or K/D.

The payload is already multi-slot:

```text
slots[0] -> ToF0
slots[1] -> ToF1
slots[2] -> ToF2
```

Only slot0 is physically active today.

Expected visual test:

- move a large flat object across the ToF field;
- projected anchor cloud should move with the object in CAMERA_A;
- no left/right mirror;
- no 90/180 degree rotation error;
- central anchors should remain close to the physical ToF footprint;
- edge anchors should remain geometrically ordered.

## LM03.5C — machine-readable diagnostics

LM03.5C is host-side because LM03.5B already transports the complete same-frame
registered ToF payload to the laptop.

Runtime endpoint:

```text
GET /api/tof/registered
```

Bounded persisted evidence:

```text
sessions/<session>/tof_registered_latest.json
```

The file is overwritten atomically rather than appended forever. To avoid
high-frequency disk churn it is refreshed every 15 CAMERA_A frames while the
in-memory/API snapshot remains current for every received registered frame.

Snapshot contract:

```text
schema_version
ready
coordinate_space

camera_frame_sequence
camera_sensor_timestamp_ns
camera_capture_elapsed_ns
camera_elapsed_realtime_ns
camera_width
camera_height
rotation_degrees

configured_slot_count
paired_slot_count
valid_zone_count
projected_anchor_count
inside_image_count

slots[]
  slot
  tof_width / tof_height
  tof_sequence
  pair_delta_us / pair_threshold_us
  pair_accepted
  status
  valid_zone_count
  projected_anchor_count
  inside_image_count
  min_depth_mm
  median_depth_mm
  max_depth_mm
  anchor_count
  anchors[]
```

`anchors[]` preserves the compact LM03.5B laptop payload including zone index,
distance, sigma/status, registered `u_px/v_px`, CAMERA_A Z and `inside_image`.

This is bounded diagnostic evidence, not a calibration artifact and not yet the
per-SfM-frame ToF archive. Full capture-time ToF sidecars belong to LM03.5D.

## LM03.5 real-device closeout

LM03.5A acceptance established slot-aware CAMERA_A event-time registration with
the active slot0 profile and successful frames reaching up to 64/64 registered
anchors while remaining inside the LM03.3.2 pairing threshold.

LM03.5B acceptance established the complete operator path:

```text
MASTER CAMERA_A + ToF slot0
        |
        +--> same-frame registered ToF metadata
        |
        v
laptop host
        |
        v
web CAMERA_A + native-zone grid + distance labels
```

Real scene screenshots showed the projected native-zone grid and metric distance
labels moving over CAMERA_A content in the laptop web UI. Browser code only
applies the same presentation transform as the raw CAMERA_A image; it does not
recompute ToF geometry.

LM03.5C real-device acceptance:

```text
static contract test: PASS

GET /api/tof/registered:
  ready: true
  configured_slot_count: 1
  paired_slot_count: 1
  slot0.status: OK

accepted example:
  camera_frame_sequence: 6516
  tof_sequence: 14752
  pair_delta_us: +16038
  pair_threshold_us: 35333

  valid_zone_count: 12
  projected_anchor_count: 12
  inside_image_count: 12

  min_depth_mm: 1487
  median_depth_mm: 2822
  max_depth_mm: 3322

bounded persistence:
  snapshots_seen: 371
  snapshots_persisted: 25
  persist_stride_frames: 15
```

The persisted `tof_registered_latest.json` was observed changing camera frame
sequence and payload size while the host remained live, proving that the file is
a bounded current snapshot rather than a stale one-shot artifact.

LM03.5 is CLOSED. LM03.5D is a new capture/export extension; it must not reopen
the accepted LM03.4 calibration or LM03.5 registration geometry.

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
