# APP dual-phone LM02.6.3 raw-camera registered overlay contract

Baseline:

```text
b52159324c344fda1eea070aa94d2699cc4c43af
```

## Registration

The full-screen operator product uses the natural reduced MASTER JPEG from the
same stereo pair that produced the displayed depth. DENSE and STRICT products
are projected from rectified processing coordinates back into that JPEG's raw
pixel coordinate system through the accepted MASTER rectification maps.

The projection pipeline is:

```text
work depth
-> restore depthMaster size
-> undo vertical-baseline processing rotation
-> rectified pixel -> masterInput source coordinate through mapMasterX/mapMasterY
-> bounded forward splat
-> uniform resize masterInput -> paired MASTER JPEG
-> alpha PNG
```

The paired natural JPEG and both alpha PNGs have identical unrotated dimensions
and use the same `imageProxyRotationDegrees` in the UI.

## Products

```text
registeredMasterJpeg
registeredDenseOverlayPng
registeredStrictOutlinePng
registeredRotationDegrees
registeredMasterFrameSequence
```

DENSE remains an operator/tracking product. STRICT remains the geometry gate.
The registered outline does not weaken STRICT correspondence or temporal rules.

## Freshness and motion

The operator background switches to the paired natural frame only while its
registered depth is LIVE/HOLD/STALE. This avoids drawing an older depth map over
a newer camera frame during rig motion. EXPIRED depth falls back to the newest
natural camera frame.

## Invariants

* no rectified frame becomes the full-screen operator background;
* no non-uniform scaling is introduced;
* K/D/R/T, stereoRectify, pairing and metric depth stay unchanged;
* invalid projected pixels remain transparent;
* the RECT DEPTH inset remains available for pixel-space diagnostics;
* processing queues and thermal policy remain bounded.
