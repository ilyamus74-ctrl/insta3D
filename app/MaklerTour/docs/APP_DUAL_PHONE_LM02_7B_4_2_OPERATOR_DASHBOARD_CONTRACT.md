# LM02.7B.4.2 — operator dashboard and selected preview streaming

## Goal

Keep both phone feeds visible while publishing exactly one operator-selected
stereo/depth preview at the active CPU profile cadence.

## Runtime rules

- CAMERA_A and CAMERA_B remain visible.
- Default operator preview is `DEPTH_FILTERED`.
- Supported preview modes:
  - `DISPARITY`
  - `DEPTH_RAW`
  - `DEPTH_FILTERED`
  - `DEPTH_STRICT`
  - `CONFIDENCE`
- The selected preview is encoded once for every accepted depth budget:
  - FHD_1920: approximately 1 FPS
  - ULTRA_960: approximately 2.5 FPS
  - HIGH_640: approximately 4 FPS
  - QUALITY_480/BALANCED_320: approximately 5 FPS
- Non-selected preview images are not JPEG-encoded for the dashboard.
- Depth calculations and confidence statistics remain active regardless of
  which preview is selected.
- Calibration details and diagnostic JSON are collapsed by default.

## API

- `POST /api/depth/preview/<MODE>`
- `GET /stereo/selected.jpg`

## Diagnostics

`stereo_preview_status.json` reports the selected mode, selected sequence,
selected pair index, and publication interval. The latest selected image is
stored as `selected_preview_latest.jpg` and included in the diagnostic package.
