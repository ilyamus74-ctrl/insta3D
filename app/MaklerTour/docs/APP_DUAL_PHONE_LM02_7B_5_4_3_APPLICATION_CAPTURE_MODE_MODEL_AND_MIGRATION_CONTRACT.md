# APP contract — LM02.7B.5.4.3 application capture mode model and migration

## Base

This contract extends LM02.7B.5.4.2 and is implemented against commit
`fccefa3b11118524de5e8096e504ac9113a4a5c0`.

## Purpose

Introduce one persisted top-level application operating mode before the existing
mixed settings page is split into mode-specific surfaces.

## Authoritative modes

The operator selects exactly one of these values:

1. `STANDALONE_COLMAP`
2. `DUAL_PHONE_MASTER`
3. `DUAL_PHONE_SLAVE`
4. `LAPTOP_STEREO_CLIENT`
5. `PHONE_USB_STEREO`

No sixth implicit mode may be inferred from a random combination of switches.

## Semantics

### STANDALONE_COLMAP

One phone records video for later COLMAP/DENSE processing. It does not start the
phone-to-phone control transport and does not calculate realtime stereo depth.

### DUAL_PHONE_MASTER

The local phone is MASTER. It controls the peer SLAVE, performs the complete
calibration and is the authoritative owner of the resulting profile. Realtime
stereo depth is calculated on the MASTER phone.

### DUAL_PHONE_SLAVE

The local phone is controlled by the phone MASTER. It does not create an
independent calibration profile and does not become calibration authority.

### LAPTOP_STEREO_CLIENT

Two phones connect independently to the laptop as `CAMERA_A` and `CAMERA_B`.
The laptop performs realtime stereo processing. `CAMERA_A` remains the only
calibration authority and automatically transfers the profile created by the
phone MASTER.

### PHONE_USB_STEREO

The phone camera and USB camera form a local stereo pair. The phone owns the
calibration and performs realtime stereo processing.

## Separation of concerns

`ApplicationCaptureMode` describes the whole application workflow.

`DualPhoneRole` describes only the phone-to-phone MASTER/SLAVE control relation.
It is not the permanent model for laptop slots.

`DualPhoneLaptopCameraSlot` describes only `CAMERA_A` or `CAMERA_B` in laptop
mode.

## Compatibility bridge

Until LM02.7B.5.4.5 removes the laptop dependency on `DualPhoneRole.SLAVE`, each
application mode exposes a temporary compatibility role:

| Application mode | Compatibility role |
|---|---|
| `STANDALONE_COLMAP` | `STANDALONE` |
| `DUAL_PHONE_MASTER` | `MASTER` |
| `DUAL_PHONE_SLAVE` | `SLAVE` |
| `LAPTOP_STEREO_CLIENT` | `SLAVE` |
| `PHONE_USB_STEREO` | `STANDALONE` |

This bridge is transitional and must not be interpreted as meaning that the
laptop is the phone-pair MASTER.

## Persistence and migration

The settings schema becomes version 6 and stores
`application_capture_mode`.

For installations without that key:

- legacy `STANDALONE` migrates to `STANDALONE_COLMAP`;
- legacy `MASTER` migrates to `DUAL_PHONE_MASTER`;
- legacy `SLAVE` migrates to `DUAL_PHONE_SLAVE`.

The migration writes both the new mode and its compatibility role atomically.
Unknown mode values fail closed to the legacy-role migration.

Old callers that still save only `DualPhoneRole` remain supported during the
refactor. If their role conflicts with the selected application mode, the store
converts the role to its matching legacy application mode.

## UI requirement in this patch

The dual-phone settings card starts with one selector listing all five modes in
operator language. Selection is persisted immediately.

This patch does not yet delete the old role controls or hide all unrelated
settings. That is deliberately deferred to LM02.7B.5.4.4 so intermediate builds
remain usable.

## Invariants retained from LM02.7B.5.4.2

- MASTER phone creates and owns the complete calibration profile.
- Laptop `CAMERA_A` is the sole calibration authority.
- Laptop `CAMERA_B` never sends the full calibration JSON.
- The laptop host continues to accept calibration only from `CAMERA_A`.
- Calibration upload remains automatic during handshake and reconnect.

## Next patches

### LM02.7B.5.4.4

Render only the settings surface belonging to the selected application mode and
remove the visible duplicate role selector.

### LM02.7B.5.4.5

Remove laptop transport dependence on `DualPhoneRole.SLAVE`, add explicit
profile-acceptance acknowledgement and health diagnostics.

### LM02.7B.5.4.6

Remove compatibility leftovers, add migration tests and end-to-end acceptance
coverage for all five modes.
