# APP-DUAL-PHONE-LM02.7B.5.3.8 — Local submap continuation

## Purpose

Keep metric stereo odometry and live point accumulation moving after a short
tracking break instead of permanently suppressing every later frame.

## Runtime behaviour

1. A trusted small-step stereo bridge is evaluated cumulatively against the last
   published keyframe. When total displacement reaches the normal keyframe gate,
   it becomes a normal map keyframe.
2. A small valid stereo SE(3) step from an untrusted provisional reference keeps
   the provisional transform chain alive instead of replacing it with a yaw-only
   pose and resetting chain length to zero.
3. After at least three consecutive provisional stereo steps, a locally
   consistent submap may be promoted when cumulative travel is 0.12–2.0 m and
   the current stereo estimate has at least 16 inliers, at least 0.65 inlier
   ratio and at most 0.08 m residual.
4. Promotion creates a new trusted local anchor at the current provisional pose
   and backfills only the continuous cached provisional chain. It does not invent
   a trajectory, scale stereo translation or modify earlier accepted geometry.
5. The first unmeasured interval before a local submap remains an uncertainty.
   Future relocalization or loop closure may correct the submap globally.
6. Rotation geometry remains protected by the LM02.7B.5.3.6 positive tripod
   confirmation guard.

## Diagnostics

`pose_validation.jsonl` records `provisional_stereo_bridge` and
`local_submap_promoted`. Methods include:

- `AUTO_STEREO_SE3_PROVISIONAL_BRIDGE`
- `AUTO_LOCAL_SUBMAP_STEREO_SE3_PROMOTED`
- `AUTO_STEREO_SE3_TRUSTED_BRIDGE_CUMULATIVE_KEYFRAME`
