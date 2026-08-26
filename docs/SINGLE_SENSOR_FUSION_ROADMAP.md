# SINGLE Sensor Fusion Roadmap

## Status

```text
ROADMAP
IMPLEMENTATION NOT STARTED
```

## Purpose

Этот документ фиксирует переход SINGLE pipeline от текущего video-first processing к версионированному sensor-fusion contract, в котором IMU и ToF имеют доказуемое и измеримое влияние на reconstruction.

Основа:

- `docs/SINGLE_PIPELINE_ROADMAP.md`;
- `docs/SINGLE_IMU_PARTICIPATION_AUDIT.md`;
- `docs/SINGLE_TOF_PARTICIPATION_AUDIT.md`.

Это roadmap, а не утверждение о том, что target functionality уже реализована.

## 1. Current state

| Input | Status | Current participation |
|---|---|---|
| Video | **WORKING** | CameraX capture, upload, frame extraction and visual COLMAP reconstruction |
| IMU | **PARTIAL** | Timestamped sidecar; affects `auto_quality` frame selection and post-COLMAP diagnostics, but not pose optimization or bundle adjustment |
| ToF | **METRIC ONLY** | Calibrated metric observations and sparse/dense diagnostics; no applied scale, depth constraint or geometry mutation |

Current effective model:

```text
Video
  → selected RGB frames
  → visual matching
  → visual COLMAP mapper / bundle adjustment
  → sparse/dense geometry

IMU
  → frame-selection penalty/rejection
  → post-COLMAP diagnostics

ToF
  → temporal association
  → metric observations
  → scale/depth measurement reports
```

Текущий optimizer не имеет IMU или ToF residuals. Сенсоры не образуют единый optimization contract.

## 2. Target state — SINGLE SENSOR FUSION

Target pipeline:

```text
CaptureSessionDescriptor
  ├── camera/video timeline
  ├── IMU timeline + calibration + uncertainty
  ├── ToF timeline + calibration + uncertainty
  └── generated-file inventory
            ↓
      validated associations
            ↓
  visual + inertial trajectory optimization
            ↓
  reviewed ToF metric scale/depth constraints
            ↓
  fused metric reconstruction
            ↓
  validation report and reproducible acceptance result
```

Target properties:

- stable capture/session identity from Android through processing;
- explicit clock domains and association quality;
- versioned camera, IMU and ToF calibration references;
- uncertainty/weight for every active sensor constraint;
- deterministic sensor-off fallback;
- measurable delta between Video, Video+IMU and Video+IMU+ToF;
- no silent geometry mutation;
- every applied transform recorded in lineage artifacts;
- original visual reconstruction remains recoverable for comparison and rollback.

## 3. Migration phases

## Phase 0 — Documentation freeze

Status:

```text
IN PROGRESS
```

### Goal

Зафиксировать current behavior, identifiers, schemas, clock domains, sensor participation boundaries and baseline metrics before any optimizer or geometry change.

### Files expected to change

Documentation only:

- `docs/SINGLE_PIPELINE_ROADMAP.md`;
- `docs/SINGLE_IMU_PARTICIPATION_AUDIT.md`;
- `docs/SINGLE_TOF_PARTICIPATION_AUDIT.md`;
- `docs/SINGLE_SENSOR_FUSION_ROADMAP.md`;
- a future versioned SINGLE sensor contract/schema document;
- a future baseline artifact inventory for the accepted reference capture.

No Android, PHP, Python processing or database code should change in this phase.

### Required tests and evidence

- static producer/consumer inventory;
- immutable reference input checksums;
- effective pipeline parameter snapshot;
- current visual reconstruction metrics;
- current IMU frame-selection statistics;
- current ToF association and metric-observation statistics;
- confirmation that current geometry mutation is OFF.

### Ready criterion

- all existing cross-stage formats and IDs are documented;
- current Video/IMU/ToF participation status is unambiguous;
- reference capture and baseline outputs are reproducible;
- numerical baseline metrics exist for later A/B/C comparison;
- target contract changes can be reviewed without relying on undocumented runtime behavior.

## Phase 1 — CaptureSessionDescriptor

Status:

```text
TODO
```

### Goal

Ввести единый versioned `CaptureSessionDescriptor` для SINGLE capture и processing lineage.

Minimum descriptor content:

```text
schema_version
session_id
capture_id
bundle/upload identity
topology = SINGLE
capture type
camera identity/profile
IMU source/profile
ToF source/profile
clock domains and anchors
capture timestamps
generated files
checksums and sizes
required/optional status
association quality
applied transforms
```

### Files expected to change

Exact file list must be frozen in a separate implementation task. Expected areas:

- Android descriptor model/writer under `app/MaklerTour/app/src/main/java/.../data/capture/`;
- `PhoneCameraScanProvider.kt`;
- `ImuRecorder.kt` and ToF sidecar metadata producers only where descriptor references are required;
- `PhoneScanManifestWriter.kt` or its V2 replacement/adapter;
- `MobileUploadApi.kt`;
- `web/www/api/mobile.php`;
- `web/tools/sfm_remote_worker.php`;
- remote extract/materialization scripts;
- versioned JSON Schema and contract documentation;
- producer/consumer contract tests.

### Required tests

- Android descriptor serialization tests;
- stable-ID and clock-domain tests;
- generated-files inventory/checksum tests;
- upload compatibility tests with and without descriptor;
- PHP validation and authorization tests;
- safe path and sidecar validation tests;
- remote materialization tests;
- legacy capture compatibility test;
- missing optional IMU/ToF test;
- descriptor round-trip test Android → PHP → remote job.

### Ready criterion

- every new SINGLE capture has one valid descriptor;
- the descriptor survives upload without identity changes;
- every processing job records its source `capture_id` and descriptor version;
- all declared files are verified before processing;
- legacy captures continue through a documented adapter;
- sensor timestamps and calibration identities are machine-verifiable.

## Phase 2 — ToF scale application

Status:

```text
BLOCKED BY METRIC STABILITY REVIEW
```

### Goal

Перевести ToF из `METRIC ONLY` в controlled, reviewed metric scale application без преждевременного depth fusion.

Этап не должен автоматически применять один global scale, пока не объяснена зафиксированная distance-dependent instability.

### Files expected to change

Expected areas after a separate approved design task:

- `build_tof_metric_observations.py` only if its versioned observation contract must evolve;
- `measure_tof_sparse_scale.py` or a new reviewed scale-estimation module;
- a new non-destructive metric transform/apply stage;
- `sfm_remote_worker.php` routing and job lineage;
- sparse/dense downstream input selection;
- result/metric status schemas;
- viewer/measurement consumers that must distinguish original and metric-derived geometry;
- ToF scale contract and rollback documentation.

### Required tests

- known-distance calibration-object tests across the supported depth range;
- scale stability by distance bucket and ToF zone;
- cross-capture repeatability tests;
- invalid/unbound calibration rejection;
- insufficient observation coverage rejection;
- outlier and uncertainty propagation tests;
- no-ToF fallback test;
- original sparse immutability test;
- derived-model lineage/checksum test;
- scale apply/rollback test;
- A/B comparison: visual geometry versus reviewed metric-scaled geometry.

### Ready criterion

- scale estimator passes predefined distance/zone stability gates;
- applied scale has uncertainty and provenance;
- original COLMAP model remains immutable;
- derived metric model is created atomically and can be rejected independently;
- known dimensions improve measurably without degrading loop geometry;
- no automatic application occurs when gates fail;
- runtime report proves the exact scale transform applied.

## Phase 3 — IMU pose constraints

Status:

```text
TODO
```

### Goal

Перевести IMU из frame-selection/diagnostic input в calibrated pose-orientation constraints, влияющие на trajectory optimization.

Первая безопасная цель — gravity/orientation priors. Translation и scale не должны выводиться из accelerometer без bias, noise and observability model.

### Files expected to change

Expected areas after optimizer design approval:

- Android IMU calibration/profile and descriptor references;
- `imu_utils.py` or a versioned inertial preprocessing module;
- selected-frame/IMU association output;
- a new device-to-camera and camera-to-COLMAP transform module;
- COLMAP database pose-prior writer or a separate visual-inertial optimizer stage;
- sparse job runner and parameters;
- trajectory diagnostics and constraint residual reports;
- IMU calibration/constraint contract documentation.

### Required tests

- IMU/video timestamp alignment tests;
- device-to-camera extrinsic calibration tests;
- axis/sign/orientation convention tests;
- stationary gravity tests;
- gyro bias and drift characterization;
- rotation-vector/gyro consistency tests;
- pose-prior database/optimizer wiring tests;
- constraint weight and covariance tests;
- invalid/stale IMU fallback tests;
- visual-only versus visual+IMU controlled comparison;
- loop-closure and spiral-deformation regression tests.

### Ready criterion

- optimizer objective contains documented IMU-derived residuals;
- runtime artifacts report accepted/rejected constraints, weights and residuals;
- coordinate transforms are calibrated and versioned;
- visual-only fallback remains available;
- IMU improves orientation/loop metrics on the validation dataset;
- IMU does not introduce scale claims unsupported by the sensor model;
- repeated runs produce stable results within predefined thresholds.

## Phase 4 — IMU + ToF fusion

Status:

```text
TODO
```

### Goal

Объединить visual observations, IMU pose/orientation constraints and ToF metric evidence в один versioned fusion stage с explicit uncertainty, gates and rollback.

Responsibilities:

- visual features preserve geometric detail and correspondence;
- IMU constrains orientation/motion consistency;
- ToF supplies bounded metric depth/scale evidence;
- optimizer/fusion policy resolves conflicts using uncertainty rather than sensor presence alone.

### Files expected to change

Expected areas after completion of Phases 2 and 3:

- a new SINGLE sensor-fusion optimizer/module;
- fusion job type and worker routing;
- versioned fusion parameters and schema;
- constraint association artifacts;
- sparse/dense derived-model publication;
- metric confidence and failure-status logic;
- viewer/report support for visual, inertial and ToF residuals;
- CaptureSessionDescriptor processing lineage;
- operational and rollback documentation.

### Required tests

- Video-only, Video+IMU and Video+IMU+ToF runs from identical input;
- sensor disagreement and outlier tests;
- missing/degraded sensor fallback matrix;
- uncertainty-weight sensitivity tests;
- loop closure and trajectory drift metrics;
- metric dimension and scale stability metrics;
- sparse/dense geometry regression tests;
- deterministic/repeatability tests;
- long-capture resource and failure-recovery tests;
- lineage, artifact integrity and rollback tests.

### Ready criterion

- fusion is a declared processing mode, never inferred from file presence;
- active constraints and weights are visible in runtime reports;
- fused result measurably outperforms Video-only baseline on approved metrics;
- fused result also outperforms or safely matches Video+IMU where ToF is valid;
- failed sensor gates fall back without corrupting the visual result;
- metric claims are limited to validated coverage/range;
- final geometry, transforms and provenance are reproducible.

## Phase 5 — Validation dataset

Status:

```text
TODO
```

### Goal

Создать версионированный validation dataset и acceptance harness для доказуемой стабильности SINGLE sensor fusion.

Minimum capture classes:

- circular object scan with a closed path;
- textured and low-texture objects;
- known metric dimensions at multiple depths;
- near/mid/far ToF ranges;
- controlled rotations and pauses;
- captures with ToF absent/degraded;
- captures with IMU degraded or intentionally disabled;
- repeated captures with unchanged hardware/calibration;
- at least one negative case expected to fail sensor gates.

### Files expected to change

- dataset manifest and licensing/privacy documentation;
- immutable input/artifact storage index;
- expected-metrics files;
- evaluation scripts and fixtures;
- CI/offline validation entry point;
- acceptance report schema;
- calibration/profile snapshots associated with every capture;
- operational runbook for adding or revising cases.

Large media should remain in approved artifact storage rather than being added to Git unless repository policy explicitly permits it.

### Required tests

- dataset checksum and completeness validation;
- per-case descriptor/schema validation;
- automated A/B/C processing;
- start/end loop error;
- trajectory drift and self-intersection metrics;
- registered-frame and reprojection metrics;
- known-dimension absolute/relative errors;
- scale variation across distance buckets;
- repeatability across runs and captures;
- sensor association coverage/residual thresholds;
- fallback and expected-failure assertions.

### Ready criterion

- dataset is immutable, versioned and reproducible;
- ground-truth method and uncertainty are documented;
- acceptance thresholds are approved before candidate evaluation;
- A/B/C runs can be executed with one command or one documented workflow;
- results contain machine-readable and human-readable reports;
- SINGLE acceptance passes across the required case matrix;
- regressions block promotion of a new fusion contract/version.

## Phase dependencies

```text
Phase 0 Documentation freeze
  ↓
Phase 1 CaptureSessionDescriptor
  ├──→ Phase 2 ToF scale application
  └──→ Phase 3 IMU pose constraints
             └──┴──→ Phase 4 IMU+ToF fusion
                         ↓
                 Phase 5 Validation dataset acceptance
```

Phase 5 dataset definition should begin early enough to freeze metrics before optimizer tuning, but final Phase 5 acceptance follows the fusion implementation.

## Global safety and compatibility rules

- Do not treat sidecar presence as active sensor participation.
- Do not mutate original COLMAP artifacts in place.
- Do not apply scale without stability, calibration and uncertainty gates.
- Do not create IMU translation/scale constraints without a validated inertial model.
- Keep visual-only processing as the mandatory fallback.
- Record every applied transform and constraint in processing lineage.
- Version every cross-system schema.
- Separate measurement, candidate generation, reviewed application and fusion.
- Compare all sensor modes using immutable input and controlled parameters.
- Do not claim improvement without numerical A/B/C evidence.

## Final gate

> Do not start DUAL_PHONE migration until SINGLE baseline reaches stable sensor contract

