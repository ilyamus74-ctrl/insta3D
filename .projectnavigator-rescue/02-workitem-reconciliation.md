# WorkItem reconciliation

Each section starts with the authoritative user WorkItem ID. Archaeology mapping uses meaning, implementation ownership and evidence paths—not title similarity alone.

## `8801e3ab-2953-4113-b260-4cdb17238425` — Order, session and 360 tour MVP

**Recommended status: DONE (scoped). Archaeology:** `tour-platform-mvp`. The mapping is confirmed by shared responsibility for order creation, capture session, uploaded panorama media, private/public tour rendering and the PHP/Smarty paths cited by archaeology.

- **Implemented:** order/session persistence, media association, tour management, private/public presentation, X4 panorama/photo-video ingestion within the existing workflow.
- **Partial:** operational polish and newer reconstruction products remain outside this scoped MVP.
- **Missing:** no missing element was found that invalidates the recorded MVP boundary.
- **Unverified:** current fresh-deployment smoke test was not run in this reconciliation.
- **Sources:** `web/www/order.php`, `web/www/api/tour_session.php`, `web/templates/maklertour_tour.html`, `web/www/public_tour.php`, `db/create_database.sql`.
- **Docs:** `docs/llm/00_PROJECT_OVERVIEW.md`, `ROADMAP.md`, `DOC/PHONE_SCAN_MVP_STATUS.md`.
- **Issues:** `documentation-drift`, `repository-provenance-hygiene` are project hygiene, not evidence that the scoped MVP fails.

## `4dcc4e53-cc41-4b0a-bb3a-ca2e47cca4d3` — APP Android capture and upload foundation

**Recommended status: IN-PROGRESS. Archaeology:** `android-capture-upload` + `capture-topology-unification`. The former maps the ordinary Android recording/upload lifecycle; the latter maps the unresolved common envelope across SINGLE, dual, USB and laptop-live modes.

- **Implemented:** Android order/session entry, camera capture, local artifacts, foreground upload/service paths, IMU capture on relevant paths, server upload for ordinary/SINGLE packages.
- **Partial:** mode-specific payloads diverge; some telemetry is saved only in selected modes; server routing is not unified.
- **Missing:** canonical versioned envelope covering identity, mode, roles, clocks, calibration, media, IMU, ToF, integrity and lifecycle.
- **Broken:** aggregate dual-phone `capture_type=dual_phone_stereo_video` is rejected by the current PHP whitelist.
- **Unverified:** every mode against one server-side fixture/contract suite.
- **Sources:** `app/MaklerTour/app/src/main/java/com/example/maklertour/MainActivity.kt`, `app/MaklerTour/app/src/main/java/com/example/maklertour/state/AppStateViewModel.kt`, `app/MaklerTour/app/src/main/java/com/example/maklertour/auth/MobileUploadApi.kt`, `app/MaklerTour/app/src/main/java/com/maklertour/data/local/AppDatabase.kt`, `web/www/api/mobile.php`.
- **Docs:** `docs/ANDROID_CAPTURE_ARCHITECTURE_AUDIT.md`, `docs/CAPTURE_ARCHITECTURE_RECOVERY_PLAN.md`, `web/DOCS/CAPTURE_BUNDLE_DENSE_CONTRACT.md`.
- **Issues:** `dual-capture-type-mismatch`, `sensor-sidecar-gaps`, `mainactivity-coupling`.

## `da6e553d-a4ec-40c5-a431-b03941393d29` — APP SINGLE baseline

**Recommended status: IN-PROGRESS. Archaeology:** `single-sfm-baseline`, `single-connectivity-drift`, `tof-imu-measurement`, `single-sensor-constraints`. These entities collectively cover capture through sparse reconstruction, the measured connectivity/drift result, passive diagnostics, and the missing active-prior experiment.

- **Implemented:** SINGLE video + IMU + ToF artifacts, upload/acceptance, visual sequential/exhaustive/hybrid sparse experiments, telemetry diagnostics.
- **Partial:** hybrid v1 improves connectivity (440 registered, 3 components) but does not close physical trajectory drift.
- **Missing:** active IMU pose/gravity prior and/or ToF metric constraint with an A/B result; accepted metric model.
- **Broken:** Hybrid v2 documentation and runner disagree about loop detection; its audit is NOT_RUN.
- **Unverified:** Hybrid v2 result, sensor-prior improvement, metric accuracy.
- **Sources:** `app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/PhoneCameraScanProvider.kt`, `web/www/api/mobile.php`, `web/remote_station/scripts/process_colmap_sparse.sh`, `web/remote_station/single_hybrid_v2/run.sh`, `web/remote_station/scripts/build_world_alignment.py`.
- **Docs:** `docs/SINGLE_HYBRID_LOOP_RESULT.md`, `docs/SINGLE_VISUAL_LOOP_CLOSURE_AB_AUDIT.md`, `docs/SINGLE_IMU_PARTICIPATION_AUDIT.md`, `docs/SINGLE_TOF_PARTICIPATION_AUDIT.md`, `docs/SINGLE_HYBRID_V2_AUDIT.md`.
- **Issues:** `imu-not-active-prior`, `tof-measurement-only`, `hybrid-v2-contract-conflict`.

## `2498f509-0f87-41e1-9eba-fe0684511263` — APP Automatic photo capture

**Recommended status: IN-PROGRESS. Archaeology:** `auto-photo-sfm`, plus telemetry questions from `tof-imu-measurement`. Mapping is based on B01–B06 Android capture, dedicated upload endpoint, job dispatch and Photo SfM/viewer artifacts.

- **Implemented:** automatic high-resolution photo session, manifest/upload endpoint, server registration, SfM job stages and result viewer path.
- **Partial:** IMU snapshots/metadata exist on parts of the path; result production is implemented but current device-to-result acceptance is not comprehensively recorded.
- **Missing:** complete ToF sidecars and active IMU/ToF use toward the user’s metric-model objective.
- **Unverified:** repeatable physical-device capture → upload → reconstruction → viewer run and metric accuracy.
- **Sources:** `app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/AutoPhotoCaptureManager.kt`, `web/libs/auto_photo_bundle_lib.php`, `web/libs/auto_photo_sparse_lib.php`, `web/tools/sfm_remote_worker.php`, `web/www/sfm_viewer.php`.
- **Docs:** `docs/llm/tasks/AUTO-PHOTO-EPIC.md`, `docs/llm/tasks/results/`.
- **Issues:** `sensor-sidecar-gaps`, `imu-not-active-prior`, `tof-measurement-only`.

## `72378a61-7092-43c3-9671-4b365d929265` — APP MASTER + SLAVE

**Recommended status: IN-PROGRESS. Archaeology:** `dual-phone-capture` + `capture-topology-unification`. The shared implementation is BLE role/control, clock mapping, dual recording and aggregate upload—not merely similarly named stereo work.

- **Implemented:** MASTER/SLAVE roles, BLE control/clock samples, headless dual recording, per-phone video/IMU artifacts, DP04.2 aggregate uploader source.
- **Partial:** synchronized role bundle exists conceptually and in source, but acceptance/processing is incomplete.
- **Missing:** ToF sidecars for this mode, accepted dual package, downstream use of both camera roles and clocks, global fused output.
- **Broken:** PHP rejects the aggregate dual capture type.
- **Unverified:** DP04.2 runtime upload acceptance and final model.
- **Sources:** `app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneControlManager.kt`, `app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneCaptureRuntime.kt`, `app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneBundleTransfer.kt`, `web/www/api/mobile.php`.
- **Docs:** `docs/llm/tasks/APP-DUAL-PHONE-STEREO-ROADMAP.md`, `docs/ANDROID_CAPTURE_ARCHITECTURE_AUDIT.md`.
- **Issues:** `dual-capture-type-mismatch`, `sensor-sidecar-gaps`, `stereo-runtime-pending`.

## `a94d2800-5bdf-4dea-83d9-cc05e8ac5152` — APP 2 Android phones + notebook/PC

**Recommended status: IN-PROGRESS. Archaeology:** host/live portions of `dual-phone-capture`, `capture-topology-unification`, and `stereo-global-fusion`. The mapping follows the laptop host, reduced JPEG/IMU/ToF packet transport and intended two-camera reconstruction path.

- **Implemented:** independent phone live stream clients, notebook host, reduced JPEG plus telemetry packets.
- **Partial:** two inputs can reach a host; this is separate from the MASTER/SLAVE recorded bundle and from server upload.
- **Missing:** shared session/clock authority, calibration-preserving pair manifest, durable upload/acceptance, global optimized metric reconstruction.
- **Unverified:** end-to-end two-phone notebook reconstruction and output accuracy.
- **Sources:** `app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneLiveStreamController.kt`, `app/MaklerTour/app/src/main/java/com/example/maklertour/data/dualphone/DualPhoneLiveStreamSessionCoordinator.kt`, `app/MaklerTour/docs/APP_DUAL_PHONE_LM02_7B_1_LAPTOP_HOST_CONTRACT.md`, `app/MaklerTour/pipeline_93/`.
- **Docs:** `docs/ANDROID_CAPTURE_ARCHITECTURE_AUDIT.md`, `docs/CAPTURE_ARCHITECTURE_RECOVERY_PLAN.md`.
- **Issues:** `sensor-sidecar-gaps`, `stereo-runtime-pending`.

## `4c7e40a0-5f6a-4563-b889-27e1f3286010` — APP Android + USB camera

**Recommended status: IN-PROGRESS. Archaeology:** `usb-stereo-capture`, `stereo-global-fusion`, `capture-topology-unification`. Implementation evidence ties UVC camera roles and calibration to GrafikStation stereo pair-cloud/odometry/fusion work.

- **Implemented:** UVC detection/preview, phone+USB role assignment, calibration artifacts, synchronized-pair recording source, stereo preflight/operator diagnostics, pair-cloud/ORB/global-fusion stages in tooling.
- **Partial:** initial global accumulation exists; complete fusion/optimization is not established.
- **Missing:** saved ToF sidecars, device/build acceptance for the latest preflight path, globally optimized metric cloud, accepted mesh/texturing.
- **Unverified:** F02 physical-device acceptance and graphics-station runtime acceptance.
- **Sources:** `app/MaklerTour/app/src/main/cpp/cam1_uvc.cpp`, `app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/StereoCaptureExperimental.kt`, `app/MaklerTour/tools/stereo_contract_audit.py`, `web/remote_station/scripts/process_maklertour_synced_dense.sh`, `web/remote_station/scripts/stereo_global_fusion.py`.
- **Docs:** `docs/llm/tasks/APP-STEREO-F01-GLOBAL-STEREO-DEPTH-FUSION.md`, `docs/llm/tasks/APP-STEREO-F02-A-ANDROID-CAPTURE-BUNDLE-PREFLIGHT.md`, `docs/ANDROID_CAPTURE_ARCHITECTURE_AUDIT.md`.
- **Issues:** `sensor-sidecar-gaps`, `stereo-runtime-pending`.

## `19dfb29a-4d50-4fb5-8848-39af4e78eb5d` — Insta360 capture

**Recommended status: DONE for stated acquisition/tour use. Archaeology:** no dedicated WorkItem; merge the Insta360-specific evidence under `android-capture-upload` and `tour-platform-mvp`. This mapping is based on camera integration and the same tour media lifecycle, not title similarity.

- **Implemented:** Insta360 X4 connection/control and photo/video material entering the tour workflow.
- **Partial:** use as an SfM/metric sensor is not established and is not included in the DONE boundary.
- **Missing:** if metric reconstruction is intended, calibration, telemetry alignment and processing acceptance need a separate criterion inside existing metric/server tiles.
- **Unverified:** current hardware regression run.
- **Sources:** `app/MaklerTour/app/src/main/java/com/example/maklertour/data/camera/Insta360Provider.kt`, `app/Insta360/makler_tour/`, `web/templates/maklertour_tour.html`.
- **Docs:** `docs/llm/00_PROJECT_OVERVIEW.md`, `ROADMAP.md`.
- **Issues:** `documentation-drift`.

## `c7ba832d-1307-475d-b543-59ac5c17ea6c` — Server storage and GrafikStation orchestration

**Recommended status: IN-PROGRESS. Archaeology:** `server-processing-orchestration`, server portions of `auto-photo-sfm`, `capture-topology-unification`, and assembly orchestration from `sfm-component-assembly`. Evidence is the upload validators, processing queue/worker, remote runners and result registration.

- **Implemented:** storage, processing job lifecycle, token-authenticated remote claim/heartbeat/finish, dispatch for frame extraction, sparse/dense, PLY, mesh and synced dense jobs.
- **Partial:** multiple capture-specific endpoints/contracts; telemetry is retained/diagnosed on some paths.
- **Missing:** accepted canonical dual package, schema/fixture conformance for every mode, explicit processing expectations and sensor-consumption reporting.
- **Broken:** dual aggregate capture type mismatch.
- **Unverified:** full orchestration acceptance for Hybrid v2, latest stereo fusion and all sensor modes.
- **Sources:** `web/www/api/mobile.php`, `web/libs/auto_photo_bundle_lib.php`, `web/tools/sfm_remote_worker.php`, `web/remote_station/sfm_pipeline.php`, `web/remote_station/sfm_cleanup.php`, `db/create_database.sql`.
- **Docs:** `web/remote_station/README.md`, `web/DOCS/CAPTURE_BUNDLE_DENSE_CONTRACT.md`, `docs/ANDROID_CAPTURE_ARCHITECTURE_AUDIT.md`.
- **Issues:** `dual-capture-type-mismatch`, `sensor-sidecar-gaps`, `hybrid-v2-contract-conflict`.

## `fa24df7a-87d4-4606-b4c8-3ce87eed24a1` — GrafikStation processing worker

**Recommended status: IN-PROGRESS. Archaeology:** processing parts of `server-processing-orchestration`, `single-sfm-baseline`, `tof-imu-measurement`, `stereo-global-fusion`, and `sfm-component-assembly`. The mapping follows actual remote scripts and artifacts.

- **Implemented:** COLMAP sparse/dense/mesh runners, synchronized stereo dense runner, model statistics, trajectory and sensor diagnostics, result upload/finish protocol.
- **Partial:** component analysis/assembly and initial stereo global fusion exist; telemetry is mostly diagnostic.
- **Missing:** active IMU/ToF constraints, accepted stereo global optimization/loop handling, one repeatable globally metric textured result.
- **Broken:** Hybrid v2 loop-setting contract conflict.
- **Unverified:** current isolated Hybrid v2 and stereo-runtime acceptance.
- **Sources:** `web/remote_station/scripts/process_colmap_sparse.sh`, `web/remote_station/scripts/process_colmap_dense.sh`, `web/remote_station/scripts/process_colmap_mesh.sh`, `web/remote_station/scripts/process_maklertour_synced_dense.sh`, `web/remote_station/single_hybrid_v2/run.sh`, `web/remote_station/scripts/stereo_global_fusion.py`.
- **Docs:** `web/remote_station/README.md`, `docs/SINGLE_IMU_PARTICIPATION_AUDIT.md`, `docs/SINGLE_TOF_PARTICIPATION_AUDIT.md`, `docs/llm/tasks/APP-STEREO-F01-GLOBAL-STEREO-DEPTH-FUSION.md`.
- **Issues:** `imu-not-active-prior`, `tof-measurement-only`, `hybrid-v2-contract-conflict`, `stereo-runtime-pending`.

## `0b06374d-ccc3-4261-a376-676807e8ac12` — Globally consistent metric mesh with textures

**Recommended status: PLANNED. Archaeology:** `metric-textured-model`; its real predecessors are the unfinished outcomes of `single-sensor-constraints`, `stereo-global-fusion`, and `sfm-component-assembly`. This is an output-quality tile, not a synonym for “COLMAP_MESH ran.”

- **Implemented:** provisional sparse/dense/PLY/mesh artifacts and metric diagnostics on limited paths.
- **Partial:** visual connectivity and component assembly experiments; initial stereo fusion.
- **Missing:** globally consistent optimized trajectory/geometry, active metric constraints, accepted measurement thresholds, texture completeness and reproducible artifact publication.
- **Unverified:** any final artifact against physical ground truth.
- **Sources:** `web/remote_station/scripts/process_colmap_dense.sh`, `web/remote_station/scripts/process_colmap_mesh.sh`, `web/remote_station/scripts/process_maklertour_synced_dense.sh`, `web/remote_station/scripts/stereo_global_fusion.py`.
- **Docs:** `docs/SINGLE_VISUAL_LOOP_CLOSURE_AB_AUDIT.md`, `docs/SINGLE_TOF_PARTICIPATION_AUDIT.md`, `docs/llm/tasks/APP-STEREO-F01-GLOBAL-STEREO-DEPTH-FUSION.md`.
- **Issues:** `imu-not-active-prior`, `tof-measurement-only`, `stereo-runtime-pending`.

## `633331e3-586d-4e7c-976f-e2c18e6c966c` — Dollhouse / floorplan / photorealistic viewer

**Recommended status: IN-PROGRESS. Archaeology:** `photorealistic-viewer` for the intended deliverable, with existing viewer slices from `tour-platform-mvp`, `auto-photo-sfm`, and `sfm-component-assembly`.

- **Implemented:** 360 tour rendering and basic sparse/dense/SfM result viewers.
- **Partial:** model artifact presentation exists but is not the requested dollhouse/floorplan/photorealistic experience.
- **Missing:** floorplan extraction, dollhouse navigation, production photorealistic textured model pipeline, UX/performance acceptance.
- **Unverified:** browser/device matrix and complete-model visual quality.
- **Sources:** `web/templates/maklertour_tour.html`, `web/www/public_tour.php`, `web/www/sfm_viewer.php`, `web/www/sfm_manual_align.php`.
- **Docs:** `docs/llm/00_PROJECT_OVERVIEW.md`, `DOC/PHONE_SCAN_MVP_STATUS.md`.
- **Issues:** upstream metric/assembly issues; no separate archaeology viewer defect was recorded.
