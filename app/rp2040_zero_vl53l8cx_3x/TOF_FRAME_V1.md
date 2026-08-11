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
