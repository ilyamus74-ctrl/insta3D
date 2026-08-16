# SFM-S01H — ToF metric geometry measurement and assistance

## Goal

Use VL53L8CX as optional metric evidence around the stock COLMAP pipeline.
Measure benefit before allowing ToF to change reconstruction geometry.

## Status snapshot — 2026-08-16

```text
S01H.1  metric observation builder                       PASS / CLOSED
S01H.2  sparse metric-scale diagnostic                   MEASURED
S01H.2.1 sparse nearest-radius / zone-footprint test     MEASURED
S01H.2.2 dense depth diagnostic                          MEASURED
S01H.2.3 controlled error decomposition + optics audit   IMPLEMENTING
S01H.3  reviewed metric geometry mutation                CLOSED / BLOCKED
S01H.4  final dense-vs-ToF validation                    NOT STARTED
S01H.5  conservative fusion                              CLOSED / BLOCKED
```

Current rule:

```text
measurement may advance
geometry mutation may not advance
until H2.x explains the observed depth-dependent scale instability
```

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

## S01H.1 — metric observation builder — PASS / CLOSED

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

Accepted reproducible real-capture evidence:

```text
capture UUID:                  5ae1fa5a-224a-4b85-927d-d32a40dc143f
pipeline_run_id:               92
EXTRACT remote job:            185780520
selected associations:         143
selected with accepted ToF:    128
selected with raw ToF:         128
raw ToF frames:                220
frames with observations:      128
metric observations:           4896
distance p50 / p95:            829.0 / 1704.25 mm
sigma p50 / p95:               6.0 / 21.0 mm
measurement_gate_pass:         true
ready_for_sparse_scale:        true
geometry mutation:             OFF
fusion:                        OFF
```

Pipeline 92 reproduced the same core S01H.1 values previously observed from
pipeline 91. This closes H1 on repeatable processing of the same real capture.

Cleanup-independent evidence:

```text
/home/makler/web/remote_station/output/pipeline_92/metric_evidence/h1/
```

Detailed closure:

```text
docs/llm/tasks/results/SFM-S01H1-METRIC-OBSERVATION-RESULT.md
```

## S01H.2 — sparse comparison

After stock COLMAP sparse:

1. load the selected model and final optimized camera parameters;
2. associate S01H.1 observations with registered images;
3. compare ToF depth/rays with sparse geometry;
4. estimate a robust global COLMAP-to-metric scale candidate;
5. report residuals/outliers;
6. do not apply the scale automatically.

Observed sparse result:

```text
global robust scale candidate: about 195.5 mm / COLMAP unit
candidate count:               1792
inlier count:                  1135
inlier ratio:                  0.633
depth residual p95:            about 223.8 mm
```

The global value must not be applied because scale changes strongly with ToF
distance.

### S01H.2.1 — sparse correspondence diagnostic

Nearest-radius and projected ToF-zone-footprint strategies were compared.

Key conclusion:

```text
global scale is similar across strategies
nearest correspondence choice is not the primary source of the error
distance-dependent scale remains
```

Representative global values:

```text
nearest 4 px:                  198.50 mm/unit
nearest 8 px:                  198.98 mm/unit
nearest 12 px:                196.37 mm/unit
nearest 24 px:                195.53 mm/unit
footprint median:              194.91 mm/unit
footprint front cluster:       195.75 mm/unit
```

### S01H.2.2 — dense depth diagnostic

COLMAP PatchMatch geometric and photometric depth maps were compared directly
against S01H.1 observations before fusion.

Reference strategy:

```text
geometric_footprint_p50
candidates:                    4458
inliers:                       2903
inlier ratio:                  0.651
global scale:                  191.36 mm/unit
depth residual p95:            217.81 mm
distance scale spread ratio:   1.99
```

Distance buckets showed non-constant scale:

```text
0.5-1.0 m:                     about 183.4 mm/unit
1.0-1.5 m:                     about 241.2 mm/unit
1.5-2.0 m:                     about 365.3 mm/unit
2.0-3.0 m:                     about 315.1 mm/unit
```

Diagnostic signals:

```text
distance-dependent scale:      true
ToF row correlation:           true
ToF column correlation:        true
image-region correlation:      true
time-drift correlation:        false
zone-radial correlation:       false
```

Therefore the sparse-only explanation is rejected. Dense depth has the same
metric instability and a single global scale is unsafe.

### S01H.2.3 — controlled error decomposition — IMPLEMENTING

H2.3 removes distance trend before evaluating ToF row/column effects, then
removes row/column effects before evaluating the remaining distance trend.
It also compares final optimized COLMAP camera parameters against the validated
Camera2-derived `colmap_camera_prior`.

Pipeline 92 is regenerating fresh sparse/dense workspaces so H2.2 and H2.3 can
be persisted before temporary job cleanup.

## S01H.3 — reviewed metric sparse — CLOSED / BLOCKED

Only after S01H.2 review may a derived metric sparse model be generated.
The original COLMAP sparse model remains immutable and is always the fallback.

Dense continues with stock COLMAP.

## S01H.4 — dense vs ToF validation

Report absolute and relative depth errors, coverage, distance buckets, target
status buckets and sigma buckets. No fusion is enabled here.

## S01H.5 — conservative fusion

Only after S01H.4 demonstrates useful behaviour may ToF affect final geometry.
The 8x8 ToF sensor is a metric anchor/validator, not a replacement for RGB dense.

## Metric confidence policy

```text
valid ToF <= 4 m:
    eligible as direct metric reference

geometry outside direct ToF support:
    RGB/COLMAP geometry may continue
    metric dimensions are APPROXIMATE_ONLY
    do not label them as directly measured by ToF
```

The scene is not truncated at 4 m. Only the confidence/measurement claim changes.
