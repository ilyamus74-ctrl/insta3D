# DP04.4A1 — Dual-phone recorder health gate

## Problem

The asynchronous timeline correctly decouples control delivery from physical
capture, but some Slave devices finalize with CameraX error 8
(`ERROR_NO_VALID_DATA`). A `VideoRecordEvent.Start` callback is not proof that
the encoder has produced a usable video sample.

The standalone phone-video path has also demonstrated that a mode advertised as
`1920x1080@60` may produce a valid 30 FPS MP4. Therefore capability metadata is
not treated as proof of actual encoded cadence.

## Runtime contract

1. Headless dual-phone capture binds `VideoCapture` together with a small
   keep-alive `ImageAnalysis` stream whose frames are immediately closed.
2. Physical pre-roll starts during ARM.
3. ARM does not return ready until `VideoRecordEvent.Status` reports at least:
   - 4096 encoded bytes;
   - 500 ms recorded duration.
4. The first health attempt is bounded to 3500 ms.
5. If the requested mode produces no valid data, CameraX is finalized, rebound,
   and retried with a regular 30 FPS mode, preferring the same resolution.
6. If the fallback also produces no valid data, ARM fails before START/STOP.
7. Width and height must match between roles. FPS may differ and is reconciled
   from timestamps and MP4 PTS on the server.

## Metadata

Each role manifest records:

```text
requested_video_mode_id
effective_video_mode_id
mode_fallback_reason
pre_roll_valid_encoded_data
pre_roll_bytes_at_ready
pre_roll_duration_ns_at_ready
encoded_video_observed_fps
```

The requested FPS is never silently presented as actual encoded FPS.

## Timeline events

The role event log may contain:

```text
ARM_RECEIVED
MODE_FALLBACK_SELECTED
PHYSICAL_RECORDING_STARTED
CAPTURE_WINDOW_START
CAPTURE_WINDOW_STOP
PHYSICAL_RECORDING_FINALIZE_REQUESTED
PHYSICAL_RECORDING_FINALIZED
```

`MODE_FALLBACK_SELECTED` contains the original mode, selected fallback and
failure reason.

## Metric-model implications

Different effective FPS values do not invalidate the capture. The processing
pipeline must:

1. convert both sensor timelines into the common time domain;
2. select nearest usable frame observations;
3. reject pairs with excessive temporal or motion error;
4. preserve unpaired frames for visual odometry, loop closure and texturing.

Frame indexes are never assumed to correspond across devices.

## Runtime acceptance

The patch is accepted when:

1. both roles report valid encoded pre-roll before ARM completes;
2. STOP produces two non-empty MP4 files;
3. neither role returns `ERROR_NO_VALID_DATA`;
4. a fallback is visible in manifest/events when requested mode is unusable;
5. different FPS values do not trigger `VIDEO_MODE_MISMATCH`;
6. both role packages can be transferred or uploaded.
