# APP-STEREO-F01B-A — Metric Stereo Visual Odometry Core

## Status

```text
IMPLEMENTED CORE
NOT WIRED INTO MAKLERTOUR_SYNCED_DENSE
```

## Parent

```text
APP-STEREO-F01 — Global Stereo Depth Fusion
```

## Goal

Estimate a metric camera trajectory from the pair-local artifacts produced by
`APP-STEREO-F01A`.

The core engine consumes:

```text
dense/pair_cloud_manifest.json
dense/dense_depth_debug.json
dense/dense_pair_<index>_rect_cam0.png
dense/dense_pair_<index>_depth_mm.npy
```

and writes:

```text
dense/stereo_trajectory.json
dense/stereo_odometry_debug.json
```

F01B-A is intentionally not called by
`process_maklertour_synced_dense.sh`. Job integration is a separate F01B-B
patch after F01A runtime verification.

## Method

For every current pair:

1. Detect ORB features on rectified cam0 images.
2. Match descriptors with a mutual ratio test.
3. Read metric depth in the reference cam0 frame.
4. Back-project reference pixels to metric 3D points.
5. Build 3D-to-2D correspondences against the current rectified cam0 image.
6. Estimate `reference -> current camera` using `solvePnPRansac`.
7. Refine accepted inliers with `solvePnPRefineLM` when available.
8. Invert the relative transform and chain it into
   `transform_cam0_to_world`.

The engine tries a small window of previously accepted references. A rejected
pair does not advance the reference chain.

## Coordinate contract

```text
input 3D:
    rectified reference cam0
    millimeters

PnP output:
    transform_reference_to_current_camera

trajectory output:
    transform_cam0_to_world
    millimeters
```

The first accepted pair defines the temporary F01 world origin.

## Quality gates

Default gates:

```text
minimum 3D-to-2D correspondences: 20
minimum PnP inliers:              15
minimum inlier ratio:             0.35
maximum median reprojection:      3.5 px
maximum relative translation:     1500 mm
maximum relative rotation:        35 deg
minimum positive-depth ratio:     0.90
reference retry window:           3 accepted pairs
```

All thresholds are CLI parameters and are recorded in
`stereo_odometry_debug.json`.

## Output contract

`stereo_trajectory.json` contains:

```text
schema_version
coordinate_system
units
pose_convention
world_origin_pair_index
pair_count
accepted_pose_count
rejected_pose_count
trajectory_status
global_fusion_complete=false
poses[]
```

Each pose attempt records:

```text
pair_index
reference_pair_index
accepted
status
rejection_reason
transform_reference_to_current_camera
transform_cam0_to_world
camera_center_world_mm
correspondence_count
PnP inlier count and ratio
reprojection error
relative translation and rotation
```

Rejected entries have no global transform.

## Non-goals

F01B-A does not:

- modify Android capture;
- modify the capture bundle;
- run global cloud fusion;
- invoke ICP;
- use UI orientation as camera pose;
- claim `global_fusion_complete=true`;
- change the current remote job result contract.

## Validation

Run with the same Python environment that provides OpenCV:

```bash
PYTHON=/tmp/insta3d-f01a-venv/bin/python \
php web/tests/stereo_visual_odometry_test.php
```

Expected:

```text
OK
```

Manual execution after an F01A job exists:

```bash
python3 web/remote_station/scripts/stereo_visual_odometry.py \
  /path/to/job/dense
```

## Next patch

```text
APP-STEREO-F01B-B
→ call the engine from process_maklertour_synced_dense.sh
→ publish trajectory/debug paths in result.json
→ keep global_fusion_complete=false
```
