# APP-DUAL-PHONE-LM02.7B.5.4.1 — Stationary truth, robust gyro bias and metric depth health

## Purpose

Prevent an in-flight result from the previous calibration from publishing a
stale or empty live heatmap, expose exact metric-depth health, reject false
motion while the rig is stationary and make initial gyro-bias estimation robust
against isolated motion samples.

## Live-preview contract

1. `LivePreviewRuntime::reset()` invalidates the previous JPEG, probe data and
   rectification revision.
2. A worker result may publish only when both its profile revision and reset
   revision still match the current runtime.
3. `selected_preview_latest.jpg` is written only after the stale-result guard.
4. A missing measured baseline falls back to the norm of the calibrated stereo
   translation. Invalid focal length or baseline is reported explicitly.
5. A metric preview with no valid disparity, an empty mask or an effectively
   black heatmap is not reported as READY.
6. After two consecutive recoverable failures, rectification maps are rebuilt
   once for that calibration revision. There is no unbounded retry loop.

## Motion contract

1. A frame is stationary truth only when corrected gyro motion is low,
   acceleration motion is low and stereo/PnP does not confirm translation.
2. Stationary truth preserves the reference pose and never publishes a map
   keyframe.
3. Gyro bias uses a bounded median/MAD window. Samples outside the inertial
   stillness and robust gates are rejected.
4. Bias samples are cleared when the IMU session changes and bias remains frozen
   after initial calibration.

## Diagnostics

`live_preview` status exposes calibration/reset revisions, per-reset pair and
publish counters, mask ratios, non-black heatmap ratio, focal length, baseline,
failure reason and bounded recovery counters.

`pose_validation.jsonl` exposes stationary truth evidence and robust gyro-bias
sample rejection, MAD and confidence.
