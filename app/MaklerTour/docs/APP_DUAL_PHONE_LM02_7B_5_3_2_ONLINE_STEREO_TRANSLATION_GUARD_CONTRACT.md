# APP-DUAL-PHONE-LM02.7B.5.3.2 — Online stereo translation guard

## Purpose

Use the metric stereo geometry already available in the live pipeline to keep
walking translation observable without running the full COLMAP mapper online.
The offline rig reconstruction remains a diagnostic reference, not a runtime
dependency.

## Runtime behaviour

1. Accelerometer motion above the existing walking threshold is independent
   walking evidence. A failed PnP estimate must not silently classify that
   frame as tripod rotation.
2. When gyro or visual yaw is available, matched depth points are evaluated by
   a known-yaw stereo 3D-to-3D translation estimator. Translation candidates
   are reduced with component medians, a MAD-derived residual gate and minimum
   inlier support.
3. A valid PnP depth pose remains the preferred walking pose. The known-yaw
   stereo estimate is a fallback when PnP is missing or unsafe.
4. When walking is detected but neither translation source is trustworthy, the
   state is `TRACKING_TRANSLATION_UNCERTAIN`. Such a frame must not become a
   keyframe, must not be merged into the accumulated cloud and must not replace
   the last trusted tracking reference.
5. Older trusted tracking references remain available for recovery. A trusted
   pose recovered from an older reference must outrank an uncertainty-only
   candidate.
6. Tripod X/Z constraints remain active only for rotation evidence. No inertial
   position integration, synthetic translation, hard yaw multiplier or hard
   COLMAP pose prior is introduced.

## Diagnostics

The status and pose-validation streams expose:

- `translation_uncertain_frames`;
- `geometry_suppressed_frames`;
- `stereo_translation_attempts` and `stereo_translation_successes`;
- pair count, inlier count, inlier ratio, median residual and metric step for
  the known-yaw stereo estimate;
- `translation_source`, `translation_trusted` and `geometry_suppressed`.

The offline COLMAP exporter accepts both legacy `trajectory` arrays and the
current live `samples` array when computing `live_path_length_m`.
