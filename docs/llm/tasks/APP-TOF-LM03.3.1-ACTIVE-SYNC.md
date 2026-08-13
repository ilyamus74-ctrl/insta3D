# LM03.3.1 — active RP2040 / Android clock sync

## Status

```text
REPOSITORY BASELINE: b8c70f46a89cf336961adc7ebf6aa2656fbd11e1
LM03.3.0: CLOSED
LM03.3.1: CLOSED
```

## LM03.3.0 closeout

Real MASTER run:

```text
frames=1350
crc=0
drops=0
clockGen=0
```

The passive 30-second arrival model eventually reached roughly:

```text
clockRmsUs ~ 1.0..2.3 ms
clockP95Us ~ 2.0..4.4 ms
```

Earlier windows showed P95 above 8 ms. Therefore Android USB arrival time is not
accepted as the ToF event timestamp.

## LM03.3.1 implementation

The 15 Hz ToF stream remains running.

Android sends one `sync NONCE` request per second. RP2040 replies with `TSY1`
(`TOF_SYNC_V1`) containing RP2040 receive/transmit timestamps and CRC32.

The CDC IN stream becomes:

```text
TOF1
TOF1
TSY1
TOF1
...
```

The Android parser recognizes both packet types and preserves CRC/resync behavior.

## Runtime test

After rebuilding and flashing both sides:

```bash
adb logcat -c
adb logcat -s TofUsbRuntime
```

Keep the rig connected for at least 60 seconds.

Expected ToF transport:

```text
crc=0
drops=0
8x8@15Hz
irq=true
```

Expected active-sync diagnostics:

```text
TOF_SYNC_V1 phase=WARMING_UP ...
TOF_SYNC_V1 phase=READY ...
```

Capture:

```text
syncN
lastRttUs
bestRttUs
rttP50Us
rttP95Us
driftPpm
modelRmsUs
```

No hard threshold was invented before the real-device measurement.

## Closeout

Integrated CAMERA_A / MASTER live-stereo evidence on 2026-08-13 keeps the ToF
transport clean while CameraX and IMU are active:

```text
frames >= 1290
crc=0
drops=0
TOF_SYNC_V1 phase=READY
driftPpm roughly -35..-37
steady modelRmsUs roughly 61..77
```

The accepted active mapping is therefore the event-time mapping used by LM03.3.2.
The older passive `ARRIVAL_MODEL_READY` telemetry remains diagnostic only.

## Next

LM03.3.2 consumes the accepted active clock mapping for:

```text
Camera2 sensor timestamps
Android IMU timestamps
video ToF timeline
live stereo ToF timeline
```

LM03.3.2A local timeline diagnostics are CLOSED. See:

```text
docs/llm/tasks/APP-TOF-LM03.3.2-SENSOR-TIMELINE.md
```
