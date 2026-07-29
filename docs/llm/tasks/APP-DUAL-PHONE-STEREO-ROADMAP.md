# APP-DUAL-PHONE-STEREO — Master/Slave Capture and Metric Model Roadmap

## Status

```text
ACTIVE
REPOSITORY BASELINE: ff208280a43737907ded5f08036766802148e4f2
DP01 IDENTITY / ROLE / CAMERA MODE VERIFIED ON REAL PHONES
DP02 TCP PAIRING AND CONTROL VERIFIED ON TWO REAL PHONES
DP03 UDP CLOCK MODEL IMPLEMENTED AND VERIFIED ON TWO REAL PHONES
DP03.1 PERIODIC CLOCK STABILIZATION IMPLEMENTED
DP03.2 STABLE-FAIR CAPTURE SCHEDULING IMPLEMENTED
DP04.1 HEADLESS CAMERAX ARM / START_AT / STOP RECORDING VERIFIED
IMMEDIATE NEXT: DP04.2 PER-FRAME SIDECARS, IMU, BUNDLE AND SYNC VALIDATION
```

The previous status text that said clock synchronization and camera recording
were not implemented is obsolete. The current two-phone POC has completed a real
cycle on both phones:

```text
CONNECTED → ARMED → START_SCHEDULED → RECORDING → STOP → CONNECTED
```

## Product goal

Use two independently capable Android phones as one rigid calibrated stereo rig
and produce a metrically scaled, globally consistent and textureable model of a
room:

```text
Master phone cam0
+ Slave phone cam1
+ rigid mount with known revision
+ synchronized local FHD30/FHD60 recording
+ per-frame timestamps and camera metadata
+ IMU on both phones
+ dual-phone intrinsics and stereo extrinsics
→ timestamp-paired stereo frames
→ metric depth
→ metric trajectory and submaps
→ optimized metric geometry
→ triangle mesh
→ textures from original full-resolution frames
```

The primary product is not merely a synchronized pair of videos. The primary
product is a metric room model with inspectable dimensions and a textured mesh.

Different phone models are supported. The system must not assume identical
intrinsics, distortion, rolling shutter, exposure behavior or supported modes.

## Fixed architecture: shared core and three operating modes

The implementation is divided into shared stereo infrastructure plus two product
branches and one combined mode.

### Shared dual-phone stereo core

```text
identity and roles
pairing and reliable control
clock synchronization
capture state machine
camera capability and mode identity
calibration identity
per-frame timestamp contract
IMU contract
bundle and upload identity
```

This code must not be duplicated between the recording and live-metric branches.

### Mode A — SYNC_VIDEO

```text
both phones record original FHD video locally
→ STOP
→ complete per-phone artifacts
→ upload cam0 and cam1 under one dual_capture_id
→ server-side pairing, depth, odometry, fusion, mesh and texturing
```

This is the quality path for final geometry and textures.

### Mode B — LIVE_METRIC

```text
Slave sends reduced analysis frames to Master
+ both phones provide timestamps and IMU
→ live frame pairing
→ rectification and disparity
→ metric depth preview
→ stereo-inertial odometry
→ live metric submaps and room measurements
```

This mode may work without the backend. It provides operator feedback, room
measurements, coverage and an initial metric map. It is not automatically the
final production mesh.

### Mode C — HYBRID

```text
SYNC_VIDEO local originals
+ LIVE_METRIC reduced frame stream
→ live metric guidance during capture
→ server refinement from original synchronized video after STOP
```

HYBRID is the target operator mode. The live map becomes a metric prior and
coverage guide. The server still rebuilds and optimizes the final model from the
original full-resolution data.

## Fixed transport decisions

```text
Reliable commands: TCP
Clock synchronization: UDP
Full-resolution video during capture: local recording only
Reduced live-analysis frames: allowed only for LIVE_METRIC / HYBRID
Full-resolution upload: after STOP
Optional discovery/bootstrap: Bluetooth, QR or LocalOnlyHotspot
```

The old blanket rule that all video/data transfer during capture is forbidden is
replaced by a narrower rule: original FHD streams are never sent between phones
or to the backend during capture, but a bounded reduced-resolution analysis
stream is allowed for live metric processing.

The initial implementation may use an existing Wi-Fi network or a manually
enabled phone hotspot. Automatic LocalOnlyHotspot provisioning remains a later
UX task.

## Ownership

The Master owns:

```text
dual_capture_id
session token
peer identity
start/stop state
clock-sync state
capture readiness
live-metric state
upload authorization
upload progress for cam0 and cam1
aggregate bundle readiness
processing registration
```

The Slave may upload its own files directly to the backend, but the Master must
observe and control the two-sided workflow. A dual capture is not ready for
processing until both sides are registered under the same `dual_capture_id`.

## Calibration boundary: phone + USB and phone + phone

The current phone + USB UVC rig remains a separate supported stereo topology:

```text
PHONE_USB_STEREO
cam0 = Android phone camera
cam1 = USB UVC camera
```

The new topology is:

```text
DUAL_PHONE_STEREO
cam0 = Master phone physical camera
cam1 = Slave phone physical camera
```

The existing phone + USB calibration workflow, ChArUco capture code, OpenCV
calibration routines and `stereo_extrinsics.json` schema should be reused where
possible. The numeric calibration itself must never be reused across the two
rigs.

Every physical camera requires its own intrinsics and distortion coefficients:

```text
cam0: K0, D0
cam1: K1, D1
stereo pair: R, T, E, F, baseline
```

A dual-phone calibration may be reused only when the complete identity key
matches:

```text
rig_topology=DUAL_PHONE_STEREO
rig_id
rig_mount_revision
cam0 device_id
cam1 device_id
cam0 physical camera_id
cam1 physical camera_id
cam0 requested and actual resolution/FPS
cam1 requested and actual resolution/FPS
cam0 effective zoom
cam1 effective zoom
cam0 stabilization mode
cam1 stabilization mode
capture orientation contract
```

Changing either phone, physical lens, effective zoom, recording mode,
stabilization behavior or rigid mounting geometry invalidates stereo extrinsics.

## Calibration artifact contract

`dual_phone_stereo_extrinsics.json` must contain at least:

```text
schema_version
status
rig_topology
rig_id
rig_mount_revision
calibration_identity_hash
cam0_device_id
cam1_device_id
cam0_camera_id
cam1_camera_id
cam0_video_mode_id
cam1_video_mode_id
cam0_image_width
cam0_image_height
cam1_image_width
cam1_image_height
cam0_camera_matrix / K0
cam0_dist_coeffs / D0
cam1_camera_matrix / K1
cam1_dist_coeffs / D1
stereo_R / R
stereo_T / T
stereo_E / E
stereo_F / F
baseline_magnitude
rms_intrinsics_cam0
rms_intrinsics_cam1
rms_stereo
created_at_utc
```

## Current per-phone local artifact

DP04.1 currently writes one local directory per role:

```text
files/dual_phone_captures/<dual_capture_id>/master/
├── video.mp4
└── dual_capture_manifest.json

files/dual_phone_captures/<dual_capture_id>/slave/
├── video.mp4
└── dual_capture_manifest.json
```

This proves real synchronized control and local recording. It is not yet a
complete stereo-processing bundle.

## Target aggregate capture bundle

```text
capture_type=dual_phone_stereo_video

bundle_manifest.json
capture/
├── dual_phone_stereo_manifest.json
├── cam0.mp4
├── cam1.mp4
├── cam0_frames.jsonl
├── cam1_frames.jsonl
├── cam0_camera_info.json
├── cam1_camera_info.json
├── clock_sync.json
├── imu0.jsonl
└── imu1.jsonl
calibration/
└── dual_phone_stereo_extrinsics.json
live_metric/                         # optional for HYBRID
├── live_trajectory.json
├── live_measurements.json
├── live_coverage.json
└── live_metric_cloud.ply
```

The two videos and sidecars may upload independently. Backend registration joins
them by `dual_capture_id`, role, device identity and calibration identity.

## DP04.2 per-frame sidecar contract

Each phone must create one JSONL row per observed camera frame:

```json
{
  "schema_version": 1,
  "frame_index": 123,
  "sensor_timestamp_ns": 1234567890123,
  "elapsed_realtime_ns": 1234567890456,
  "encoder_pts_us": 2050000,
  "encoder_mapping_status": "verified",
  "exposure_time_ns": 8000000,
  "frame_duration_ns": 16666667,
  "sensitivity_iso": 320,
  "focus_distance_diopters": 1.2,
  "rolling_shutter_skew_ns": 12000000,
  "width": 1920,
  "height": 1080,
  "rotation_degrees": 0
}
```

CameraX `ImageAnalysis.imageInfo.timestamp` is a sensor-domain timestamp but does
not by itself prove a one-to-one mapping to encoded MP4 frames. DP04.2 therefore
must distinguish:

```text
analysis frame index
sensor timestamp
actual MP4 sample PTS
mapping status
```

If CameraX cannot expose or validate a deterministic encoded-frame mapping, the
dual-phone recorder must move to a Camera2 + MediaCodec path rather than silently
claiming exact frame synchronization.

## Recording mode contract

Resolution and FPS are explicit persistent settings per physical camera.

The UI may show only modes verified from Camera2 capabilities. A 60 FPS option
must not be invented merely because an AE FPS range contains 60; the output
stream minimum frame duration must also permit it.

Requested and actual values are both written to metadata:

```text
requested_width
requested_height
requested_fps
actual_width
actual_height
observed_fps
physical_camera_id
effective_zoom_ratio
stabilization_mode
```

## Metric-model processing contract

The deterministic first production path is:

```text
timestamp pairing
→ calibrated rectification
→ StereoSGBM metric depth
→ depth/keyframe quality gates
→ stereo visual odometry
→ IMU rotation consistency gates
→ metric submaps
→ relocalization and loop closure
→ global pose optimization
→ accepted optimized depth integration
→ TSDF or equivalent surface fusion
→ triangle mesh cleanup
→ UV generation and texture projection from original FHD frames
→ GLB plus diagnostic PLY/OBJ artifacts
```

TSDF is the first planned deterministic surface-fusion implementation, not a
license to hide trajectory errors. Mesh integration starts only after accepted
poses and global trajectory optimization are available.

The canonical final artifacts are:

```text
metric_trajectory.json
optimized_pose_graph.json
metric_cloud.ply
room_shell.ply
mesh_raw.ply
mesh_clean.ply
textured_model.glb
texture_atlas.png
measurement_report.json
processing_quality.json
```

## Implementation and acceptance order

### Completed foundation

- **DP01** — identity, roles, physical camera and recording mode;
- **DP02** — TCP pairing, commands and reconnect;
- **DP03** — four-timestamp UDP clock model;
- **DP03.1** — periodic 12-probe stabilization and diagnostic rounds;
- **DP03.2** — bounded stable-FAIR scheduling policy;
- **DP04.1** — automatic headless CameraX preparation and real dual recording.

### Immediate shared work

#### DP04.2 — Timestamped FHD capture artifacts

- per-frame camera sidecar on both phones;
- IMU sidecar on both phones using the same boot-time domain;
- camera-info artifact on both phones;
- clock-sync history artifact;
- observed FPS and dropped-frame diagnostics;
- aggregate manifest fields required for later pairing;
- explicit encoder mapping status;
- fix stale `CAPTURE_STARTED` UI messages after STOP.

#### DP05 — Synchronization validator

- match cam0/cam1 frames by corrected timestamps;
- report matched and unmatched frames;
- median, P95 and maximum frame delta;
- dropped-frame runs;
- CameraX start-call and actual-start diagnostics;
- LED or rapidly changing screen acceptance test;
- reject processing when timing quality is outside the agreed gate.

#### DP06 — Dual-phone ChArUco calibration

- reuse the existing phone + USB calibration implementation structure;
- collect separate cam0 and cam1 intrinsics at the selected recording mode;
- calculate dual-phone stereo R/T and baseline;
- persist the exact calibration identity key;
- reject a stale or mismatched profile.

#### DP07 — Aggregate bundle and upload

- independent registration/upload for cam0 and cam1;
- Master-controlled aggregate state;
- completeness and calibration-identity gates;
- server-side bundle normalization;
- offline MP4 frame extraction and sidecar reconciliation.

### Offline metric model

#### MM01 — Deterministic paired-frame dataset

- extract original frames using MP4 PTS;
- reconcile PTS with sensor sidecars;
- create `synced_depth_manifest.json`;
- select stereo keyframes with timing, blur, exposure and disparity gates.

#### MM02 — Metric depth and trajectory baseline

- reuse StereoSGBM, pair-local metric PLY, ORB/PnP odometry and current fusion;
- record every accepted/rejected pose and reason;
- add IMU rotation consistency checks.

#### MM03 — Submaps and global optimization

- bounded submaps;
- relocalization candidates;
- loop closure;
- global pose graph or bundle adjustment;
- metric drift report.

#### MM04 — Mesh

- integrate only accepted optimized depth into TSDF or an equivalent deterministic
  surface representation;
- export raw and cleaned metric triangle meshes;
- preserve scale and coordinate-system metadata.

#### MM05 — Textures

- choose sharp, exposed and geometrically valid original FHD keyframes;
- visibility and occlusion testing;
- color/exposure normalization between phones and frames;
- UV atlas and texture projection;
- export `textured_model.glb` as the primary user artifact.

### Live metric branch

Live development begins only after DP04.2, DP05 and DP06 establish trustworthy
shared timing and calibration:

- **LM01** — bounded reduced-frame Slave → Master transport;
- **LM02** — live pairing, rectification and depth;
- **LM03** — stereo-inertial odometry;
- **LM04** — metric submaps and live measurements;
- **LM05** — coverage and tracking-quality UI;
- **LM06** — HYBRID handoff of live priors to server refinement.

## Immediate next task

Implement **DP04.2** against the current real dual-phone recording path:

1. bind a metadata-only `ImageAnalysis` stream together with `VideoCapture` in
   headless dual-phone mode;
2. write `frames.jsonl` beside each role's `video.mp4`;
3. start/stop an `ImuRecorder` beside each dual-phone recording;
4. write `camera_info.json` for both phones;
5. persist clock-sync and capture timing diagnostics;
6. add bundle completeness checks and a local sync-validator CLI;
7. verify a new two-phone recording before starting dual-phone calibration.

The existing phone + USB UVC runtime remains supported and provides reusable
calibration and processing components, but it remains a different calibrated
rig topology.
