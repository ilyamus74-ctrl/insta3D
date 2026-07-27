# APP-STEREO — Current Status and Next Steps

## Status

```text
CURRENT SOURCE OF TRUTH
REPOSITORY BASELINE: a88340b92d8dca2d2819743170d13ee3edd63479
IMPLEMENTED THROUGH INITIAL GLOBAL FUSION
RUNTIME ACCEPTANCE PENDING
```

This document separates the active stereo pipeline from the independent Video
SfM component-assembly roadmap. Older task documents remain useful as stage
contracts, but their historical status headers must not override this file or
the current runtime code.

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

### B. Synced stereo branch

```text
cam0 + cam1 synchronized JPEG pairs
→ calibrated stereo rectification
→ StereoSGBM metric depth
→ pair-local metric PLY clouds
→ cross-pair visual odometry
→ initial global point-cloud fusion
```

LightGlue in this branch means a possible replacement or fallback matcher for
cross-pair stereo odometry. It is not the same task as the Video SfM bridge POC.

Every future document must qualify the term as either:

```text
Video SfM LightGlue bridge
Stereo odometry LightGlue matcher
```

### C. Dual-phone stereo capture frontend

The planned two-phone Master/Slave capture frontend is documented separately:

```text
docs/llm/tasks/APP-DUAL-PHONE-STEREO-ROADMAP.md
```

It is an additional source for the stereo processing branch and does not replace
the current phone + USB UVC runtime until dual-phone synchronization is proven.

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

## Not implemented yet

- hardware-triggered stereo synchronization;
- simultaneous FHD60 stereo video capture;
- stereo pair quality/keyframe selection beyond the current ordered pair limit;
- LightGlue in stereo odometry;
- IMU rotation consistency gate in pose acceptance;
- stereo-inertial SLAM and submaps;
- relocalization and loop-closure graph;
- VGGT recovery/global initialization;
- COLMAP or equivalent global bundle adjustment for the stereo trajectory;
- neural-depth fallback/fusion;
- neighbor-only ICP refinement;
- Open3D TSDF integration and mesh export;
- production `global_fusion_complete=true` artifact.

## Fixed implementation and acceptance order

```text
1. Runtime-test the current ORB + StereoSGBM pipeline.
2. Record accepted/rejected poses, reprojection errors, depth coverage and drift.
3. Add deterministic stereo pair quality/keyframe selection.
4. A/B-test SuperPoint + LightGlue against ORB on the same accepted pair list.
5. Keep ORB as a fast path and use LightGlue as fallback until evidence supports replacement.
6. Add timestamp-aligned IMU rotation validation without using IMU as the sole pose source.
7. Add submap boundaries, relocalization candidates and trajectory-gap diagnostics.
8. Add bounded neighbor-only ICP after a valid visual initial transform.
9. Add VGGT recovery/global initialization only for failed bridges or broken submaps.
10. Run global pose optimization / bundle adjustment after recovery edges are available.
11. Add stereo/neural depth selection only after pose quality is measurable.
12. Add Open3D TSDF and mesh export last, using only accepted optimized poses.
```

TSDF must not be used to hide trajectory errors. VGGT must not replace the
working deterministic path; it is a recovery stage for cases where the normal
front end cannot maintain or reconnect the trajectory.

## Immediate runtime gate

Use a short calibrated synced-depth capture first. Inspect:

```text
pair_cloud_count
trajectory_pair_count
trajectory_status
accepted_pose_count
rejected_pose_count
included_cloud_count
excluded_cloud_count
fused_points_after_voxel
stereo_odometry_debug.json
global_fusion_manifest.json
fused_global_no_icp.ply
```

No LightGlue, ICP, VGGT or TSDF task starts until this baseline result is saved
and its failure modes are classified.
