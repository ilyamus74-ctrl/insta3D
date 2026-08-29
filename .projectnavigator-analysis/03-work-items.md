# Work items

Statuses are conservative: `done` requires executable evidence plus tests/runtime records where the repository claims them; `in-progress` includes implemented-but-unaccepted work.

| temporaryKey | title | kind | status | priority | objective | evidence / source files | confidence |
|---|---|---|---|---|---|---|---:|
| `tour-platform-mvp` | Order, session and 360 tour MVP | milestone | done | high | Manage assignments, capture sessions, media and private/public tours | `ROADMAP.md` P0/P1; `web/www/order.php`; tour APIs/templates | 0.88 |
| `android-capture-upload` | Durable Android capture and upload foundation | milestone | done | critical | Capture Insta360/phone media, persist local state and upload against order/session identity | camera providers, Room entities/DAOs, repositories, `AppStateViewModel.kt`, `MobileUploadApi.kt` | 0.92 |
| `auto-photo-sfm` | Automatic photo capture to Photo SfM/viewer | deliverable | in-progress | high | Turn guided automatic JPEG sessions into sparse/dense preview artifacts | `AUTO-PHOTO-EPIC.md`; B01-B06 result docs; `auto_photo_*` libs/tests | 0.86 |
| `server-processing-orchestration` | Server storage and GrafikStation job orchestration | milestone | done | critical | Own paths/state, launch remote jobs and publish validated artifacts | `sfm_remote_worker.php`, `sfm_pipeline.php`, station runners, cleanup/status APIs | 0.91 |
| `single-sfm-baseline` | SINGLE reference sparse/dense reconstruction | milestone | done | critical | Produce reproducible visual reconstruction from standalone phone video | `SFM-S01`; `process_extract_frames.sh`; `process_colmap_sparse.sh`; recorded job audits | 0.94 |
| `single-connectivity-drift` | SINGLE visual connectivity and drift experiments | experiment | in-progress | critical | Determine whether bounded non-local visual edges resolve fragmentation without exhaustive cost | visual loop A/B audit; Hybrid v1 result; Hybrid v2 scripts/audit | 0.90 |
| `tof-imu-measurement` | Synchronized IMU/ToF evidence and metric diagnostics | research | done | high | Capture, align and validate sensor observations without mutating RGB geometry | ToF LM03 roadmap through 5C; IMU/ToF audits; S01H results | 0.93 |
| `single-sensor-constraints` | Active IMU pose prior and ToF metric constraints | experiment | planned | critical | Use physical priors to reduce drift and establish metric geometry | `SINGLE_SENSOR_FUSION_ROADMAP.md`; audits show current mutation/BA absent | 0.89 |
| `capture-topology-unification` | Unify SINGLE, dual-phone, USB rig and laptop contracts | milestone | in-progress | critical | Give all topologies explicit identities, profiles, timelines and server-compatible packages | recovery plan; Android capture architecture audit; mode model/contracts | 0.91 |
| `dual-phone-capture` | Dual-phone synchronized recorded capture | deliverable | in-progress | high | Capture two role packages with shared identity, timing, calibration and accepted upload | dual-phone roadmap; control/clock/recorder code; current PHP mismatch | 0.95 |
| `usb-stereo-capture` | Phone + USB calibrated synced capture | deliverable | in-progress | high | Produce valid calibrated stereo pairs/bundles with operator preflight | UVC native code, stereo capture, F02 tasks; build/device acceptance pending | 0.88 |
| `stereo-global-fusion` | Metric stereo trajectory and initial global cloud | milestone | in-progress | high | Convert pair-local depth into a globally aligned metric model | F01A/B/C tasks; dense/odometry/fusion scripts; runtime acceptance pending | 0.95 |
| `sfm-component-assembly` | Multi-component review, alignment and assembly | milestone | in-progress | high | Review, bridge, align, merge and publish disconnected reconstruction components | workbench/manual-align/run-lineage tasks, UI and tests | 0.86 |
| `metric-textured-model` | Globally consistent metric mesh with textures | deliverable | planned | critical | Produce the primary room-scale 3D product | stereo current status/roadmap, P4 roadmap | 0.93 |
| `photorealistic-viewer` | Dollhouse / floorplan / splat delivery | milestone | backlog | medium | Deliver navigable spatial and photorealistic presentation modes | `ROADMAP.md` P4/P6 | 0.78 |

## Notes on granularity

Firmware, calibration solvers, upload endpoints and individual diagnostic scripts are evidence within the relevant engineering stage, not separate WorkItems. The workbench is separate because it is an operator-facing recovery/assembly capability with its own dependency and acceptance chain.
