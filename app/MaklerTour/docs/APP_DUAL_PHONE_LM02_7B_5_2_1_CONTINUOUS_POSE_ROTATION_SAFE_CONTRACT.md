# LM02.7B.5.2.1 — continuous pose tracking and rotation-safe accumulation

## Purpose

Prevent accumulated point clouds from collapsing into a directed fan during tripod rotation and reject discontinuous relocalization poses before they contaminate the world map.

## Runtime contract

- `HIGH_640` and strict stereo pairs remain the only geometry source.
- The most recent accepted keyframe is always attempted first.
- Older keyframes are used only after the continuous reference fails.
- Relocalization must pass tighter translation, rotation and yaw-direction gates.
- Strong pure rotation is estimated from a global rotation homography and uses zero relative translation.
- Normal movement continues to use depth-backed `solvePnPRansac`.
- Sparse 3D correspondences refine PnP translation and validate both pose modes.
- A rejected pose never merges points into `point_cloud_accumulated.ply`.
- The legacy `accumulated_map_runtime.cpp` remains in the tree as an implementation reference but is not compiled.

## Continuity limits

- Continuous pose: up to 0.65 m and 30 degrees per accepted step.
- Relocalized pose: up to 0.40 m and 22 degrees from the last accepted pose.
- A relocalized yaw reversal greater than 6 degrees is rejected when recent yaw direction is stable.
- Sparse depth median residual must not exceed 0.50 m when enough depth pairs exist.

## Tracking modes

- `IDENTITY`
- `PNP_DEPTH`
- `ROTATION_HOMOGRAPHY`

## States

- `TRACKING_INITIALIZED`
- `TRACKING`
- `TRACKING_ROTATION`
- `TRACKING_STATIONARY`
- `RELOCALIZED_CONTINUOUS`
- `POSE_REJECTED`
- `LOST`
- `ERROR`

## Diagnostics

`accumulated_map_status.json` and `accumulated_map.jsonl` expose:

- tracking method;
- rotation-only flag;
- yaw step and accumulated yaw;
- sparse depth median residual;
- pose rejection reason;
- count of rotation-only keyframes;
- count of rejected poses.

`camera_trajectory.json` schema version 2 stores the same per-keyframe pose quality fields.

## Expected tripod result

A slow pan around the stereo midpoint should grow an angular world map around a nearly fixed camera position. Older-keyframe relocalization must not roll the trajectory backward into an already traversed sector.
