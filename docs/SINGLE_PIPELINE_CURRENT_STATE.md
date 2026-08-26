# SINGLE Pipeline Current State

## Status

```text
CURRENT STATE BASELINE
SENSOR FUSION NOT IMPLEMENTED
```

Reference processing job:

```text
job_180237696
```

Документ фиксирует текущее состояние SINGLE pipeline перед началом реализации sensor fusion. Целевые возможности не описываются как уже реализованные.

## 1. Назначение

`SINGLE` — topology съёмки с одного Android-телефона:

- одна camera;
- optional IMU;
- optional ToF;
- запись видео;
- сохранение camera/sensor metadata;
- загрузка на сервер;
- серверная reconstruction;
- получение 3D-модели;
- получение metric observations и метрических diagnostics при наличии валидного ToF.

Текущая 3D reconstruction основана на visual COLMAP pipeline. IMU и ToF уже записываются, передаются и частично обрабатываются, но ещё не являются active constraints COLMAP optimization.

## 2. Capture stage

### Camera

Status:

```text
✅ WORKING
```

Работает:

- запись `video.mp4` через CameraX;
- выбор requested resolution и FPS;
- сохранение `camera_info.json`;
- сохранение `manifest.json`;
- camera-frame telemetry;
- encoder PTS timeline;
- привязка selected frames к video timestamps;
- загрузка видео и metadata на сервер;
- frame extraction и visual COLMAP processing.

Запрошенные resolution/FPS и фактические capture properties должны различаться: фактическое значение подтверждается metadata/telemetry, а не только UI request.

### IMU

Status:

```text
PARTIAL
```

Работает:

- ✅ запись Android `imu.jsonl`;
- ✅ доставка на processing station как `scan_imu.jsonl`;
- ✅ запись gyroscope, accelerometer, gravity и rotation vector;
- ✅ синхронизация с video timeline через `video_t_sec` и CameraX video-start anchor;
- ✅ timestamp association с selected frames;
- ✅ gyro-based penalty/rejection в `auto_quality` frame selection;
- ✅ post-COLMAP visual/IMU trajectory diagnostics.

Не работает / не реализовано:

- ❌ IMU pose prior для COLMAP;
- ❌ gravity constraint внутри reconstruction;
- ❌ bundle adjustment с IMU residuals;
- ❌ loop-closure correction с IMU;
- ❌ IMU-based scale correction;
- ❌ device-to-camera/device-to-COLMAP transform, достаточный для safe hard alignment.

Точная граница: IMU может изменить набор selected JPEG до COLMAP. В `feature_extractor`, matcher и `mapper` IMU не передаётся. После mapper IMU используется для reports, но не вызывает re-optimization.

### ToF

Status:

```text
METRIC ONLY
```

Работает:

- ✅ запись `tof_frames.jsonl`;
- ✅ сохранение frozen `tof_calibration.json`;
- ✅ доставка обоих sidecars на processing station;
- ✅ привязка selected video frames к Camera2/ToF timestamps и sequence;
- ✅ проверка capture/calibration identity;
- ✅ фильтрация ToF zones по target status, range и sigma;
- ✅ генерация `tof_metric_observations.jsonl`;
- ✅ преобразование ToF zone points в camera coordinates;
- ✅ sparse/dense metric diagnostics;
- ✅ оценка candidate COLMAP-to-metric scale в diagnostic tools.

Не работает / не реализовано:

- ❌ изменение camera poses;
- ❌ ToF residuals в bundle adjustment;
- ❌ automatic scale injection в reconstruction;
- ❌ ToF depth constraint в COLMAP/PatchMatch;
- ❌ изменение или rescale sparse points;
- ❌ fusion с sparse/dense model;
- ❌ geometry mutation по результатам metric observations.

Текущие ToF reports явно декларируют `measurement_only=true`, `geometry_mutation_enabled=false` и `fusion_enabled=false`. Рассчитанный scale candidate не применяется к модели.

## 3. Current processing pipeline

```text
                       Android phone

                       Phone Camera
                            |
             +--------------+--------------+
             |                             |
             v                             v
       IMU recording                 ToF recording
       imu.jsonl                     tof_frames.jsonl
       video timeline                tof_calibration.json
             |                             |
             +--------------+--------------+
                            |
                            v
                     Server upload/storage
                            |
                            v
                      Frames extraction
                            |
                            v
                  Quality / frame selection
                    (IMU may affect this)
                            |
                            v
                  Visual COLMAP reconstruction
               feature extraction -> matching -> mapper
                            |
                            v
                         3D model
                            |
                            v
                   ToF metric analysis
              observations / scale-depth diagnostics
```

IMU и ToF сейчас являются **side-channel data**:

- IMU влияет на pre-COLMAP frame selection, но не на visual optimizer objective;
- ToF даёт metric observations и post-reconstruction measurement, но не меняет reconstruction;
- COLMAP camera poses и geometry оптимизируются по visual observations;
- stock RGB reconstruction остаётся authoritative output.

## 4. Observed problem

При круговом обходе объекта наблюдается, что:

- модель может закручиваться в спираль;
- возникает trajectory drift;
- возможны self-intersections;
- масштаб и размеры объекта не всегда стабильны.

Рабочая гипотеза:

> COLMAP оптимизирует траекторию без IMU и ToF sensor constraints, поэтому visual drift и metric-scale ambiguity не ограничиваются сенсорами.

Это обоснованная архитектурная гипотеза, но не завершённый root-cause proof. Для доказательства нужны controlled runs Video-only, Video+IMU и Video+IMU+ToF на одном immutable capture с численными trajectory/scale metrics.

## 5. Current status table

| Component | Status |
|---|---|
| Camera recording | **READY** |
| Video metadata | **READY** |
| Frames timeline | **READY** |
| Server video processing | **READY** |
| Visual COLMAP reconstruction | **READY** |
| IMU recording | **READY** |
| IMU/video timestamp association | **READY** |
| IMU frame-selection assistance | **READY** |
| IMU fusion | **NOT IMPLEMENTED** |
| IMU pose/gravity constraint | **NOT IMPLEMENTED** |
| ToF recording | **READY** |
| ToF calibration | **READY** |
| ToF/frame association | **READY** |
| ToF metric extraction | **READY** |
| ToF scale diagnostics | **READY** |
| ToF geometry fusion | **NOT IMPLEMENTED** |
| Automatic metric-scale application | **NOT IMPLEMENTED** |
| Metric reconstruction | **NOT IMPLEMENTED** |

`READY` в таблице означает, что соответствующий current stage имеет реализованный code path. Это не означает, что весь SINGLE pipeline достиг metric/fusion acceptance.

## 6. Next implementation phase

SINGLE Phase 1:

```text
Sensor Fusion Integration
```

### Goals

- добавить calibrated IMU orientation/gravity usage;
- добавить versioned device-to-camera/device-to-COLMAP transform;
- добавить ToF scale constraints только после metric-stability gates;
- проверить фактическое влияние sensor constraints на COLMAP reconstruction;
- сравнить visual baseline и sensor-assisted model;
- измерить loop closure, drift, self-intersection, scale и known dimensions;
- сохранить visual-only fallback;
- не менять current capture format в рамках первой integration phase.

### Required comparison

```text
A: Video only
B: Video + IMU constraints
C: Video + IMU constraints + ToF metric constraints
```

Все три варианта должны по возможности использовать один immutable video input, один frame set и одинаковые visual parameters. Любое изменение frame selection должно быть вынесено в отдельное сравнение.

### Phase completion criterion

- runtime report показывает, какие IMU/ToF constraints приняты;
- веса, uncertainty, residuals и rejected constraints зафиксированы;
- sensor-assisted model имеет измеримое улучшение по approved validation metrics;
- круговой обход не создаёт spiral deformation;
- масштаб и known dimensions стабильны;
- невалидные sensor data безопасно отклоняются;
- visual-only fallback остаётся воспроизводимым;
- original visual artifacts не изменяются in place.

## Documentation basis

- `docs/ANDROID_CAPTURE_ARCHITECTURE_AUDIT.md`;
- `docs/SINGLE_PIPELINE_ROADMAP.md`;
- `docs/SINGLE_IMU_PARTICIPATION_AUDIT.md`;
- `docs/SINGLE_TOF_PARTICIPATION_AUDIT.md`;
- `docs/SINGLE_SENSOR_FUSION_ROADMAP.md`.

