# LM02.7B.5.5.4 — Stereo metric labels and EPI failure attribution

## Goal

Remove ambiguity between the actual stereo calibration resolution and the normalized quality scale introduced in 5.5.3, while preserving all existing acceptance thresholds.

## Invariants

- The stereo solver continues to run at the actual collected image size, e.g. 1920x1080.
- `RAW @WxH` values are the OpenCV errors measured at the actual solver resolution.
- `QUALITY EQUIV @1280` is only a normalized comparison scale: `raw_error * 1280 / actual_width`.
- No calibration frame is resized to 1280 for the stereo solve.
- `MAX_STEREO_RMS_PX = 2.0` and `MAX_MEAN_EPIPOLAR_ERROR_PX = 1.75` remain reference-width quality thresholds.
- High EPI must not be accepted merely by raising the threshold.

## UI contract

Final calibration result must explicitly show:

- actual stereo solve resolution;
- RAW RMS and RAW EPI at that resolution;
- QUALITY EQUIV @1280 RMS/EPI separately;
- baseline and rejected-pair count;
- rejection metric when calibration is rejected;
- a heuristic `AUDIT` hint when EPI is the failing metric.

The UI must state that `QUALITY EQUIV @1280` does not mean the calibration was performed at 1280 pixels.

## EPI attribution hints

The audit is intentionally heuristic and does not change acceptance:

- `SYNC_SUSPECT`: high EPI and large maximum stereo frame delta;
- `SYSTEMATIC_EPI`: normalized RMS and physical baseline agree while EPI remains high;
- `EPI_ONLY_FAILURE`: RMS passes but EPI fails without enough evidence to classify further;
- `STEREO_GEOMETRY_UNSTABLE`: RMS and EPI are both high.

These hints exist to decide the next diagnostic step, not to convert a failed calibration into a valid profile.

## Live coach

The live stereo coach shows both:

- RAW RMS/EPI at the actual solve resolution;
- normalized QUALITY EQUIV @1280 values.

## Compatibility

The serialized calibration profile schema is unchanged. Existing profiles continue to load. Acceptance semantics are unchanged from 5.5.3.
