# APP Dual-phone LM02.7B.5.4.4 — mode-scoped settings visibility contract

## Base

This contract is implemented on top of commit
`256b588c6fd4cde0c0a8cfb1570d3301d156b4d2`.

## Purpose

The Settings screen must not render parameters from unrelated capture modes at
the same time. The persisted `ApplicationCaptureMode` introduced by 5.4.3 is
now the only operator-facing mode selector.

## Visibility matrix

| Application mode | Stereo rig / USB | Phone recording | Dual identity | Runtime control |
|---|---:|---:|---:|---:|
| `STANDALONE_COLMAP` | no | yes | no | no |
| `DUAL_PHONE_MASTER` | no | yes | yes | MASTER |
| `DUAL_PHONE_SLAVE` | no | no | yes | SLAVE |
| `LAPTOP_STEREO_CLIENT` | no | yes | yes | laptop uplink |
| `PHONE_USB_STEREO` | yes | yes | no | no |

## Invariants

1. There is one top-level selector containing exactly five modes.
2. The selector is rendered before all mode-specific settings.
3. Selecting a mode updates the visible menu immediately.
4. The legacy `Standalone / Master / Slave` button group is removed.
5. `DualPhoneRole` remains a compatibility field until 5.4.5, but the operator
   no longer selects it directly.
6. Switching to `STANDALONE_COLMAP` does not destroy an existing stereo
   calibration profile.
7. Switching between `PHONE_USB_STEREO` and a dual-phone topology invalidates
   calibration because the physical topology changed.
8. `DUAL_PHONE_MASTER` assigns the local device as cam0.
9. `DUAL_PHONE_SLAVE` assigns the local device as cam1.
10. Laptop mode does not guess CAMERA_A/CAMERA_B from `DualPhoneRole`; the
    explicit laptop slot remains authoritative.
11. MASTER/CAMERA_A remains the only source of the full laptop calibration JSON.
12. Calibration controls are visible only in `DUAL_PHONE_MASTER` and in the
    dedicated `PHONE_USB_STEREO` workflow.

## Runtime sections

### Standalone

Only phone recording quality is displayed. Dual-phone identity, pairing,
clock-sync, laptop host and USB stereo-rig settings are hidden.

### Dual-phone MASTER

Phone recording quality, device identity/capability export, MASTER connection,
rig geometry, board settings, capture controls and calibration controls are
displayed. Laptop uplink is hidden.

### Dual-phone SLAVE

Only identity/capabilities, MASTER address, pairing code, connection state,
clock-sync and recorder state are displayed. Rig editing, MASTER calibration
and laptop uplink are hidden.

### Laptop/PC stereo

Phone recording quality, identity/capabilities and laptop uplink are displayed.
Phone-to-phone pairing and MASTER calibration controls are hidden. The 5.4.2
automatic profile handshake remains unchanged.

### Phone + USB

Stereo-rig, phone camera mode and USB camera/calibration settings remain
available. Phone-to-phone and laptop controls are hidden.

## Immediate recomposition

The selector writes the complete migrated `DualPhoneStereoSettings` object and
returns it to `SettingsScreen`. `SettingsScreen` replaces its Compose state in
the same click handler. Reopening the screen is not required.

## Compatibility

5.4.4 does not yet remove the transitional mapping:

- `LAPTOP_STEREO_CLIENT -> DualPhoneRole.SLAVE`.

That dependency is removed by 5.4.5. The compatibility role is displayed as
diagnostic text only and is not directly editable.

## Next patches

- **5.4.5:** decouple laptop transport from `DualPhoneRole.SLAVE`; add explicit
  profile acknowledgement and handshake health.
- **5.4.6:** remove transitional compatibility paths, consolidate duplicated
  calibration entry points and run end-to-end mode transition tests.
