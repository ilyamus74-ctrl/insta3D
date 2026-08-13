# LM03.3.2 — CAMERA_A / ToF / IMU sensor timeline

## Status

```text
REPOSITORY BASELINE: e125fcc11ed3db9d3a361b8cb13d704f5b7b584c

LM03.3.1:  CLOSED
LM03.3.1a: CLOSED
LM03.3.2:  CLOSED
LM03.3.2A: CLOSED
LM03.3.2B: CLOSED
```

## Purpose

LM03.3.2 aligns three local CAMERA_A event sources on one Android monotonic
timeline:

```text
CAMERA_A frame event
Android IMU event
ToF RP2040 event
```

This milestone does not yet define RGB/ToF extrinsics or spatial fusion.

## Authoritative live-stereo runtime

For laptop/live-stereo, CAMERA_A frames are produced by:

```text
DualPhoneLaptopUplinkRuntime
  -> DualPhoneReducedFrameProducer
  -> CameraX ImageAnalysis
  -> ImageProxy.imageInfo.timestamp
```

This is the authoritative runtime path for LM03.3.2 live diagnostics.

`PhoneCameraVideoRecorder` is a separate recording/calibration runtime. A silent
callback there does not imply that laptop/live-stereo CAMERA_A frames are absent.

## Clock-domain rules

### Camera

Raw camera event timestamp:

```text
ImageProxy.imageInfo.timestamp
```

The clock source is resolved through:

```text
CameraCharacteristics.SENSOR_INFO_TIMESTAMP_SOURCE
```

Mapping:

```text
REALTIME
    use the camera timestamp directly in the Android elapsed-realtime /
    boot-time monotonic domain

UNKNOWN / non-REALTIME
    map it with DualPhoneCalibrationTimestampMapper using observed callback
    arrival offsets
```

The ImageAnalysis callback arrival timestamp:

```text
SystemClock.elapsedRealtimeNanos()
```

is diagnostic arrival/processing time. It is not camera exposure/event time.

### IMU

Laptop/live-stereo IMU is owned by:

```text
DualPhoneLaptopUplinkRuntime
```

Accelerometer and gyroscope listeners are registered when uplink starts.
Video recording is not required.

Canonical IMU event time:

```text
SensorEvent.timestamp
```

### ToF

The ToF sensor is physically attached only to `CAMERA_A` / MASTER.

Raw ToF event time:

```text
rp2040TimestampUs
```

Accepted active mapping:

```text
rp2040TimestampUs
    -> TofActiveClockSync
    -> Android elapsedRealtimeNs
```

USB frame arrival time is retained as diagnostics and is not substituted for the
ToF event timestamp.

`CAMERA_B` has no local ToF/RP2040 mapping. CAMERA_B is aligned later through the
dual-phone/host clock model.

## LM03.3.2A implementation

`SensorTimelineDiagnostics` receives:

```text
mapped CAMERA_A event timestamp
raw CAMERA_A timestamp
camera timestamp source
ImageAnalysis callback arrival timestamp
nearest observed gyro timestamp
nearest observed accelerometer timestamp
latest published mapped ToF timestamp
```

The live test requires laptop/live-stereo only. Starting video recording is not
part of the acceptance procedure.

## Accepted real-device evidence — 2026-08-13

Observed CAMERA_A timeline:

```text
source=IMAGE_ANALYSIS
camSource=REALTIME
camRaw == cam
gyro present
accel present
tofClock=READY
```

Observed ToF transport:

```text
8x8@15Hz
frames >= 1290
crc=0
drops=0
irq=true
```

Observed active clock model during the steady integrated run:

```text
driftPpm roughly -35..-37
modelRmsUs roughly 61..77
```

Observed CameraX callback delay:

```text
camRecvDeltaUs roughly 82,000..118,000 us
```

This delay demonstrates why callback arrival must not be used as camera event
time.

Observed nearest IMU diagnostics are consistent with the common Android monotonic
timeline. Gyroscope samples were typically within about +/-1.1 ms of the camera
event; accelerometer samples in the captured log were within roughly +/-10 ms.

## Important diagnostic limitation

LM03.3.2A currently reports:

```text
tofDeltaUs = mapped latest published ToF timestamp - mapped camera event timestamp
```

It does not yet search a ToF history for the closest event.

Because the CameraX ImageAnalysis callback itself arrives roughly 82..118 ms after
the camera event, the latest ToF frame available at callback time can legitimately
have an event timestamp later than that camera event.

Therefore observed LM03.3.2A values:

```text
tofDeltaUs roughly 20..81 ms
```

are not an active clock-sync error and are not the final ToF/camera pairing
quality.

Do not set or tune a fusion threshold from this `latestFrame` diagnostic.

## LM03.3.2B — CLOSED

Implementation:

```text
TofUsbRuntime
    -> bounded raw TofFrameV1 history
    -> current TofActiveClockSync mapping
    -> nearest mapped event by absolute CAMERA_A event-time delta
```

Raw RP2040 timestamps are retained in history and mapped at pairing time so the
current accepted active clock fit is applied consistently.

Acceptance rule:

```text
threshold_us = 500,000 / tof_frequency_hz + 2,000
```

For the accepted 15 Hz run:

```text
threshold_us = 35,333
```

Accepted low-light/night live-stereo evidence on 2026-08-13:

```text
frame=450
paired=407
rejected=0
unpaired=43

tofP50Us=16873
tofP95Us=31901
tofP99Us=32683
tofPairThresholdUs=35333

crc=0
drops=0
```

`unpaired=43` is a startup-only active-clock warm-up population. It stopped
growing once the clock model became usable. No nearest candidate was rejected
after the acceptance threshold became active.

This run was intentionally performed in a poorly illuminated room. The
ImageAnalysis callback delay remained variable, but pairing by event timestamp was
stable. This validates the separation between camera event time and callback
arrival time.

LM03.3.2 is CLOSED. The next spatial milestone is LM03.4.

## Canonical contracts

```text
app/MaklerTour/docs/APP_TOF_USB_CONTRACT.md
app/MaklerTour/docs/APP_DUAL_PHONE_LM02_7B_1_LAPTOP_HOST_CONTRACT.md
docs/llm/04_CONTRACTS.md
docs/llm/tasks/APP-TOF-LM03-ROADMAP.md
```
