# APP dual-phone LM01A — accepted calibration and session-owned stream skeleton

## Status

```text
CURRENT SOURCE OF TRUTH FOR THE NEXT IMPLEMENTATION SLICE
BASELINE REPOSITORY: 29dcf3fa204d414b9b21b93dcd0c7fa1a2ec0c59
DUAL-PHONE CHARUCO CALIBRATION: RUNTIME ACCEPTED
PRODUCTION CALIBRATION MODE: AUTO
NEXT IMPLEMENTATION SLICE: LM01A
```

This document records the accepted real-device calibration result and defines the
next Android implementation slice. Older roadmap/status documents remain historical
until they are consolidated separately.

## Accepted calibration result — 2026-07-31

The accepted run used the complete automatic workflow on two real phones:

```text
15 MASTER intrinsics frames
→ 15 SLAVE intrinsics frames
→ 18 dual-visible stereo pairs
→ robust stereo outlier rejection
→ fixed-intrinsics stereo R/T solve
→ rectified epipolar validation
→ profile persistence and activation on both phones
```

Observed result:

```text
MASTER intrinsics RMS: 0.527 px
SLAVE intrinsics RMS: 0.453 px
stereo RMS: 0.615 px
rectified epipolar residual: 0.84 px
operator baseline: 215.0 mm
calculated baseline: 221.8 mm
baseline delta: +6.8 mm
automatically rejected stereo pairs: 3
```

The profile was accepted, persisted and activated on both phones.

These values are evidence for the tested devices, physical lenses, selected image
modes, board definition, zoom, stabilization state and mount revision. They are not
portable calibration constants for another rig.

## Production calibration decisions

```text
board type: ChArUco
correspondence source: explicit common corner IDs
interaction mode: AUTO
intrinsics collection: automatic
stereo collection: automatic
stereo pair filtering: robust outlier rejection
rectified residual: measured on the non-disparity axis
```

Legacy chessboard remains available for diagnostics and mono experiments, but is not
production-accepted for dual-phone stereo until deterministic global corner
orientation mapping and regression fixtures are implemented.

`MANUAL_STEREO` remains a diagnostic mode. It has not passed real-device acceptance
on the tested rig and is not the production operator path.

The entered physical baseline is a prior and sanity check. It must not overwrite the
calculated stereo translation vector.

## Goal of LM01A

Add the first bounded Slave-to-Master reduced analysis stream and make its lifecycle
belong to the selected capture session.

LM01A proves:

```text
session ownership
dedicated frame transport
bounded queues
backpressure
resource cleanup
stream diagnostics
```

LM01A does not implement:

```text
stereo pair acceptance
rectification
disparity
metric depth
visual or inertial odometry
submaps
measurements
coverage model
server reconstruction
mesh
textures
```

## Session ownership

The stream is owned by:

```text
selected application session
dual_capture_id
peer identity
capture mode
active accepted calibration identity
stream_id
```

Changing the session, role, peer, physical camera, recording mode, calibration
identity or mount revision stops the current stream and requires a new preparation.

Supported operating modes:

```text
SYNC_VIDEO  — original local recording; reduced stream disabled
LIVE_METRIC — reduced stream enabled
HYBRID      — original local recording plus reduced stream
```

LM01A must not change existing `SYNC_VIDEO` recording behavior.

## State machine

```text
DISABLED
→ PREPARING
→ READY
→ STREAMING
→ STOPPING
→ STOPPED
```

Failure and recovery states:

```text
DEGRADED
FAILED
RECONNECTING
```

Master owns the session-visible state. Slave reports local readiness and counters.

## Channel separation

```text
commands and acknowledgements = existing TCP control channel
clock model                   = existing UDP clock-sync channel
reduced frame payloads        = dedicated stream data channel
```

Frame payloads must not be placed on the command socket.

The first implementation may use a dedicated length-prefixed TCP connection.

## Initial bounded media contract

```text
maximum frame size: 640x360
target rate: 5 frames/s
encoding: JPEG
initial JPEG quality: 65
maximum payload: 256 KiB
sender pending queue: 1 frame
receiver decode queue: 1 frame
policy: newest frame replaces stale pending frame
```

A device that cannot bind the reduced analysis output beside the selected local
recording mode reports `STREAM_UNAVAILABLE`.

The application must not silently change:

```text
original recording resolution
requested/effective recording FPS
physical camera
zoom
stabilization
raw orientation contract
```

## Frame envelope

Each payload contains a versioned header followed by encoded bytes:

```text
schema_version
stream_id
dual_capture_id
session_uuid
role
frame_sequence
sensor_timestamp_ns
capture_elapsed_realtime_ns
timestamp_source
clock_model_revision
width
height
rotation_applied_degrees
image_proxy_rotation_degrees
encoding
payload_size
payload_crc32
```

Raw orientation remains authoritative:

```text
rotation_applied_degrees = 0
```

Display rotation is applied only after decode on Master. It must not alter
transported pixel coordinates or future calibration/depth calculations.

## Calibration gate

`LIVE_METRIC` and `HYBRID` require an active accepted dual-phone calibration profile
matching:

```text
both device identities
both physical cameras
both image modes
both image dimensions
zoom
stabilization
orientation contract
rig_id
rig_mount_revision
```

A debug transport-only mode may be labelled `UNCALIBRATED`, but it must not produce
or claim metric depth.

## Health counters

```text
frames_produced
frames_encoded
frames_sent
frames_received
frames_decoded
frames_replaced_before_send
frames_dropped_oversize
frames_dropped_decode_busy
bytes_sent
stream_fps
stream_bitrate_kbps
last_frame_age_ms
last_network_receive_age_ms
decode_time_ms
connection_restarts
last_error
```

Counters are monotonic for one `stream_id` and reset only when a new stream is
created.

## Session lifecycle

```text
select session
→ pair phones
→ verify matching accepted calibration
→ choose LIVE_METRIC or HYBRID
→ ARM prepares recorder and reduced stream
→ both roles READY
→ START opens logical stream window
→ STREAMING
→ STOP closes logical stream window
→ bounded drain
→ STOPPED
```

Disconnect, role change, session change, camera change or Activity destruction must
close sockets, release queued bitmaps/byte arrays and stop analyzers.

## UI skeleton

Master session screen shows:

```text
stream state
peer ready state
effective dimensions
effective FPS
bitrate
last frame age
replaced frames
dropped frames
connection restarts
latest reduced preview
```

Slave shows:

```text
local stream state
frames produced
frames encoded
frames sent
replaced/dropped frames
last error
```

Stream status must be visible on the session capture screen, not only in settings or
calibration UI.

## Implementation boundaries

Prefer focused components:

```text
DualPhoneLiveStreamController
DualPhoneLiveStreamServer
DualPhoneLiveStreamClient
DualPhoneLiveStreamFrame
DualPhoneLiveStreamState
DualPhoneLiveStreamStats
```

`DualPhoneControlManager` may coordinate lifecycle commands but must not own bitmap
encoding, decode queues or the stream socket loop.

Avoid adding stream transport loops directly to `MainActivity.kt`.

## Acceptance criteria

1. A stream cannot start without a selected session and `dual_capture_id`.
2. `SYNC_VIDEO` records exactly as before with no stream data connection.
3. `HYBRID` records original local video while the reduced stream remains active.
4. Control commands and clock synchronization remain responsive under frame load.
5. Sender and receiver queue depth remains bounded during a deliberately slow peer.
6. Replaced and dropped frames are counted explicitly.
7. STOP, disconnect and session change release stream resources.
8. Original camera, recording mode, zoom, stabilization and raw orientation do not change.
9. A 10-minute stream has bounded memory with no monotonic bitmap/byte-array growth.
10. No UI or artifact claims metric depth during LM01A.

## Required tests

```text
L0 diff and contract review
L1 static contract test for channel separation, queue bounds and raw rotation
L2 :app:compileDebugKotlin
L2 :app:assembleDebug
L3 state machine unit tests
L3 frame envelope parser tests
L3 latest-frame queue tests
L4 loopback slow-receiver test
L4 reconnect test
L5 two-phone session start/stop/disconnect/reconnect
L6 HYBRID session with valid local MP4 on both phones
L7 10-minute bounded-memory and control-latency run
```

After Android camera/stereo changes:

```bash
cd app/MaklerTour
python3 tools/stereo_contract_audit.py
./gradlew :app:testDebugUnitTest
./gradlew :app:assembleDebug
```

## Rollback

Disable `LIVE_METRIC` and `HYBRID` and remove the dedicated stream controller/data
channel.

The accepted calibration profile and unchanged `SYNC_VIDEO` path remain intact.
