# Acceptance criteria

## Explicit criteria recovered from project

| WorkItem | Criterion | Source |
|---|---|---|
| `tour-platform-mvp` | Authenticated operator/broker order visibility, session/media upload, private/public tour access and per-order media isolation work | `ROADMAP.md`, requirements/contracts |
| `android-capture-upload` | Captured file is non-empty, bound to stable identity, persisted across restart and uploaded with valid order/session authorization; terminal upload status reflects actual completion | requirements/contracts, Room/upload code |
| `auto-photo-sfm` | Safe bundle index/materialization, prepared frames, sparse model review/export, simple viewer and bounded dense preview complete without trusting archive paths | AUTO task/result sequence B01-B06 |
| `server-processing-orchestration` | Success requires valid result JSON, expected job/source identity and non-empty required artifacts, not exit code alone | `docs/AGENTS.md`, processing contracts |
| `single-sfm-baseline` | Phone video reaches EXTRACT_FRAMES -> COLMAP_SPARSE -> export/dense stages with registered model and inspectable artifacts | `SFM-S01`, phone MVP status |
| `tof-imu-measurement` | Timing/calibration identity gates pass; reports preserve measurement-only, geometry mutation OFF and RGB fallback | ToF roadmap, S01H tasks/results |
| `dual-phone-capture` | Two-phone cycle reaches CONNECTED -> ARMED -> START_SCHEDULED -> RECORDING -> STOP -> CONNECTED; DP04.2 then validates capture-result, PTS and IMU sidecars | dual-phone roadmap/DP04.2 |
| `usb-stereo-capture` | Preflight rejects missing/invalid pairs or calibration before TGZ/queue; valid capture produces operator-visible success | F02-A/B tasks |
| `stereo-global-fusion` | Pair clouds, trajectory and fused global PLY exist with validated counters/manifests; runtime gate must preserve baseline metrics | F01A/B/C and station deploy gate |
| `sfm-component-assembly` | Operator can review components, persist valid alignments/assembly and publish run-scoped outputs with lineage | workbench/manual align task acceptance sections |

## Inferred measurable criteria

| WorkItem | Criterion | Confidence |
|---|---|---:|
| `single-connectivity-drift` | Same immutable frame set/intrinsics/features; bounded pair graph; fewer components and larger temporal coverage without unacceptable reprojection/step outliers; diagnostics and logs preserved | 0.92 |
| `single-sensor-constraints` | Controlled A/B changes only the selected prior/constraint; reports pose/scale residuals; improves drift/metric error; RGB fallback remains non-blocking | 0.88 |
| `capture-topology-unification` | Every mode has one explicit descriptor containing capture identity, camera roles/profiles, clock domain, calibration and optional sidecars; Android producer and PHP consumer accept the same versioned contract | 0.93 |
| `dual-phone-capture` | Aggregate bundle is accepted/materialized server-side, role identities share a capture UUID, actual timestamps/FPS drive pairing, and an invalid/missing role is rejected safely | 0.95 |
| `stereo-global-fusion` | Independent calibrated capture produces stable metric trajectory and global cloud; `global_fusion_complete=true` only after accepted optimization/closure stage | 0.90 |
| `metric-textured-model` | Independent room capture yields globally consistent metric geometry, triangle mesh and texture assets with reproducible provenance and viewer loading | 0.84 |
| `photorealistic-viewer` | Viewer exposes accepted panorama/spatial/model modes, measurement scale and bounded web performance on representative assets | 0.72 |

## Not acceptance evidence by itself

Checkboxes in legacy `ROADMAP.md`, source compilation, existence of a script, static contract tests and successful-looking remote deploy logs are insufficient where device/station/runtime acceptance is explicitly pending.
