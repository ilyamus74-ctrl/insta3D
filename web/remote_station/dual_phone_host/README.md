# MaklerTour dual-phone CPU host — LM02.7B.1

This slice creates the Linux laptop side of the new architecture:

```
CAMERA_A phone ─┐
                ├─ TCP/48640 → C++ laptop host
CAMERA_B phone ─┘                  │
                                  ├─ browser GUI TCP/48641
                                  └─ JPEG + JSONL session archive
```

The laptop is the only processing MASTER. Both phones are capture clients. The
old on-device MASTER/SLAVE depth branch is retained as an archived fallback.

## Fedora 41 build

```bash
cd ~/Документы/Insta3D/web/remote_station/dual_phone_host
./scripts/install_fedora41.sh
./scripts/build.sh
./scripts/run.sh
```

Open `http://127.0.0.1:48641/`.

## Runtime data

The default output is outside the Git checkout:

```text
${XDG_STATE_HOME:-$HOME/.local/state}/maklertour/dual_phone_host/sessions
```

Preview and JSON/JSONL diagnostics are enabled by default. JPEG frame archiving
is disabled by default:

```bash
./scripts/run.sh
```

Enable bounded JPEG recording explicitly:

```bash
MAKLER_ARCHIVE_EVERY=1 ./scripts/run.sh   # every frame
MAKLER_ARCHIVE_EVERY=5 ./scripts/run.sh   # every fifth frame
```

Package one session for diagnostics without adding it to Git:

```bash
./scripts/pack_session.sh /path/to/session
./scripts/pack_session.sh /path/to/session --sample-every 25
```

## C++ synthetic two-camera test

Run the host, then open two terminals:

```bash
./build/maklertour-dual-phone-synthetic-client \
  --slot CAMERA_A --jpeg /path/to/a.jpg --width 960 --height 540

./build/maklertour-dual-phone-synthetic-client \
  --slot CAMERA_B --jpeg /path/to/b.jpg --width 960 --height 540
```

The dashboard must show both clients, FPS, sequence numbers and pair delta.
Session output is created below the external XDG state directory shown above.

## Wire protocol

1. Client opens TCP/48640.
2. Client sends one UTF-8 JSON hello line terminated by `\n`.
3. Server replies with one `hello_ack` JSON line.
4. Every following message is:

```
uint32_be header_length
uint32_be payload_length
UTF-8 JSON header
binary payload
```

Frame headers use `type=frame`; IMU headers use `type=imu` with zero payload.
The complete contract is in
`app/MaklerTour/docs/APP_DUAL_PHONE_LM02_7B_1_LAPTOP_HOST_CONTRACT.md`.

## Scope boundary

LM02.7B.1 implements and tests the C++ host, C++ synthetic client, GUI, pairing telemetry and archive.
The following Android slice switches both phones to CAMERA_A/CAMERA_B uplink
clients for this protocol. StereoSGBM, metric calibration and room skeleton are
subsequent host slices after reliable dual ingest is proven.
