# Capture Bundle Stereo Fusion Contract

## Status

```text
IMPLEMENTED THROUGH INITIAL GLOBAL FUSION
CURRENT TRAJECTORY MATCHER: ORB
CURRENT FUSION STAGE: initial_no_icp
RUNTIME ACCEPTANCE PENDING
```

## Purpose

Define the artifact and coordinate-system contract for extending
`MAKLERTOUR_SYNCED_DENSE` from independent stereo depth pairs to a global
metric point cloud.

This document does not change the Android capture bundle schema.

## Source contract

Accepted input remains:

```text
capture_type=synced_depth_frames

bundle_manifest.json
capture/synced_depth_manifest.json
capture/pairs/
calibration/stereo_extrinsics.json
```

`stereo_video_legacy` is not accepted by this pipeline.

## Current boundary

The current job creates:

- pair-local metric depth and colored PLY clouds;
- an ORB/PnP metric trajectory with accepted/rejected pose records;
- an accepted-pose-only initial global voxel fusion;
- `dense/global_fusion/fused_global_no_icp.ply`;
- `fusion_stage=initial_no_icp`;
- `icp_applied=false`;
- `global_fusion_complete=false`.

The current global PLY is diagnostic, not the final production model. The job
does not yet provide LightGlue matching, IMU pose gating, submaps,
relocalization, ICP refinement, VGGT recovery, global bundle adjustment, TSDF
integration or mesh export.

## Stage F01A — Pair-local metric clouds

Required coordinate system:

```text
rectified cam0 of the individual pair
```

Required units:

```text
millimeters
```

Required artifacts:

```text
dense/pair_clouds/dense_pair_<index>_cloud.ply
dense/pair_cloud_manifest.json
dense/contact_pair_clouds.jpg
```

F01A invariant:

```text
global_fusion_complete = false
```

Pair-local clouds must not contain invented global transforms.

## Stage F01B — Metric trajectory

Required artifacts:

```text
dense/stereo_trajectory.json
dense/stereo_odometry_debug.json
```

Each accepted pose must identify:

```text
pair_index
reference_pair_index
transform_cam0_to_world
translation_mm
rotation representation
PnP correspondence count
PnP inlier count
reprojection error
IMU rotation comparison when available
accepted/rejected status
rejection reason
```

Canonical transform:

```text
4x4 homogeneous rigid transform
cam0(pair_i) → F01 world
metric translation in millimeters
```

Scale remains fixed by stereo geometry. Sim(3) scale fitting is not the normal
F01B contract.

## Stage F01C — Global fusion

Required artifacts:

```text
dense/global_fusion/fused_global_no_icp.ply
dense/global_fusion/global_fusion_manifest.json
```

Global fusion may include only pair clouds whose pose and depth quality gates
were accepted.

The debug contract records:

```text
input pair count
accepted pair count
rejected pair count and reasons
trajectory length
voxel size
ICP usage and neighbor edges
point counts before and after filtering
world coordinate convention
units
```


## Matcher and roadmap branch boundary

The current stereo trajectory matcher is ORB with mutual binary-descriptor
matching followed by metric 3D-to-2D PnP.

The repository contains two different future LightGlue tasks:

```text
Video SfM LightGlue bridge:
    bridge disconnected COLMAP room/staircase components

Stereo odometry LightGlue matcher:
    improve cross-pair correspondences in MAKLERTOUR_SYNCED_DENSE
```

These tasks must not share an unqualified `LightGlue POC` status. They use
different inputs, outputs, acceptance metrics and failure handling.

The stereo matcher must first be A/B-tested against a saved ORB baseline. ORB
remains the fast path or fallback until runtime evidence supports a different
policy.
## Coordinate and orientation rules

- UI/display rotation is diagnostic only.
- Physical orientation may validate rotation but does not define camera pose.
- Raw cam0/cam1 files remain immutable.
- Rectification uses calibration matching pair resolution.
- Vertical-baseline disparity input rotation is inverted before local
  back-projection into canonical rectified cam0 coordinates.
- Local and global PLY units remain millimeters unless a future schema version
  explicitly changes this.

## ToF extension boundary

VL53L8CX is not part of F01A, F01B or F01C input requirements.

A later ToF contract may add timestamped depth anchors:

```text
timestamp_ns
sensor_id
zone grid
distance_mm
target_status
sensor_to_cam0 extrinsics
```

ToF may validate or constrain depth. It must not silently replace stereo
calibration or camera-pose estimation.

## Compatibility

Existing outputs remain valid:

```text
dense/contact_dense_depth.jpg
dense/dense_depth_debug.json
dense/dense_depth_summary.csv
result.json
```

New fields and artifacts are additive until a documented schema migration is
introduced.

## Security and scope

Published artifacts must:

- belong to the requested order/session/bundle/job chain;
- remain inside the allowed output root;
- be regular non-symlink files;
- have positive size;
- match the expected artifact path;
- not expose arbitrary station filesystem paths through a download endpoint.
