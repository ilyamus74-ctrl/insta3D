# APP-DUAL-PHONE-LM02.7A — 16:9 high-resolution phone probe

## Problem

LM02.6.4 diagnostics showed a 360x360 transported source and a 270x270 depth
profile. The square CameraX output removed horizontal information before stereo
matching and made further UI tuning unable to reveal door and wall edges.

## Implementation

* replace deprecated setTargetResolution with CameraX ResolutionSelector;
* prefer a 1280x720 16:9 analysis buffer;
* center-crop a non-16:9 fallback in NV21 without stretching;
* continue transporting bounded 640x360 JPEG frames;
* expose capture and encoded dimensions in SLAVE diagnostics;
* add an adaptive HIGH_640 depth profile at 4 FPS;
* preserve thermal downgrade and bounded media queues.

## Acceptance

Expected SLAVE diagnostics:

```text
capture 1280x720
stereo 640x360 · 16:9 NATIVE
```

An OEM fallback may report another capture size, but stereo must still be 16:9.

Expected MASTER profile sequence:

```text
HIGH_640
or automatic downgrade to QUALITY_480 / BALANCED_320
```

The test result must record media FPS, depth FPS, p50/p95, thermal state and the
stable/high coverage change. That measurement decides whether LM02.7B moves the
high-resolution calculation to the RTX 3080 workstation.
