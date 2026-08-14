# LM03.4B2 — persisted planar samples and ToF/CAMERA_A solver

## Status

```text
REPOSITORY BASELINE: 03a7a66b14a87b8ce4755ea3fc51040710261eaa

LM03.3.2: CLOSED
LM03.4A:  CLOSED
LM03.4B1: CLOSED
LM03.4B2: IMPLEMENTED; real-device acceptance pending
LM03.4C:  PLANNED
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

The solver estimates ten parameters:

```text
fx_zones fy_zones cx_zones cy_zones
Rodrigues rx ry rz
tx_mm ty_mm tz_mm
```

It uses sigma-aware bounded robust residuals and multiple roll seeds. Nominal 45°
ToF FoV is only the initial seed. No final RMS/p95 acceptance threshold is imposed
before the first real-device distribution is measured.

Diagnostic log tag:

```text
TofCalibration
```

Important: ToF ranges were not persisted by the previous LM03.4B1 build, so the
first B2 acceptance requires a new calibration run after this patch.

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
