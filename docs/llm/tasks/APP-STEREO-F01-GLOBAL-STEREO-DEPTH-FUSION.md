# APP-STEREO-F01 — Global Stereo Depth Fusion

## Status

```text
IMPLEMENTED THROUGH F01C INITIAL GLOBAL FUSION
CURRENT MATCHER: ORB
RUNTIME ACCEPTANCE PENDING
```

## Goal

Extend the existing synced stereo capture path from independent pair-local
depth maps to one globally aligned metric 3D point cloud.

## Current verified base

```text
Android synced_depth_frames capture
→ capture bundle upload
→ bundle validation
→ MAKLERTOUR_SYNCED_DENSE
→ stereo rectification
→ baseline-axis detection
→ horizontal or vertical StereoSGBM
→ metric depth for individual pairs
→ colored pair-local metric PLY clouds
→ ORB cross-pair metric visual odometry
→ accepted-pose global transforms
→ initial voxel-fused global PLY without ICP
→ browser preview, manifests and result summary
```

The current output includes an initial diagnostic global model:

```text
pair-local metric clouds
+ accepted ORB/PnP trajectory poses
→ dense/global_fusion/fused_global_no_icp.ply
→ icp_applied=false
→ global_fusion_complete=false
```

The missing production pipeline is:

```text
runtime acceptance and drift classification
→ deterministic pair quality/keyframe selection
→ LightGlue A/B and fallback integration
→ IMU rotation validation
→ submaps and relocalization
→ bounded neighbor-only ICP
→ VGGT recovery for broken trajectories
→ global pose optimization / bundle adjustment
→ Open3D TSDF and mesh
```

## Implementation sequence

### APP-STEREO-F01A — Metric Pair Cloud Export — implemented

For each accepted stereo pair:

```text
metric depth
+ rectified cam0 color
+ rectified cam0 intrinsics
→ colored local PLY in cam0 pair coordinates
```

F01A does not estimate motion and must not claim global alignment.

### APP-STEREO-F01B — Metric Stereo Visual Odometry — implemented with ORB

For selected consecutive pairs:

```text
3D points from pair N
+ cam0 features from pair N+1
→ 3D-to-2D correspondences
→ solvePnPRansac
→ metric relative SE(3)
→ chained trajectory
```

IMU rotation is validation/support data, not the sole pose source.

### APP-STEREO-F01C — Initial Global Fusion — implemented without ICP

```text
local pair clouds
+ accepted trajectory poses
→ world transforms
→ quality gates
→ voxel filtering
→ dense/global_fusion/fused_global_no_icp.ply
→ icp_applied=false
→ global_fusion_complete=false
```

### APP-STEREO-T01 — Optional ToF depth anchor

Only after F01C runtime acceptance:

```text
one central VL53L8CX
→ independent absolute distance samples
→ stereo-depth validation
→ scale/depth anchor diagnostics
```

Three ToF sensors are deferred until one sensor proves useful.

## Parallel Video SfM branch

FHD 60 FPS Video SfM evaluation continues independently. Its LightGlue bridge
POC targets disconnected COLMAP room/staircase components.

Stereo F01 uses a different matching problem: cross-pair trajectory estimation
from synchronized calibrated stereo pairs. A future stereo LightGlue matcher
must be documented and tested separately from the Video SfM bridge POC.

Video and stereo changes must remain in separate patch series until each branch
has runtime evidence.

## Non-goals

F01 does not initially:

- change the Android synced capture layout;
- replace current stereo calibration;
- add stereo video;
- add ToF hardware support;
- build a mesh;
- use UI orientation as camera pose;
- modify raw bundle files.

## Canonical coordinate systems

```text
pair cloud:
    rectified cam0 pair-local coordinates
    units: millimeters

trajectory:
    cam0(pair_i) → F01 world
    rigid SE(3)
    metric translation in millimeters

global cloud:
    F01 world coordinates
    units: millimeters
```

The first accepted pair may define the temporary world origin.

## Quality gates

A pair may participate only when minimum criteria are satisfied:

- valid calibration;
- valid manifest entry;
- readable cam0/cam1 frames;
- acceptable synchronization delta;
- sufficient valid depth ratio;
- finite local cloud points;
- sufficient visual correspondences;
- successful PnP with enough inliers;
- bounded translation and rotation jump;
- acceptable IMU rotation mismatch when IMU is available.

Rejected pairs remain in diagnostics and do not silently enter fusion.

## Required artifacts

```text
F01A:
dense/pair_clouds/dense_pair_<index>_cloud.ply
dense/pair_cloud_manifest.json
dense/contact_pair_clouds.jpg

F01B:
dense/stereo_trajectory.json
dense/stereo_odometry_debug.json

F01C:
dense/global_fusion/fused_global_no_icp.ply
dense/global_fusion/global_fusion_manifest.json
global_fusion_complete=false
```

## Acceptance sequence

```text
1. Run a short calibrated MAKLERTOUR_SYNCED_DENSE capture.
2. Inspect 3–5 pair-local clouds and confirm metric scale/orientation.
3. Inspect accepted/rejected ORB trajectory poses and reprojection errors.
4. Open fused_global_no_icp.ply and classify drift, duplicates and gaps.
5. Add pair quality/keyframe selection.
6. A/B-test stereo LightGlue against the saved ORB baseline.
7. Add IMU rotation validation and submap/relocalization diagnostics.
8. Add bounded neighbor-only ICP.
9. Add VGGT recovery and then global pose optimization.
10. Add TSDF/mesh only after optimized-pose acceptance.
11. Add web viewer and secure PLY download for the accepted artifact.
12. Evaluate one central VL53L8CX only after stereo runtime acceptance.
```

## Related contracts

```text
web/DOCS/CAPTURE_BUNDLE_DENSE_CONTRACT.md
web/DOCS/CAPTURE_BUNDLE_STEREO_FUSION_CONTRACT.md
app/MaklerTour/docs/APP_CAMERA_STEREO_CONTRACT.md
docs/llm/tasks/APP-STEREO-F01A-METRIC-PAIR-CLOUD-EXPORT.md
```
