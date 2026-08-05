# APP-DUAL-PHONE-LM02.7B.5.4.0 — Bridge quarantine, structural live map and all depth profiles

## Purpose

Reduce ray-like single-view clutter in the live viewer, prevent weak chains of
small stereo bridge steps from publishing a drifting map keyframe, keep the
depth probe visible while the heatmap refreshes, and allow accumulated-map
processing for every concrete depth profile.

## Runtime behaviour

1. RAW and the existing multiview files remain unchanged and available for
   diagnostics.
2. A new `point_cloud_accumulated_structural.ply` is published from voxels that
   have multi-keyframe support, repeated pixel support and connected neighbours
   along at least two voxel axes.
3. The Live 3D model opens the structural cloud by default. This is a display and
   export filter; it does not delete the accumulated RAW map.
4. Small trusted stereo bridge frames may continue refreshing the tracking
   reference, but a cumulative bridge chain cannot publish a map keyframe until
   the current step agrees with PnP and at least two thirds of a chain of at
   least three steps have PnP agreement.
5. This quarantine protects map publication only. It is not a sliding-window
   optimiser or loop-closure system.
6. Accumulated-map input accepts `ULTRA_960`, `HIGH_640`, `QUALITY_480`,
   `BALANCED_320`, `THROTTLED_320` and `FHD_1920`. Each frame keeps the focal
   length and principal point produced for its active work resolution.
7. A stationary depth-probe result is not hidden by the next heatmap image
   refresh. Moving the pointer requests a fresh value; leaving the image or
   changing preview/profile still clears the probe.

## Diagnostics

`pose_validation.jsonl` records:

- `trusted_bridge_pnp_confirmed`
- `trusted_bridge_publish_ready`
- `trusted_bridge_chain_length`
- `trusted_bridge_confirmed_steps`

Methods include:

- `AUTO_STEREO_SE3_TRUSTED_BRIDGE_QUARANTINED`
- `AUTO_STEREO_SE3_TRUSTED_BRIDGE_CONFIRMED_CUMULATIVE_KEYFRAME`
