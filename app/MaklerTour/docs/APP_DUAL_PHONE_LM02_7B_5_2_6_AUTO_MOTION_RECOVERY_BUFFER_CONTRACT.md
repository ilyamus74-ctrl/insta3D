# LM02.7B.5.2.6 — automatic motion mode and recovery buffer

## Goal

Keep one operator workflow while the host automatically distinguishes rotation around the rig axis from translational walking and retries lost visual registration against a bounded recent-frame/keyframe buffer.

## Motion modes

- `AUTO_UNKNOWN`: insufficient evidence.
- `AUTO_ROTATION`: gyro/homography rotation with translation compatible with the stereo-rig pivot radius.
- `AUTO_WALK`: physically bounded stereo-depth PnP translation supported by visual inliers and IMU motion evidence.

The classifier uses hysteresis. It does not switch mode from one isolated estimate.

## Recovery buffer

The host keeps up to 24 recent successfully tracked frames in memory and up to 64 accepted geometry keyframes. If registration against the newest reference fails, the current frame is retried against older references, up to 12 attempts. A recovered pose is marked `RECOVERED_*` and records the reference pair.

The buffer stores recent rectified tracking frames, ORB descriptors, disparity/mask references, pose and gyro reference. It is bounded and latest-oriented; it is not an unbounded video archive.

## Safety

- WALK translation step is limited to 0.65 m.
- WALK requires at least 24 PnP inliers and 0.40 inlier ratio.
- Sparse stereo-depth residual must be at most 0.35 m when available.
- Rotation accepts the small translation expected from a 0.12 m tripod/pivot radius.
- When WALK temporarily loses translation but gyro remains usable, the host enters `TRACKING_COASTING`; it updates the short-term reference orientation but does not add a geometry keyframe.
- Absurd PnP solutions remain rejected.

## Diagnostics

`accumulated_map_status.json` exposes motion mode, vote counters, buffer occupancy, recovery attempts/successes and coasting frames. `pose_validation.jsonl` records motion evidence, accelerometer motion, selected reference and retry count.

## Limitation

The buffer currently receives full HIGH_640 depth results, approximately 3–6 Hz depending on CPU load. A later tracker can consume rectified CAMERA_A frames directly at the uplink rate without changing the geometry keyframe contract.
