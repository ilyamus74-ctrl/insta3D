# APP-DUAL-PHONE-LM02.4.1 — fast producer and adaptive-profile correction

## Baseline

```text
72012941f2120c25dd81721df4d93fd2950d9ba2
```

## Observed regression

MASTER remained near 10 FPS, while the older SLAVE produced about 1.4 FPS in both
LIVE_METRIC and HYBRID. Depth followed the remote cadence. Transport counters showed
no replacement or oversize drops, so TCP was not the bottleneck.

## Corrections

1. Scale raw NV21 planes before one JPEG encode; remove full JPEG decode/Bitmap
   scale/second JPEG encode.
2. Ignore initial OpenCV/JIT warm-up samples.
3. Require three sustained slow p95 decisions before downgrade.
4. Permit promotion after twelve stable fast decisions.
5. Use current thermal status as a recoverable floor rather than a permanent latch.
6. Resize both processing-oriented stereo buffers to the exact active profile before
   grayscale/StereoSGBM and scale focal length along the final disparity axis.
7. Preserve the last valid depth state while another pair is collected or processed.

## Acceptance

* SLAVE reduced stream returns toward the configured 10 FPS on the same device;
* MASTER/SLAVE media remain bounded and replacement counters do not grow normally;
* QUALITY reports 480x270 and BALANCED reports 320x240;
* warm-up does not immediately force BALANCED;
* sustained overload still downgrades;
* stable recovery can promote without restarting LIVE;
* transient PAIRING/PROCESSING does not blank the last valid map;
* LIVE and HYBRID use the same corrected media/depth pipeline;
* no texture-video recording is introduced in this slice.
