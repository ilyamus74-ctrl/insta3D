# LM02.7B.5.2.3 — gyro-assisted reconnect-safe accumulation

## Scope

This slice keeps local stereo geometry unchanged and replaces only the multi-frame
accumulation backend.

- CAMERA_A gyroscope is integrated on the host and used as a yaw prior.
- Visual homography refines gyro yaw when both estimates agree.
- Gyro-only rotation keyframes are allowed when visual registration is temporarily
  unavailable.
- Translation is fixed to zero for tripod-like rotation.
- A same-device reconnect creates a new segment without deleting the accumulated map.
- Each voxel counts unique keyframes separately from pixel samples.

## Outputs

- `point_cloud_accumulated_raw.ply`
- `point_cloud_accumulated_multiview.ply`
- `camera_trajectory.json`
- `camera_trajectory.ply`
- `pose_validation.jsonl`
- `keyframes/keyframe_<id>_local.ply`
- `keyframes/keyframe_<id>_world.ply`

`point_cloud_accumulated.ply` remains an alias of the raw accumulated cloud for
compatibility. The multiview PLY contains only voxels observed in at least two
accepted keyframes.

## Reconnect semantics

A camera disconnect increments `segment_id`. Existing voxels and trajectory are
preserved. Reconnecting the same device does not force stereo calibration or map
reset. A changed device identity still runs the normal calibration resolution path.

## Recommended test

Use `HIGH_640`, rotate the rig slowly on the tripod, intentionally reconnect one
phone once, then continue the same rotation. Inspect raw and multiview PLY files and
compare gyro/visual/fused yaw in `pose_validation.jsonl`.
