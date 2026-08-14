# LM03.4B1 — reusable pairing and planar calibration samples

## Status

```text
REPOSITORY BASELINE: ec2aa89053fd420c6a35881788f58070bb2cb3fd

LM03.3.2: CLOSED
LM03.4A:  CLOSED
LM03.4B:  IN PROGRESS
LM03.4B1: IMPLEMENTED; runtime integration pending
```

## Purpose

Prepare one authoritative data path for the LM03.4 planar extrinsics solver.

```text
CAMERA_A mapped event time
        +
TofUsbRuntime recent raw frames
        |
        v
TofCameraFramePairer
        |
        +--> accepted nearest TofFrameV1
        |
        v
ChArUco CAMERA_A frame
        |
        v
TofCameraCharucoPlaneEstimator
        |
        +--> board plane in CAMERA_A coordinates
        |
        v
TofCameraPlanarCalibrationSampleBuilder
        |
        +--> solver-ready valid ToF zone ranges
```

## Timing rule

LM03.4 reuses the exact LM03.3.2B pairing rule:

```text
threshold_us = 500,000 / tof_frequency_hz + 2,000
```

The spatial calibration path must not invent a second timestamp pairing rule.

## Board plane

The ChArUco board pose is solved with CAMERA_A's accepted K/D model.

The stored plane is:

```text
normal_camera . P_camera_mm + d_mm = 0
```

The board plane is independent of the future ToF extrinsics parameters.

## ToF observations

A planar sample retains only zones accepted by `TofFrameV1.isZoneValid()` and
stores:

```text
zone index
distance mm
range sigma mm
target status
number of detected targets
```

No sigma/outlier threshold is invented in this slice. LM03.4B2 will measure the
real residual distribution first.

## Next integration patch

Connect the existing CAMERA_A calibration frame source to:

```text
camera event timestamp
-> TofCameraFramePairer
-> ChArUco board plane
-> TofCameraPlanarCalibrationSample
```

Then collect multiple board poses before enabling the nonlinear extrinsics
optimizer.
