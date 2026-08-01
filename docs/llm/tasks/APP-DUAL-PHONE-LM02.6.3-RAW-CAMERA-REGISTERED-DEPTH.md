# APP-DUAL-PHONE-LM02.6.3 — raw-camera registered depth projection

Baseline:

```text
b52159324c344fda1eea070aa94d2699cc4c43af
```

## Defect

LM02.6.2 preserved the natural MASTER camera but intentionally disabled its
full-screen DENSE and STRICT layers. Depth remained visible only in a small
rectified inset, so the operator could not see geometry on the room view.

## Correction

The processor now reprojects DENSE and STRICT from rectified coordinates to the
natural paired MASTER frame using `mapMasterX` and `mapMasterY`. The UI renders:

```text
paired natural MASTER JPEG
+ registered DENSE alpha PNG
+ registered STRICT green outline PNG
```

All three products have identical dimensions and rotation. The latest camera is
used only before the first registered result and after depth expiration.

## Acceptance

* depth is visible in OUTLINE on the natural room image;
* door frames and wall corners keep natural proportions;
* depth does not shift because a newer camera frame replaced its paired base;
* invalid areas do not darken the camera image;
* RECT DEPTH remains available for diagnosis;
* RAW/DENSE/STRICT/CONF modes remain unchanged;
* no changes to stereo filtering, clock sync, pairing or thermal control.
