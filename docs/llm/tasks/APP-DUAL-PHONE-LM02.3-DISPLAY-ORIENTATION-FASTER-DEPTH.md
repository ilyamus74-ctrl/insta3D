# APP-DUAL-PHONE-LM02.3 — display orientation and faster live depth

## Baseline

```text
c203f5b519de503b96e9b9f1b4009cab7066e8f3
```

## Goal

Correct the operator-visible orientation of rectified/depth diagnostics without
changing stereo mathematics, and reduce pairing latency by increasing the bounded
media cadence.

## Orientation contract

The frame envelope remains raw:

```text
rotation_applied_degrees = 0
```

For vertical-baseline StereoSGBM, both rectified images may still be rotated
identically by 90 degrees in the processing buffer. The processor publishes:

```text
processing_rotation_degrees
display_rotation_degrees
```

The latter is normalized from MASTER display metadata minus processing rotation.
Compose applies it only while drawing RECT/RAW/FILTERED/CONF bitmaps. No K/D/R/T,
raw JPEG, rectification map, disparity or temporal history is rewritten.

## Throughput profile

```text
CameraX reduced-frame target   10 FPS
Depth processing target         4 FPS
Depth minimum start interval  250 ms
Transport pending frames         1
Pair history per role             8
Temporal disparity maps           5
```

The profile is deliberately asymmetric: network/pair selection receives more
candidates while CPU StereoSGBM remains bounded below full media cadence.

## Diagnostics

MASTER displays:

```text
MASTER media FPS
SLAVE receive FPS
depth FPS
READY pair percentage
READY/LATE/DROPPED counts
processing utilization
remote replacements
oversize drops
display and processing rotations
```

## Acceptance

1. MASTER, SLAVE and SPLIT camera previews remain correctly oriented.
2. RECT, RAW, FILTERED and CONF views have the same operator orientation.
3. Depth values and confidence masks continue changing with the scene.
4. Actual media cadence approaches 8–10 FPS on both devices.
5. Actual depth cadence approaches 3–4 FPS without growing latency.
6. Pair delta improves without relaxing the 35/120 ms gates.
7. Replacement counters may grow under load, but memory and displayed frame age
   remain bounded.
8. STOP, minimize, Settings and emergency release keep their existing semantics.
