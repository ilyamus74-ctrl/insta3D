# DP04.4A2 — Preview-backed dual-phone recorder

## Reason for the change

DP04.4A1 proved that an ARM callback is not enough: on the tested device a
`VideoCapture + ImageAnalysis` headless session produced neither useful
`VideoRecordEvent.Status` data nor a valid MP4. The same application can record
a valid standalone MP4 when CameraX is attached to a real `PreviewView`.

This milestone therefore changes the dual-phone recording contract from a
headless keep-alive session to a real preview-backed CameraX session.

## Runtime contract

While the dual-phone settings card is visible and a peer is connected, each
phone owns one real `PreviewView` registered through
`DualPhoneRecorderPreviewRegistry`.

ARM performs:

```text
wait for attached and measured PreviewView
→ unbind previous CameraX use cases
→ bind Preview + VideoCapture to the same lifecycle
→ start physical pre-roll
→ wait for valid encoded Status data
→ return ARM ready
```

The preview surface is not used as a timing trigger. START and STOP remain
logical markers in the asynchronous capture timeline.

## Health gate

A recorder attempt is ready only after CameraX reports at least:

```text
encoded bytes >= 4096
recorded duration >= 500 ms
```

The Status wait is bounded to 10 seconds. A missing preview surface is reported
separately after a 5 second wait. The outer ARM watchdog is 60 seconds so a
failed requested mode, clean finalize, full rebind and fallback attempt fit in one
controlled ARM operation.

## Two attempts

If preview-backed preparation of the requested mode itself fails, ARM first
rebinds a regular 30 FPS mode before recording begins. This covers devices that
advertise a mode but reject it when `Preview + VideoCapture` are bound together.

Attempt 1 uses the resulting prepared mode. If it produces no valid data, the
application selects a regular 30 FPS mode, fully rebinds
`Preview + VideoCapture`, and starts attempt 2.

Both attempts use the real preview surface. An exception thrown during attempt 2
is handled by the same outer cleanup path as attempt 1.

## Diagnostics

`capture_events.jsonl` records:

```text
RECORDER_ATTEMPT_STARTED
RECORDER_ATTEMPT_READY
RECORDER_ATTEMPT_FAILED
MODE_FALLBACK_SELECTED
ARM_RECORDING_START_FAILED
```

A failed attempt records:

```text
attempt number
requested/effective mode
CameraX Start observed
Status event count
last encoded byte count
last encoded duration
partial file size
binding mode
preview attached state and size
preserved partial MP4 path
```

A non-empty failed MP4 is renamed to
`video_attempt_<n>_failed.mp4` instead of being silently deleted.

## FPS contract

Requested FPS remains a camera request, not proof of encoded cadence. Different
actual FPS values between Master and Slave do not invalidate the capture. Frame
pairing is performed from timestamps and MP4 PTS after capture.

## Acceptance

The milestone is accepted when:

1. both phones show a live recorder preview while connected;
2. ARM binds `DUAL_PHONE_PREVIEW_BACKED` on both roles;
3. both roles observe valid encoded pre-roll;
4. STOP produces two valid non-empty MP4 files;
5. no role returns `ERROR_NO_VALID_DATA(8)`;
6. requested-mode and fallback attempts are both diagnosable;
7. a failed second attempt closes IMU and timeline writers cleanly;
8. START/STOP remain asynchronous logical markers.

## Fallback boundary

If preview-backed CameraX still cannot produce a valid stream on a supported
device, the next implementation boundary is Camera2 with a MediaCodec encoder
surface and an explicit muxer. That fallback changes the recorder implementation,
not the common-timeline or metric-model architecture.
