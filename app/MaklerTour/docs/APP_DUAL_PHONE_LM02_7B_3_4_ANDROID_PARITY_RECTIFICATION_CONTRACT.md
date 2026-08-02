# LM02.7B.3.4 — Android-parity rectification

## Purpose

Bring the Linux laptop stereo preview into geometric parity with the already working Android MASTER/SLAVE depth processor.

## Input orientation

The JPEG byte buffer remains in the unrotated CameraX/sensor landscape orientation. `rotation_degrees` is display metadata and must not be applied before calibration, `stereoRectify`, or remap-map construction.

Both runtime frames must have the same dimensions. Accepted calibration intrinsics are scaled from their calibration resolution to the runtime JPEG resolution without changing the image aspect ratio.

## OpenCV rectification

The laptop host must use the accepted calibration values directly:

- camera matrices scaled to runtime dimensions;
- distortion vectors from accepted `k1` and `k2`;
- accepted stereo `R` and `T`;
- `CALIB_ZERO_DISPARITY`;
- `alpha = 0`;
- runtime frame dimensions as `newImageSize`.

The complete 3×4 `P1` and `P2` matrices returned by `stereoRectify` are passed to `initUndistortRectifyMap`. The host must not crop them to 3×3 and must not replace them with an inferred shared camera matrix.

## Vertical stereo

Rectification occurs before any processing rotation. If the rectified baseline is vertical:

- negative `P2(1,3)` rotates both rectified images 90° counter-clockwise;
- positive `P2(1,3)` rotates both rectified images 90° clockwise.

StereoSGBM then operates on the identically oriented rectified pair.

## Diagnostics

Raw A/B JPEG files are persisted before map construction so they remain available when rectification fails.

Whenever maps are rebuilt, `stereo_preview.jsonl` records:

- input dimensions;
- rotation metadata;
- rectification axis and projection shift;
- valid-map fractions;
- `R1`, `R2`, `P1`, `P2`, and `Q`.

Low map-validity fraction is diagnostic information, not an early failure by itself. The remapped output is rejected only when it is effectively black or otherwise unusable.
