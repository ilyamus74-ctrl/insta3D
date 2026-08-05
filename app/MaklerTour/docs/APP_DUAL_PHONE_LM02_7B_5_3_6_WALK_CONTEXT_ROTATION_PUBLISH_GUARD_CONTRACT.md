# APP-DUAL-PHONE-LM02.7B.5.3.6 — Walk-context rotation publish guard

## Purpose

Prevent a temporary loss of stereo translation during physical walking from
being interpreted as proof that the camera is rotating around a fixed point.
The live point-cloud viewer continues to display the same accumulated global
map; this contract changes only which new poses are trusted for map fusion.

## Motion-state rules

1. A missing or rejected stereo translation is not rotation evidence. It yields
   unknown motion unless walking or positively confirmed tripod rotation is
   observed.
2. Reliable stereo SE(3) translation beyond the tripod pivot envelope or valid
   accelerometer walking evidence activates a short walk-context latch.
3. While the walk-context latch is active, yaw-only pose candidates may remain
   tracking references, but their geometry is suppressed and must not enter the
   accumulated voxel map.
4. Rotation geometry may be published only with positive current-frame tripod
   evidence: gyro and visual yaw agreement, low measured acceleration, and a
   safe PnP translation inside the existing tripod bound.
5. A gyro-only or visual-only rotation, failed PnP pose, or unconfirmed tripod
   translation is reported as `TRACKING_TRANSLATION_UNCERTAIN`; it must not
   become a keyframe or alter trusted accumulated geometry.
6. Existing trusted points, anchored provisional backfill, AprilTag handling,
   and the initial stillness gyro calibration remain intact.

## Safety constraints

- No IMU position integration.
- No synthetic camera path or forced circular trajectory.
- No hard yaw multiplier.
- No online COLMAP mapper and no hard COLMAP pose prior.
- Suppression affects only the new untrusted frame; it does not reset or replace
  the accumulated global map.

## Diagnostics

The status and pose-validation streams expose the walk-context state, rotation
publish candidates, positively confirmed rotation frames, suppressed rotation
geometry, and rejection counts for recent walking or missing tripod proof.
