# APP-DUAL-PHONE-STEREO — Master/Slave Capture Roadmap

## Status

```text
ACTIVE
DP01 FOUNDATION IMPLEMENTED IN SOURCE
NETWORK PAIRING AND RECORDING NOT IMPLEMENTED YET
```

## Goal

Use two independently capable Android phones as one rigid calibrated stereo rig:

```text
Master phone cam0
+ Slave phone cam1
+ rigid printed mount
+ local FHD30/FHD60 recording on both phones
+ sensor timestamps
+ Wi-Fi control and clock synchronization
→ timestamp-paired calibrated stereo video
```

Different phone models are supported. The system must not assume identical
intrinsics, distortion, rolling shutter, exposure behavior or supported modes.

## Fixed decisions

### Transport

```text
Primary control transport: Wi-Fi LAN
Reliable commands: TCP
Clock synchronization: UDP
Optional discovery/bootstrap: Bluetooth or QR
Video/data transfer during capture: forbidden
```

Both phones record locally. Bluetooth is not used for control timing, clock
synchronization or video transfer.

The initial POC may use an existing Wi-Fi network or a manually enabled hotspot.
Automatic LocalOnlyHotspot provisioning is a later UX task.

### Ownership

The Master owns:

```text
dual_capture_id
session token
peer identity
start/stop state
clock-sync state
capture readiness
upload authorization
upload progress for cam0 and cam1
final bundle readiness
```

The Slave may upload its own files directly to the backend, but the Master must
observe and control the two-sided upload workflow. A dual capture is not ready
for processing until both sides are registered under the same
`dual_capture_id`.

### Calibration reuse

A calibration may be reused only when the complete identity key matches:

```text
rig_id
cam0 device_id
cam1 device_id
cam0 physical camera_id
cam1 physical camera_id
cam0 resolution/FPS mode
cam1 resolution/FPS mode
rig mounting revision
```

Changing either phone, physical lens, recording mode or rigid mounting geometry
invalidates stereo extrinsics. The Master stores the reusable calibration
profile.

## Capture bundle contract

```text
capture_type=dual_phone_stereo_video

bundle_manifest.json
capture/
├── dual_phone_stereo_manifest.json
├── cam0.mp4
├── cam1.mp4
├── cam0_frames.jsonl
├── cam1_frames.jsonl
├── clock_sync.json
├── imu0.csv
└── imu1.csv
calibration/
└── dual_phone_stereo_extrinsics.json
```

The two videos may be uploaded independently. Backend registration joins them by
`dual_capture_id`, role and device identity.

## Recording mode contract

Resolution and FPS are explicit persistent settings per physical camera.

The UI may show only modes verified from Camera2 capabilities. A 60 FPS option
must not be invented merely because an AE FPS range contains 60; the output
stream minimum frame duration must also permit it.

Requested and actual values are both written to metadata:

```text
requested_width
requested_height
requested_fps
actual_width
actual_height
observed_fps
physical_camera_id
```

## Implementation order

### DP01 — Persistent identity, role, mode and capability foundation

- stable `device_id`;
- roles `STANDALONE`, `MASTER`, `SLAVE`;
- Wi-Fi transport contract;
- persistent resolution/FPS selection;
- capability JSON export;
- rig topology and device/camera identity fields;
- Master upload ownership invariant.

### DP02 — Wi-Fi pairing and reliable control

- Master TCP server;
- Slave TCP client;
- one-time session token;
- manual IP entry first;
- QR bootstrap after the protocol is stable;
- HELLO, CAPABILITIES, ARM, START_AT, STOP and health messages;
- reconnect without changing `dual_capture_id`.

### DP03 — UDP clock synchronization

- repeated four-timestamp exchanges;
- RTT filtering;
- offset and drift model;
- sync quality report;
- periodic resynchronization during recording.

### DP04 — Timestamped local FHD recording

- Camera2 frame metadata sidecar;
- sensor timestamp;
- encoder PTS;
- exposure/frame duration/ISO/focus;
- IMU sidecar;
- requested and observed FPS;
- dropped-frame diagnostics.

### DP05 — Synchronization validation

- LED or rapidly changing screen test;
- compare calculated frame pairing with visible transitions;
- define accepted median/P95/max frame deltas.

### DP06 — Dual-phone ChArUco calibration

- separate intrinsics;
- stereo extrinsics;
- exact calibration identity key;
- reusable Master-side profile;
- invalidation on identity or mode change.

### DP07 — Backend upload and GPU integration

- independent cam0/cam1 upload registration;
- Master-controlled aggregate state;
- `dual_capture_id` completeness gate;
- offline frame pairing;
- quality/keyframe selection;
- reuse the existing stereo depth, odometry and fusion pipeline.

## Immediate next task

DP02 starts only after DP01 builds on a real phone and the capability report
shows the intended resolution/FPS modes. The existing USB stereo capture type
and processing pipeline remain unchanged.
