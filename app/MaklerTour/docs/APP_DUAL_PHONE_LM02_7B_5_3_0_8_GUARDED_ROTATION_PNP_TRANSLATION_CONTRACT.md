# LM02.7B.5.3.0.8 — Guarded rotation PnP translation

## Problem

During tripod pan the optical centre of CAMERA_A does not necessarily coincide
with the tripod rotation axis. A rotation-only pose that preserves the previous
camera position makes the reconstructed room appear to orbit a stationary
camera. The world model must keep walls stationary while the camera pose is
allowed to follow the small pivot arc.

## Contract

- Fused gyro/visual yaw remains authoritative for orientation in rotation mode.
- A synthetic 0.12 m arc is never injected into the pose.
- The already computed PnP camera centre supplies only the translation column.
- PnP translation is accepted only for rotation evidence, outside active walk
  mode, with valid PnP support and within `tripod_translation_limit()`.
- Rejected PnP translation leaves the prior camera position unchanged.
- Plane fusion thresholds and stereo depth are not changed by this patch.

## Diagnostics

Every pose-validation record contains:

- `rotation_translation_applied`;
- `rotation_translation_candidate_m`;
- `rotation_translation_limit_m`;
- `rotation_translation_vector_world_m`;
- `rotation_translation_rejection_reason`.

An accepted rotation pose method ends in `_PNP_TRANSLATION`.

## Expected result

For a stationary tripod pan, neighbouring rotation keyframes may have small,
smoothly changing `position_m` values instead of one fixed camera position.
Physical walls should overlap more closely in world-space PLY files rather than
moving around the camera origin.
