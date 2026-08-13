# LM03.3.0 — RP2040 / Android arrival-clock baseline

## Status

```text
REPOSITORY BASELINE: ec4ec5d2d95f509d1ca4157cc2d5ad90dc6c3522
LM03.2B: CLOSED on real CAMERA_A / MASTER
LM03.3.0: IN PROGRESS
```

## Accepted LM03.2B evidence

```text
USB permission granted
CDC streaming started
frames = 390
crc = 0
drops = 0
8x8@15Hz
irq = true
```

## Purpose

`rp2040TimestampUs` is the ToF event timestamp in the RP2040 clock domain.

`hostReceivedElapsedRealtimeNs` is captured by Android after the USB bulk read
returns and therefore contains USB packetization/scheduling/userspace delay.

LM03.3.0 measures:

```text
driftPpm
arrivalResidualRmsUs
arrivalResidualP50Us
arrivalResidualP95Us
lastArrivalResidualUs
generation
```

Diagnostic fit:

```text
Android USB arrival ns ~= a * RP2040 event ns + b
```

The slope is useful for relative clock-rate characterization. The offset still
contains unknown one-way USB latency and MUST NOT be used as final fusion event time.

## Runtime test

```bash
adb logcat -c
adb logcat -s TofUsbRuntime
```

Keep MASTER + RP2040 connected for at least 60 seconds.

After warm-up expect:

```text
clock=ARRIVAL_MODEL_READY
clockN=...
clockSpanMs=...
driftPpm=...
clockRmsUs=...
clockP95Us=...
clockLastUs=...
clockGen=...
```

Transport must remain:

```text
crc=0
drops=0
irq=true
```

## Next

LM03.3.1 adds active RP2040 <-> Android round-trip synchronization.

LM03.3.2 then aligns the accepted ToF mapping with Camera2 sensor timestamps and
Android IMU timestamps.
