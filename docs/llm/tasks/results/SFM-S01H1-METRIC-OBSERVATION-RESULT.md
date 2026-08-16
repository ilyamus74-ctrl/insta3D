# SFM-S01H.1 — metric observation builder — closure result

Status: `PASS / CLOSED`

Date: `2026-08-16`

## Scope

S01H.1 converts temporally associated raw VL53L8CX measurements into filtered
metric observations in CAMERA_A coordinates. It is measurement-only and may not
change COLMAP sparse, dense, camera poses, point clouds or fusion.

## Accepted capture

```text
capture UUID:                  5ae1fa5a-224a-4b85-927d-d32a40dc143f
pipeline_run_id:               92
video_scan_id:                 91
EXTRACT remote job:            185780520
```

The same physical capture was processed again after the earlier pipeline-91
temporary compute workspace had been cleaned. The repeated run reproduced the
previous accepted S01H.1 measurements.

## Accepted S01G -> S01H.1 input

```text
selected associations:         143
selected with accepted ToF:    128
selected with raw ToF:         128
raw ToF frames:                220
frames with observations:      128
```

## Accepted metric observations

```text
metric observations:           4896
JSONL lines:                   4897
distance p50:                  829.0 mm
distance p95:                  1704.25 mm
camera Z p50:                  852.5759151003749 mm
camera Z p95:                  1723.0829986865342 mm
sigma p50:                     6.0 mm
sigma p95:                     21.0 mm
```

Rejected zones:

```text
no_target:                     884
invalid_status:                2412
distance_out_of_range:         0
sigma_missing:                 0
sigma_too_high:                0
profile_dimension_mismatch:    0
camera_transform_invalid:      0
```

Acceptance gates:

```text
status:                                MEASURED
measurement_gate_pass:                 true
ready_for_sparse_scale_measurement:    true
geometry_mutation_enabled:             false
fusion_enabled:                        false
```

## Reproducibility

Pipeline 92 reproduced the same core S01H.1 values previously observed from
pipeline 91. Therefore S01H.1 is closed on repeatable real-capture evidence.

## Persisted evidence

Cleanup-independent copy:

```text
/home/makler/web/remote_station/output/pipeline_92/metric_evidence/h1/
```

Preserved files:

```text
camera_info.json
camera_metadata.json
result.json
selected_sensor_association_report.json
selected_sensor_associations.jsonl
tof_calibration.json
tof_metric_observation_report.json
tof_metric_observations.jsonl
```

Future S01H diagnostic stages must preserve accepted evidence outside temporary
compute `job_*` workspaces before normal cleanup.

## Closure decision

S01H.1 is `PASS / CLOSED`.

It proves that the current capture/association/calibration chain can provide a
repeatable filtered metric ToF observation set. It does not prove that COLMAP
geometry is metrically correct and does not authorize geometry mutation.

Next gate:

```text
S01H.2.x diagnostic chain
  -> distinguish COLMAP depth deformation from ToF/RGB calibration residuals
  -> keep S01H.3 geometry mutation CLOSED until reviewed
```
