# LM03.4C — CAMERA_A / ToF hold-out validation

## Status

```text
REPOSITORY BASELINE: 8562264865eb4520b6298476c0e1ffb18b86ffac

LM03.3.2: CLOSED
LM03.4A:  CLOSED
LM03.4B:  CLOSED
LM03.4C:  CLOSED — independent real-device hold-out passed
```

## Purpose

Validate the already-solved CAMERA_A/ToF profile on new captures that were not
used by LM03.4B.

LM03.4C is a validation path, not another calibration solver.

```text
active stereo profile
active ToF profile
        |
        | frozen fx/fy/cx/cy/R/t
        v
12 new CAMERA_A + ToF ChArUco poses
        |
        v
point-to-board-plane residuals
        |
        +--> RMS / p95
        +--> zone coverage
        +--> RGB reprojection telemetry
        |
        v
HOLDOUT PASS / FAIL
```

SLAVE is not required. The validation is performed on MASTER with CAMERA_A and
the ToF sensor attached to the same rigid mount used for B2.

## Frozen profile contract

The active ToF profile must match:

```text
rig_id
rig_mount_revision
master_device_id
master_camera_id
camera_calibration_profile_id
tof_slot
tof_width
tof_height
```

LM03.4C never changes or reactivates the profile under test.

The geometry remains the accepted B2/V6 geometry:

```text
column = zone_index % width
row    = zone_index / width

xn = (column - cx_zones) / fx_zones
yn = (row    - cy_zones) / fy_zones

Z = distance_mm
X = Z * xn
Y = Z * yn

P_camera = R * P_tof + t
```

`distance_mm` is R2P-corrected axial depth. Native sensor zone order is used.

## Capture contract

C1 collects 12 new automatic hold-out poses.

Use the same ChArUco board, CAMERA_A, focus mode, zoom and rigid mount as the
accepted profile. Do not move the phones or ToF relative to each other.

The operator should vary:

```text
distance: near / medium / far
position: left / right / upper / lower / diagonal
angle: yaw / pitch / roll
```

Do not intentionally reproduce the exact 18 training poses.

The existing CAMERA_A quality/novelty gate and the LM03.3.2 nearest-ToF timestamp
pairing are reused.

Calibration-only near-field returns below 100 mm are removed before validation.

## C1 metrics

For every usable ToF zone:

1. deproject the frozen ToF ray;
2. transform it with the frozen `R/t`;
3. measure signed distance to the independently estimated CAMERA_A ChArUco plane;
4. compute the expected intersection of the same transformed ToF ray with that
   plane;
5. project measured and expected CAMERA_A points to RGB pixels.

Because the finite board does not cover every ToF zone at every pose, C1 uses
the same robust reporting rule as B2:

```text
per pose:
  sort by absolute point-to-plane residual
  retain lowest 70%
  retain at least 8 zones when available
```

Reported telemetry:

```text
sample_count
total_observation_count
retained_observation_count
retained_zone_coverage_count
retained_zone_coverage_percent
plane_rms_mm
plane_p95_mm
all_plane_rms_mm
reprojection_observation_count
reprojection_rms_px
reprojection_p95_px
```

## C1 acceptance gate

Initial real-device gate:

```text
valid hold-out samples >= 8
retained observations >= 128
retained ToF-zone coverage >= 60%
plane RMS <= 20 mm
plane p95 <= 40 mm
```

RGB reprojection RMS/p95 are persisted as telemetry in C1 but do not yet fail the
profile. A pixel threshold will be frozen only after the first independent
real-device hold-out distribution is measured.

## Persistence

```text
files/tof_camera_calibration/runs/<validation-run>/
  validation_samples/
    pose_00_*.json
    ...
  validation_result.json
```

The B2 training run and active profile are not modified.

## Logs

Android tag:

```text
TofCalibration
```

Expected records:

```text
TOF_VAL_SAMPLE
TOF_VAL_START
TOF_VAL_RESULT
```

## Operator acceptance procedure

1. Keep the accepted rigid CAMERA_A/ToF assembly unchanged.
2. MASTER only; SLAVE may be disconnected.
3. Start `ПРОВЕРИТЬ TOF ПРОФИЛЬ · LM03.4C`.
4. Present 12 new ChArUco poses.
5. Wait for `HOLDOUT PASS` or `HOLDOUT FAIL`.
6. Export `validation_result.json`, logcat and optionally the full run archive.
7. Only after C1 passes may LM03.5 treat the ToF profile as independently
   validated for registered RGB/depth fusion.

## Real-device C1 acceptance — CLOSED

Accepted hold-out run:

```text
validation_run_id:
  cal-943f7076-28bd-4f7b-be12-29578d2d35dc

profile_solver:
  LM03.4B2_5_NEAR_GHOST_FILTER_R2P_LM_V6

sample_count:                     12
total_observation_count:          658
retained_observation_count:       466
retained_zone_coverage_count:     62 / 64
retained_zone_coverage_percent:   96.875%

plane_rms_mm:                     11.54886330237104
plane_p95_mm:                     19.259423254422813
all_plane_rms_mm:                 234.5132919988271

reprojection_observation_count:   466
reprojection_rms_px:              2.10986858858945
reprojection_p95_px:              3.11261812973377

status:
  HOLDOUT_PASS_C1; RGB reprojection telemetry only
```

The hold-out set was captured independently from the 18 B2 training poses while
keeping the accepted rigid CAMERA_A/ToF assembly unchanged.

Acceptance checks:

```text
samples:     12 >= 8          PASS
retained:   466 >= 128        PASS
coverage:   96.875% >= 60%    PASS
RMS:        11.549 mm <= 20   PASS
p95:        19.259 mm <= 40   PASS
```

The hold-out RMS is higher than the B2 training RMS (8.466 mm -> 11.549 mm), as
expected for an independent set, while remaining comfortably inside the frozen
acceptance gate. This is evidence that the accepted profile generalizes rather
than merely fitting its training poses.

`all_plane_rms_mm` remains intentionally non-gating because ToF zones outside
the finite ChArUco board see real background geometry.

C1 also establishes an initial real-device RGB reprojection distribution:

```text
RMS: 2.110 px
p95: 3.113 px
```

These values remain telemetry for LM03.4C. LM03.5 may use them as an engineering
reference but must not retroactively change the closed LM03.4C acceptance gate.

LM03.4C is CLOSED. The active profile is independently validated and may now be
consumed by LM03.5 registered RGB anchor projection.
