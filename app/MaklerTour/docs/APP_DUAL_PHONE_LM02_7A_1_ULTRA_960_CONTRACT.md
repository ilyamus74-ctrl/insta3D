# APP dual-phone LM02.7A.1 — ULTRA_960 phone probe

Baseline:

```text
12d54288493e33d510cf301c6d924d1e8c46ff45
```

## Purpose

Measure the practical ceiling of the phone-only stereo pipeline before desktop
offload is introduced.

## Media bounds

```text
capture preference: 1280x720, 16:9
transport maximum: 960x540 JPEG
media target: 10 FPS
payload maximum: 512 KiB
queue policy: latest frame only
```

The producer still performs one NV21-to-JPEG encode. It may center-crop a
non-16:9 CameraX fallback, but it must never stretch source pixels.

## Depth profiles

```text
ULTRA_960     960x540, target 2.5 FPS, LR enabled
HIGH_640      640x360, target 4 FPS, LR enabled
QUALITY_480   480x270, target 5 FPS, LR enabled
BALANCED_320  320x240, target 5 FPS, LR enabled
THROTTLED_320 320x240, target about 3 FPS, LR disabled
```

ULTRA_960 is the initial probe profile. Sustained processing p95 above the probe
budget downgrades it. WARM, HOT and CRITICAL thermal states retain immediate
quality floors and depth-only pause behavior.

## Operator outline

The registered STRICT bitmap is not changed by this slice. The default OUTLINE
surface suppresses the saturated contour completely, ASSIST renders it at low
opacity, and HEATMAP retains the stronger diagnostic presentation. This keeps
the operator view calm without changing depth registration or confidence data.

## Future desktop processing

A later offload client may run on an ordinary CPU laptop.
GPU acceleration is an optional optimization, not a protocol requirement.
The local phone pipeline remains the fallback.
