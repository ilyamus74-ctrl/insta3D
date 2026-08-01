# MaklerTour dual-phone live stream contract

## Status

```text
LM01B implementation contract
baseline: 1d1dec6424ee184348261320526d792ff98495d6
```

## Ownership

MASTER owns `LIVE_METRIC` and `HYBRID`. SLAVE may not start or select a live mode
locally. `DualPhoneApplicationRuntime` owns the camera producer and network media
transport outside Compose.

Application ownership and media readiness are independent. Media failure does not
restore normal SLAVE navigation. Only MASTER Settings, control loss or the SLAVE
emergency action releases the managed application surface.

## Channel separation

```text
control commands/ACKs        existing reliable control socket
clock synchronization        existing UDP clock socket
session heartbeat            TCP/45831
reduced frame media          TCP/45832
```

JPEG payloads are forbidden on the command socket and TCP/45831 heartbeat loop.

## Frame envelope

Required fields:

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
sender_frames_offered
sender_frames_replaced_before_send
sender_frames_dropped_oversize
payload_size
payload_crc32
```

Required bounds:

```text
width <= 640
height <= 360
target producer rate = 5 FPS
encoding = JPEG
JPEG quality = 65
payload_size <= 256 KiB
rotation_applied_degrees = 0
```

`image_proxy_rotation_degrees` is display-only metadata. Rectification and all
future metric calculations consume unrotated transported pixels.

## Backpressure and memory

SLAVE has exactly one pending network frame. A newer frame atomically replaces an
older unsent frame and increments `frames_replaced_before_send`.

MASTER exposes only the latest received frame to UI. No unbounded bitmap, JPEG or
decode queue is allowed. Counters are monotonic for one stream and reset with a
new stream.

## Camera binding

The producer uses the selected physical camera through CameraX `ImageAnalysis` and
`STRATEGY_KEEP_ONLY_LATEST`. It must not alter the original recording camera,
resolution, FPS, zoom, stabilization or orientation.

If the use-case combination cannot bind, state is `FAILED` with a
`STREAM_UNAVAILABLE` reason. Silent camera/mode fallback is forbidden.

## UI truth

LM01B previews are diagnostic only. UI must not label them as:

```text
metric depth
room scan
room skeleton
coverage complete
measurement
mesh
```

Those claims require LM02 and later quality gates.

## Cleanup

The producer, analyzer, pending JPEG, accepted socket and server socket are closed
on mode replacement, `Выкл. LIVE`, Settings, disconnect, emergency release and
runtime close.

## LM02 full-screen scan and first depth contract

```text
baseline: bb25f4bf6931ecb8df3f149932d0729cddfc0ef0
```

After MASTER selects LIVE or HYBRID, the Camera card opens a separate full-screen
scan workspace. Closing the workspace only hides it; `СТОП`, `Выкл. LIVE` or
MASTER Settings stop the live pipeline.

MASTER overlay controls may select:

```text
MASTER
SLAVE
SPLIT
DEPTH
LIVE
HYBRID
STOP
MINIMIZE
```

SLAVE uses its local preview as the full-screen background. It remains managed by
MASTER and may expose only local diagnostic visibility controls plus the emergency
disconnect action. It must not select LIVE/HYBRID or stop the MASTER session.

The first depth preview requires all of the following:

```text
real MASTER reduced frame
real SLAVE reduced frame
matching stream_id/session ownership
ready clock-sync model
accepted active calibration profile
accepted MASTER/SLAVE intrinsics
accepted stereo R/T and baseline
```

`capture_elapsed_realtime_ns` is sampled when CameraX delivers the frame to the
analyzer, before JPEG scaling/compression. SLAVE elapsed time is then converted to
the MASTER clock domain before pair selection. Frame history is bounded to eight
frames per role. Pairs within
35 ms are `READY`; pairs between 35 and 120 ms are `LATE`; larger deltas are not
processed.

LM02 rectifies both real frames with OpenCV `stereoRectify` and
`initUndistortRectifyMap`, then computes a low-resolution `StereoBM` disparity
preview. Vertical rectified baselines are rotated only for the disparity input;
the calibration matrices and transported JPEG pixels are not rewritten.

The displayed median distance and heatmap are diagnostic. LM02 must not claim a
completed room model, room skeleton, scan coverage or final measurement. Those
claims require LM03 and later tracking/geometry gates.
