# APP ToF LM03 roadmap

## Status

```text
REPOSITORY BASELINE: e125fcc11ed3db9d3a361b8cb13d704f5b7b584c

LM03.1      RP2040 + VL53L8CX bring-up               CLOSED
LM03.2A     TOF_FRAME_V1 over USB CDC                CLOSED
LM03.2B     Android USB Host + parser                CLOSED
LM03.3.0    RP2040/Android arrival-clock baseline    CLOSED
LM03.3.1    active RP2040 <-> Android clock sync     CLOSED
LM03.3.1a   USB session lifecycle fence              CLOSED
LM03.3.2    ToF + Camera2 + IMU time alignment       CLOSED
LM03.3.2A   local CAMERA_A sensor timeline           CLOSED
LM03.3.2B   nearest event-time ToF pairing           CLOSED
LM03.4      CAMERA_A <-> ToF extrinsics              IN PROGRESS
LM03.4A     extrinsics profile + projection model    IN PROGRESS
LM03.4B     ChArUco + ToF planar solver              PLANNED
LM03.5      64 ToF anchors on Registered RGB         PLANNED
LM03.6   STEREO / TOF / FUSED cursor        PLANNED
LM03.7   VIO + ToF metric trajectory        PLANNED
LM03.8   accumulated metric live 3D          PLANNED
```

## LM03.1 closeout

The physical sensor path is proven:

```text
VL53L8CX -> I2C -> RP2040-Zero
```

Accepted results include ACK on 7-bit address `0x29`, successful ST firmware upload
and stable 8x8@15Hz ranging with INT timestamps.

The wiring error discovered during bring-up was SDA/SCL reversal. Both lines can
still measure HIGH at idle when reversed, therefore future hardware diagnostics must
verify signal identity as well as idle voltage.

## LM03.2A closeout

The machine transport is proven:

```text
RP2040-Zero
  -> TOF_FRAME_V1
  -> USB CDC
  -> Linux parser
```

Accepted runtime evidence on 2026-08-11:

```text
frames_ok: 169
crc_bad: 0
8x8@15Hz
irq_ts: true
```

The first implementation produced CRC errors because binary output went through a
text stdout path with CR/LF translation. The accepted implementation disables text
translation for binary frame writes.

Canonical wire contract:

```text
app/rp2040_zero_vl53l8cx_3x/TOF_FRAME_V1.md
```

Canonical Android boundary:

```text
app/MaklerTour/docs/APP_TOF_USB_CONTRACT.md
```

## LM03.2B closeout

LM03.2B moved the binary consumer into CAMERA_A Android and is CLOSED by
real-device evidence.

Architecture:

```text
RP2040 USB CDC
       |
       v
TofUsbRuntime
       |
       +--> TofFrameV1Parser
       |
       +--> StateFlow<TofUsbState>
       |
       +--> StateFlow<TofFrameV1?>
```

Accepted MASTER run:

```text
frames=390
crc=0
drops=0
8x8@15Hz
irq=true
```

No RGB fusion was added in this milestone.

## LM03.3.0 boundary

Only after LM03.2B passes on CAMERA_A do we estimate:

```text
RP2040 monotonic us
        <- clock mapping ->
Android elapsedRealtimeNanos
        <- Camera2 timestamp relation ->
Camera sensor timestamp
        +
Android IMU timestamps
```

The arrival timestamp is not a substitute for the RP2040 event timestamp. Both must
be retained.

LM03.3.0 is CLOSED by the real MASTER run: 1350 frames, crc=0, drops=0,
clockGen=0. Passive USB-arrival P95 varied from roughly 2 ms to above 8 ms, so
arrival time is not accepted as the final ToF event timestamp.

LM03.3.1 adds `TOF_SYNC_V1` active round-trip synchronization while the 15 Hz ToF
stream remains running.

A camera/live-stereo concurrency run exposed a lifecycle race: an Activity
recreation could cancel the old USB coroutine, set the shared `readJob` to null,
start a replacement session, and then let the old coroutine continue because its
loop checked the new global `scope`. The observed result was a second
`CDC streaming started`, repeated stale-session sync write failures, one CRC error
and one sequence drop.

LM03.3.1a hardens that boundary with:

```text
per-session lifecycle generation
session-local parser state
synchronous UsbDeviceConnection close on stop()
coroutine-local isActive checks
stale-session publish/sync/state fences
configuration-change preservation of the process-scoped ToF runtime
```

LM03.3.1 and LM03.3.1a are CLOSED by repeated real CAMERA_A / MASTER runs with
one active USB generation, no stale-session writes and no growth of CRC/drop
counters. The accepted active model settles around `-35 ppm` drift and tens of
microseconds RMS under live camera/IMU load.

## LM03.3.2A closeout

The authoritative CAMERA_A laptop/live-stereo frame path is:

```text
DualPhoneLaptopUplinkRuntime
  -> DualPhoneReducedFrameProducer
  -> CameraX ImageAnalysis
  -> ImageProxy.imageInfo.timestamp
```

`PhoneCameraVideoRecorder` is a separate recording/calibration runtime and must
not be used to diagnose laptop/live-stereo frame flow.

Accepted real-device evidence on 2026-08-13:

```text
source=IMAGE_ANALYSIS
camSource=REALTIME
camRaw == mapped cam timestamp
gyro present
accel present
tofClock=READY
ToF transport crc=0
ToF transport drops=0
active-sync drift roughly -35..-37 ppm
steady active-sync model RMS roughly 61..77 us
```

Camera `camRecvDeltaUs` is roughly 82..118 ms in this run. It is ImageAnalysis
callback latency and is not camera exposure/event time.

The diagnostic currently compares each camera event with the latest published ToF
frame. Consequently observed `tofDeltaUs` values of roughly 20..81 ms are not a
final pairing-quality metric and must not be interpreted as clock-sync error.

LM03.3.2B must retain mapped ToF event timestamps in a bounded history and choose
the nearest ToF event to each camera event by event time. A pairing acceptance
threshold is not invented before measuring the resulting nearest-event
distribution.

Canonical LM03.3.2 task:

```text
docs/llm/tasks/APP-TOF-LM03.3.2-SENSOR-TIMELINE.md
```
