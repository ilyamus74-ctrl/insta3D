# APP dual-phone LM01A-4 — MASTER-controlled runtime and SLAVE work screen

## Baseline

```text
b5f43001db05d18fa115a1ed048b931e87ff209a
```

## Scope

- Move `DualPhoneLiveStreamSessionCoordinator` and
  `DualPhoneLiveStreamDataChannelController` out of the Compose card.
- Keep the runtime alive while navigating between Camera and Settings.
- Make MASTER authoritative for LIVE/HYBRID selection.
- Use the existing TCP control channel for work-mode commands and ACKs.
- Start MASTER TCP/45831 only after SLAVE acknowledges its listener.
- Replace the normal SLAVE application surface with a locked work screen while
  MASTER owns LIVE/HYBRID mode.
- Return SLAVE to Settings when MASTER exits work mode.
- Preserve an emergency local disconnect action on SLAVE.

## Control sequence

```text
MASTER ENTER_WORK_MODE
→ SLAVE validates session/calibration/mode
→ SLAVE starts TCP/45831 listener
→ SLAVE ENTER_WORK_MODE_ACK accepted=true
→ MASTER starts TCP client
→ data channel READY
```

Exiting work mode keeps pairing alive:

```text
MASTER EXIT_WORK_MODE
→ both application runtimes stop Camera/data work
→ SLAVE opens Settings
→ control channel remains connected
```

## Non-goals

LM01A-4 does not bind CameraX preview, encode or transmit reduced frames, compute
rectification/disparity/depth, or start physical recording. The SLAVE screen
contains a structural guide and runtime diagnostics until the frame-producing
slice is implemented.
