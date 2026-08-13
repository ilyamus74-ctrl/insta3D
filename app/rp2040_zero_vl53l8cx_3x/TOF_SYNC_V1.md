# TOF_SYNC_V1

LM03.3.1 active RP2040 <-> Android clock synchronization.

Baseline:

```text
45cdef2feeb33c2ad9c225f2deafe52a32c39250
```

Android sends over CDC OUT:

```text
sync NONCE
```

`NONCE` is an unsigned 32-bit decimal value.

RP2040 replies on CDC IN with one fixed 32-byte binary packet:

```text
offset 0   4 bytes   ASCII TSY1
offset 4   1 byte    version = 1
offset 5   1 byte    flags = 0
offset 6   2 bytes   reserved = 0
offset 8   4 bytes   nonce, uint32 LE
offset 12  8 bytes   rp2040_rx_timestamp_us, uint64 LE
offset 20  8 bytes   rp2040_tx_timestamp_us, uint64 LE
offset 28  4 bytes   IEEE CRC32 over bytes 0..27
```

The response is byte-preserving binary output with CR/LF translation disabled.

Android records:

```text
t0 = elapsedRealtimeNanos before sending sync
t1 = elapsedRealtimeNanos when TSY1 is received
r0 = RP2040 receive timestamp
r1 = RP2040 transmit timestamp
```

Diagnostic wire RTT:

```text
wire_rtt_ns = (t1 - t0) - (r1 - r0) * 1000
```

Midpoint correspondence:

```text
RP midpoint      = (r0 + r1) / 2
Android midpoint = (t0 + t1) / 2
```

The Android active model ranks samples by RTT and fits the lower-latency half.
