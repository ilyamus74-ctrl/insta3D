# APP dual-phone LM02.6.1 geometry-preserving overlay contract

Baseline:

```text
6211d1cd2d5589de67e013f918f0e978b49c1768
```

## Processing geometry

Depth performance profiles describe the horizontal-disparity processing size.

```text
horizontal stereo baseline:
    QUALITY_480  -> 480×270
    BALANCED_320 -> 320×240

vertical stereo baseline after the required 90° processing rotation:
    QUALITY_480  -> 270×480
    BALANCED_320 -> 240×320
```

The rotated image must never be resized back into the unswapped profile size.
Such a resize changes the aspect ratio and invalidates object shape, focal scale,
metric depth colours and future point-cloud measurements.

The effective focal length is scaled with the actual final disparity width:

```text
workMaster.cols / depthMaster.cols
```

It must not assume that `profile.workWidth` is the final width for a vertical
baseline.

## Operator overlay

RECT MASTER, DENSE and STRICT are produced in one pixel coordinate system and
use the same display rotation. The operator viewport uses aspect-preserving
FIT_CENTER rendering. Cropping or non-uniform stretching is not allowed.

Expected diagnostic dimensions for the current vertical rig:

```text
baseline vertical
QUALITY_480 270×480
```

## Invariants

* stereo K/D/R/T and rectification remain unchanged;
* processing rotation remains unchanged;
* only post-rotation work dimensions and display fitting change;
* raw, dense, strict and confidence maps retain identical dimensions;
* metric colours continue to represent 0.5–6.0 m;
* no stale frame, queue or stream ownership behaviour changes.
