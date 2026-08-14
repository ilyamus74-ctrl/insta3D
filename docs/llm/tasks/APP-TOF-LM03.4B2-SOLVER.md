# LM03.4B2 — persisted planar samples and ToF/CAMERA_A solver

## Status

```text
REPOSITORY BASELINE: 03a7a66b14a87b8ce4755ea3fc51040710261eaa

LM03.3.2: CLOSED
LM03.4A:  CLOSED
LM03.4B1: CLOSED
LM03.4B2: CLOSED — real-device V6 accepted
LM03.4C:  NEXT — independent hold-out validation
```

## Runtime path

```text
accepted MASTER_TOF_EXTRINSICS observation
  + accepted nearest TofFrameV1
  -> ChArUco plane in CAMERA_A coordinates
  -> persisted TofCameraPlanarCalibrationSample
  -> 18 accepted poses
  -> robust nonlinear solver
  -> ToF zone intrinsics + R/t
  -> solve_result.json
  -> COMPLETE
  -> final stereo profile solve
  -> active TofCameraExtrinsicsProfile bound to the same profile id
```

The planar sample is persisted inside the MASTER calibration gate before the pose
counter advances. A ToF pose is therefore not accepted if its plane/sample cannot
be constructed or written.

The final B2 solver keeps the ToF principal point fixed at the geometric
8x8 zone-grid centre and estimates eight independent parameters:

```text
fx_zones fy_zones
Rodrigues rx ry rz
tx_mm ty_mm tz_mm

cx_zones = 3.5 fixed
cy_zones = 3.5 fixed
```

The final geometry contract is:

```text
column = zone_index % width
row    = zone_index / width

xn = (column - cx_zones) / fx_zones
yn = (row    - cy_zones) / fy_zones

Z = distance_mm
X = Z * xn
Y = Z * yn

P_camera_mm = R_tof_to_camera * [X,Y,Z] + t_tof_to_camera_mm
```

`distance_mm` is treated as the VL53L8CX default R2P-corrected axial depth.
The native ULD zone-index order is preserved; no one-axis mirror/reflection is
inserted between the sensor and the solved rigid rotation.

Calibration-only observations below 100 mm are rejected as near-field/ghost
returns. Normal runtime ToF measurements are not changed by that filter.

The nonlinear objective remains sigma-aware and robust. Reporting retains the
lowest 70% point-to-plane residuals per pose, with at least eight retained zones
per pose, because zones outside the physical board legitimately see background.

Diagnostic log tag:

```text
TofCalibration
```

## Real-device B2 acceptance — CLOSED

Authoritative accepted run:

```text
calibration_run_id:
  cal-2aca1840-6303-4d4c-b8ce-a855a5cf5a0d

solver:
  LM03.4B2_5_NEAR_GHOST_FILTER_R2P_LM_V6

samples:                18
observations:           1039
retained observations: 735

fx_zones: 10.618251917983343
fy_zones: 11.034381474777096
cx_zones: 3.5
cy_zones: 3.5

t_tof_to_camera_mm:
  [82.17996397749516, 4.134596092959632, 22.518672022696737]

plane RMS: 8.466426054195251 mm
plane p95: 15.265841454616861 mm
iterations: 23
successful: true
```

The accepted rotation matrix was finite, orthonormal to numerical precision and
physically consistent with the rigid CAMERA_A/ToF mount.

`all_plane_rms_mm = 215.60595341096763` is not the B2 acceptance metric. At
edge/oblique board poses, valid ToF zones outside the finite ChArUco board see
background several metres behind the board. Those ranges are real measurements,
but they are not samples of the ChArUco plane. The robust retained metrics above
are therefore the training-quality metrics used by B2.

B2 is closed. The solver must not be refit or loosened during LM03.4C.
LM03.4C validates this frozen profile on a separate set of captures.

## Optional-ToF calibration contract

Repository baseline for this fix:

```text
9d80823ffc6cc41a1a2a1cb0b67425b08f4fedc1
```

ToF is an optional extension of the dual-phone calibration workflow.

At completion of `STEREO_EXTRINSICS`, MASTER enters
`MASTER_TOF_EXTRINSICS` only when `TofUsbRuntime` is actively streaming,
has recent frames, and the last frame is not stale.

If no active ToF is present, calibration transitions directly to `COMPLETE`
and the normal stereo profile solve is allowed to finish. The UI reports
`TOF —` / `TOF НЕ ИСПОЛЬЗОВАЛСЯ` instead of claiming a ToF calibration.

The `MASTER_TOF_EXTRINSICS` observation must include the same accepted
ChArUco corner correspondences used by stereo. Without those correspondences
the CAMERA_A board plane cannot be solved and no planar ToF sample can be
accepted.

## Stereo preflight before ToF

Repository baseline:

```text
c3d3ae50862bacad75d91ecd7b7253201e13f81d
```

ToF calibration must not consume operator time when stereo geometry has already
failed.

After the 18th `STEREO_EXTRINSICS` pair, the stage is held at 18/18 while MASTER
runs the existing full stereo solver. `DualPhoneStereoEstimate.acceptable` is the
quality gate. Only an accepted stereo result may advance to
`MASTER_TOF_EXTRINSICS`.

If stereo fails, calibration completes with the failed stereo diagnostic and ToF
is not started. The existing stereo retry controls remain available.

If stereo passes and ToF is not actively streaming, calibration completes as a
valid stereo-only calibration.

## Persistent optical focus state

The fullscreen calibration UI exposes two local focus controls:

```text
АВТОФОКУС
∞ FIXED
```

`∞ FIXED` uses Camera2 `CONTROL_AF_MODE_OFF` plus
`LENS_FOCUS_DISTANCE=0.0f`.

Focus mode is persisted per physical camera id as one of:

```text
AUTO
INFINITY_FIXED
```

The saved mode is restored after every relevant CameraX bind:

```text
calibration preview / analysis
PhoneCameraVideoRecorder preview / video recording / phone scan
DualPhoneReducedFrameProducer live stereo / laptop uplink
AutoPhotoCaptureManager
```

This is a metric contract: K/D, stereo R/t and ToF extrinsics must be used with
the same optical focus state under which they were calibrated.

The focus preference defaults to `AUTO`. `INFINITY_FIXED` is stored only after
the physical camera successfully reports `FOCUS_INFINITY_LOCKED`.

If focus mode is changed after calibration poses have already been accepted, the
run contains mixed optical states and must be restarted. On the first migration
to `∞ FIXED`, set it on both phones and then restart the full calibration so
MASTER K/D, SLAVE K/D, stereo R/t and ToF R/t are all measured under the saved
fixed-focus state.
