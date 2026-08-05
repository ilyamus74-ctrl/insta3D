# APP-DUAL-PHONE-LM02.7B.5.3.9 — Tripod rotation priority and upright handedness

## Purpose

Prevent a strong stationary-rotation observation from being reclassified as
walking merely because stereo 3D-to-3D reports a large translational component,
and correct the live viewer orientation without swapping the room's left and
right sides.

## Runtime behaviour

1. Inertial walking evidence remains the highest-priority walk signal.
2. With low acceleration, consistent gyro/visual or gyro/stereo rotation and a
   strong PnP pose inside the tripod pivot bound, rotation evidence is evaluated
   before stereo translation.
3. A large stereo translation that conflicts with a strong near-stationary PnP
   pose is not accepted as walking. The rotation pose is published instead.
4. A fresh positively confirmed rotation may override stale walk-context state.
   Three consecutive rotation votes clear the old walk-context latch.
5. Trusted and provisional small-step stereo bridges are not selected while the
   same frame has positive stationary-rotation confirmation.
6. Unanchored local-submap promotion introduced in LM02.7B.5.3.8 is disabled.
   Provisional geometry still requires a trusted relocalization/backfill anchor.
7. The live viewer keeps the 180-degree upright camera roll but mirrors display X
   around the cloud centre, preserving left/right placement. Stored PLY and
   trajectory coordinates are unchanged.

## Scope boundary

The patch does not change stereo calibration, disparity, voxel density, PLY
storage coordinates or the physical tripod pivot limits.
