# APP-VIDEO-FPS-A — Phone Video Mode Selection

## Status

```text
IMPLEMENTED
ANDROID BUILD AND DEVICE ACCEPTANCE PENDING
```

## Goal

Add an explicit resolution/FPS selector to ordinary phone video recording.
The 60 FPS option is shown only when the selected camera reports both:

```text
MediaRecorder output size
AE target FPS range containing 60
```

## Modes

The first implementation supports standard CameraX qualities:

```text
3840×2160  UHD  30/60 when reported
1920×1080  FHD  30/60 when reported
1280×720   HD   30/60 when reported
```

The compatibility default remains:

```text
1280×720 @ 30 FPS
```

The selected mode is stored per camera ID.

## Recorder contract

The recorder now constructs:

```text
Recorder(QualitySelector)
→ VideoCapture.Builder
→ setTargetFrameRate([fps, fps])
```

Ordinary video recording disables the calibration `ImageAnalysis` use case.
That analysis stream is useful for stereo calibration but can constrain a
high-frame-rate ordinary video session.

## CameraX limitation

CameraX 1.3 treats `setTargetFrameRate` as a target used by stream-combination
heuristics. A device may still choose another effective frame rate. This patch
therefore records the requested mode in existing camera/manifest metadata.
A following patch must inspect the completed MP4 and publish actual width,
height, and capture FPS.

## UI

`Настройки камеры` now shows a `Режим видео` section. Selecting a mode:

```text
persists cameraId + mode
rebinds Preview + VideoCapture
updates the visible requested mode
```

60 FPS is not shown unless both the selected resolution capability and the camera FPS ranges allow 60.

## Test

```bash
php web/tests/phone_video_fps_mode_test.php
```

## Device acceptance

1. Open ordinary phone video.
2. Open `Настройки камеры`.
3. Confirm available resolution/FPS combinations.
4. Select FHD 60 when present.
5. Record at least 20 seconds.
6. Check the resulting MP4 with `ffprobe`.
7. Confirm camera/manifest metadata contains the requested mode.
