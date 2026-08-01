# APP dual-phone LM01A-4 — MASTER-controlled runtime and SLAVE work screen

## Implemented baseline

```text
LM01A-4:   dee925449e070cefc42d6191d90e3ecf72aa5fdf
LM01A-4.1: 81f9928a6adb5f16f0ae878959914f7707404eb5
```

## Runtime ownership

- `DualPhoneApplicationRuntime` owns the session coordinator and TCP/45831 data
  channel outside Compose destinations.
- MASTER is authoritative for application work/settings state and LIVE/HYBRID.
- SLAVE renders a locked managed screen while MASTER owns a work state.
- Passive managed state is `WORK_APP`; LIVE/HYBRID remain transport modes.
- Work-screen ownership and data-channel readiness are separate states.
- A transport block must be displayed without returning SLAVE to normal UI.

## Navigation ownership

After pairing, every MASTER tab except Settings is a managed work section:

```text
Sessions
Orders
Camera
Draft
Queue
```

While MASTER is in any of these sections, SLAVE must remain on the locked
`SLAVE · УПРАВЛЯЕТСЯ MASTER` screen. Moving between work sections must not stop
an active LIVE/HYBRID data channel and must not release SLAVE ownership.

Only this navigation transition releases SLAVE:

```text
MASTER opens Settings
→ MASTER sends EXIT_WORK_MODE
→ SLAVE opens Settings
→ control channel remains connected
```

The in-card `Выкл. LIVE` action returns to passive `WORK_APP` and must not
release SLAVE. Emergency disconnect and loss of the control channel are the only
other local release paths.

## LIVE/HYBRID control sequence

```text
MASTER ENTER_WORK_MODE
→ SLAVE enters managed screen immediately
→ SLAVE validates session/calibration/mode
→ SLAVE starts TCP/45831 listener when transport is accepted
→ SLAVE ENTER_WORK_MODE_ACK
→ MASTER starts TCP client only for transport_accepted=true
→ data channel READY
```

## Non-goals

LM01A-4 does not bind CameraX preview, encode or transmit reduced frames, compute
rectification/disparity/depth, or start physical recording. The SLAVE screen
contains a structural guide and runtime diagnostics until the frame-producing
slice is implemented.
