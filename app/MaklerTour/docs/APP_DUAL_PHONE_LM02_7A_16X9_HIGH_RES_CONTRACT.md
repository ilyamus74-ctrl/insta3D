# APP dual-phone LM02.7A — 16:9 high-resolution phone probe

Baseline:

```text
e8a4592614c4cabbfb3a718fbde250bb72d806cd
```

## Capture contract

CameraX ImageAnalysis requests a 1280x720 bound and prioritizes 16:9 through
ResolutionSelector. The phone-to-phone media contract remains bounded to
640x360 JPEG at 10 FPS.

If a device still returns a non-16:9 YUV buffer, the producer performs one
even-aligned center crop in NV21 before downscaling. It never stretches the
camera buffer to satisfy the stereo aspect ratio.

The producer reports both:

```text
analysis source dimensions
transported stereo dimensions
whether the 16:9 fallback crop was applied
```

## Phone depth probe

The adaptive controller starts with:

```text
HIGH_640     640x360, target 4 FPS
QUALITY_480  480x270, target 5 FPS
BALANCED_320 320x240, target 5 FPS
THROTTLED    320x240, target 3 FPS
```

For a vertical stereo baseline the processor may expose the corresponding
rotated dimensions, for example 360x640. Aspect ratio must remain native.

HIGH_640 is a probe, not a permanent requirement. Processing p95 and Android
thermal status may downgrade it automatically. Texture-video recording is not
enabled by this slice.

## Desktop continuation

The next slice may send timestamped high-resolution stereo pairs to the existing
GrafikStation with RTX 3080. The current control channel, clock model, bounded
queues and local low-resolution fallback remain authoritative.
