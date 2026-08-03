# LM02.7B.5.2.2 — stereo vs registration diagnostics

## Purpose

Separate two independent stages of the laptop reconstruction pipeline:

1. local stereo geometry generated from one synchronized CAMERA_A/CAMERA_B pair;
2. multi-frame registration that transforms those local clouds into one world map.

The diagnostic slice does not change StereoSGBM, pose selection or accumulated-map
coordinates. It is an offline observer and therefore cannot damage a capture.

## Inputs

- `point_cloud_latest.ply` — latest single-frame stereo cloud;
- `point_cloud_accumulated.ply` — current world cloud;
- `camera_trajectory.json` — accepted keyframe poses;
- `accumulated_map.jsonl` — tracking events and rejection reasons.

## Outputs

- `accumulated_diagnostics.json`;
- `accumulated_diagnostics.txt`;
- `point_cloud_accumulated_confirmed.ply` — voxels with at least two observations;
- `point_cloud_accumulated_strict.ply` — voxels with at least three observations;
- `point_cloud_accumulated_keyframe_colors.ply` — colour by last contributing keyframe.

## Interpretation

If `point_cloud_latest.ply` already has broken walls and radial rays, the problem is in
stereo disparity, calibration, masks or metric reprojection. If the latest local cloud
is coherent while only the accumulated cloud explodes, the primary problem is camera
pose registration or acceptance of weakly confirmed voxels.

The `keyframe_id` stored by the current map is the last contributing keyframe, not a
complete provenance history. The coloured cloud is therefore diagnostic evidence, not
an authoritative segmentation.

## Run

```bash
python3 web/remote_station/dual_phone_host/tools/analyze_accumulated_map.py \
  "$SESSION"
```

The runtime remains on `HIGH_640`. No IMU fusion or pose correction is introduced by
this slice; those changes must be based on the diagnostic result.
