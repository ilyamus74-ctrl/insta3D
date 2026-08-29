# Current verified state

## Evidence labels

- **Runtime-confirmed**: repository records a concrete device/job/artifact run.
- **Implemented**: executable source and wiring exist, but this analysis did not rerun it.
- **Partial**: usable path exists with an explicit contract or quality gap.
- **Planned**: task/roadmap only or disabled by a gate.
- **Unknown**: repository cannot establish current external state.

## What is finished or demonstrably working

| Area | State | Evidence |
|---|---|---|
| Order/session/tour MVP | Implemented; roadmap marks core flow done | `ROADMAP.md`, `web/www/order.php`, tour templates/viewers, mobile API |
| Insta360 photo/video and phone video capture | Implemented; phone scan MVP records end-to-end smoke path | `DOC/PHONE_SCAN_MVP_STATUS.md`, camera providers, `PhoneCameraScanProvider.kt` |
| Room persistence and upload queue | Implemented | Room database/entities/DAOs, repositories, `AppStateViewModel.kt` |
| Auto Photo Android bundle | Implemented | `AutoPhotoCaptureManager.kt`, `CaptureBundlePackager.kt`, epic and discovery result |
| Auto Photo backend B01-B06 stages | Implemented with result documents/tests; epic remains in-progress | `docs/llm/tasks/results/AUTO-B*.md`, `web/libs/auto_photo_*`, tests |
| Server remote SfM orchestration | Implemented | `sfm_remote_worker.php`, `sfm_pipeline.php`, run scripts and status/result contracts |
| SINGLE frame extraction, sparse, dense, mesh/export paths | Implemented and used by recorded audits | station scripts, `SFM-S01`, SINGLE A/B audit |
| ToF device/protocol, Android parser, time alignment, calibration and registered anchors | Recorded CLOSED through LM03.5C | firmware, Android ToF classes/tests, `APP-TOF-LM03-ROADMAP.md` |
| Dual-phone control/clock/recording foundation | Real two-phone control cycle recorded; DP04.2 source implemented | dual-phone roadmap and Android control/recorder code |
| USB/phone stereo pair capture and pair-local dense processing | Implemented foundation | stereo contract, native UVC, synced bundle, station dense scripts |

## Implemented but not runtime-accepted

- F01A pair clouds, F01B ORB stereo visual odometry and F01C initial global fusion are wired into `MAKLERTOUR_SYNCED_DENSE`; all current task headers say runtime acceptance pending.
- F02 Android bundle preflight/operator feedback are implemented; Android build and device acceptance pending.
- Station deploy gate exists; actual station sync/runtime is not established in this environment.
- Dual-phone DP04.2 telemetry and MP4 PTS/IMU sidecars exist in source; runtime acceptance remains the stated next gate.

## Partial or broken paths

1. **Dual-phone aggregate upload is contract-broken.** Android queues `capture_type=dual_phone_stereo_video`; `web/www/api/mobile.php` allowlist accepts only `synced_depth_frames`, `stereo_video_legacy`, `auto_photo_session`. Expected result is HTTP 400 before processing.
2. **Dual-phone and USB+ToF bundles omit ToF sidecars.** ToF is persisted only in standalone phone-video capture; mode labels/capability do not imply recorded ToF.
3. **SINGLE reconstruction is visually functional but geometrically incomplete.** Sequential baseline fragments; exhaustive improves connectivity but leaves reprojection/trajectory outliers. IMU and ToF do not constrain mapper/BA.
4. **IMU is partial.** It can influence quality frame selection and produces diagnostics; gravity alignment returns identity because device-to-COLMAP transform is not implemented.
5. **ToF is measurement-only.** It creates metric observations and scale/residual reports but does not apply scale, change sparse/dense geometry or enter optimization.
6. **Hybrid v2 is not run and its artifacts conflict.** `docs/SINGLE_HYBRID_V2_AUDIT.md` says loop detection enabled and vocab tree required; `web/remote_station/single_hybrid_v2/run.sh` explicitly disables it and takes no vocab argument. Both agree runtime is absent.
7. **Stereo global result is provisional.** `fused_global_no_icp.ply` has `icp_applied=false` and `global_fusion_complete=false`.

## Only stated/planned

- Active IMU pose prior/gravity constraint in reconstruction.
- ToF metric constraint/fusion that mutates accepted geometry.
- Stable global loop/drift correction for SINGLE.
- Completed dual-phone server consumer and downstream stereo job.
- ICP/pose-graph/loop correction for stereo global fusion.
- Final triangle mesh texture projection/dollhouse/floorplan product.
- Gaussian Splatting/NeRF branch.

## Verification performed by this archaeology

Read-only source/doc/history inspection only. Existing tests were inventoried (192 top-level web tests, 19 Android unit/instrumentation files), not executed. Device, DB, network and station state are therefore not newly verified.

## Blocking state

The most direct next blockers are: establish an immutable runtime baseline; reconcile the dual bundle contract before dual processing; run the already-wired stereo gate on actual captures; and decide whether bounded visual Hybrid v2 is worth one run before starting an IMU prior experiment.
