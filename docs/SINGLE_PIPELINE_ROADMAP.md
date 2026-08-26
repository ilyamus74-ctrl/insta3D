# SINGLE Pipeline Roadmap

## Status

Current state:

```text
PARTIALLY WORKING
```

Goal:

> Получить стабильную метрическую 3D-реконструкцию одного объекта в topology `SINGLE`.

Этот документ является управляющим roadmap для стабилизации SINGLE pipeline. Он отделяет фактически подтверждённое поведение от гипотез и фиксирует порядок следующих работ.

Reference job:

```text
/mnt/storage/makler_pipeline/output/job_180237696
```

Заявленные артефакты reference job:

```text
manifest.json
camera_info.json
tof_frames.jsonl
tof_calibration.json
tof_metric_observations.jsonl
```

> Наличие артефакта подтверждает доставку или выполнение стадии, но само по себе не доказывает влияние IMU или ToF на оптимизацию траектории и геометрии.

## Phase 0 — Baseline freeze

Status:

```text
IN PROGRESS
```

### Objective

Зафиксировать текущий SINGLE pipeline до изменения sensor participation и reconstruction logic.

### Capture side

Фактически подтверждено кодом и аудитом:

- CameraX video capture;
- сохранение `video.mp4`;
- camera metadata в `camera_info.json`;
- capture metadata в `manifest.json`;
- IMU sidecar;
- ToF frames sidecar;
- ToF calibration sidecar.

### Server side

Фактически подтверждённая цепочка:

```text
upload
  ↓
sfm_remote_worker.php
  ↓
run_extract_frames_job.sh
  ↓
process_extract_frames.sh
  ↓
process_colmap_sparse.sh
  ↓
COLMAP pipeline
```

### Confirmed delivery

| Data | Capture | Server delivery | Reconstruction influence |
|---|---|---|---|
| Video | Confirmed | Confirmed | Confirmed visual input |
| Camera metadata | Confirmed | Confirmed | Participation requires stage-level inventory |
| IMU | Confirmed | Confirmed as `imu.jsonl` / `scan_imu.jsonl` | Not yet proven |
| ToF frames | Confirmed | Confirmed | Not yet proven |
| ToF calibration | Confirmed | Confirmed | Not yet proven |
| ToF metric observations | Generated for reference job | Confirmed artifact | Effect on scale/depth/reconstruction not yet proven |

### Baseline evidence to preserve

- exact capture input and checksums;
- Android capture settings and camera identity;
- input video properties;
- complete sensor sidecars;
- frame selection result;
- COLMAP configuration and logs;
- sparse/dense output metrics;
- trajectory and metric measurement reports;
- pipeline version and processing parameters.

### Phase 0 completion criteria

- reference input is immutable or reproducibly archived;
- all input and output artifacts are inventoried;
- pipeline commands and effective parameters are recorded;
- current reconstruction result is preserved as the comparison baseline;
- known defects are documented with measurable symptoms;
- no claim is made that IMU or ToF constrains reconstruction without stage-level evidence.

## Phase 1 — Sensor participation audit

Status:

```text
TODO
```

### Objective

Доказать для каждого sensor source, где и как его данные влияют на результат. Файл, переданный на следующую стадию, не считается доказательством его участия в optimization.

### IMU audit

Проследить:

```text
imu.jsonl
  → scan_imu.jsonl
  → parser / association
  → consumer
  → output or optimizer
```

Определить, используется ли IMU только для:

- выбора стабильных кадров;
- оценки движения;
- diagnostics;
- gravity/world alignment после COLMAP;

или реально влияет на:

- camera pose initialization;
- pose prior;
- bundle adjustment constraint;
- trajectory optimization;
- loop closure;
- global orientation.

Для каждого consumer зафиксировать:

- input fields;
- timestamp association;
- coordinate frame;
- weight/uncertainty;
- fallback behavior;
- output artifact;
- фактическое изменение optimizer objective.

### ToF audit

Проследить:

```text
tof_frames.jsonl
  + tof_calibration.json
  → RGB/ToF association
  → tof_metric_observations.jsonl
  → consumer
  → scale/depth/reconstruction result
```

Определить, используется ли ToF только для:

- association;
- diagnostics;
- quality reports;
- post-hoc metric measurement;

или реально влияет на:

- global scale;
- camera trajectory scale;
- sparse points;
- dense depth;
- reconstruction constraints;
- metric validation with pass/fail effect.

Для каждой ToF-стадии зафиксировать:

- association coverage;
- accepted/rejected observation count;
- calibration version;
- coordinate transform;
- residuals and uncertainty;
- applied scale or depth correction;
- downstream consumer;
- behavior when ToF is absent or invalid.

### Phase 1 deliverable

Создать матрицу участия:

| Sensor | Stage | Read | Associated | Used for decision | Used in optimizer | Affects final geometry |
|---|---|---:|---:|---:|---:|---:|
| IMU | TBD | TBD | TBD | TBD | TBD | TBD |
| ToF | TBD | TBD | TBD | TBD | TBD | TBD |

### Phase 1 completion criteria

- every IMU and ToF reader and consumer is identified;
- each use is classified as selection, diagnostics, alignment, post-processing or optimization constraint;
- optimizer participation is proven by code path and effective runtime parameters;
- sensor-off fallback is documented;
- unsupported claims are explicitly marked as hypotheses;
- sufficient evidence exists to design controlled A/B/C runs.

## Phase 2 — Reconstruction stability

Status:

```text
TODO
```

### Current problem

При круговом обходе объекта наблюдаются:

- spiral deformation;
- self-intersection траектории/модели;
- некорректный или нестабильный масштаб;
- нестабильные размеры объекта.

### Hypotheses

Следующее не считается подтверждённой причиной до завершения Phase 1 и controlled comparison:

- IMU недостаточно используется;
- ToF не даёт метрического ограничения;
- COLMAP оптимизирует траекторию преимущественно или полностью по визуальной геометрии;
- loop closure отсутствует, не срабатывает или имеет недостаточный вес;
- frame selection создаёт разрывы или недостаточное overlap;
- rolling shutter, blur, exposure или calibration error ухудшают visual constraints;
- timestamps между video, IMU и ToF сопоставлены с ошибкой.

### Required diagnostics

- registered image count and ratio;
- sequential and loop match counts;
- first/last camera position distance;
- first/last orientation difference;
- trajectory length and discontinuities;
- reprojection error distribution;
- sparse component count;
- scale estimate and uncertainty;
- known-distance measurement errors;
- IMU/video and ToF/video association coverage;
- sensor residuals before and after any correction.

### Phase 2 completion criteria

- deformation is represented by reproducible numeric metrics;
- at least one root cause is proven, not inferred from appearance alone;
- proposed correction has a defined input, optimizer location and weight model;
- regression thresholds are established before implementation;
- the same input can reproduce baseline and candidate results.

## Phase 3 — Controlled comparison

Status:

```text
TODO
```

### Objective

Изолировать фактический эффект IMU и ToF. Все три варианта должны использовать один и тот же immutable video input, одинаковые кадры и одинаковые COLMAP parameters, кроме явно отключаемого sensor contribution.

### Test A — Video only

```text
Video: enabled
IMU participation: disabled
ToF participation: disabled
```

Это базовая visual SfM reconstruction.

### Test B — Video + IMU

```text
Video: enabled
IMU participation: enabled
ToF participation: disabled
```

Тест должен изменять не только наличие sidecar, но и доказанный IMU consumer. Если IMU сейчас влияет только на frame selection, это должно быть явно указано в результате.

### Test C — Video + IMU + ToF

```text
Video: enabled
IMU participation: enabled
ToF participation: enabled
```

Тест должен включать доказанный ToF consumer. Одной генерации `tof_metric_observations.jsonl` недостаточно: нужно зафиксировать, как observations меняют scale, depth или reconstruction.

### Comparison metrics

| Category | Metric |
|---|---|
| Loop closure | Start/end translation error |
| Loop closure | Start/end orientation error |
| Trajectory | Self-intersection count or minimum non-neighbor segment distance |
| Trajectory | Drift per travelled metre |
| Scale | Estimated scale and uncertainty |
| Dimensions | Error against independently measured reference distances |
| Geometry | Reprojection error and registered image count |
| Geometry | Component count and completeness |
| Sensors | Association coverage and rejection ratio |
| Repeatability | Variation across repeated processing runs |

### Test validity rules

- use one immutable capture for A/B/C when technically possible;
- keep extracted frame set identical unless frame-selection influence is the variable under test;
- if IMU-based frame selection is evaluated, run it as a separate sub-comparison;
- keep camera model, feature, matcher and mapper settings identical;
- record every effective parameter and software version;
- do not compare only screenshots;
- report failures and missing artifacts, not only successful runs.

### Phase 3 completion criteria

- A, B and C are reproducible;
- sensor participation differs exactly as declared;
- all comparison metrics are produced from the same reference geometry;
- IMU improvement or lack of improvement is measurable;
- ToF improvement or lack of improvement is measurable;
- results identify the next implementation phase without relying on visual judgement alone.

## SINGLE acceptance criteria

SINGLE pipeline считается стабилизированным, когда одновременно выполнены следующие условия:

- круговой обход не создаёт спиральную деформацию;
- траектория не имеет необоснованного self-intersection;
- loop closure соответствует заранее зафиксированным numeric thresholds;
- масштаб и размеры объекта стабильны в повторных запусках;
- metric errors на known-distance references не превышают заранее утверждённые thresholds;
- участие IMU подтверждено code path, runtime evidence и controlled comparison;
- участие ToF подтверждено code path, runtime evidence и controlled comparison;
- pipeline, contracts, parameters, artifacts and failure behavior документированы;
- acceptance воспроизводится на controlled object scan.

## Rules and scope gate

До стабилизации SINGLE не переходить к развитию:

- `DUAL_PHONE`;
- `USB_RIG`;
- `LAPTOP`.

Исключения допустимы только для critical data-loss/security fixes или изменений, необходимых для воспроизводимого SINGLE baseline.

Порядок работ:

1. Завершить Phase 0.
2. Выбрать Phase 1.
3. Выполнить отдельное read-only исследование IMU.
4. Отдельно выполнить ToF participation audit.
5. Только после этого переходить к reconstruction changes и controlled comparison.

## Status summary

| Phase | Status | Exit condition |
|---|---|---|
| Phase 0 — Baseline freeze | IN PROGRESS | Immutable, inventoried and reproducible baseline |
| Phase 1 — Sensor participation audit | TODO | Stage-level proof of IMU and ToF participation |
| Phase 2 — Reconstruction stability | TODO | Proven root cause and measurable correction target |
| Phase 3 — Controlled comparison | TODO | Reproducible A/B/C evidence and quantified sensor effect |

