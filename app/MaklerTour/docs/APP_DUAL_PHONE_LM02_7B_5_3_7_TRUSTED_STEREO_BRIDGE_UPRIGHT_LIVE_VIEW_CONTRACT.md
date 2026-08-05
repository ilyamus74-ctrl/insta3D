# APP-DUAL-PHONE-LM02.7B.5.3.7 — Trusted stereo bridge and upright live view

## Purpose

Keep the trusted online tracking chain fresh when a robust full stereo SE(3)
estimate reports motion below the walking-keyframe threshold, and correct the
default presentation roll in the diagnostic Live 3D model.

## Tracking rules

1. A robust full stereo SE(3) estimate may be trusted as a tracking bridge even
   when its translation is below the minimum walking step.
2. A trusted stereo bridge updates only the fresh trusted tracking reference.
   It does not create a keyframe and does not merge its local cloud into the
   accumulated voxel map.
3. The bridge requires a trusted reference, sufficient stereo pairs and
   inliers, bounded residual, bounded rotation, gyro-yaw consistency when
   available, finite pose values and a bounded vertical step.
4. Walking translation still requires the existing minimum metric step and all
   existing walk safety checks. The bridge must not weaken the walk pose gate.
5. The walk-context rotation publish guard remains active. Yaw-only or
   unconfirmed rotation geometry remains suppressed.
6. Provisional geometry is not promoted by chain length and still requires a
   trusted global anchor before backfill.

## Live-view orientation

1. The stored PLY and trajectory stay unchanged in their existing
   X-right, Y-up, Z-forward coordinate system.
2. The Live 3D model defaults to an UPRIGHT display mode implemented as a
   180-degree camera roll.
3. RAW stored Y-up remains selectable for comparison.
4. The same display transform applies to the cloud and trajectory because it is
   a viewer-camera change, not a reconstruction transform.
5. Absolute gravity alignment from IMU is not claimed: the current runtime does
   not retain a calibrated camera-frame gravity orientation for this purpose.

## Safety constraints

- No IMU position integration.
- No synthetic translation or camera path.
- No hard yaw multiplier.
- No online COLMAP mapper or COLMAP pose prior.
- No mutation of stored PLY, trajectory or previously accumulated geometry.
