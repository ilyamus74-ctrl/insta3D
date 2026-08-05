# APP Dual-phone LM02.7B.5.4.5

## Laptop mode role decoupling and calibration handshake health

Base commit: `03f1b9d04c1f470db8114d799b5bdcf51349f304`.

## 1. Purpose

This stage removes the laptop transport dependency on the phone-to-phone
`DualPhoneRole.SLAVE` value and makes calibration acceptance observable on both
Android and the CPU host.

## 2. Separation of concepts

`ApplicationCaptureMode.LAPTOP_STEREO_CLIENT` is the only persisted selector for
laptop capture.

`DualPhoneRole.MASTER` and `DualPhoneRole.SLAVE` are reserved for the direct
phone-to-phone control channel.

Laptop capture persists the neutral compatibility role `STANDALONE`. The
reduced-frame packet still carries a legacy role field until LM02.7B.5.4.6; that
field is derived from `LaptopCameraSlot` and is not read from settings.

## 3. Calibration authority

The authority rules remain strict:

- `CAMERA_A` is the MASTER phone that created the calibration;
- `CAMERA_A` sends the full successful calibration JSON automatically;
- `CAMERA_B` sends only the same `calibration_profile_id`;
- `CAMERA_B` must never send the full calibration JSON;
- both phones must report the same profile ID;
- a profile mismatch rejects the second handshake before frame streaming.

## 4. Android preconditions

Both laptop clients require:

- application mode `LAPTOP_STEREO_CLIENT`;
- a non-empty active dual-phone calibration profile ID;
- a configured laptop address and camera slot.

`CAMERA_A` additionally requires:

- the profile exists in `DualPhoneCalibrationProfileStore`;
- profile status is successful;
- profile owner equals the local device ID;
- rig ID and mount revision match current settings.

## 5. Host validation

The host validates the hello before accepting frames.

For `CAMERA_A` it checks authority, full profile object, profile ID, MASTER device
ownership, rig ID, mount revision and success status.

For `CAMERA_B` it rejects authority and rejects a full profile object.

The host tracks reported IDs for both slots and rejects mismatches.

## 6. Hello acknowledgement

Every accepted or rejected hello contains explicit calibration health fields:

- `calibration_accepted`;
- `reported_calibration_profile_id`;
- `host_calibration_profile_id`;
- `host_calibration_ready`;
- `calibration_revision`;
- `calibration_reason`.

`CAMERA_A` may start streaming only after the host confirms that its profile is
active. `CAMERA_B` may start connecting before `CAMERA_A`; the host responds with
`WAITING_FOR_CAMERA_A`, and the Android reconnect loop retries until A has activated
the matching profile. No B frames are streamed before host calibration is ready.

## 7. UI health

The laptop card displays:

- local profile ID;
- host profile ID;
- acceptance state;
- host readiness;
- calibration revision;
- reason returned by the host.

A blank thermal map must therefore be distinguishable from a missing or rejected
calibration handshake.

## 8. Compatibility

The TCP protocol schema remains version 1. New acknowledgement fields are
additive.

Phone-to-phone MASTER/SLAVE operation is unchanged.

The host remains the sole location for laptop stereo-depth computation.

## 9. Acceptance criteria

1. Laptop runtime contains no requirement that persisted role equals SLAVE.
2. Laptop mode persists the neutral STANDALONE compatibility role.
3. CAMERA_A cannot stream without host activation of its full profile.
4. CAMERA_B cannot send a full profile.
5. Profile mismatch between slots is rejected.
6. Android displays host calibration health.
7. Existing 5.4.3 and 5.4.4 contract tests continue to pass.
8. Android Kotlin and C++ host builds succeed.

## 10. Next stage

LM02.7B.5.4.6 removes the remaining legacy frame-role bridge, consolidates mode
migration, and adds end-to-end reconnect and mode-switch tests.
