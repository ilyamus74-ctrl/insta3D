# LM02.7B.5.3.0.9 — Constrained tripod translation and clean model archive

## Problem

The first guarded PnP-translation implementation improved plane overlap, but it
accepted almost every three-dimensional PnP displacement. During a stationary
tripod pan this accumulated false vertical motion and a long recursive camera
trajectory. The diagnostics archive also exposed every raw, duplicate and
per-keyframe PLY model at once, obscuring the final geometry.

## Pose contract

- Fused gyro/visual yaw remains authoritative for orientation.
- Tripod rotation translation uses PnP only as a measured horizontal X/Z hint.
- The tripod anchor Y coordinate is fixed for the complete rotation segment.
- Every X/Z update is low-pass filtered and limited by a per-step pivot chord.
- Total X/Z distance from the segment anchor is limited by the yaw-dependent
  chord of `kTripodPivotRadiusM` plus a small return tolerance.
- Returning near the anchor yaw forces the camera position back near the anchor
  instead of permitting recursive drift.
- Walk and AprilTag relocalization reset the tripod constraint.
- No synthetic fixed-radius trajectory is injected.

## Archive contract

The default archive contains JSON/JSONL diagnostics, images and only this
curated `models/` set:

1. filtered multi-view cloud;
2. temporal-strict multi-view cloud;
3. final Manhattan room skeleton;
4. constrained camera trajectory;
5. AprilTag map.

Raw clouds, duplicate live models and per-keyframe PLY files are excluded by
default. `--include-intermediate-models` restores them under
`models/intermediate/` for deep debugging.

## Expected result

A stationary tripod pan keeps camera height constant, bounds the horizontal
camera arc and reduces plane smearing without reverting to a fixed camera
origin. Opening the archive shows only the models needed for normal inspection.
