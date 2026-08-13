# APP ToF LM03 roadmap

## Status

```text
REPOSITORY BASELINE: ec4ec5d2d95f509d1ca4157cc2d5ad90dc6c3522

LM03.1    RP2040 + VL53L8CX bring-up              CLOSED
LM03.2A   TOF_FRAME_V1 over USB CDC               CLOSED
LM03.2B   Android USB Host + parser               CLOSED
LM03.3.0  RP2040/Android arrival-clock baseline   IN PROGRESS
LM03.3.1  active RP2040 <-> Android clock sync    PLANNED
LM03.3.2  ToF + Camera2 + IMU time alignment      PLANNED
LM03.4    CAMERA_A <-> ToF extrinsics             PLANNED
LM03.5   64 ToF anchors on Registered RGB   PLANNED
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

LM03.3.0 characterizes relative clock rate and USB arrival jitter only.

LM03.3.1 adds active round-trip synchronization. LM03.3.2 then aligns ToF with
Camera2 and Android IMU timestamps.
