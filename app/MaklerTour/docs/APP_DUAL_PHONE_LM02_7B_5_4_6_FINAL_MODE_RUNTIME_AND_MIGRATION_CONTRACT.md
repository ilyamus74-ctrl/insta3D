# APP Dual-phone LM02.7B.5.4.6

## Final mode runtime and migration contract

Base commit: `5d0b9a43fc910f1e304242a2ffaba8c7d07d0c6c`.

## 1. Final operating model

The application has exactly five mutually exclusive top-level modes:

1. `STANDALONE_COLMAP`;
2. `DUAL_PHONE_MASTER`;
3. `DUAL_PHONE_SLAVE`;
4. `LAPTOP_STEREO_CLIENT`;
5. `PHONE_USB_STEREO`.

`ApplicationCaptureMode` is the persisted operator choice. `DualPhoneRole` is
derived only for direct phone-to-phone MASTER/SLAVE control. Laptop slots are
`CAMERA_A` and `CAMERA_B` and never become MASTER/SLAVE roles.

## 2. Atomic mode transition

The selector delegates the requested mode to `SettingsScreen`. Before persisting
it, the application:

1. exits the managed phone-to-phone work runtime;
2. stops laptop uplink and its CameraX producer;
3. stops the phone-to-phone control manager;
4. persists the new mode;
5. reloads the persisted settings;
6. updates rig topology and Compose state.

No runtime is allowed to observe a half-written mode transition. Selecting the
already active mode is a no-op.

## 3. Settings migration

Settings schema 7 performs a one-way migration:

- existing `application_capture_mode` is authoritative when valid;
- installations without it are migrated once from the legacy role key;
- subsequent saves never derive application mode backwards from role;
- role remains a derived phone-to-phone mirror for existing runtime consumers;
- laptop, standalone and phone+USB modes derive the neutral value `STANDALONE`.

## 4. Laptop frame producer

The shared reduced-frame producer has a dedicated `startLaptop` entry point.
Laptop `CAMERA_A/CAMERA_B` is carried by the hello handshake and stream identity.
The internal reduced-frame role is neutral and is not serialized to the CPU host.
There is no camera-slot-to-MASTER/SLAVE conversion.

## 5. Calibration authority

The LM02.7B.5.4.5 authority contract remains unchanged:

- the physical MASTER phone is laptop `CAMERA_A`;
- only `CAMERA_A` sends the full successful calibration JSON;
- `CAMERA_B` sends the matching profile ID only;
- the host rejects authority, identity, rig or profile mismatches;
- the Android UI displays host calibration acceptance and revision.

## 6. Validated end-to-end evidence

On 2026-08-05 the complete path was validated manually:

- Android application compiled and installed;
- C++ host configured, built and linked successfully;
- both phones completed calibration;
- the host accepted the calibration profile;
- synchronized pairs were processed;
- the dashboard reported `TEMPORAL STRICT · READY`;
- live metric depth and the thermal map became available.

## 7. Acceptance criteria

1. Five modes remain declared and mutually exclusive.
2. No `compatibilityRole` property remains.
3. Settings schema is 7 and migration is one-way.
4. Laptop runtime calls `startLaptop` and contains no slot-to-role bridge.
5. Mode transition stops all incompatible runtimes before saving.
6. Previous 5.4.3, 5.4.4 and 5.4.5 regression tests pass under final semantics.
7. Android and C++ host builds pass.
8. Real CAMERA_A/CAMERA_B depth preview remains operational.

This closes the LM02.7B.5.4 operating-mode refactor. Further changes are defect
fixes or feature work, not continuation of this migration.
