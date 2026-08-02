# APP-DUAL-PHONE-LM02.7B.3.1 — vertical rectification fix

Baseline: `0f5ae66c197888d6618b09f9578824daa17d072b`.

## Observed failure

The first LM02.7B.3 laptop run accepted the calibration and processed synchronized
pairs, but both rectified previews were effectively black and every StereoSGBM result
reported `valid_disparity_ratio = 0.0`.

The accepted calibration used landscape sensor frames while the two phones were
mounted vertically. Consequently the calibrated translation was dominated by its Y
component. OpenCV therefore produced a vertical stereo rectification, while the
LM02.7B.3 matcher still searched only along image X.

## Runtime rule

The host determines the rectification direction from the calibrated `P2` projection
matrix returned by `stereoRectify`:

- dominant `P2(0,3)` means horizontal stereo;
- dominant `P2(1,3)` means vertical stereo.

For vertical stereo the host first applies the calibrated remap and then rotates both
rectified images 90 degrees counter-clockwise. The same rotation is applied to both
cameras, so vertical epipolar correspondence becomes horizontal before StereoSGBM.
The disparity sign is derived from the projection term on the selected rectification
axis, not from raw `T.x`.

This is a processing-space correction. It does not alter the Android capture stream,
calibration profile, camera identity or persisted R/T geometry.

## Rectification safety

`initUndistortRectifyMap` receives the explicit 3x3 camera blocks from the 3x4 P1/P2
matrices. The host records the fraction of map coordinates that point into each
source image and rejects maps with negligible coverage.

Every processed pair records raw and rectified luminance/nonzero statistics. An
almost entirely black remap is reported as `STEREO_PREVIEW_FAILED` rather than a
false `READY` state.

## Diagnostic bundle

The current successful pair overwrites these files in the session directory:

```text
raw_a_latest.jpg
raw_b_latest.jpg
rectified_a_latest.jpg
rectified_b_latest.jpg
disparity_latest.jpg
```

The normal JSON diagnostic package includes them even when raw frame sampling is
disabled. `MANIFEST.sha256` excludes itself from its own input list.

## Expected result

For the confirmed vertical phone mount, `/api/status` must report:

```text
rectification_axis = VERTICAL
processing_rotation_degrees = 90
runtime_width = 540
runtime_height = 960
```

Rectified A/B must contain visible scene pixels with horizontal guide lines. A normal
textured scene should produce a non-zero disparity-valid ratio. Metric depth remains
outside this slice.
