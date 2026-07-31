# APP dual-phone CAL00 — realtime calibration UX and reusable profile contract

## Status

```text
CONTRACT ACCEPTED FOR IMPLEMENTATION
BASELINE REPOSITORY: 53f7d6a94880aa4e1a5c9cf35739d04c9148e5ca
```

This milestone defines the calibration workflow before CAL01–CAL03 implement the
detector, solver and acceptance gates.

## Operator baseline input

Master settings store:

```text
rig_id
rig_mount_revision
operator_lens_baseline_mm
active_calibration_profile_id
```

`operator_lens_baseline_mm` is measured from optical centre to optical centre, not
from phone edge to phone edge. The UI uses millimetres as the persisted canonical
unit and may display centimetres as a derived value.

The entered distance is a prior and sanity check. It must not silently override the
baseline measured by accepted stereo calibration. CAL02 compares operator and
measured baseline and requires explicit review when they differ beyond the accepted
tolerance.

Changing the physical spacing or any mounting geometry requires a new
`rig_mount_revision` and invalidates the old stereo extrinsics.

## Fullscreen realtime calibration mode

Calibration is not a blind capture button. Both phones enter a dedicated fullscreen
camera mode with the normal settings card and navigation hidden.

Both screens show:

```text
live camera preview filling the usable display
ChArUco corner and ID overlay
detection confidence
sharpness / motion warning
exposure warning
current pose target
accepted-pose count and coverage
REC/capture feedback
connection and peer status
```

Master is the workflow coordinator. CAL01/CAL02 frame collection is presented as
one explicit three-stage operator sequence:

```text
1. MASTER intrinsics — only the Master camera accepts 24 poses;
2. SLAVE intrinsics — only the Slave camera accepts 24 poses;
3. stereo extrinsics — both cameras accept 12 synchronized dual-visible poses.
```

The inactive phone keeps the fullscreen live view and connection status, but its
quality state must not block a single-camera intrinsics stage. Both quality states
become mandatory only during the stereo stage.

Example realtime instructions:

```text
Move board closer
Move board farther
Move to upper-left corner
Tilt board left
Tilt board right
Rotate board clockwise
Hold still
Too much motion
Board is clipped on Slave
Good pose — capturing 3…2…1
Pose accepted on both phones
```

During the MASTER and SLAVE intrinsics stages the application accepts a sample when
the active camera satisfies the requested pose and quality gates. During the stereo
stage it accepts a pair only when both roles satisfy the same-pose gate during a
bounded time window. Manual capture remains available for diagnostics but is not the
normal operator path.

Every accepted sample produces visible `FRAME ACCEPTED` feedback. Completion of each
stage and final completion of MASTER, SLAVE and stereo collection must also be shown
explicitly on both phones.

After the final stereo pair, frame collection is not reported as a successful
calibration until numerical validation finishes:

```text
MASTER intrinsics K/D + RMS
SLAVE intrinsics K/D + RMS
stereo R/T + stereo RMS
calculated baseline |T|
comparison against operator_lens_baseline_mm
atomic calibration profile persistence
active_calibration_profile_id update
```

The Slave sends its final intrinsics model to Master. Accepted stereo observations
carry ChArUco IDs and raw-frame normalized coordinates; Master computes R/T by common
IDs with `CALIB_FIX_INTRINSIC`, publishes the final profile to Slave, and both phones
persist the same profile. A failed RMS/model check must remain visible and must not
activate the profile.

## Required pose coverage

CAL01 collects intrinsics independently for each physical camera while preserving a
shared operator workflow. The target set includes:

```text
centre near / medium / far
all four image corners
top / bottom / left / right edges
positive and negative yaw
positive and negative pitch
clockwise and counter-clockwise roll
combined oblique poses
```

Repeated nearly identical frontal frames do not count as coverage. The realtime
guide selects the next missing pose bin and rejects blurred, clipped or redundant
samples.

CAL02 uses static dual-visible poses. The board must be held still long enough to
remove residual cross-phone timing error from the extrinsics estimate.

After a completed solve, Master may start a stereo-only retry. The retry creates a
new calibration run, preserves the already validated MASTER and SLAVE intrinsics,
clears only the stereo-pair counter and in-memory stereo estimator, then captures
18 new dual-visible pairs. The previous accepted profile remains active until the
replacement stereo solve is accepted.

The board definition is explicit rig configuration. Master stores and transmits the
selected board type and dimensions. Supported modes are ChArUco and legacy chessboard.
The calibration UI must show the active board definition before capture; detector,
object-point generation, manifests and solver use the same values.

CAL02 provides a realtime Stereo Coach. Candidate pairs require common board IDs,
stability on both phones and a frame-time delta derived from CameraX timestamps and
the clock model. The UI shows common corners, frame delta, coverage, live RMS,
live baseline and epipolar residual. Final solve performs robust per-pair outlier
rejection while keeping at least ten pairs.

## Profile identity and reuse

Profiles are persisted on-device and later mirrored to the server. They are matched
to the physical cameras and rig configuration, not merely to the current Master or
Slave labels.

The identity hash contains:

```text
rig topology = DUAL_PHONE_STEREO
rig_id
rig_mount_revision
operator lens baseline
both device IDs
both physical camera IDs
both video mode IDs
both image dimensions
both zoom ratios
both stabilization modes
both orientation contracts
```

Camera identities are canonicalized as an unordered pair. Therefore the same two
phones may exchange Master/Slave network roles and still locate the accepted
profile.

The calibration result itself preserves its original ordered camera convention:

```text
cam0 device ID
cam1 device ID
R_01
T_01
```

When runtime roles are reversed, the consumer must derive:

```text
R_10 = transpose(R_01)
T_10 = -transpose(R_01) * T_01
```

It must not reuse `R_01/T_01` unchanged under reversed roles.

No repeat calibration is required when the complete identity hash matches and the
profile status is `ACCEPTED`. A new calibration is required after changing any
identity field, including phone, physical lens, resolution/crop mode, zoom,
stabilization, orientation contract, spacing or mount revision.

## Profile lifecycle

```text
DRAFT
→ COLLECTING_INTRINSICS
→ COLLECTING_STEREO
→ SOLVING
→ VALIDATING
→ ACCEPTED
```

Failure or invalidation states:

```text
REJECTED
STALE_IDENTITY
MISSING_CAMERA
BASELINE_MISMATCH
INSUFFICIENT_COVERAGE
HIGH_REPROJECTION_ERROR
HIGH_EPIPOLAR_ERROR
UNSTABLE_EXTRINSICS
```

An accepted profile stores the solver result, report, rectification maps or their
generation parameters, identity hash, measured baseline and acceptance timestamp.

## CAL01 output

```text
cam_a_intrinsics.json
cam_b_intrinsics.json
intrinsics_capture_report.json
```

Each intrinsics result contains `K`, distortion coefficients, image dimensions,
camera identity, RMS error, per-view errors and coverage statistics.

## CAL02 output

```text
dual_phone_stereo_calibration.json
stereo_capture_report.json
```

It contains ordered `R`, `T`, `E`, `F`, measured baseline, overlap and stereo
residuals. Operator baseline and measured baseline are both preserved.

## CAL03 output

```text
rectification_profile.json
calibration_acceptance_report.json
```

Acceptance evaluates mono reprojection error, stereo reprojection error, vertical
epipolar residual after rectification, common field of view and stability across
repeated calibration subsets.

Hard thresholds are set only after the first real calibration dataset is measured.
They must be written explicitly into the accepted report rather than remaining
hidden constants.

## Actual FPS rule

Calibration identity stores operating mode, dimensions, zoom and stabilization.
Every capture and server job additionally stores actual Camera2 and encoder cadence.
Actual FPS is never inferred from the requested mode name.

Different actual FPS values do not automatically invalidate intrinsics, but they
remain part of capture diagnostics and timing/rolling-shutter validation. A mode
that changes crop, stabilization or physical camera selection requires a different
profile.

## Next implementation slices

```text
CAL01A fullscreen synchronized calibration screen on both phones
CAL01B realtime ChArUco detection, quality gates and pose guidance
CAL01C per-camera intrinsics solve and profile persistence
CAL02A static synchronized stereo sample collection
CAL02B stereo R/T solve, baseline comparison and role-reversal support
CAL03A rectification preview and epipolar acceptance UI
CAL03B accepted profile activation and server registration
```
