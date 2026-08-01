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
