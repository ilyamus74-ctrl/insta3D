# SFM-S01H2.2 — ToF vs COLMAP dense depth diagnostic

## Goal

Use COLMAP PatchMatch depth maps as a diagnostic surface and compare them directly
with accepted ToF metric observations. This stage measures error structure before
any geometry mutation.

## Metric policy

Direct metric authority is intentionally bounded by the accepted ToF working
range:

```text
100 mm .. 4000 mm
    -> ToF may be used as a direct metric reference when its quality gates pass.

> 4000 mm
    -> APPROXIMATE_ONLY
    -> geometry may inherit scale from validated <=4 m anchors
    -> dimensions must not be presented as directly measured/factual
    -> ToF values are never extrapolated beyond the sensor range
```

The goal is a dimensionally honest model: measured where the sensor provides
trustworthy metric evidence, approximate where only RGB/SfM/Dense geometry is
available.

## Inputs

```text
tof_metric_observations.jsonl
tof_metric_observation_report.json
tof_calibration.json
COLMAP dense reconstruction job directory
```

H2.2 discovers chunk workspaces under:

```text
<dense_job>/chunks/chunk_*/
  workspace_model_text/
  undistorted/stereo/depth_maps/
```

Both geometric and photometric PatchMatch depth maps are supported.

## Dense comparison strategies

For every ToF observation the tool projects the ToF camera-space point and the
full ToF zone footprint into the undistorted dense camera model.

It measures:

```text
geometric_center
geometric_footprint_p25
geometric_footprint_p50
geometric_footprint_p75
geometric_footprint_front_cluster

photometric_center
photometric_footprint_p25
photometric_footprint_p50
photometric_footprint_p75
photometric_footprint_front_cluster
```

Overlapping dense chunks are deduplicated per ToF observation by taking the
median depth estimate across chunk copies before scale statistics are computed.

## Error decomposition

Every strategy is decomposed by:

```text
distance:
  0-0.5 m
  0.5-1.0 m
  1.0-1.5 m
  1.5-2.0 m
  2.0-3.0 m
  3.0-4.0 m

ToF zone row 0..7
ToF zone column 0..7
ToF zone radial class: center / mid / edge
sigma bucket
target_status
time quartile
image region: center / mid / edge
```

The report emits scale-spread signals for distance, zone position, time and image
position. These are diagnostic correlations, not automatic root-cause claims.

## Safety boundary

H2.2 is measurement-only:

```text
geometry_mutation_enabled = false
ready_for_geometry_mutation = false
sparse_model_modified = false
camera_poses_modified = false
points3d_modified = false
dense_input_modified = false
dense_depth_modified = false
fusion_enabled = false
```

Missing ToF or missing Dense depth maps produces a non-fatal `SKIPPED_*` report.
The normal RGB -> COLMAP sparse -> COLMAP dense -> mesh pipeline remains the
fallback.
