# APP dual-phone LM01A-3 — dedicated data channel

## Status

```text
READY FOR IMPLEMENTATION
BASELINE REPOSITORY: 0df64e8cda47ba99a9ae90f53eff3a0d96cc3ace
```

## Scope

Create a dedicated TCP data connection without reusing either existing transport:

```text
control commands and acknowledgements = existing TCP control channel
clock model                           = existing UDP clock-sync channel
LM01A reduced data                    = TCP port 45831
```

Connection direction follows the existing configured peer address:

```text
SLAVE listens on TCP/45831
MASTER connects to settings.peerHost:45831
future reduced-frame payload direction is SLAVE → MASTER
```

TCP is full duplex, so MASTER performs heartbeat probes over the same connection.

## Handshake identity

Before the channel enters READY, both phones must match:

```text
session UUID
dual_capture_id
local and expected peer device IDs
MASTER/SLAVE complementary roles
active calibration profile identity
rig mount revision
LIVE or HYBRID mode
recording mode identity
```

The local `stream_id` is exchanged for diagnostics but is not required to be equal,
because each phone currently creates its own lifecycle instance.

## Runtime states

```text
PREPARING + LISTENING/CONNECTING
→ HANDSHAKING
→ READY
```

Disconnect or failed heartbeat produces:

```text
READY
→ RECONNECTING
→ HANDSHAKING
→ READY
```

The UI shows port, remote socket, packet counters, RTT and the last transport error.

## Non-goals

LM01A-3 does not bind CameraX analysis, encode JPEG, send camera frames, rectify,
calculate disparity/depth, or construct geometry. The next slice attaches the
bounded latest-frame producer and receiver to this validated channel.
