# LM02.7B.5.1 — strict geometry pairing and smooth live fallback

Base commit: `e4765f67987c4794091f87f41a05a6e66b250a9d`.

- Geometry, metric depth, point cloud, planes and PLY remain STRICT-only (`delta <= 25 ms`).
- RELAXED pairs (`delta <= 60 ms`) feed only the live preview after 250 ms without a STRICT pair.
- RELAXED pairs never enter StereoDepthRuntime or RoomGeometryRuntime.
- Live replay remains available and is labelled `REPLAY`.
- Pairing diagnostics expose STRICT/RELAXED counts and the last mode.
- Room geometry applies backpressure before cloning Mats.
- Geometry accepts at most one job per two seconds and continues atomic PLY/JSON publication.
- Existing HIGH_640 geometry and profile-coupled live preview remain compatible.
