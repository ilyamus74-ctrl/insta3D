# LM03.4B2.2 — CAMERA_A + ToF standalone recalibration

Baseline:

```text
47a20532ccb63902bf83418e693d116af3de340d
```

## Contract

ToF extrinsics are independent from CAMERA_B once a valid CAMERA_A/stereo
calibration profile already exists.

The MASTER settings screen provides:

```text
CAMERA A + TOF · ИЗ СОХРАНЁННОГО STEREO
```

This mode:

- loads `activeCalibrationProfileId`;
- validates the stored profile and CAMERA_A identity;
- reuses MASTER K/D, SLAVE K/D and stereo R/t without recomputing them;
- does not require SLAVE to be connected;
- starts directly at `MASTER_TOF_EXTRINSICS`;
- requires ChArUco and a live ToF stream;
- creates a new ToF calibration run and solve result;
- binds the new ToF extrinsics profile to the existing camera calibration
  profile id.

## Mechanical alignment gate

When `MASTER_TOF_EXTRINSICS` opens, observation/capture is blocked until the
operator presses:

```text
TOF ЗАФИКСИРОВАН · НАЧАТЬ 18 ПОЗ
```

Before arming, the screen displays the raw live ToF matrix and a CAMERA_A centre
guide. The highlighted ToF centre is the central 4x4 region of the sensor's own
8x8 grid.

This is a mechanical aid only. Before R/t is solved, raw ToF zones are not
claimed to be pixel-registered to CAMERA_A.

Once armed, the ToF module must remain rigid relative to CAMERA_A for the whole
profile lifetime. Moving only ToF invalidates only ToF extrinsics; it does not
require re-solving stereo if the two phone cameras and their optical state have
not changed.

## Current profile identity checks

ToF-only start requires:

- active stored stereo profile;
- successful stereo quality gate;
- same MASTER device id;
- same Rig ID / mount revision;
- same selected CAMERA_A id;
- acceptable stored CAMERA_A intrinsics;
- ChArUco board configuration;
- fresh STREAMING ToF frames.

Focus mode is restored by the persisted camera focus runtime introduced in the
preceding fixed-focus work.
