# LM02.7B.5.5.3 — Stereo calibration FHD accuracy fix

Base commit: `4401062d32f02f0fb3703de3ad5e9d21a88632e0`.

## Contract

1. Metric calibration frames are not eligible until camera controls report `METRIC_READY`.
2. Calibration mode binds at 1.0x before the first eligible frame. EIS/OIS Camera2 options are applied before `METRIC_READY`.
3. Frames produced while controls are changing keep `PREPARING_METRIC_CONTROLS` and cannot satisfy `qualityReady`.
4. Stereo sample dimensions must equal both MASTER and SLAVE intrinsics dimensions. Any mismatch fails with `CALIBRATION_GEOMETRY_MISMATCH`.
5. OpenCV RMS and epipolar values remain stored as raw pixel errors at the actual calibration resolution.
6. Quality decisions normalize pixel errors to a 1280-pixel-wide reference: `normalized = raw * 1280 / actual_width`.
7. Therefore the existing quality limits retain the same angular meaning across resolutions: raw 3.0 px at 1920 is equivalent to 2.0 px at 1280.
8. Outlier rejection also operates in the 1280-equivalent error domain.
9. No empirical baseline/depth scale coefficient is introduced.
10. A new full calibration is required after this patch. Old partial stereo results must not be reused for the depth accuracy retest.

## Expected FHD behavior

For 1920x1080 calibration:

- OpenCV raw stereo RMS can be up to about 3.0 px while remaining equivalent to the historical 2.0 px @ 1280 criterion.
- Raw mean epipolar 2.625 px is equivalent to 1.75 px @ 1280.
- UI/coach reports `RMS@1280` / `epi@1280` for quality comparison, while the saved stereo object retains raw values plus `image_width` / `image_height`.

## Required validation

Run full MASTER intrinsics -> SLAVE intrinsics -> STEREO. Confirm both intrinsics and stereo observations are 1920x1080, camera status starts with `METRIC_READY`, and final stereo output includes raw and normalized quality values.
