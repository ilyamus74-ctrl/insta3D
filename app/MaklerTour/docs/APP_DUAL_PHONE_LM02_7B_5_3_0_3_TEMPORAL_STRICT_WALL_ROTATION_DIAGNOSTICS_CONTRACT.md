# LM02.7B.5.3.0.3 — TEMPORAL STRICT wall rotation diagnostics

## Purpose

Make a single-wall tripod test interpretable while preserving the existing dense
tracking and accumulated-map path. The operator rotates the paired phones slowly by
45–90 degrees and pauses at several angles.

## Behaviour

The dense geometry remains authoritative for ORB tracking, pose estimation and the
existing accumulated PLY files. The runtime additionally carries the actual temporal
strict disparity and mask into the accumulated-map worker.

For every accepted keyframe it writes:

- `keyframe_N_local_temporal_strict.ply`;
- `keyframe_N_world_temporal_strict.ply`.

The session root additionally contains:

- `point_cloud_accumulated_temporal_strict_raw.ply`;
- `point_cloud_accumulated_temporal_strict_multiview.ply`.

The multiview file contains strict voxels observed from at least two accepted
keyframes. Its vertex count and `temporal_strict_overlap_fraction` are diagnostic
evidence of whether the same wall lands in the same world coordinates after camera
rotation.

## Safety

This slice does not replace dense geometry, change pose thresholds, lower plane
confirmation requirements, alter AprilTag correction policy, or modify the calibrated
baseline. Empty strict geometry is allowed and does not block capture.

## Capture protocol

1. Aim at one textured wall and hold still for 2–3 seconds.
2. Rotate slowly by roughly 15–25 degrees and hold again.
3. Repeat until total rotation reaches 45–90 degrees.
4. Avoid translation of the tripod centre.
5. Package the session and compare local/world strict PLY files and the strict
   multiview count.
