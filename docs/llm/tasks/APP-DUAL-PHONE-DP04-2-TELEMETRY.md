# APP-DUAL-PHONE DP04.2 — Capture telemetry and local sync acceptance

## Status

```text
IMPLEMENTED IN SOURCE
RUNTIME ACCEPTANCE PENDING
BASELINE: b02acaace22271311f05126d2832e66600b0cede
```

## Purpose

DP04.2 turns each role-local dual-phone MP4 into an inspectable capture member
that can later be joined into a calibrated stereo dataset. It does not yet claim
that a Camera2 capture result maps one-to-one to an encoded MP4 sample.

## Role-local artifact

Each phone writes:

```text
files/dual_phone_captures/<dual_capture_id>/<role>/
├── video.mp4
├── dual_capture_manifest.json
├── frames.jsonl
├── encoder_pts.jsonl
├── imu.jsonl
├── camera_info.json
└── clock_sync.json
```

`frames.jsonl` is populated from a read-only
`CameraCaptureSession.CaptureCallback` attached to the CameraX `VideoCapture`
builder through `Camera2Interop.Extender`. It records:

```text
camera frame number
sensor timestamp
elapsed-realtime callback receive timestamp
exposure time
frame duration
ISO sensitivity
focus distance
rolling-shutter skew
requested dimensions and target rotation
```

`encoder_pts.jsonl` is generated after MP4 finalization with Android
`MediaExtractor` and records one row per encoded video sample:

```text
sample index
presentation timestamp in microseconds
sample flags
sample size
```

## Explicit mapping state

The initial implementation writes:

```text
frame_to_encoder_mapping_status=UNVERIFIED_SEPARATE_TIMELINES
```

No row in `frames.jsonl` may claim a verified `encoder_pts_us` until DP05 proves
a deterministic relationship on the supported devices and recording modes. A
matching count is useful evidence but is not proof.

If exact mapping cannot be established with CameraX, the production recorder
must migrate to a controlled Camera2 + MediaCodec path.

## Time domains

The scheduled capture start and callback receive timestamp use
`SystemClock.elapsedRealtimeNanos()` / `CLOCK_BOOTTIME`.

Camera sensor timestamps are used directly for cross-phone pairing only when
`camera_info.json` reports:

```text
sensor_timestamp_source_name=REALTIME
```

When a device reports `UNKNOWN`, the local validator falls back to callback
receive elapsed time and labels the result:

```text
CALLBACK_RECEIVE_FALLBACK
```

That fallback includes pipeline and scheduler latency and is not sufficient for
final stereo acceptance by itself.

## Local validator

Run:

```bash
python3 app/MaklerTour/tools/dual_phone_capture_sync_validator.py \
  /path/to/master \
  /path/to/slave \
  --output /path/to/dual_phone_sync_validation.json
```

The validator reports:

```text
dual_capture_id and role checks
timestamp source selected for each phone
capture-result count and observed rate
encoded-sample count and observed rate
capture-result / encoded-sample count delta
matched and unmatched frame candidates
match ratio
median, P95 and maximum absolute timing delta
relative start-call skew
IMU presence
mapping limitations
```

## Acceptance gate before DP06

A runtime capture is ready for the DP05 visual timing test only when:

```text
both MP4 files are non-empty
both manifests report captured=true
both frame sidecars contain frame rows
both encoder PTS sidecars contain sample rows
both IMU files are non-empty
both camera-info files identify the intended physical camera and mode
both clock-sync files exist
validator result is GOOD or FAIR
```

DP06 calibration must not begin from a capture where either phone silently fell
back to another camera, mode, effective zoom or mount geometry.
