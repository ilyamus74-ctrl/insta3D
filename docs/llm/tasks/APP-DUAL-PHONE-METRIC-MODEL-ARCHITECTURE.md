# APP-DUAL-PHONE-METRIC-MODEL — Architecture Decision

## Status

```text
ACCEPTED DIRECTION
BASELINE: ff208280a43737907ded5f08036766802148e4f2
PRIMARY TARGET: METRIC ROOM MODEL WITH TEXTURED TRIANGLE MESH
FIRST IMPLEMENTATION TASK: DP04.2
```

## Decision

The dual-phone work is not limited to synchronized video capture. Two rigidly
mounted and calibrated phones are a stereo metric sensor. The project will use
that sensor in two branches sharing one core:

```text
SYNC_VIDEO  → final server-quality metric model and textures
LIVE_METRIC → on-device metric measurements and coverage
HYBRID      → live guidance plus final server refinement
```

The final model must preserve metric scale and expose enough diagnostics to
explain timing, calibration, depth, trajectory, fusion and texturing quality.

## Why the branches are separated

`SYNC_VIDEO` optimizes quality and reproducibility. Both phones preserve their
original full-resolution recordings, frame metadata and IMU. Expensive pairing,
optimization, surface fusion and texturing may run after capture.

`LIVE_METRIC` optimizes latency. Slave sends only a reduced analysis stream to
Master. Master computes approximate live depth, trajectory, coverage and room
measurements. The live result may be less complete and less visually detailed.

Both branches depend on exactly the same:

```text
rig identity
camera identity
clock model
frame timestamp semantics
intrinsics and distortion
stereo extrinsics
coordinate conventions
quality gates
```

## Rig topology rule

`PHONE_USB_STEREO` and `DUAL_PHONE_STEREO` are different calibration identities.
They may share source code and JSON schemas, but never numeric K/D/R/T values.

For every stereo topology:

```text
K0, D0 = cam0 intrinsics and distortion
K1, D1 = cam1 intrinsics and distortion
R, T   = cam1 pose relative to cam0
|T|    = metric baseline
```

For `DUAL_PHONE_STEREO`, the calibration key includes both Android device IDs,
both physical camera IDs, both actual recording modes, effective zoom,
stabilization, orientation and mount revision.

## Geometry path

The deterministic geometry path is:

```text
synchronized calibrated frame pair
→ disparity
→ metric depth
→ local 3D points
→ stereo visual odometry
→ metric submap
→ loop closure and global pose optimization
→ globally aligned depth observations
→ TSDF or equivalent surface fusion
→ triangle mesh
```

TSDF is the preferred first surface integration method because it can combine
many depth observations while producing a triangle surface. It must consume only
accepted optimized poses. Alternative deterministic fusion methods may be A/B
tested later, but they do not change the timing/calibration requirements.

## Texture path

Textures are not painted from the reduced live stream. They are projected from
original synchronized FHD frames after geometry and camera poses are optimized:

```text
optimized mesh
+ original cam0/cam1 frames
+ optimized camera poses
→ visibility and occlusion test
→ keyframe selection
→ exposure/color normalization
→ UV atlas
→ texture projection and blending
→ textured_model.glb
```

The live metric map may guide coverage and provide an initialization prior, but
it is not used as the only source of final texture geometry.

## Timing truth hierarchy

The system distinguishes four timestamps:

```text
scheduled start time
CameraX start() call time
CameraX recording-start event time
per-frame sensor timestamp and encoded MP4 PTS
```

The first three are diagnostics. Exact stereo pairing ultimately depends on
per-frame timestamps reconciled with encoded MP4 sample PTS.

A sidecar row is not called an encoded-frame mapping until validation proves the
mapping. If CameraX cannot provide a reliable mapping, the recording backend must
move to Camera2 + MediaCodec.

## Shared acceptance gates before mesh work

The following gates are mandatory:

1. both role manifests report successful non-empty MP4 capture;
2. per-frame sidecars exist and have monotonic timestamps;
3. MP4 PTS reconciliation reports a verified or explicitly bounded mapping;
4. two-phone pairing reports median/P95/max deltas and dropped frames;
5. the selected dual-phone calibration identity matches the capture exactly;
6. rectification quality is measured on real captures;
7. metric depth coverage is reported;
8. every trajectory edge has an accepted/rejected reason;
9. global optimization produces a drift/closure report;
10. mesh integration uses only accepted optimized poses.

## Output levels

### Capture output

```text
cam0.mp4
cam1.mp4
cam0_frames.jsonl
cam1_frames.jsonl
cam0_camera_info.json
cam1_camera_info.json
imu0.jsonl
imu1.jsonl
capture_events_cam0.jsonl
capture_events_cam1.jsonl
clock_sync_history_cam0.jsonl
clock_sync_history_cam1.jsonl
clock_sync.json
dual_phone_stereo_manifest.json
dual_phone_stereo_extrinsics.json
```

### Geometry output

```text
synced_depth_manifest.json
metric_trajectory.json
optimized_pose_graph.json
metric_cloud.ply
room_shell.ply
mesh_raw.ply
mesh_clean.ply
measurement_report.json
processing_quality.json
```

### User output

```text
textured_model.glb
texture_atlas.png
floor_plan.json
room_dimensions.json
```

## Implementation priority

```text
DP04.2 per-frame sidecars / IMU / bundle
→ DP04.4 asynchronous pre-roll / logical markers / clock history
→ DP05 synchronization validation
→ DP06 dual-phone intrinsics and stereo calibration
→ DP07 independent role upload and server-side aggregate registration
→ MM01 paired-frame dataset
→ MM02 metric depth and trajectory baseline
→ MM03 submaps and global optimization
→ MM04 TSDF/equivalent mesh
→ MM05 full-resolution texturing
→ LM01..LM06 live metric and HYBRID mode
```

Live work must not fork timing or calibration contracts. It consumes the same
validated core built for synchronized server-quality capture.
