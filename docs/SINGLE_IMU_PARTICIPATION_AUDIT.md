# SINGLE IMU Participation Audit

## Status

```text
STATIC CODE AUDIT COMPLETE
RUNTIME NOT RUN
IMU STATUS: PARTIAL
```

## Scope

Этот отчёт фиксирует фактическое участие IMU в текущем SINGLE video pipeline по исполняемому коду:

```text
Android imu.jsonl
  → mobile upload
  → server-side stored IMU sidecar
  → scan_imu.jsonl on remote station
  → extract/frame selection
  → COLMAP sparse processing
  → post-COLMAP diagnostics/world-alignment report
```

Аудит read-only. Сборка, runtime job, remote reference job и controlled A/B run не запускались. Выводы ниже подтверждены current source code, но не являются runtime-проверкой `job_180237696`.

## Executive conclusion

IMU не является active constraint в текущем COLMAP optimizer.

Фактическое влияние IMU:

1. В `auto_quality` frame selection gyro может hard-reject кадр или уменьшить его quality score; accelerometer участвует в `imu_motion_score`, но current rejection branch прямо проверяет gyro threshold.
2. IMU привязывается к selected-frame video timestamps и сохраняется в association artifacts.
3. После COLMAP mapper gyro/rotation-vector сравниваются с уже построенной visual trajectory для diagnostics.
4. Gravity читается, но не применяется: `build_world_alignment.py` возвращает `UNALIGNED` и identity rotation, так как device-to-COLMAP transform не реализован.
5. COLMAP `feature_extractor`, matcher и `mapper` не получают IMU, pose priors или inertial weights. Вызова pose-prior mapper или IMU-aware bundle adjustment в SINGLE script нет.

Поэтому IMU влияет на reconstruction только косвенно, через выбор входных кадров. Оно не ограничивает camera poses, bundle adjustment, loop closure или scale.

## Stage matrix

| Stage | File | Function | Reads IMU | Effect |
|---|---|---|---:|---|
| Android sensor capture | `ImuRecorder.kt` | `start`, `onSensorChanged` | Yes | Пишет metadata и gyro/accel/gravity/rotation-vector samples в `imu.jsonl` |
| Android timeline finalization | `ImuRecorder.kt` | `rebaseVideoTimeline` | Yes | Пересчитывает `video_t_sec` от CameraX video-start anchor |
| Android SINGLE orchestration | `PhoneCameraScanProvider.kt` | `startVideoScan`, `stopVideoScan` | Indirect | Запускает/останавливает recorder, rebases timeline, передаёт IMU file в manifest writer |
| Android upload | `MobileUploadApi.kt` | `addPhoneScanMetadataPartsIfAvailable` | No parse | Добавляет `imu.jsonl` как multipart part `imu` |
| PHP upload | `mobile.php` | `upload_video_scan` branch, `api_store_optional_video_metadata` call | No parse | Сохраняет sidecar как `*_imu.jsonl`, пишет `imu_path`/`imu_storage_path` |
| Server job preparation | `sfm_remote_worker.php` | `safe_session_imu_path` | No content parse | Safe-resolves IMU path и добавляет `imu_jsonl_path` в extract parameters |
| Transfer to remote station | `run_extract_frames_job.sh` | main script | No content parse | Передаёт sidecar как `input/job_<id>/scan_imu.jsonl` |
| Extract setup | `process_extract_frames.sh` | main script | No content parse | Копирует IMU в output root как `scan_imu.jsonl`; передаёт path frame selector |
| IMU parsing | `imu_utils.py` | `parse_imu_jsonl` | Yes | Нормализует sensors, values, quaternion и video-relative time |
| Timestamp association | `imu_utils.py` | `_timestamp`, `frame_motion_at` | Yes | Использует `video_t_sec`; fallback: `t_ns-video_start_t_ns`; frame window ±50 ms |
| Frame selection | `select_quality_frames.py` | `main` | Yes | Gyro hard reject, gyro soft penalty, accel-derived motion score; может изменить состав `frames/` |
| Selected-frame association | `build_selected_sensor_associations.py` | `main`, `nearest_imu_record` | Yes | Добавляет motion и nearest samples к selected-frame association JSONL; optimizer не читает этот artifact |
| Sparse job enqueue | `sfm_remote_worker.php` | `auto_chain_after_done` | No content parse | Передаёт `scan_imu.jsonl` path в child job parameters |
| COLMAP feature extraction | `process_colmap_sparse.sh` | `run_colmap feature_extractor` | No | Только JPEG и camera metadata; IMU/pose prior не передаются |
| COLMAP matching | `process_colmap_sparse.sh` | `sequential_matcher` / `exhaustive_matcher` | No | Visual feature matching; loop detection setting не зависит от IMU |
| COLMAP pose estimation/BA | `process_colmap_sparse.sh` | `run_colmap mapper` | No | Visual mapper без pose prior, inertial residual и IMU BA constraint |
| Post-COLMAP diagnostics | `analyze_sparse_trajectory.py` | `load_imu`, `imu_delta`, `main` | Yes | Сравнивает visual rotation с rotation-vector/gyro; только warnings/report |
| Gravity report | `build_world_alignment.py` | `main` | Yes | Оценивает gravity, но публикует `UNALIGNED` + identity transform; geometry не меняет |

## 1. Where IMU is read

### Android producer

`ImuRecorder` регистрирует:

- `TYPE_GYROSCOPE`;
- `TYPE_ACCELEROMETER`;
- `TYPE_GRAVITY`;
- `TYPE_ROTATION_VECTOR`.

Каждая sample row содержит `t_ns`, `video_t_sec`, `sensor`, `values`. Metadata row содержит `schema_version`, `session_id`, `scan_id`, `video_start_t_ns`, `imu_start_t_ns`, `clock=CLOCK_BOOTTIME`. После CameraX finalize timeline rebased к `CAMERAX_VIDEO_RECORD_EVENT_START`.

### Server readers

Содержимое IMU читают четыре consumer paths:

1. `select_quality_frames.py` — pre-COLMAP frame selection.
2. `build_selected_sensor_associations.py` — selected-frame association artifact.
3. `analyze_sparse_trajectory.py` — post-COLMAP visual/IMU rotation diagnostics.
4. `build_world_alignment.py` — gravity estimation report, currently without applying alignment.

`mobile.php`, `sfm_remote_worker.php`, `run_extract_frames_job.sh` and most of `process_extract_frames.sh` transport or locate the file but do not interpret sensor values.

## 2. Fields actually used

### Timestamp and schema fields

| Field | Use |
|---|---|
| `type=metadata` | Identifies metadata row |
| `video_start_t_ns` | Fallback conversion from sensor `t_ns` to video-relative seconds |
| `video_timeline_anchor_source` | Preserved/logged as sync evidence |
| `video_timeline_rebased` | Preserved/logged as sync evidence |
| `video_t_sec` | Preferred authoritative association time |
| `t_ns` | Raw monotonic timestamp and fallback association input |
| `sensor` | Maps row to gyro/accel/gravity/rotation-vector |
| `values` | Sensor vector or Android rotation vector |

`session_id`, `scan_id`, `imu_start_t_ns`, `clock` are retained as metadata but are not optimizer inputs.

### Sensor values

| Sensor | Parsed representation | Current effect |
|---|---|---|
| Gyroscope | 3-vector, rad/s | Frame motion, hard reject/soft penalty, post-COLMAP integrated rotation diagnostic |
| Accelerometer | 3-vector, m/s² assumed | Magnitude/deviation from 9.80665 in motion score; fallback gravity estimator |
| Gravity | 3-vector | Gravity confidence/report only |
| Rotation vector | Android vector converted to normalized WXYZ quaternion | Nearest-frame association and post-COLMAP rotation diagnostic; fallback gravity estimator |

## 3. Timestamp association

Timestamp association exists.

Priority implemented by `imu_utils._timestamp`:

1. `video_t_sec` → method `video_t_sec`, quality `exact`.
2. `timestamp_sec` / `time` / `t` legacy fields → exact legacy mapping.
3. `t_ns` with metadata `video_start_t_ns` → `(t_ns-video_start_t_ns)/1e9`, quality `good`.
4. `t_ns` without video anchor → relative to first IMU sample, quality `approximate`.

Android normally rewrites `video_t_sec` after recording using CameraX start, so the expected SINGLE path is the first variant.

Frame selection associates motion using `frame_motion_at(imu, frame_timestamp_sec)` with a ±0.05 s window. It chooses the maximum gyro norm in the window and median acceleration magnitude.

`build_selected_sensor_associations.py` additionally records the nearest gyro, accel, gravity and rotation-vector sample for every selected video timestamp, including signed `delta_ms`. This association is diagnostic/provenance data; it is not consumed by the COLMAP mapper.

Post-COLMAP diagnostics associate registered frame timestamps from `selected_frames.json` with rotation-vector interpolation using maximum 0.1 s gaps, falling back to gyro integration over the interval.

## 4. Participation by reconstruction function

| Function | Status | Evidence and effect |
|---|---|---|
| Frame selection | **YES, indirect reconstruction input** | `select_quality_frames.py` changes selected JPEG set using gyro thresholds and motion penalty. Only active with valid IMU sync (`exact`/`good`), enabled settings and non-manual quality selection. Coverage fallback may still select a rejected frame. |
| Camera initialization | **NO** | COLMAP feature extraction receives camera model/params from camera metadata, not IMU. No inertial orientation or translation initialization is passed. |
| Pose prior | **NO** | SINGLE sparse script invokes ordinary `colmap mapper`; it does not write IMU pose priors to the database and does not invoke `pose_prior_mapper`. |
| Bundle adjustment | **NO** | No IMU residual, covariance, weight or inertial constraint is passed to mapper/BA. BA remains COLMAP visual optimization. |
| Gravity alignment | **READ, NOT APPLIED** | Gravity is estimated after reconstruction, but output is explicitly `UNALIGNED` with identity rotation because device-to-COLMAP transform is missing. |
| Loop closure | **NO** | Sequential matcher loop detection is a separate sparse setting; IMU neither enables it nor supplies a closure constraint. Current defaults set `loop_detection=false`. |
| Scale correction | **NO** | IMU acceleration is not integrated into metric translation and no scale transform is derived from IMU. |

## 5. Exact optimization boundary

Точное место, где заканчивается влияние IMU на optimization input:

```text
select_quality_frames.py
  → selected rows copied to frames/frame_XXXXXX.jpg
  → process_extract_frames.sh finishes selection
  → COLMAP feature_extractor
  → sequential/exhaustive matcher
  → ordinary COLMAP mapper
```

После копирования selected JPEG в `frames/` IMU не передаётся ни в один из трёх COLMAP calls. `process_colmap_sparse.sh` ищет `scan_imu.jsonl` только после вызова `mapper`, в `run_sparse_diagnostics` и `build_world_alignment.py`.

Значит:

- IMU может косвенно изменить visual solution, изменив набор кадров;
- IMU не может в current code исправить pose drift как optimizer constraint;
- post-COLMAP mismatch warnings не вызывают re-optimization;
- `world_alignment.json` не меняет sparse model.

## Fallback behavior and limitations

- Если IMU file нет, pipeline продолжает visual processing.
- Frame-selection IMU logic отключается, если sync quality не `exact`/`good`.
- Manual frame extraction IMU selector не вызывает.
- Hard gyro reject не абсолютен: time-bin coverage fallback может выбрать rejected candidate.
- `maximum_imu_rejection_ratio` присутствует в settings contract, но в просмотренном `select_quality_frames.py` не используется для cap rejection ratio.
- `prefer_stable_frames` также не проверяется отдельно; IMU behavior gated полем `enabled`.
- Accelerometer threshold contributes to computed motion score/penalty magnitude, but current hard-rejection condition checks angular velocity only.
- Static audit proves wiring, not that a particular runtime job had `exact/good` sync or rejected/penalized frames. That requires its `quality_summary.json` and logs.

## Final classification

```text
IMU STATUS: PARTIAL
```

Rationale:

- not `METADATA ONLY`, because IMU can change pre-COLMAP frame selection;
- not `ACTIVE CONSTRAINT`, because camera poses, pose graph and bundle adjustment are optimized without IMU;
- gravity and visual/IMU comparison are post-COLMAP diagnostics and do not mutate reconstruction;
- there is no IMU-based loop-closure or scale correction path.

## Files reviewed

### Android

- `app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/ImuRecorder.kt`
- `app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/PhoneCameraScanProvider.kt`
- `app/MaklerTour/app/src/main/java/com/example/maklertour/auth/MobileUploadApi.kt`
- `app/MaklerTour/app/src/main/java/com/example/maklertour/state/AppStateViewModel.kt`

### PHP/backend and job orchestration

- `web/www/api/mobile.php`
- `web/tools/sfm_remote_worker.php`
- `web/libs/sfm_settings_lib.php`
- `web/remote_station/run_extract_frames_job.sh`
- `web/remote_station/run_colmap_sparse_job.sh`
- `web/remote_station/sfm_pipeline.php`

### Remote processing

- `web/remote_station/scripts/process_extract_frames.sh`
- `web/remote_station/scripts/select_quality_frames.py`
- `web/remote_station/scripts/imu_utils.py`
- `web/remote_station/scripts/build_selected_sensor_associations.py`
- `web/remote_station/scripts/process_colmap_sparse.sh`
- `web/remote_station/scripts/analyze_sparse_trajectory.py`
- `web/remote_station/scripts/build_world_alignment.py`

### Documentation/context

- `docs/SINGLE_PIPELINE_ROADMAP.md`
- `docs/llm/tasks/SFM-S01-SINGLE-SERVER-RECONSTRUCTION.md`

