# APP-DUAL-PHONE-LM02.6.1 — geometry-preserving vertical baseline

Baseline:

```text
6211d1cd2d5589de67e013f918f0e978b49c1768
```

## Observed defect

For the current vertical stereo baseline, rectification produced a landscape
frame and disparity preparation rotated it into portrait coordinates. LM02.4.1
then resized that portrait buffer back into the original landscape profile:

```text
480×270 -> rotate -> 270×480 -> resize -> 480×270
```

This created non-uniform geometric distortion. Door frames, wall corners and
object proportions became unreliable in OUTLINE, and the focal scale used for
metric depth referenced the wrong final axis.

## Correction

```text
horizontal baseline -> workWidth × workHeight
vertical baseline   -> workHeight × workWidth
```

For QUALITY_480 the vertical rig therefore publishes 270×480 rectified and
depth products. Focal scaling uses the actual final `workMaster.cols()`.

The operator viewport changes from center-crop to aspect-preserving fit-center.
The camera, DENSE and STRICT products retain a common rotation and coordinate
system, so their overlays remain registered without stretching.

## Acceptance

* a door frame retains its natural width/height proportion;
* a room corner remains geometrically recognizable;
* UI reports 270×480 for vertical QUALITY_480 processing;
* DENSE and STRICT remain aligned with RECT MASTER;
* the full rectified field is visible, with letterboxing when necessary;
* pairing, freshness, adaptive scene profiles and thermal control are unchanged.
