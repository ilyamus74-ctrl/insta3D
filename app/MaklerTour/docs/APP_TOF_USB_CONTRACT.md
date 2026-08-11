# MaklerTour ToF USB contract

> Canonical contract for LM03 phone + ToF integration.
>
> Status: EXPERIMENTAL / CROSS-SYSTEM
>
> Updated: 2026-08-11

## 1. Scope

This contract defines the boundary:

```text
VL53L8CX
  -> RP2040-Zero
  -> TOF_FRAME_V1
  -> USB CDC
  -> Android CAMERA_A
```

It does not define RGB/ToF extrinsic calibration, VIO, stereo fusion, meshing or
final world coordinates.

## 2. Current accepted hardware baseline

```text
sensor: VL53L8CX
RP2040 board: Waveshare RP2040-Zero
mode: 8x8 @ 15 Hz continuous
sensor slot: 0
default VL53L8CX 7-bit I2C address: 0x29
runtime assigned slot-0 7-bit address: 0x2A

RP2040 GP4 -> SDA/MOSI
RP2040 GP5 -> SCL/MCLK
SPI_I2C_N -> GND
NCS -> NC
MISO -> NC
LPn slot 0 -> GP2
INT slot 0 -> GP3
```

## 3. LM03.1 acceptance

LM03.1 is CLOSED.

Accepted evidence:

```text
raw I2C ACK: 0x29
VL53L8CX firmware upload: PASS
8x8 ranging: PASS
15 Hz ranging: PASS
INT timestamp: PASS
distance matrix: PASS
```

## 4. LM03.2A acceptance

LM03.2A is CLOSED.

Accepted Linux host run:

```text
frames_ok >= 169
crc_bad = 0
mode = 8x8@15Hz
irq_ts = true
```

The binary transport is therefore accepted as byte-preserving over USB CDC.

The earlier CRC failures were caused by CR/LF translation in the RP2040 stdout path.
Binary `TOF_FRAME_V1` writes must bypass all text newline translation.

## 5. TOF_FRAME_V1 consumer contract

Canonical wire format:

```text
app/rp2040_zero_vl53l8cx_3x/TOF_FRAME_V1.md
```

Android must preserve and expose at least:

```text
protocolVersion
slot
width
height
frequencyHz
siliconTemperatureC
sequence
rp2040TimestampUs
irqTimestampValid

distanceMm[]
rangeSigmaMm[]
targetStatus[]
nbTargetDetected[]

hostReceivedElapsedRealtimeNs
```

The Android receiver must not replace invalid raw zones with synthetic distance
values. Validity is derived by consumers from target count, target status, distance
and sigma.

## 6. Android USB Host responsibilities

LM03.2B must:

1. enumerate USB devices while the app is running;
2. identify a CDC data interface with BULK IN and BULK OUT endpoints;
3. request Android USB permission when required;
4. open and claim the CDC interfaces off the UI thread;
5. assert CDC DTR/RTS before using RP2040 stdio USB;
6. send:

```text
print off
stream 0
```

7. receive arbitrary USB chunks and reassemble `TOF1` frames;
8. resynchronize on the `TOF1` magic after arbitrary text or damaged bytes;
9. verify IEEE CRC-32 before publishing a frame;
10. timestamp receipt with `SystemClock.elapsedRealtimeNanos()`;
11. publish latest frame and transport state through `StateFlow`;
12. count CRC errors and sequence drops;
13. close claimed interfaces and the device connection on shutdown/error.

Android USB I/O must never block the main/UI thread.

## 7. Clock rule

The two timestamps have different meanings:

```text
rp2040TimestampUs
    event time in the RP2040 monotonic clock domain

hostReceivedElapsedRealtimeNs
    arrival/completion time in the Android monotonic clock domain
```

They must not be treated as the same clock.

LM03.3 will estimate the mapping between these clock domains and align ToF with
Camera2 sensor timestamps and Android IMU timestamps.

## 8. Error handling

Minimum transport states:

```text
STOPPED
SEARCHING
WAITING_PERMISSION
CONNECTING
STREAMING
ERROR
```

Minimum diagnostics:

```text
framesOk
crcErrors
malformedHeaders
sequenceDrops
lastSequence
lastFrameHostElapsedRealtimeNs
lastError
```

A CRC-invalid frame must never reach `latestFrame`.

## 9. Thermal observation

During the accepted 8x8@15Hz run the sensor-reported silicon temperature stabilized
around:

```text
61 C
```

This is recorded as an observation, not yet as an accepted enclosure thermal limit.
Thermal qualification remains a separate hardware task.

## 10. Deferred work

Not part of LM03.2B:

```text
RGB overlay
ToF intrinsics/FoV model
CAMERA_A <-> ToF extrinsic calibration
clock-domain fit
IMU alignment
stereo/ToF fusion
VIO
metric world accumulation
multi-ToF optical scheduling
```
