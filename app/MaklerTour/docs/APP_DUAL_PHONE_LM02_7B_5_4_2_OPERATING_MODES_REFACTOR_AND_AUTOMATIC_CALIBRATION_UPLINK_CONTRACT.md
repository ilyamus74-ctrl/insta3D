# APP contract: operating modes refactor and automatic calibration uplink

Contract ID: `APP_DUAL_PHONE_LM02_7B_5_4_2`

Base commit: `29b755854e9bac73a339f7bc736b314e75179fdf`

Status: binding implementation roadmap.

## 1. Problem statement

The current Settings screen mixes three different concepts:

- the application operating mode;
- the MASTER/SLAVE role inside a phone-to-phone pair;
- the CAMERA_A/CAMERA_B slot used by the laptop stereo host.

The refactor must separate these concepts and show only the settings required by
the selected operating mode.

## 2. Five mutually exclusive application modes

The target application-level selector is:

- `STANDALONE_COLMAP`;
- `DUAL_PHONE_MASTER`;
- `DUAL_PHONE_SLAVE`;
- `LAPTOP_STEREO_CLIENT`;
- `PHONE_USB_STEREO`.

Only one mode is active at a time. Selecting a mode hides all settings that are
not part of that mode.

### 2.1 STANDALONE_COLMAP

One phone records video and metadata for later COLMAP/DENSE processing.
No phone-to-phone control, live stereo depth, laptop uplink or USB stereo
settings are visible.

### 2.2 DUAL_PHONE_MASTER

The local phone is MASTER and controls the second phone. It receives synchronized
SLAVE frames and computes live stereo depth and the metric heatmap locally.

All mono and dual-phone calibration is initiated here. MASTER calculates:

- CAMERA_A intrinsics;
- CAMERA_B intrinsics;
- stereo rotation R;
- stereo translation T and baseline;
- device, camera, rig and mount identities.

The successful profile is stored on the MASTER phone and is the source of truth.

### 2.3 DUAL_PHONE_SLAVE

The local phone is controlled by the MASTER phone. It exposes only pairing,
camera, synchronization, command and transfer status. It cannot create,
activate or authoritatively publish a calibration profile.

### 2.4 LAPTOP_STEREO_CLIENT

Two phones connect independently to a laptop or workstation as `CAMERA_A` and
`CAMERA_B`. Stereo depth, heatmap and accumulated geometry are computed on the
computer.

`LaptopCameraSlot` is independent from `DualPhoneRole`.

- `CAMERA_A` is the phone that previously acted as MASTER and owns the accepted
  calibration profile.
- `CAMERA_A` is the only calibration authority for the laptop host.
- `CAMERA_A` sends the full active profile automatically during every handshake.
- `CAMERA_B` sends frames and IMU and may send the profile ID, but never the full
  authoritative profile.
- the laptop host continues to accept the profile only from `CAMERA_A`.

A separate manual "send calibration" action must not be required.

### 2.5 PHONE_USB_STEREO

The phone camera and USB camera form a stereo pair. The phone performs stereo
depth and heatmap computation. Calibration is initiated and stored on the phone
acting as the local compute authority.

## 3. Calibration ownership invariants

- Calibration is created only by the compute authority.
- For dual-phone calibration the compute authority is the MASTER phone.
- The accepted profile is always persisted on the MASTER phone.
- The SLAVE phone is not allowed to generate a competing profile.
- Laptop `CAMERA_A` must load the current profile from persistent storage, not
  from a stale UI snapshot.
- A laptop connection from `CAMERA_A` without a valid active profile is rejected
  before frame streaming starts.
- Profile ID, master device ID, rig ID and mount revision must match.
- Reconnect performs a fresh settings/profile read.
- No stale profile may survive a new calibration or mount revision.

## 4. Target data model

The refactor introduces three separate dimensions:

```kotlin
enum class ApplicationCaptureMode {
    STANDALONE_COLMAP,
    DUAL_PHONE_MASTER,
    DUAL_PHONE_SLAVE,
    LAPTOP_STEREO_CLIENT,
    PHONE_USB_STEREO,
}

enum class DualPhoneRole {
    MASTER,
    SLAVE,
}

enum class LaptopCameraSlot {
    CAMERA_A,
    CAMERA_B,
}
```

`DualPhoneRole` is used only by phone-to-phone control. `LaptopCameraSlot` is
used only by laptop uplink. Neither enum substitutes for
`ApplicationCaptureMode`.

## 5. Settings visibility contract

Changing `ApplicationCaptureMode` rebuilds the visible settings section.

- Standalone shows camera, recording and offline export settings.
- Dual-phone MASTER shows rig, pairing, control, calibration and live depth.
- Dual-phone SLAVE shows only MASTER connection and controlled-device status.
- Laptop mode shows host, port, slot, stream and calibration delivery status.
- Phone + USB shows phone camera, USB camera, stereo calibration and live depth.

Hidden mode settings remain persisted but cannot start their runtime while
another mode is active.

## 6. Sequential implementation plan

### Patch LM02.7B.5.4.2

- publish this contract;
- reload current stereo settings inside laptop runtime;
- reload profile before the first handshake and every reconnect;
- automatically attach the full profile only for `CAMERA_A`;
- reject `CAMERA_A` without a valid MASTER-owned profile;
- remove dependency on the stale Compose settings snapshot.

### Patch LM02.7B.5.4.3

- introduce and persist `ApplicationCaptureMode`;
- migrate existing installations without deleting saved settings;
- create a single top-level five-mode selector.

### Patch LM02.7B.5.4.4

- render mode-specific Settings sections;
- hide unrelated controls;
- prevent hidden runtimes from starting.

### Patch LM02.7B.5.4.5

- decouple `LAPTOP_STEREO_CLIENT` from `DualPhoneRole.SLAVE`;
- enforce CAMERA_A/CAMERA_B slot validation;
- expose calibration handshake health and host acknowledgement.

### Patch LM02.7B.5.4.6

- remove duplicate/legacy controls;
- finalize calibration ownership and migration tests;
- update operator diagnostics and end-to-end acceptance tests.

Every patch is based on the commit produced by the previous patch.

## 7. LM02.7B.5.4.2 acceptance

The patch is accepted when:

- the Android runtime loads `DualPhoneStereoSettingsStore` itself;
- `CAMERA_A` cannot connect without an active successful profile;
- the profile belongs to the local MASTER device;
- rig ID and mount revision match;
- the full JSON profile is attached automatically by CAMERA_A;
- CAMERA_B never attaches the full JSON profile;
- every reconnect reloads settings and profile;
- the laptop host remains strict and reads calibration only from CAMERA_A;
- no manual calibration-transfer button is required.
