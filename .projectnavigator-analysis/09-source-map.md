# Source map and document routing

## Runtime/source topology

| Area | Primary sources | Responsibility |
|---|---|---|
| Android composition/UI | `app/MaklerTour/app/src/main/java/com/example/maklertour/MainActivity.kt`, `ui/`, `state/AppStateViewModel.kt` | Lifecycle, navigation, operator workflows, orchestration |
| Android camera/capture | `data/camera/`, `data/phonecamera/`, `data/capture/` | Insta360 OSC, CameraX video/photo, IMU/ToF sidecars, bundles |
| Dual/USB stereo | `data/dualphone/`, `data/calibration/`, `cam1_uvc.cpp`, stereo UI | Roles/control/clock, UVC, calibration, paired capture/live transport |
| Android persistence/network | `com/maklertour/data/local/`, repositories, `auth/Mobile*Api.kt` | Room state, queue, server APIs |
| ToF firmware | `app/rp2040_zero_vl53l8cx_3x/src/` | VL53L8CX acquisition, TOF_FRAME_V1 and clock sync |
| Web business/UI | `web/www/`, `web/templates/`, `web/libs/` | Orders, sessions, media, job UI, viewers |
| Mobile API/storage | `web/www/api/mobile.php` | Authenticated session/media/bundle receipt and storage |
| Job orchestration | `web/tools/sfm_remote_worker.php`, `web/remote_station/sfm_pipeline.php` | Queue/state machine, remote launch, artifact publication |
| GrafikStation processing | `web/remote_station/scripts/`, runners | Extraction, COLMAP sparse/dense/mesh, stereo depth/VO/fusion, diagnostics |
| Assembly/viewers | workbench/manual-align PHP/UI, `sfm_*viewer.php`, order template | Component review, merge, visualization |
| Tests | `web/tests/`, Android `src/test` and `src/androidTest` | Mostly contract/unit/static checks plus selected algorithm tests |

## Knowledge routing for future WorkItem workspaces

| WorkItem | Category | Suggested document | Existing source material |
|---|---|---|---|
| `android-capture-upload` | requirements | `requirements/capture-upload-contract.md` | requirements, contracts, architecture audit |
| `android-capture-upload` | implementation | `implementation/android-capture-map.md` | MainActivity/ViewModel/providers/Room/API |
| `auto-photo-sfm` | plans | `plans/auto-photo-stage-chain.md` | epic and B01-B06 tasks |
| `auto-photo-sfm` | worklog | `worklog/auto-photo-results.md` | B01-B06 result documents |
| `server-processing-orchestration` | implementation | `implementation/job-state-and-artifacts.md` | worker, pipeline, station README/contracts |
| `single-sfm-baseline` | decisions | `decisions/single-reference-pipeline.md` | recovery plan, current-state and S01 docs |
| `single-connectivity-drift` | research | `research/visual-connectivity-experiments.md` | loop closure A/B, Hybrid docs/scripts |
| `tof-imu-measurement` | research | `research/sensor-evidence-status.md` | IMU/ToF audits, S01H results, LM03 roadmap |
| `single-sensor-constraints` | plans | `plans/physical-priors-experiment.md` | SINGLE sensor fusion roadmap/audits |
| `capture-topology-unification` | decisions | `decisions/capture-topology-contract.md` | recovery plan, Android architecture audit, mode model |
| `dual-phone-capture` | requirements | `requirements/dual-phone-package.md` | dual roadmap, DP tasks, current PHP contract |
| `usb-stereo-capture` | requirements | `requirements/usb-stereo-capture.md` | stereo contract, F02 tasks |
| `stereo-global-fusion` | implementation | `implementation/stereo-f01-pipeline.md` | F01A/B/C docs and station scripts |
| `stereo-global-fusion` | worklog | `worklog/stereo-runtime-acceptance.md` | runtime gate and future evidence |
| `sfm-component-assembly` | plans | `plans/component-assembly.md` | workbench/manual align/post-workbench tasks |
| `metric-textured-model` | requirements | `requirements/metric-model-acceptance.md` | stereo status/roadmap, P4 |
| `photorealistic-viewer` | notes | `notes/viewer-options.md` | ROADMAP P4/P6 |

## Documentation precedence

1. Reproducible runtime/artifact evidence.
2. Current executable code and actual schema/config.
3. Newer task result/current-status documents.
4. Contracts and task plans.
5. Legacy roadmap/checklists.

## Excluded as authoritative source

`*.before_*`, `*.bak*`, `.gradle/`, `.idea/`, `.cxx/`, build output, generated Smarty templates, vendored libraries, debug dumps and the COLMAP source checkout were not used as current MaklerTour behavior. They remain relevant to repository hygiene/provenance only.

## Self-review outcome

1. **File-shaped WorkItems:** none. Each item spans a deliverable, milestone or controlled research question.
2. **Dependencies without basis:** none. Every edge is labelled confirmed/inferred; inferred edges include rationale.
3. **Unsupported DONE:** reviewed. `done` is limited to scoped foundations with executable and recorded evidence; it does not imply final product quality.
4. **Cycles:** machine check passed; the 15-node/19-edge graph is acyclic.
5. **Lost incomplete work:** dual upload, missing ToF sidecars, physical constraints, Hybrid v2, stereo runtime acceptance, assembly and texturing are represented.
6. **Contradictions:** legacy roadmap, F01C status and Hybrid v2 mismatch are explicitly recorded.
7. **Actionability:** the development plan provides parallel next gates with blockers, dependencies, completion criteria and affected subsystems.

Machine review also confirmed that source paths exist, temporary-key references resolve, enums are allowed and no UUIDs were generated.
