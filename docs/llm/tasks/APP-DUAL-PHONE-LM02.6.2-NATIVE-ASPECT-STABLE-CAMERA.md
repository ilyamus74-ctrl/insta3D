# APP-DUAL-PHONE-LM02.6.2 — native aspect and stable operator camera

Baseline:

```text
7b0d0b0390aa022488129955c04330a9a49cd392
```

## Defect

LM02.6.1 swapped the profile dimensions for a vertical baseline but still
forced the rotated rectified frame to fill the whole envelope. When the actual
source aspect was 3:4, a 270×360 frame was stretched to 270×480.

OUTLINE also replaced the natural camera background with the rectified frame as
soon as depth became available, producing a crop/field-of-view jump.

## Correction

* fit the rotated rectified frame with uniform scaling and no upscaling;
* derive the final work size from actual `depthMaster.cols/rows`;
* keep the natural MASTER camera as the full-screen operator background;
* show registered rectified MASTER + DENSE + STRICT in a separate inset;
* do not pretend that rectified depth is already inverse-mapped to raw camera.

## Acceptance

* door and wall proportions do not change when depth starts;
* the full-screen camera does not jump at the first calculated pair;
* diagnostic `workWidth × workHeight` reflects native aspect, for example
  `270×360` rather than a forced `270×480`;
* the `RECT DEPTH` inset keeps all rectified layers aligned;
* WAIT CLOCK and WAIT FRAMES retain a visible natural camera;
* no changes to pairing, clock sync, adaptive filtering or thermal policy.
