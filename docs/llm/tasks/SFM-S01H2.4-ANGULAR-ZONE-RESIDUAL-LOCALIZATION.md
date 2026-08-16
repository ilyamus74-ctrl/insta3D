# SFM-S01H.2.4 — angular / zone residual localization

Status: `NEXT / DIAGNOSTIC`

## Goal

Separate the structured ToF row/column residual from the independent
distance-dependent COLMAP dense-depth deformation measured by S01H.2.3.

H2.4 is measurement-only. It must not mutate ToF calibration, COLMAP cameras,
poses, sparse points, dense maps or fusion.

## Inputs

```text
S01H.1:
  tof_metric_observations.jsonl
  tof_calibration.json
  camera_metadata.json

S01H.2.2:
  tof_dense_h22_candidates.jsonl
  tof_dense_h22_report.json

S01H.2.3:
  tof_dense_h23_decomposition.jsonl
  tof_dense_h23_report.json

Sparse evidence:
  cameras.txt
```

## Primary strategy

Use:

```text
geometric_footprint_p50
```

and only direct ToF support:

```text
100 mm <= distance_mm <= 4000 mm
```

Join records by:

```text
image
tof_sequence
zone_index
```

## Required measurements

### A. Distance-conditioned 8x8 residual map

For every supported ToF zone, report signed residual after removing the H2.3
distance model.

Required fields:

```text
zone_index
zone_row
zone_column
count
signed_ratio_residual_p50
signed_ratio_residual_p25
signed_ratio_residual_p75
absolute_ratio_residual_p95
distance-bucket support
```

Do not collapse this into radial buckets.

### B. Projected RGB-coordinate decomposition

Reconstruct each accepted ToF zone center in CAMERA_A coordinates using the
frozen calibration and project it with the final sparse camera.

Report residual versus:

```text
projected RGB x
projected RGB y
normalized image radius
distance
zone row
zone column
```

### C. Angular-calibration sensitivity

Diagnostic-only perturbations may be evaluated for:

```text
ToF cx_zones
ToF cy_zones
ToF fx_zones
ToF fy_zones
small ToF->RGB rotation perturbations
small ToF->RGB translation perturbations
```

The tool may report which perturbation family best reduces structured residuals,
but must not write a new calibration profile.

## Decision outputs

```text
ZONE_ANGULAR_PATTERN_SUPPORTED
RGB_IMAGE_REGION_PATTERN_SUPPORTED
MIXED_PATTERN_SUPPORTED
INSUFFICIENT_SUPPORT
```

A result is diagnostic evidence only.

## Safety

Always:

```text
measurement_only = true
calibration_mutation_enabled = false
geometry_mutation_enabled = false
ready_for_geometry_mutation = false
sparse_model_modified = false
camera_poses_modified = false
points3d_modified = false
dense_input_modified = false
dense_depth_modified = false
fusion_enabled = false
```

S01H.3 remains closed regardless of H2.4 execution status until the H2.x
diagnostic chain is explicitly reviewed.
