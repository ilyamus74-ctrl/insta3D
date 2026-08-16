# SFM-S01H — ToF metric geometry measurement and assistance

## Goal

Use VL53L8CX as optional metric evidence around the stock COLMAP pipeline.
Measure benefit before allowing ToF to change reconstruction geometry.

## Non-blocking rule

RGB is authoritative for pipeline availability.

```text
RGB + IMU
  -> EXTRACT -> COLMAP sparse -> COLMAP dense -> mesh

RGB + IMU + valid ToF
  -> the same RGB path
  -> additional S01H measurement artifacts
  -> later optional metric assistance
```

Every S01H tool must return a non-fatal `SKIPPED_*` result when ToF is missing or
untrusted.

Missing ToF, failed temporal association, unbound calibration, or zero valid ToF
zones must never block RGB sparse, dense, mesh, or export.

## Geometry convention

VL53L8CX `distance_mm` is axial/perpendicular Z depth:

```text
xn = (col - cx_zones) / fx_zones
yn = (row - cy_zones) / fy_zones

Z_tof = distance_mm
X_tof = Z_tof * xn
Y_tof = Z_tof * yn
```

The ray is not normalized.

Rigid transform:

```text
P_camera_mm =
    R_tof_to_camera * P_tof_mm
    + t_tof_to_camera_mm
```

## S01H.1 — metric observation builder

Inputs:

```text
selected_sensor_associations.jsonl
selected_sensor_association_report.json
tof_frames.jsonl                  optional
tof_calibration.json              optional
```

Required gates:

```text
temporal_candidate_pass == true
binding_status == MATCHED_CAPTURE_IDENTITY
identity_match == true
matching_profile_count == 1
selected ToF pair accepted == true
raw ToF frame found == true
```

Initial zone filters:

```text
nb_target_detected > 0
target_status in {5, 6, 9}
100 mm <= distance_mm <= 4000 mm
sigma_mm <= 100 mm
camera-space Z > 0
```

Outputs:

```text
tof_metric_observations.jsonl
tof_metric_observation_report.json
```

S01H.1 is measurement-only:

```text
geometry_mutation_enabled = false
colmap_input_modified = false
dense_input_modified = false
fusion_enabled = false
```

## S01H.2 — sparse comparison

After stock COLMAP sparse:

1. load the selected model and final optimized camera parameters;
2. associate S01H.1 observations with registered images;
3. compare ToF depth/rays with sparse geometry;
4. estimate a robust global COLMAP-to-metric scale candidate;
5. report residuals/outliers;
6. do not apply the scale automatically.

## S01H.3 — reviewed metric sparse

Only after S01H.2 review may a derived metric sparse model be generated.
The original COLMAP sparse model remains immutable and is always the fallback.

Dense continues with stock COLMAP.

## S01H.4 — dense vs ToF validation

Report absolute and relative depth errors, coverage, distance buckets, target
status buckets and sigma buckets. No fusion is enabled here.

## S01H.5 — conservative fusion

Only after S01H.4 demonstrates useful behaviour may ToF affect final geometry.
The 8x8 ToF sensor is a metric anchor/validator, not a replacement for RGB dense.
