# LM02.7B.5.5.1-r3 — Metric depth accuracy audit and dynamic disparity

## Scope

This patch changes only the laptop C++ depth pipeline and diagnostics.
Android capture, calibration storage, TCP handshake, and `web/tools/colmap_src`
are not changed.

## Why r3 exists

The r2 backend hunks were valid, but the dashboard `index.html` hunk did not
apply on the operator workstation. r3 deliberately leaves `index.html`
untouched and exposes the expanded probe through a standalone helper:

```bash
web/remote_station/dual_phone_host/metric_depth_probe.sh 0.5 0.5
```

The existing browser tooltip continues to show distance. The helper prints the
full JSON audit without requiring a browser-source modification.

## Metric rules

- No fixed `+17 px` correction.
- No empirical distance multiplier such as `0.72`.
- The rectified principal-point offset is derived from OpenCV `P1/P2`.
- Metric distance uses effective disparity:

  `Z = focal_px * baseline_mm / effective_disparity_px / 1000`.

- Stereo search range is derived from focal length, baseline, working width,
  and a 0.75 m near-range requirement.
- The former hard ceiling of 128 disparity pixels is removed.
- FILTERED and STRICT masks require left-right consistency.

## Probe output

The helper reports:

- `distance_m`, minimum, maximum, and spread;
- `raw_disparity_px`;
- `disparity_zero_offset_px`;
- `effective_disparity_px`;
- local disparity spread;
- left-right consistency ratio;
- measurement confidence and reason;
- focal length, baseline, profile, sequence, pair, and pixel coordinates.

## Validation target

Use a flat textured target at 1 m, 2 m, and 3 m. Place the target near the
centre of the preview and query `0.5 0.5`, or adjust normalized X/Y until the
reported source pixel lies inside the target.

Compare HIGH_640 and ULTRA_960 in DEPTH_RAW and DEPTH_FILTERED. A usable metric
sample should normally report HIGH confidence, adequate left-right agreement,
and a small local disparity spread.

## Next stage

After the metric audit is understood, implement Native selected-resolution
calibration and uplink:

`selected Android resolution -> CameraX -> calibration -> uplink -> host`,

with resizing performed only by the host after rectification.
