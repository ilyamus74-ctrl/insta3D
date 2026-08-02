# APP-DUAL-PHONE-LM02.7B.2 — Android uplink and bounded host pairing

## Goal

Connect two real Android phones directly to the Fedora laptop. The phones are
capture clients; the laptop is the only MASTER.

## Android

- Add laptop host, TCP port and `CAMERA_A`/`CAMERA_B` selection.
- Require local dual-phone role `SLAVE`.
- Reuse `DualPhoneReducedFrameProducer`.
- Keep only the latest frame waiting for network transmission.
- Reconnect automatically.
- Run periodic clock probes and send host-aligned frame timestamps.
- Send accelerometer and gyroscope samples.

## Linux C++ host

- Bind ingest independently from the local-only HTTP dashboard.
- Reply to clock probes.
- Keep bounded pairing queues.
- Match the nearest unused timestamps within 25 ms.
- Report queue depth, dropped unmatched frames and clock RTT in the GUI.

## Exit criteria

1. Both real phones show `STREAMING`.
2. Laptop GUI shows the two live images.
3. Both slots remain near the requested frame rate.
4. Pair delta is normally below 25 ms.
5. `ready_pairs / pairs` is near 100%, not the former 50% intermediate-state
   artifact.
6. IMU JSONL files grow while capture is active.
7. Git remains clean except the known `web/tools/colmap_src` submodule.
