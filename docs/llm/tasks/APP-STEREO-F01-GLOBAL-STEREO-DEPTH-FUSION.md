# APP-STEREO-F01 — Global Stereo Depth Fusion

## Status

```text
PLANNED
ACTIVE NEXT: APP-STEREO-F01A
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
→ browser preview and depth summary
```

The current output is not a global model:

```text
pair 0 → local depth
pair 1 → local depth
pair 2 → local depth
```

The missing pipeline is:

```text
pair-local metric 3D
+ metric camera trajectory
+ quality-controlled transforms
→ global fused 3D
```

## Implementation sequence

### APP-STEREO-F01A — Metric Pair Cloud Export

For each accepted stereo pair:

```text
metric depth
+ rectified cam0 color
+ rectified cam0 intrinsics
→ colored local PLY in cam0 pair coordinates
```

F01A does not estimate motion and must not claim global alignment.

### APP-STEREO-F01B — Metric Stereo Visual Odometry

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

### APP-STEREO-F01C — Global Fusion

```text
local pair clouds
+ accepted trajectory poses
→ world transforms
→ quality gates
→ voxel filtering
→ optional neighbor-only ICP refinement
→ stereo_global_fused.ply
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

FHD 60 FPS Video SfM evaluation continues independently.

Its result may establish Video SfM as the recommended capture mode, but it
does not block stereo F01. Video and stereo changes must remain in separate
patch series until each branch has runtime evidence.

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
dense/stereo_global_fused.ply
dense/stereo_fusion_debug.json
dense/stereo_fusion_preview.jpg
```

## Acceptance sequence

```text
1. Implement F01A.
2. Inspect 3–5 local pair clouds in MeshLab.
3. Confirm metric scale and orientation.
4. Implement F01B.
5. Validate trajectory on a short controlled path.
6. Implement F01C.
7. Inspect global model and drift.
8. Add web viewer and secure PLY download.
9. Evaluate one central VL53L8CX.
```

## Related contracts

```text
web/DOCS/CAPTURE_BUNDLE_DENSE_CONTRACT.md
web/DOCS/CAPTURE_BUNDLE_STEREO_FUSION_CONTRACT.md
app/MaklerTour/docs/APP_CAMERA_STEREO_CONTRACT.md
docs/llm/tasks/APP-STEREO-F01A-METRIC-PAIR-CLOUD-EXPORT.md
```
