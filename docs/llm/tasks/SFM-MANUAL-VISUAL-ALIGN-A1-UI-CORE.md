# SFM-MANUAL-VISUAL-ALIGN-A1 — UI Core

## Status

```text
IMPLEMENTED
WEB DEPLOYMENT AND RUNTIME ACCEPTANCE PENDING
```

The manual alignment page now exposes the already loaded Anchor and Source
geometries through `window.sfmManualClouds`.

A second module creates a permanent third viewport without downloading either
PLY again.

The Anchor is fixed. The Moving source supports translation, rotation and
positive uniform scale through TransformControls.

Controls:

```text
W = Move
E = Rotate
R = Scale
Q = World / Local axes
```

The combined camera uses free-roll TrackballControls.

The transform is stored in localStorage and can be copied or exported as
matrix4 JSON.

This patch does not change the existing correspondence API, PLY files,
database or accepted assemblies.

Next: `SFM-MANUAL-VISUAL-ALIGN-A2` adds server preview PLY generation and
accepted visual-assembly finalization.
