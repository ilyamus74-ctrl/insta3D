# TOF_FRAME_V1

Binary RP2040 -> host frame over USB CDC.

All multibyte integers are little-endian.

## Header: 28 bytes

- 0..3: ASCII `TOF1`
- 4: protocol version = 1
- 5: sensor slot
- 6: width (4 or 8)
- 7: height (4 or 8)
- 8: ranging frequency Hz
- 9: silicon temperature, signed int8 Celsius
- 10: zone count (16 or 64)
- 11: flags; bit 0 = timestamp came from INT falling edge
- 12..15: sequence, uint32
- 16..23: RP2040 timestamp_us, uint64
- 24..25: payload size, uint16
- 26..27: reserved

## Payload

Arrays are serialized in this order:

1. distance_mm[zone_count], int16
2. range_sigma_mm[zone_count], uint16
3. target_status[zone_count], uint8
4. nb_target_detected[zone_count], uint8

Raw measurements are transmitted. The receiver applies validity rules.

## Trailer

IEEE CRC-32, uint32, calculated over header + payload.

Frame sizes:
- 4x4 = 128 bytes
- 8x8 = 416 bytes

Commands:

```text
stream 0
stream off
```

The receiver resynchronizes on the `TOF1` magic.

## Accepted LM03.2A runtime evidence

Accepted on 2026-08-11 with one VL53L8CX on RP2040-Zero:

```text
mode: 8x8@15Hz
irq timestamp: true
frames_ok: 169
crc_bad: 0
silicon temperature observed: 61 C
```

The accepted path is:

```text
VL53L8CX
  -> I2C
RP2040-Zero
  -> TOF_FRAME_V1
USB CDC
  -> host parser
```

A transport bug was found and fixed during acceptance: binary frames must not pass
through newline translation. RP2040 firmware therefore writes the binary packet with
CR/LF translation disabled. Any future transport implementation must preserve every
frame byte exactly.

LM03.2A is CLOSED. The next consumer is the Android USB Host runtime in LM03.2B.
