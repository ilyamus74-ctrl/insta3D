# APP-DUAL-PHONE-LM01B — real reduced-frame stream and dual preview

## Baseline

```text
1d1dec6424ee184348261320526d792ff98495d6
```

## Goal

Replace the LM01A heartbeat-only proof with the first real bounded camera media
flow for `LIVE_METRIC` and `HYBRID`:

```text
SLAVE CameraX ImageAnalysis
→ raw-orientation reduced JPEG
→ bounded latest-frame queue
→ dedicated TCP media channel
→ MASTER receive/decode
→ MASTER local + SLAVE dual preview
```

This slice proves real camera frames, bounded backpressure, cleanup and
operator-visible diagnostics. It does not claim stereo pairing, rectification,
disparity, depth, odometry, room skeleton, coverage, mesh or textures.

## Channel allocation

```text
TCP control commands and ACKs       existing control channel
UDP clock model                     existing clock-sync channel
TCP/45831                           LM01A session identity and heartbeat
TCP/45832                           LM01B reduced JPEG media only
```

A blocked decoder or slow preview must not delay control messages, clock sync or
the TCP/45831 heartbeat.

## Media contract

```text
maximum dimensions       640x360
producer target rate     5 FPS
encoding                 JPEG
JPEG quality             65
maximum JPEG payload     256 KiB
sender pending depth     1
receiver visible depth   1 latest frame
backpressure             newest replaces stale pending frame
rotation on wire         0 degrees
```

`image_proxy_rotation_degrees` is display metadata. Transported pixels remain in
the raw camera coordinate system.

## Runtime ownership

`DualPhoneApplicationRuntime` owns both the media transport and CameraX producer.
They outlive Compose destinations while LIVE/HYBRID is active and are released on:

```text
Выкл. LIVE
MASTER Settings
control disconnect
session/work-mode replacement
SLAVE emergency disconnect
runtime close
```

SLAVE listens and sends. MASTER connects and receives. A producer starts only
after its media channel reaches `READY`.

## UI

MASTER session card displays:

```text
MASTER local reduced preview
SLAVE received reduced preview
producer state and effective FPS
received frame count and bitrate
last frame age
SLAVE offered/replaced/oversize sender counters
```

SLAVE managed screen displays:

```text
local reduced preview
STREAMING TO MASTER
producer/media states
encoded/sent/replaced counters
media bitrate and errors
```

All preview labels explicitly state that LM01B is diagnostic and not metric depth.

## Failure behavior

If the selected physical camera cannot bind an additional `ImageAnalysis` use
case, the producer reports `STREAM_UNAVAILABLE`. It must not silently change the
selected camera, original recording resolution/FPS, zoom, stabilization or raw
orientation contract.

Media reconnect is independent of application ownership. SLAVE remains on the
managed screen and the control channel remains paired.

## Acceptance

1. LIVE produces real moving preview on SLAVE and the same reduced view on MASTER.
2. MASTER also shows its local reduced preview.
3. Frame dimensions never exceed 640x360 and payload never exceeds 256 KiB.
4. Deliberately slow receiving causes replacement counters, not unbounded memory.
5. Control heartbeat/RTT remains responsive under media load.
6. Switching among work tabs does not restart the stream.
7. `Выкл. LIVE`, Settings and disconnect release CameraX/media resources.
8. No UI claims depth, measurements, room geometry or scan completion.
9. A ten-minute run has bounded memory and stable preview latency.

## Next slice

After real-device acceptance:

```text
APP-DUAL-PHONE-LM02
live timestamp pairing, rectification and disparity/depth preview
```
