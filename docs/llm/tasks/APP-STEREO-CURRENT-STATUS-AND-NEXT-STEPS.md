# APP-STEREO — Current Status and Next Steps

## Status

```text
CURRENT SOURCE OF TRUTH
REPOSITORY BASELINE: ff208280a43737907ded5f08036766802148e4f2
PHONE + USB STEREO IMPLEMENTED THROUGH INITIAL GLOBAL FUSION
DUAL-PHONE CONTROL, CLOCK SYNC AND REAL FHD RECORDING VERIFIED
PRIMARY TARGET: OPTIMIZED METRIC MODEL WITH TEXTURED TRIANGLE MESH
IMMEDIATE NEXT: DUAL-PHONE DP04.2
```

This document separates the active stereo pipeline from the independent Video
SfM component-assembly roadmap. Older task documents remain useful as stage
contracts, but their historical status headers must not override this file or
the current runtime code.

The detailed dual-phone product and architecture decisions are fixed in:

```text
docs/llm/tasks/APP-DUAL-PHONE-STEREO-ROADMAP.md
docs/llm/tasks/APP-DUAL-PHONE-METRIC-MODEL-ARCHITECTURE.md
```

## Branch boundary

### A. Video SfM branch

```text
FHD60 single-camera video
→ frame extraction and keyframes
→ COLMAP sparse components
→ dense components
→ run-scoped merge and final assembly
```

The `LightGlue bridge POC` in `SFM-POST-WORKBENCH-ROADMAP.md` belongs to this
branch. Its purpose is to find bridges between disconnected room/staircase
COLMAP components.

### B. Calibrated synced stereo processing branch

```text
cam0 + cam1 synchronized calibrated pairs
→ stereo rectification
→ StereoSGBM metric depth
→ pair-local metric PLY clouds
→ cross-pair visual odometry
→ submaps and global optimization
→ metric surface fusion
→ triangle mesh
→ full-resolution texture projection
```

LightGlue in this branch means a possible replacement or fallback matcher for
cross-pair stereo odometry. It is not the same task as the Video SfM bridge POC.

Every future document must qualify the term as either:

```text
Video SfM LightGlue bridge
Stereo odometry LightGlue matcher
```

### C. Stereo capture frontends

Two frontends feed the shared calibrated stereo processing branch:

```text
PHONE_USB_STEREO
Android phone cam0 + USB UVC cam1

DUAL_PHONE_STEREO
Master phone cam0 + Slave phone cam1
```

The source code, calibration workflow and artifact schemas should be shared where
possible. Numeric intrinsics, distortion and stereo extrinsics are topology- and
rig-specific and must never be copied from phone + USB to two phones.

The dual-phone frontend is now implemented through real two-phone
`ARM → START_AT → RECORDING → STOP` capture. It does not replace the phone + USB
runtime; both remain supported inputs.

### D. Dual-phone operating modes

```text
SYNC_VIDEO  = local original recording and server-quality reconstruction
LIVE_METRIC = reduced frame transport and on-device metric mapping
HYBRID      = both paths in one capture
```

The full-resolution streams stay local during recording. Reduced analysis frames
may be transferred from Slave to Master only for LIVE_METRIC/HYBRID.

## Implemented stereo runtime

`MAKLERTOUR_SYNCED_DENSE` currently runs:

```text
dense_depth_from_synced_capture.py
→ stereo_visual_odometry.py
→ stereo_global_fusion.py
```

Implemented behavior:

- horizontal and vertical-baseline rectification/depth handling;
- StereoSGBM disparity and metric depth in millimeters;
- colored pair-local PLY export;
- ORB feature detection and binary descriptor matching;
- metric 3D-to-2D PnP trajectory with quality gates;
- accepted-pose-only global cloud transform;
- deterministic voxel fusion;
- `fused_global_no_icp.ply` diagnostic artifact.

Current completion flags intentionally remain:

```text
fusion_stage=initial_no_icp
icp_applied=false
global_fusion_complete=false
```

## Implemented dual-phone frontend

The current source and real-device test have established:

- persistent Master/Slave identity and physical camera/mode exchange;
- TCP pairing, command acknowledgements and reconnect;
- repeated four-timestamp UDP clock synchronization;
- periodic clock-model stabilization;
- bounded stable-FAIR scheduling policy;
- automatic headless CameraX recorder preparation;
- real local FHD capture on both phones;
- role-local `video.mp4` and `dual_capture_manifest.json`;
- clock and recording timing diagnostics.

This is DP04.1. It proves synchronized capture control, not yet exact per-frame
stereo pairing.

## Not implemented yet

### Dual-phone capture completion

- per-frame camera metadata sidecars for both phones;
- deterministic mapping between sensor timestamps and encoded MP4 PTS;
- dual-phone IMU sidecars integrated into the role capture;
- aggregate dual-phone bundle;
- synchronization validator with median/P95/max frame deltas;
- dual-phone intrinsics/distortion and stereo R/T calibration;
- two-sided backend registration and upload completeness gate.

### Metric model completion

- deterministic stereo pair quality/keyframe selection;
- IMU rotation consistency gate in pose acceptance;
- stereo-inertial SLAM and bounded submaps;
- relocalization and loop-closure graph;
- global pose graph optimization or bundle adjustment;
- VGGT recovery/global initialization for failed bridges only;
- neural-depth fallback/fusion;
- bounded neighbor-only ICP refinement;
- accepted-pose TSDF or equivalent surface integration;
- mesh cleanup and metric measurement output;
- UV atlas and texture projection from original frames;
- production `global_fusion_complete=true` and `textured_model.glb` artifacts.

## Fixed implementation and acceptance order

```text
1. DP04.2: per-frame sidecars, IMU, camera info, clock history and bundle fields.
2. DP05: reconcile MP4 PTS, validate real frame pairing and dropped frames.
3. DP06: calibrate separate K0/D0 and K1/D1 plus stereo R/T for the dual-phone rig.
4. DP07: upload/register both sides and create a normalized aggregate bundle.
5. MM01: extract a deterministic accepted synchronized pair list.
6. MM02: runtime-test StereoSGBM + ORB/PnP and record every quality decision.
7. Add timestamp-aligned IMU rotation validation without using IMU as the sole pose source.
8. Add submap boundaries, relocalization candidates and trajectory-gap diagnostics.
9. Add loop closure and global pose optimization / bundle adjustment.
10. A/B-test SuperPoint + LightGlue as stereo-odometry fallback against ORB.
11. Add bounded neighbor-only ICP only after a valid visual initial transform.
12. Add VGGT recovery/global initialization only for failed bridges or broken submaps.
13. Integrate accepted optimized depth with TSDF or an equivalent deterministic method.
14. Export a cleaned metric triangle mesh and measurement report.
15. Project/blend textures from original full-resolution frames and export GLB.
16. Start LIVE_METRIC transport and on-device mapping on the same validated core.
```

TSDF must not be used to hide trajectory errors. VGGT must not replace the
working deterministic path. The live metric map must not be presented as the
final production mesh until server refinement and quality gates pass.

## Immediate runtime gate

The next gate is **DP04.2**, not mesh fusion. For a short dual-phone recording,
produce and inspect:

```text
cam0.mp4
cam1.mp4
cam0_frames.jsonl
cam1_frames.jsonl
imu0.jsonl
imu1.jsonl
cam0_camera_info.json
cam1_camera_info.json
clock_sync.json
matched_frame_count
unmatched_frame_count
frame_delta_median_ms
frame_delta_p95_ms
frame_delta_max_ms
dropped_frame_count
encoder_mapping_status
```

After DP04.2/DP05 acceptance, create the first dedicated two-phone ChArUco
calibration. Phone + USB calibration values are not valid for that rig.
