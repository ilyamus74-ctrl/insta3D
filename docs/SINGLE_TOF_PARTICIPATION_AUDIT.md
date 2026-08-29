# SINGLE ToF Participation Audit

## Status

```text
STATIC CODE AUDIT COMPLETE
RUNTIME NOT RUN
TOF STATUS: METRIC ONLY
```

## Scope

Этот отчёт фиксирует фактическое участие ToF в текущем SINGLE video pipeline:

```text
Android tof_frames.jsonl + tof_calibration.json
  → mobile upload
  → server storage
  → remote extract job
  → selected-frame / Camera2 / ToF association
  → tof_metric_observations.jsonl
  → sparse/dense metric diagnostics
  → no geometry mutation
```

Аудит read-only. Сборка, remote runtime и reference job не запускались. Выводы о `job_180237696` ограничены заявленным наличием артефактов; фактические runtime parameters и logs этого job не проверялись.

## Executive conclusion

ToF реально участвует в:

- capture raw 8×8/4×4 depth-zone data;
- frozen calibration delivery;
- selected RGB frame ↔ Camera2 frame ↔ ToF frame association;
- filtering valid ToF zones;
- conversion of ToF zone distances into camera-coordinate metric points;
- sparse and dense metric comparison/diagnostics;
- calculation of a candidate COLMAP-to-metric scale and residual reports.

ToF не участвует в:

- COLMAP feature extraction or matching;
- camera pose initialization;
- pose priors;
- bundle adjustment;
- sparse point creation or mutation;
- PatchMatch depth constraints;
- dense fusion;
- automatic scale application;
- final geometry optimization.

`tof_metric_observations.jsonl` создаётся до COLMAP как metric evidence. Основной SINGLE chain не передаёт этот файл в COLMAP commands. Найденные sparse/dense ToF consumers читают уже готовую COLMAP geometry и пингуют её metric error; их reports явно декларируют `measurement_only`, `geometry_mutation_enabled=false` и `fusion_enabled=false`.

## Stage matrix

| Stage | File | Function | Reads ToF | Effect |
|---|---|---|---:|---|
| Android raw capture | `TofCaptureSidecarRecorder.kt` | `start`, `recordFrame`, `stop` | Yes, runtime frames | Пишет `tof_frames.jsonl`; удаляет файл, если нет frames |
| Android calibration snapshot | `TofCaptureSidecarRecorder.kt` | `writeActiveTofCalibrationSnapshot` | Yes, profile store | Пишет frozen `tof_calibration.json` с capture identity и solved profiles |
| Android SINGLE orchestration | `PhoneCameraScanProvider.kt` | `startVideoScan`, `stopVideoScan` | Indirect | Запускает ToF writer; добавляет sidecars в manifest; удаляет calibration, если frames нет |
| Android upload | `MobileUploadApi.kt` | `addPhoneScanMetadataPartsIfAvailable` | No parse | Multipart parts `tof_frames` и `tof_calibration` |
| PHP storage | `mobile.php` | `upload_video_scan` branch | No parse | Сохраняет `*_tof_frames.jsonl` и `*_tof_calibration.json` |
| Worker path recovery | `sfm_remote_worker.php` | metadata/source-video preparation | No content parse | Включает ToF paths в extract job source metadata |
| Remote transfer | `run_extract_frames_job.sh` | `recover_phone_sidecar`, `upload_optional_sidecar` | No content parse | Передаёт sidecars как `tof_frames.jsonl` и `tof_calibration.json` |
| Extract materialization | `process_extract_frames.sh` | main script | No content parse | Копирует sidecars в extract job root |
| Temporal association | `build_selected_sensor_associations.py` | `main` | Yes: frames; calibration binding | Связывает selected video/encoder/Camera2 frame с accepted ToF pair и raw sequence; пишет association artifacts |
| Metric observation generation | `build_tof_metric_observations.py` | `main`, `choose_profile`, `validate_profile`, `transform_zone` | Yes: both files | Фильтрует zones, применяет ToF→camera calibration, пишет metric observations; geometry mutation OFF |
| Extract result/logging | `process_extract_frames.sh`, `sfm_remote_worker.php` | main; `pipeline_log_sensor_association_summary` | Reads report | Публикует counts/gates; явно logs `measurement_only=yes`, `geometry_mutation=OFF`, `fusion=OFF` |
| COLMAP sparse | `process_colmap_sparse.sh` | `feature_extractor`, matcher, `mapper` | No | Stock RGB/COLMAP; ToF не передаётся |
| Sparse scale diagnostic | `measure_tof_sparse_scale.py` | `main`, `robust_scale` | Metric observations | Оценивает candidate mm/COLMAP-unit и residuals; sparse model/poses/points не меняет |
| Sparse correspondence diagnostic | `measure_tof_sparse_scale_h21.py` | diagnostic entry points | Metric observations | Сравнивает correspondence strategies; measurement only |
| Dense depth diagnostic | `measure_tof_dense_depth_h22.py` | diagnostic entry points | Metric observations + calibration | Сравнивает stock dense depth с ToF; dense input/output не меняет |
| Dense residual diagnostics | `analyze_tof_dense_error_h23.py`, `analyze_tof_dense_zone_h24.py`, `analyze_tof_dense_rgb_h25.py`, later H2.x tools | diagnostic entry points | Metric observations/reports | Измеряет error structure; fusion/geometry mutation OFF |

## 1. Where `tof_frames.jsonl` is read

### Direct readers

1. `build_selected_sensor_associations.py`
   - loads rows with `type=tof_frame`;
   - indexes raw frames by `(slot, sequence)`;
   - verifies that a Camera2 `tof_pair.sequence` has a matching raw frame;
   - records slot, grid size, frequency, valid-zone count and ToF timing metadata.

2. `build_tof_metric_observations.py`
   - reloads raw `tof_frame` rows;
   - selects raw frame by accepted association sequence/slot;
   - reads zone arrays and generates filtered metric observations.

Transport components (`MobileUploadApi`, `mobile.php`, worker and shell transfer scripts) move the file but do not interpret depth values.

### Raw fields used

| Field | Effect |
|---|---|
| `slot`, `sequence` | Frame identity and association |
| `width`, `height` | Grid/profile compatibility and zone count |
| `frequency_hz` | Association/report metadata |
| `mapped_elapsed_realtime_ns` | ToF/Camera2 temporal association evidence |
| `host_received_elapsed_realtime_ns` | Fallback/diagnostic timing |
| `distance_mm` | Axial/perpendicular metric Z per zone |
| `sigma_mm` | Quality rejection threshold |
| `target_status` | Accept only statuses 5, 6, 9 |
| `nb_target_detected` | Reject zones without a target |

`rp2040_timestamp_us`, `irq_timestamp_valid`, temperature and protocol fields are capture/diagnostic metadata; metric observation generation primarily consumes the mapped association and zone arrays.

## 2. Where `tof_calibration.json` is read

### Calibration binding

`build_selected_sensor_associations.py` reads the snapshot to validate that calibration belongs to the capture identity. Matching uses:

- `device_id`;
- `rig_id`;
- `rig_mount_revision`;
- `selected_camera_id`;
- active calibration profile identity;
- observed ToF slots;
- profile `status=solved`.

### Metric projection

`build_tof_metric_observations.py` requires exactly one valid capture-matched profile and reads:

- `tof_slot`, `tof_width`, `tof_height`;
- `tof_intrinsics.fx_zones`, `fy_zones`, `cx_zones`, `cy_zones`;
- `rotation_tof_to_camera`;
- `translation_tof_to_camera_mm`;
- profile/capture identity and quality metadata.

For every accepted zone it reconstructs `(x,y,z)` in ToF coordinates from axial `distance_mm`, then applies the rigid ToF→camera transform and stores `tof_xyz_mm` and `camera_xyz_mm`.

Dense ToF diagnostics also read the calibration when projecting zone centers/footprints into the final COLMAP camera model. Those stages compare geometry; they do not modify calibration or reconstruction.

## 3. Where `tof_metric_observations.jsonl` is created

The file is created in `process_extract_frames.sh` by:

```text
build_tof_metric_observations.py
  --associations selected_sensor_associations.jsonl
  --association-report selected_sensor_association_report.json
  --tof-frames tof_frames.jsonl
  --tof-calibration tof_calibration.json
  --output-jsonl tof_metric_observations.jsonl
  --output-report tof_metric_observation_report.json
```

The generator writes:

- metadata declaring `measurement_only=true`;
- `geometry_mutation_enabled=false`;
- `fusion_enabled=false`;
- one `tof_metric_observation` row per accepted zone.

Each observation contains image/time identity, ToF sequence/slot, zone row/column, distance, sigma, target quality and metric point coordinates in ToF and camera frames.

Generation is non-fatal. Missing/raw-invalid/unbound ToF produces a `SKIPPED_*` report and RGB/COLMAP processing continues unchanged.

## 4. Consumers of metric observations

### Production/extract consumer

`sfm_remote_worker.php` reads `tof_metric_observation_report.json` only to log counts, status and readiness for later measurement. It explicitly logs:

```text
measurement_only=yes
geometry_mutation=OFF
fusion=OFF
```

No automatic consumer is passed to the COLMAP sparse command by `auto_chain_after_done`.

### Diagnostic consumers present in the repository

| Consumer | Purpose | Mutation |
|---|---|---:|
| `measure_tof_sparse_scale.py` | Compare ToF camera depth with final sparse points; estimate robust mm/COLMAP-unit candidate | No |
| `measure_tof_sparse_scale_h21.py` | Compare sparse correspondence/zone-footprint strategies | No |
| `measure_tof_dense_depth_h22.py` | Compare ToF with COLMAP PatchMatch depth | No |
| `analyze_tof_dense_error_h23.py` | Controlled dense error decomposition | No |
| `analyze_tof_dense_zone_h24.py` | Zone/angular residual analysis | No |
| `analyze_tof_dense_rgb_h25.py` | Image-region/dense-vs-ToF diagnostics | No |
| `analyze_tof_dense_frame_h27.py`, `analyze_tof_dense_quality_h28.py` and related H2.x tools | Further measurement and quality analysis | No |

Repository-wide invocation tracing found the observation generator wired into `process_extract_frames.sh`. Sparse/dense diagnostic scripts exist and were used for recorded evidence/tasks, but they are not passed into the stock COLMAP `feature_extractor`, matcher, mapper or PatchMatch command as constraints.

## 5. Participation by reconstruction function

| Function | Status | Evidence and effect |
|---|---|---|
| Reports | **YES** | Association and metric observation reports are produced and logged. |
| Post-reconstruction measurement | **YES** | Diagnostic tools compare observations against final sparse/dense geometry. |
| Scale candidate estimation | **YES, measurement only** | `measure_tof_sparse_scale.py` calculates robust mm/unit and residuals. |
| Scale correction | **NO** | Candidate is not applied automatically; reports declare `sparse_model_modified=false`. |
| Depth constraint | **NO** | ToF does not enter COLMAP mapper or PatchMatch objective. |
| Sparse points | **NO mutation** | Sparse points are read for comparison but not created, moved or rescaled by ToF. |
| Dense reconstruction | **DIAGNOSTIC ONLY** | Dense depth is compared to ToF after generation; dense inputs and fusion remain unchanged. |
| Camera pose optimization | **NO** | No ToF residual/pose prior is supplied to mapper or BA. |
| Bundle adjustment | **NO** | No ToF observation is inserted into a BA objective. |
| Fusion | **NO** | Current reports and task gates explicitly keep fusion disabled. |

## Exact reconstruction boundary

The active automatic path is:

```text
ToF capture
  → temporal association
  → metric observations and report

RGB selected frames
  → COLMAP feature extraction
  → visual matching
  → visual mapper / bundle adjustment
  → stock sparse geometry
  → stock dense processing

metric observations + completed geometry
  → optional measurement/diagnostic comparison
```

The streams meet only for measurement after or alongside reconstruction. ToF observations do not flow back into COLMAP and do not trigger re-optimization.

The scale diagnostic computes fields such as:

```text
robust_mm_per_colmap_unit
robust_m_per_colmap_unit
depth_residual_mm
depth_relative_error
```

but its base report fixes:

```text
measurement_only = true
geometry_mutation_enabled = false
sparse_model_modified = false
camera_poses_modified = false
points3d_modified = false
dense_input_modified = false
fusion_enabled = false
```

Therefore a reported scale is evidence, not an applied scale correction.

## Failure and fallback behavior

- ToF is optional; missing ToF does not block RGB capture/reconstruction.
- Android deletes empty `tof_frames.jsonl` and its calibration snapshot when no frames were recorded.
- Temporal association must pass before metric observations are accepted.
- Calibration must bind uniquely to the capture identity.
- Raw dimensions must match the selected profile.
- Zones are rejected for missing target, invalid status, out-of-range distance, missing/high sigma or invalid transform.
- Direct metric working range defaults to 100–4000 mm for observation generation.
- Any ToF measurement-stage failure is non-fatal to stock RGB/COLMAP processing.
- Current documentation blocks geometry mutation because measured scale varies materially with ToF distance.

## Final classification

```text
TOF STATUS: METRIC ONLY
```

Rationale:

- ToF is more than stored metadata: it is temporally associated, calibrated and converted into metric camera-space observations.
- ToF supports real scale/depth measurement and validation against completed sparse/dense geometry.
- No ToF value is an active reconstruction constraint.
- The scale candidate is not applied.
- Sparse points, camera poses, dense depth and fused geometry remain stock COLMAP outputs.

## Files reviewed

### Android

- `app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/TofCaptureSidecarRecorder.kt`
- `app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/PhoneCameraScanProvider.kt`
- `app/MaklerTour/app/src/main/java/com/example/maklertour/auth/MobileUploadApi.kt`

### PHP/backend and orchestration

- `web/www/api/mobile.php`
- `web/tools/sfm_remote_worker.php`
- `web/remote_station/run_extract_frames_job.sh`
- `web/remote_station/run_colmap_sparse_job.sh`
- `web/remote_station/sfm_pipeline.php`

### Remote processing

- `web/remote_station/scripts/process_extract_frames.sh`
- `web/remote_station/scripts/build_selected_sensor_associations.py`
- `web/remote_station/scripts/build_tof_metric_observations.py`
- `web/remote_station/scripts/process_colmap_sparse.sh`
- `web/remote_station/scripts/measure_tof_sparse_scale.py`
- `web/remote_station/scripts/measure_tof_sparse_scale_h21.py`
- `web/remote_station/scripts/measure_tof_dense_depth_h22.py`
- `web/remote_station/scripts/analyze_tof_dense_error_h23.py`
- `web/remote_station/scripts/analyze_tof_dense_zone_h24.py`
- `web/remote_station/scripts/analyze_tof_dense_rgb_h25.py`
- `web/remote_station/scripts/analyze_tof_dense_frame_h27.py`
- `web/remote_station/scripts/analyze_tof_dense_quality_h28.py`

### Documentation/context

- `docs/SINGLE_PIPELINE_ROADMAP.md`
- `docs/SINGLE_IMU_PARTICIPATION_AUDIT.md`
- `docs/llm/tasks/SFM-S01-SINGLE-SERVER-RECONSTRUCTION.md`
- `docs/llm/tasks/SFM-S01H-TOF-METRIC-GEOMETRY.md`
- `docs/llm/tasks/SFM-S01H2.2-DENSE-DEPTH-DIAGNOSTIC.md`
- `docs/llm/tasks/SFM-S01H2.4-ANGULAR-ZONE-RESIDUAL-LOCALIZATION.md`

