# MaklerTour dual-phone CPU host — LM02.7B.3

This is the Linux laptop side of the dual-phone stereo architecture:

```text
CAMERA_A phone ─┐
                ├─ TCP/48640 → C++ laptop MASTER
CAMERA_B phone ─┘                  │
                                  ├─ calibrated OpenCV rectification
                                  ├─ CPU StereoSGBM preview
                                  ├─ browser GUI TCP/48641
                                  └─ JSON/JSONL diagnostics
```

Both phones remain capture clients. `CAMERA_A` carries the accepted calibration
profile previously produced by the phone calibration workflow. The laptop validates
the profile, matches it to the two connected device IDs and inverts `R/T` when the
runtime CAMERA_A/CAMERA_B order is the reverse of the calibration order.

## Fedora build

LM02.7B.3 adds the OpenCV C++ development dependency:

```bash
cd ~/Документы/Insta3D/web/remote_station/dual_phone_host
./scripts/install_fedora41.sh
./scripts/build.sh
./scripts/run.sh
```

For an already configured machine, installing only the new package is sufficient:

```bash
sudo dnf install -y opencv-devel
./scripts/build.sh
./scripts/run.sh
```

Open `http://127.0.0.1:48641/`.

## Dashboard

The dashboard shows:

- raw CAMERA_A and CAMERA_B uplink frames;
- calibrated rectified CAMERA_A and CAMERA_B frames with horizontal epipolar guides;
- a colour StereoSGBM disparity preview;
- pairing delta, ingest FPS, processing FPS and processing duration;
- valid disparity ratio;
- calibration state, profile ID, device-order reversal and actionable errors.

Stereo processing runs in a bounded latest-pair worker. JPEG ingest and frame pairing
do not wait for OpenCV. When the worker is busy, an older pending pair is replaced by
the newest ready pair and the replacement counter is exposed in diagnostics.

## Calibration requirements

Rectification starts only when all conditions are true:

1. CAMERA_A hello contains a calibration object with `schema_version=1` and
   `status=success`.
2. Both intrinsics results are solved and contain dimensions, `fx/fy/cx/cy` and
   `k1/k2`.
3. Stereo contains solved 3×3 `rotation`, 3-vector `translation_mm` and a positive
   `baseline_mm`.
4. The connected CAMERA_A and CAMERA_B device IDs match the profile's
   `master_device_id` and `slave_device_id` in either order.
5. Runtime frame aspect ratio matches the calibration frame. Pure resize is handled
   by scaling the camera matrix; an incompatible crop/aspect is rejected.

The disparity image is a diagnostic preview only. LM02.7B.3 does not yet publish
metric depth or construct the room skeleton.

## Runtime data

The default output remains outside the Git checkout:

```text
${XDG_STATE_HOME:-$HOME/.local/state}/maklertour/dual_phone_host/sessions
```

New diagnostics:

```text
stereo_preview.jsonl
stereo_preview_status.json
```

`stereo_preview.jsonl` records every successful, failed or stale-discarded processing
attempt, including pair index, processing duration, frame rotations, valid disparity
ratio and calibration order. `stereo_preview_status.json` stores the final dashboard
state when the host exits.

JPEG frame archiving remains disabled by default:

```bash
./scripts/run.sh
```

Enable bounded raw JPEG recording explicitly:

```bash
MAKLER_ARCHIVE_EVERY=1 ./scripts/run.sh
MAKLER_ARCHIVE_EVERY=5 ./scripts/run.sh
```

Package one session for diagnostics:

```bash
./scripts/pack_session.sh /path/to/session
./scripts/pack_session.sh /path/to/session --sample-every 25
```

The normal `Stop + pack JSON` dashboard action includes the calibration and stereo
preview JSON/JSONL files automatically.

## Wire protocol

1. Client opens TCP/48640.
2. Client sends one UTF-8 JSON hello line terminated by `\n`.
3. Server replies with one `hello_ack` JSON line.
4. Every following message is:

```text
uint32_be header_length
uint32_be payload_length
UTF-8 JSON header
binary payload
```

Frame headers use `type=frame`; IMU headers use `type=imu` with zero payload.

## Scope boundary

LM02.7B.3 proves calibrated rectification and a bounded CPU disparity preview on the
laptop. Metric depth, confidence filtering, temporal fusion and room-skeleton output
remain later host slices.
