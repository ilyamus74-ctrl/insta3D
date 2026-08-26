# SINGLE COLMAP Execution and Fusion Entrypoints Audit

## Status and scope

```text
BASELINE: 0d36171
AUDIT: STATIC CODE AUDIT COMPLETE
RUNTIME / COLMAP RECONSTRUCTION: NOT RUN
SOURCE CHANGES: NONE
```

Отчёт фиксирует фактический server-side execution path SINGLE video reconstruction и безопасные точки расширения. Выводы о job execution подтверждены исходным кодом, но не runtime-логами `job_180237696`.

## Executive conclusion

SINGLE запускает stock-style visual COLMAP chain:

```text
capture/upload
  -> sfm_pipeline_run + EXTRACT_FRAMES
  -> selected frames/frame_*.jpg
  -> COLMAP_SPARSE
       feature_extractor
       sequential_matcher | exhaustive_matcher
       mapper (includes visual local/global BA)
  -> sparse/<model_id>/{cameras,images,points3D}.bin
  -> COLMAP_RECONSTRUCTION_PREVIEW/HQ
  -> COLMAP_DENSE_CHUNK(s)
       image_undistorter -> patch_match_stereo -> stereo_fusion
  -> merged_fused.ply -> COLMAP_MESH
```

IMU не входит в mapper/BA: его optimization-влияние заканчивается на выборе JPEG, а после mapper он только диагностический. ToF observations также не передаются COLMAP; скрипт оценки scale явно оставляет geometry mutation OFF.

Без изменения COLMAP source можно реализовать visual loop-closure matching, post-reconstruction global Sim3 scale, gravity-based coordinate-frame alignment, validation gates и повторный stock `bundle_adjuster`. Полноценные per-frame IMU orientation/gyro residuals в BA не покрываются текущим CLI position-prior contract и потребуют custom optimization layer либо COLMAP extension.

## 1. Job creation and execution path

| Stage | Job creation / execution | Inputs | Outputs |
|---|---|---|---|
| Pipeline root | `web/www/order.php`, `start_sfm_pipeline_run()` | capture session, `video_scan_id`, effective settings | row in `sfm_pipeline_runs`; root `EXTRACT_FRAMES` row; `root_remote_job_id` |
| Extract | `web/tools/sfm_remote_worker.php`, `launch_job()` -> `run_extract_frames_job.sh` -> `process_extract_frames.sh` | resolved video and Camera/IMU/ToF sidecars | `output/job_<extract>/frames/frame_*.jpg`, quality/timeline/sensor artifacts |
| Sparse enqueue | `web/tools/sfm_remote_worker.php`, `auto_chain_after_done()`, `EXTRACT_FRAMES` branch | completed extract job | child `COLMAP_SPARSE` with `parent_remote_job_id=<extract remote id>` |
| Sparse launch | `launch_job()`, `COLMAP_SPARSE` branch -> `run_colmap_sparse_job.sh` -> `process_colmap_sparse.sh` | exact parent frames directory, sparse settings | database, logs, one or more sparse models |
| Dense parent | `auto_chain_after_done()`, `COLMAP_SPARSE` branch | selected best/useful sparse model | child `COLMAP_RECONSTRUCTION_PREVIEW`/`HQ`, parent is sparse remote id |
| Dense planning | `launch_job()`, reconstruction branch -> `run_colmap_chunk_plan_job.sh` | sparse job/model and dense settings | `chunk_plan.json`, image lists |
| Dense chunks | worker monitor/enqueue block -> `run_colmap_dense_chunk_job.sh` -> `process_colmap_dense_chunk.sh` | sparse model plus per-chunk image list | chunk `fused.ply`; later merged to `merged/merged_fused.ply` |
| Mesh | `auto_chain_after_done()`, reconstruction branch | merged dense PLY | child `COLMAP_MESH` and final mesh artifacts |

`web/tools/sfm_pipeline_cli.php` provides a second root entry point for operational/CLI use, but normal web SINGLE creation is `start_sfm_pipeline_run()`.

## 2. Exact COLMAP calls

All sparse calls are in `web/remote_station/scripts/process_colmap_sparse.sh`:

| Command | Block | Effective role |
|---|---|---|
| `feature_extractor` | `FEATURE_ARGS` after status `COLMAP feature extraction` | Creates/updates `database.db`; reads only selected JPEGs and optional camera model/params |
| `sequential_matcher` | `case "$COLMAP_MATCHER"`, sequential branch | Temporal-neighbour matching with configured overlap; loop detection is a boolean setting and defaults safely to off |
| `exhaustive_matcher` | same case, exhaustive branch | All-pairs visual matching alternative |
| `mapper` | status `COLMAP mapper` | Incremental visual reconstruction; writes sparse model directories |
| `model_converter ... TXT` | `generate_sparse_components()` | Diagnostic/export text copy when mapper output is BIN |
| `model_converter ... BIN` | AprilTag metric alignment branch | Re-serializes an applied aligned TXT model as BIN |

There is **no separate production call** to `colmap bundle_adjuster`. The ordinary `mapper` performs its own local/global visual bundle adjustment internally (`src/colmap/sfm/incremental_mapper.cc`). No pipeline BA option, sensor residual, pose-prior weight or standalone re-BA step is passed.

Dense chunk processing in `process_colmap_dense_chunk.sh` runs `image_undistorter`, `patch_match_stereo`, and `stereo_fusion`. `process_colmap_dense.sh` contains the same non-chunked COLMAP stages, while the official pipeline path is currently planned/chunked by the worker.

## 3. Model artifact creation

| Artifact | Creator | Location / note |
|---|---|---|
| `database.db` | `feature_extractor`, then matcher updates it | `output/job_<sparse>/colmap/database.db` |
| `cameras.bin` | `mapper` serialization | `.../colmap/sparse/<model_id>/cameras.bin` |
| `images.bin` | `mapper` serialization | same model directory |
| `points3D.bin` | `mapper` serialization | same model directory |
| `cameras.txt`, `images.txt`, `points3D.txt` | `model_converter --output_type TXT` | `.../sparse/<model_id>/txt/`; an applied AprilTag branch may temporarily/finally use TXT before BIN conversion |
| sparse PLY | export/UI artifact path | `.../sparse/<model_id>/model.ply`; export is separate from the mapper's native model triplet |
| dense PLY | `stereo_fusion`, then merge | per-chunk `fused.ply`, final `merged/merged_fused.ply` |

The shell script does not explicitly create the three `.bin` files: their existence is the success contract of `mapper`. Dense accepts a complete BIN or TXT triplet.

## 4. IDs and parent-child lineage

- `sfm_pipeline_runs.id` is the logical pipeline run ID. It groups official jobs through `sfm_remote_jobs.pipeline_run_id`.
- `sfm_remote_jobs.id` is the database primary key and is not the station job ID.
- `sfm_remote_jobs.remote_job_id` is generated by `sfm_job_id()` as an unused random integer and names station directories `output/job_<remote_job_id>`.
- `sfm_pipeline_runs.root_remote_job_id` points to the root extract remote ID.
- Sparse parent: `COLMAP_SPARSE.parent_remote_job_id = EXTRACT_FRAMES.remote_job_id`.
- Dense reconstruction parent: `COLMAP_RECONSTRUCTION_*.parent_remote_job_id = COLMAP_SPARSE.remote_job_id`; parameters repeat `sparse_job_id`/`sparse_remote_job_id` and `model_id`.
- Dense chunk parent: `COLMAP_DENSE_CHUNK.parent_remote_job_id = reconstruction parent remote_job_id`; parameters retain sparse/model identity.
- Mesh parent: `COLMAP_MESH.parent_remote_job_id = reconstruction remote_job_id`.

Thus `parent_remote_job_id` always references another row's **remote** ID, not its DB `id`. Duplicate guards and worker queries follow that convention.

## 5. Frame selection hand-off

`select_quality_frames.py` extracts candidates, selects rows by visual quality/time coverage with optional IMU motion penalty/rejection, then copies them in chronological selected order to:

```text
output/job_<extract>/frames/frame_000001.jpg
...
```

`frames_path_for_parent()` returns exactly that directory. `run_colmap_sparse_job.sh` forwards it as `FRAMES_DIR`, and `process_colmap_sparse.sh` rejects an empty directory before passing the entire directory to `feature_extractor` and `mapper`. No image-list filter is used at sparse time: copying into `frames/` is the selection boundary. `quality/selected_frames.json` preserves candidate/video timestamps but COLMAP does not read it.

## 6. Camera data passed to COLMAP

`process_colmap_sparse.sh` locates normalized `camera_metadata.json`, derived during extract from `camera_info.json`/`manifest.json`.

| Data | Passed to COLMAP? | Mechanism |
|---|---:|---|
| selected frame dimensions | Yes | JPEG geometry; also validates/adapts calibrated parameters |
| one physical phone camera identity | Yes | `--ImageReader.single_camera 1` for `capture_source=PHONE_CAMERA` |
| calibrated model and intrinsics/distortion | Conditional | `colmap_camera_prior.usable_for_colmap`, supported model, `--ImageReader.camera_model`, `--ImageReader.camera_params` |
| focal length/profile | Conditional | only through validated `colmap_camera_prior.params`; focal metadata alone is logged, not blindly injected |
| principal point/distortion | Conditional | same verified profile and resolution/rotation adaptation |
| focus mode/lock | Indirect only | used to identify/log capture contract; no COLMAP focus parameter exists |
| fps/timeline | No optimizer input | recorded/logged; COLMAP sees filenames and image content |
| display/video rotation | Conditional geometry adaptation | calibrated params are scaled or 90/270-degree adapted to the actual extracted frame; unsafe mismatch rejects prior injection |

If no verified prior exists, SINGLE still shares one camera, but COLMAP initializes/refines intrinsics visually. Automatic fisheye model choice exists behind `COLMAP_CAMERA_MODEL_AUTO_FROM_METADATA` and does not invent calibrated numeric params.

## 7. Existing extension infrastructure

| Capability | Present in repository | Used by current SINGLE chain |
|---|---:|---:|
| COLMAP position pose priors | Yes: DB pose-prior API and `pose_prior_mapper` | No |
| image gravity metadata | Yes: COLMAP pose-prior gravity field/EXIF orientation support | No IMU injection; JPEG orientation may be read independently |
| rotation averaging with gravity | Yes: `rotation_averager --use_gravity` in local COLMAP source | No |
| standalone `bundle_adjuster` and BA options | Yes | No explicit call; mapper internal visual BA only |
| `model_aligner` / geo-registration | Yes in COLMAP | No production SINGLE call |
| `model_transformer` / Sim3 | Yes in COLMAP | No production SINGLE call |
| IMU world alignment report | `build_world_alignment.py` | Yes, but identity/`UNALIGNED`; does not mutate geometry |
| ToF scale estimator | `measure_tof_sparse_scale.py` and H2.x diagnostic scripts | Metric/diagnostic tooling exists; not an automatic COLMAP mutation step |
| metric model transform | AprilTag `apply_apriltag_metric_alignment.py` | Conditional AprilTag path; demonstrates TXT/Sim3/BIN replacement pattern, not ToF fusion |
| post-sparse diagnostics | trajectory, components, IMU mismatch, ToF tools | Yes/available, no automatic re-optimization |
| custom COLMAP source patches for fusion | No fusion patch found | No |

The nested `web/tools/colmap_src` checkout is locally dirty only in `cmake/FindDependencies.cmake` with an untracked backup; this is build/dependency material, not a sensor, mapper or optimizer patch. Production can run a native binary or a configured container image, so repository source capability must not be treated as proof that the deployed binary has the same version/features. Feature probing/version pinning is required before relying on `pose_prior_mapper`, gravity fields or PyCOLMAP APIs.

## 8. Fusion entry points

### A. IMU orientation / gravity prior

| Item | Finding |
|---|---|
| File / block | `process_colmap_sparse.sh`, immediately after camera-prior adaptation and before `feature_extractor`; alternatively after `mapper` before diagnostics for world-frame-only alignment |
| Current role | Builds visual DB/model; IMU is first located only after mapper for diagnostics |
| Safe addition | Produce a versioned per-selected-image prior artifact from `selected_frames.json` + IMU; calibrate device-to-camera axes; validate time sync; write supported gravity/pose-prior records into a copied/new COLMAP DB; select an explicitly supported stock mapper mode behind a feature flag. For presentation-only Z-up, apply a rigid global rotation to a derived model without changing relative poses. |
| Risks | Android sensor frame, display rotation, CameraX crop and COLMAP camera frame can be confused; gyro bias/time offset makes hard priors harmful; stock `pose_prior_mapper` constrains **position**, not a general per-frame orientation trajectory; deployed COLMAP compatibility is unproven; a global gravity rotation does not reduce relative drift. |

**Answer:** Existing COLMAP mechanisms are enough for GPS/position priors and may carry gravity metadata used by selected stock stages, but current IMU supplies orientation/gravity rather than reliable metric positions. They can support an initial gravity-aware experiment without modifying stock source only after calibrated frame conversion and DB import. A true per-frame gyro/orientation residual inside BA requires a custom optimizer layer (for example PyCOLMAP/Ceres problem extension) or a COLMAP source extension; `pose_prior_mapper` alone is not equivalent.

### B. ToF metric scale application

| Item | Finding |
|---|---|
| File / block | `process_colmap_sparse.sh`, after mapper and scale diagnostics, before dense planning consumes `sparse/<model_id>`; implementation pattern exists in the AprilTag alignment branch |
| Current role | ToF scale tools calculate robust `mm_per_colmap_unit` and explicitly set geometry mutation/fusion flags false |
| Safe addition | First create a **derived**, versioned metric sparse model by applying one validated uniform Sim3 scale to camera translations and points; retain the immutable visual model; convert/validate BIN; make dense choose the derived model only after gates. Scale final dense/mesh artifacts consistently or regenerate dense from the scaled sparse model. |
| Risks | A wrong RGB-ToF association or local surface correspondence biases global scale; scale cannot repair spiral/drift; partial scaling (points but not poses, or sparse but stale dense) corrupts geometry; thresholds need multiple frames/zones, uncertainty, outlier and stability gates. |

**Answer:** Yes. A uniform global metric scale after visual reconstruction is the safest first ToF geometry use and does not require BA or stock COLMAP changes. It preserves reprojection geometry when applied consistently to camera translations and 3D points. It must be a derived artifact with provenance and must not be presented as a drift fix.

### C. Post-optimization validation / possible re-optimization

| Item | Finding |
|---|---|
| File / block | `process_colmap_sparse.sh`, after `mapper` and before `generate_sparse_components`/dense hand-off; orchestration gate in `auto_chain_after_done()` before queuing reconstruction previews |
| Current role | Produces `sparse_diagnostics.json`, `camera_trajectory.json`, `world_alignment.json`; worker selects the best component mostly by registered images/points and then queues dense |
| Safe addition | Add immutable validation report and explicit PASS/WARN/FAIL gate using visual loop gap, reprojection, IMU rotation residuals and ToF scale stability. On a controlled branch, rerun matching/mapper with stronger closure pairs or run stock standalone BA on a copied model; compare metrics before promoting the derived model. |
| Risks | Re-running BA without new constraints cannot manufacture loop closure; hard failure gates may reject recoverable captures; BA changes the coordinate frame and invalidates existing dense products; retries need new job IDs and provenance to avoid overwriting baseline artifacts. |

## 9. Loop drift and spiral/self-intersection

IMU orientation prior alone is not the minimum reliable cure: it can reduce rotational inconsistency, but it does not create a translation loop constraint or visual correspondence between the first and last views.

The minimum mechanism with a direct chance to reduce a circular-path spiral is:

1. ensure actual first/last and other non-local loop image pairs are matched (`sequential_matcher` loop detection with a valid vocabulary-tree setup, explicit loop pairs, or exhaustive matching for a bounded SINGLE dataset);
2. let mapper/global BA optimize the connected loop;
3. measure start/end pose gap, registration continuity and self-intersection against the unchanged baseline.

A pose graph becomes useful when reliable relative loop edges exist; BA constraints or a pose graph without closure observations cannot infer the missing loop. IMU gravity/orientation is complementary regularization. ToF global scale is orthogonal: it fixes the similarity scale, not trajectory topology.

## 10. Can sensor integration avoid stock COLMAP source changes?

| Increment | Stock source change required? |
|---|---:|
| better visual loop matching + mapper BA | No |
| post-model gravity/Z-up rigid transform | No |
| validated uniform ToF scale via Sim3/model rewrite | No |
| validation gates and derived model promotion | No |
| position priors where actual metric positions exist | No, if deployed `pose_prior_mapper` supports the repository contract |
| per-frame IMU orientation/gyro residuals in BA | Yes, or an external custom PyCOLMAP/Ceres optimizer layer |
| ToF depth residuals jointly optimized with visual reprojection | Yes, or an external custom optimizer layer |

This supports an incremental strategy: establish closure and immutable post-transform contracts around stock COLMAP first; introduce custom residual optimization only after calibrated associations and controlled evidence justify it.

## Recommended ordering

1. Freeze sparse input/model artifacts and add quantitative loop/trajectory acceptance metrics.
2. Run a bounded visual loop-closure A/B using the existing matcher setting and ordinary mapper.
3. Add gated derived ToF global-scale output; do not overwrite visual sparse.
4. Calibrate device-to-camera axes and validate IMU orientation against COLMAP on known rotations.
5. Prototype gravity/orientation constraints on copied data; promote to custom BA only if measured improvement exceeds visual-loop baseline.
6. Add combined validation and optional re-optimization as a separate child job with explicit lineage.

## Final classification

```text
COLMAP EXTENSION READINESS: PARTIAL_CUSTOM_WORK_REQUIRED
```

Reason: existing job boundaries, camera priors, diagnostics, model conversion and Sim3-capable tooling provide safe hooks without editing COLMAP. However, the current pipeline has no sensor-to-DB/optimizer bridge, and full IMU orientation or ToF depth constraints are not expressible by its active stock CLI chain.

## Recommended first implementation

```text
RECOMMENDED FIRST IMPLEMENTATION:
Run one controlled sparse-only visual loop-closure A/B on the same selected frames,
using explicit non-local/first-last loop matching and the existing mapper, then gate
the result on start-end trajectory gap and geometry continuity before any sensor BA work.
```

This is the smallest change that directly targets spiral/self-intersection, uses existing hooks, does not conflate scale with drift, and creates the stable visual baseline required to evaluate later IMU and ToF improvements.

## Key files reviewed

- `web/www/order.php`
- `web/tools/sfm_pipeline_cli.php`
- `web/tools/sfm_remote_worker.php`
- `web/remote_station/sfm_pipeline.php`
- `web/remote_station/run_extract_frames_job.sh`
- `web/remote_station/run_colmap_sparse_job.sh`
- `web/remote_station/run_colmap_chunk_plan_job.sh`
- `web/remote_station/run_colmap_dense_chunk_job.sh`
- `web/remote_station/scripts/process_extract_frames.sh`
- `web/remote_station/scripts/select_quality_frames.py`
- `web/remote_station/scripts/process_colmap_sparse.sh`
- `web/remote_station/scripts/process_colmap_dense.sh`
- `web/remote_station/scripts/process_colmap_dense_chunk.sh`
- `web/remote_station/scripts/camera_metadata.py`
- `web/remote_station/scripts/analyze_sparse_trajectory.py`
- `web/remote_station/scripts/build_world_alignment.py`
- `web/remote_station/scripts/build_selected_sensor_associations.py`
- `web/remote_station/scripts/build_tof_metric_observations.py`
- `web/remote_station/scripts/measure_tof_sparse_scale.py`
- `web/remote_station/scripts/apply_apriltag_metric_alignment.py`
- `web/tools/colmap_src/AGENTS.md`
- `web/tools/colmap_src/doc/faq.rst`
- `web/tools/colmap_src/src/colmap/exe/colmap.cc`
- `web/tools/colmap_src/src/colmap/exe/sfm.cc`
- `web/tools/colmap_src/src/colmap/exe/model.cc`
- `web/tools/colmap_src/src/colmap/sfm/incremental_mapper.cc`
- `docs/SINGLE_PIPELINE_CURRENT_STATE.md`
- `docs/SINGLE_SENSOR_FUSION_ROADMAP.md`
- `docs/SINGLE_IMU_PARTICIPATION_AUDIT.md`
- `docs/SINGLE_TOF_PARTICIPATION_AUDIT.md`
