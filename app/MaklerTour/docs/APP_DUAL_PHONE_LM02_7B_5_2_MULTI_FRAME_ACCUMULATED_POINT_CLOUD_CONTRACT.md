# LM02.7B.5.2 — Multi-frame registration and accumulated point cloud

## Purpose

Extend the laptop dual-phone stereo host from a single-frame PLY snapshot to a
persistent metric map assembled from multiple strict stereo keyframes.

## Input contract

- Only `STRICT` stereo pairs are eligible.
- Geometry profile must be `HIGH_640`.
- Source is `StereoDepthResult::work_a`, `geometry_disparity` and
  `geometry_mask`.
- Coordinate system remains `X_right_Y_up_Z_forward_meters`.

Relaxed and replay pairs remain available only to the live operator preview and
must never enter the accumulated map.

## Registration

1. Detect ORB features on the rectified CAMERA_A geometry image.
2. Match the current frame against recent keyframes with Hamming KNN and a
   ratio test.
3. Recover 3D reference points from calibrated disparity.
4. Estimate relative camera motion with `solvePnPRansac`.
5. Reject registration when feature count, PnP inliers, inlier ratio,
   reprojection error or motion sanity limits fail.
6. Search recent keyframes for relocalization when the newest keyframe does not
   provide a valid pose.

A frame becomes a keyframe when camera motion exceeds 6 cm or 4 degrees.

## Accumulation

Accepted keyframe points are transformed into the first-keyframe world frame and
merged into a 3 cm global voxel map. Coordinates and RGB values are averaged by
observation count. The map is capped at 500000 voxels.

## Output

- `point_cloud_accumulated.ply`
- `camera_trajectory.json`
- `camera_trajectory.ply`
- `accumulated_map.jsonl`
- `accumulated_map_status.json`

The existing single-frame files remain unchanged.

## Tracking states

- `WAITING`
- `TRACKING_INITIALIZED`
- `TRACKING`
- `TRACKING_STATIONARY`
- `RELOCALIZED`
- `LOST`
- `ERROR`

## Backpressure

The runtime is latest-only, accepts no more than one source frame every 300 ms,
and clones OpenCV matrices only after profile, queue and interval checks pass.

## Scope boundary

This slice does not yet:

- fuse IMU orientation;
- run ICP;
- close loops globally;
- rebuild accumulated room planes;
- create a triangle mesh or textures.

Those operations follow after accumulated PLY and camera trajectory are
validated on real room scans.
