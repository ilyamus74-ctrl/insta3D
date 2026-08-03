# LM02.7B.4.3 — Dual-rate live preview

## Purpose

Separate the operator-facing preview cadence from the full metric geometry
pipeline.

The previous dashboard published its selected image only when the selected
geometry profile completed. FHD geometry therefore produced an operator
preview at approximately one frame per second even when the laptop still had
available CPU capacity.

## Runtime model

### LIVE contour

- latest-only queue independent from the geometry queue;
- target cadence: 5 FPS;
- fixed work profile: 360×640 portrait;
- single-direction StereoSGBM;
- CLAHE, median disparity, texture masks and lightweight morphology;
- selected operator modes:
  - DISPARITY;
  - DEPTH_RAW;
  - DEPTH_FILTERED;
  - DEPTH_STRICT;
  - CONFIDENCE;
- no left-right pass and no five-frame temporal geometry filter;
- publishes only `selected_preview_latest.jpg`.

### GEOMETRY contour

- existing calibrated metric pipeline remains authoritative;
- default profile: HIGH_640;
- manual AUTO, FHD_1920, ULTRA_960, QUALITY_480 and BALANCED_320 selection
  remains available;
- left-right validation, morphology, temporal stabilization and metric
  diagnostics remain unchanged;
- this contour will feed LM02.7B.5 point-cloud and room-plane processing.

## Dashboard

- CAMERA_A and CAMERA_B remain permanently visible;
- exactly one operator-selected depth map remains visible;
- `/api/status` is polled at 2 Hz;
- `/api/live-preview` is polled at 10 Hz;
- the JPEG is requested only when the live sequence changes;
- the page reports LIVE FPS/compute time separately from GEOMETRY profile,
  p95 and utilization.

## Diagnostics

The session archive includes:

- `live_preview.jsonl`;
- `live_preview_status.json`;
- `selected_preview_latest.jpg`.

Required counters include submitted, processed, failed and latest-only dropped
pairs, actual live FPS, compute time, JPEG encode time and total latency.

## Acceptance

1. Native host build succeeds without new compiler warnings.
2. LIVE output remains active while GEOMETRY is set to FHD_1920.
3. HIGH_640 is the default geometry profile.
4. LIVE target is 5 FPS and the measured rate is shown independently.
5. The dashboard never downloads more than one depth JPEG per live sequence.
6. Existing calibration, geometry diagnostics and stop/pack behavior remain
   intact.
