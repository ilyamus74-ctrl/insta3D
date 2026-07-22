# AUTO-PHOTO-EPIC — Automatic Apartment Photo Capture and Photo SfM

> Тип: parent epic / reference only  
> Статус: `IN_PROGRESS`  
> Область: Android Auto Photo bundle → server indexing → Photo SfM  
> Canonical capture type: `auto_photo_session`

---

## 1. Правило выполнения

Этот файл содержит полное направление работ.

Он **не является одной исполняемой задачей**.

Codex должен работать только по одному дочернему task-файлу за запуск.

Запрещено реализовывать весь epic одним diff.

---

## 2. Подтверждённая точка старта

Android уже:

- снимает автоматические JPEG;
- создаёт `manifest.json`;
- создаёт `camera_info.json`;
- создаёт `photos_metadata.jsonl`;
- создаёт `imu.jsonl`;
- создаёт дополнительные quality/events metadata, если они доступны;
- упаковывает capture session в TGZ;
- использует стабильный `capture_uuid`;
- ставит bundle в существующую upload queue;
- загружает bundle с `capture_type=auto_photo_session`;
- сервер сохраняет bundle в `capture_bundles`.

Android auto-photo capture не нужно реализовывать заново в рамках текущего серверного этапа.

Исправления Android допускаются только отдельными задачами после подтверждённого дефекта.

---

## 3. Известный тестовый bundle

Search key:

```text
app_bundle_uuid =
b8b55de2-87ec-4665-912b-b1ee906e9569
```

Известное имя файла:

```text
maklertour_capture_bundle_auto_photo_session_b8b55de2-87ec-4665-912b-b1ee906e9569.tgz
```

Размер, показанный Android:

```text
572818552 bytes
```

Эти значения используются только для диагностики и acceptance test.

Нельзя хардкодить найденный `capture_bundle_id`, `order_id`, `session_id` или filesystem path в реализации.

---

## 4. Цель epic

Создать серверный Photo SfM flow:

```text
auto_photo_session TGZ
→ safe indexing
→ gallery / thumbnails
→ Photo SfM tab
→ MAKLERTOUR_AUTO_PHOTO_PREPARE
→ COLMAP_SPARSE
→ existing reconstruction mode
→ existing mesh flow
→ Generated Models
```

Готовые Android JPEG являются source frames.

Запрещено создавать `EXTRACT_FRAMES` для auto-photo bundle.

---

## 5. Основные пользовательские результаты

### 5.1 Photo SfM tab

В Simple View должна появиться отдельная верхнеуровневая вкладка:

```text
Photo SfM
```

Она располагается рядом с:

- Overview;
- Sources;
- Video SfM;
- Stereo;
- Generated Models;
- Debug.

Photo SfM нельзя прятать только внутри Sources, Stereo, Video SfM или Debug.

### 5.2 Bundle card

Для каждого `capture_type=auto_photo_session` показывать:

- bundle ID;
- app bundle UUID;
- имя TGZ;
- archive size;
- capture session ID;
- upload time;
- capture start/end;
- validation status;
- manifest photo count;
- actual JPEG count;
- metadata record count;
- IMU record count;
- camera ID;
- lens label;
- zoom;
- image resolution;
- total JPEG bytes;
- warnings.

### 5.3 Gallery

Показывать:

- contact sheet;
- первые thumbnails;
- sequence;
- timestamp;
- sharpness;
- angular velocity;
- orientation;
- JPEG size;
- metadata warnings.

Не загружать сотни full-resolution JPEG при открытии страницы.

### 5.4 Processing

Для bundle доступны режимы:

- Preview;
- Standard;
- FullHD.

Первый обязательный flow:

```text
Run Preview Photo SfM
```

### 5.5 Metrics

После sparse показывать:

- input images;
- registered images;
- registration ratio;
- sparse points;
- sparse components.

После dense/mesh:

- dense points;
- mesh vertices;
- mesh faces;
- viewer/download links.

---

## 6. Безопасность

Обязательные требования:

- auth;
- CSRF;
- order access;
- write access для запуска;
- bundle resolution только через DB ID;
- storage path только из БД;
- safe root;
- archive inspection;
- запрет traversal;
- запрет symlink/hardlink/device entries;
- file count limit;
- unpacked size limit;
- per-file size limit;
- staging;
- atomic publish;
- lock;
- idempotent indexing;
- защита от duplicate active pipeline;
- безопасный JPEG endpoint.

---

## 7. Архив и индекс

Ожидаемая структура должна подтверждаться фактическим TGZ:

```text
bundle_manifest.json
capture/manifest.json
capture/camera_info.json
capture/photos_metadata.jsonl
capture/imu.jsonl
capture/quality.jsonl
capture/events.jsonl
capture/photos/frame_000001.jpg
...
```

Не предполагать наличие optional-файла без проверки.

После безопасной индексации создать `index.json`.

Минимальные поля:

```text
capture_bundle_id
capture_type
capture_uuid
order_id
capture_session_id
photos_count_manifest
photos_count_actual
metadata_lines
imu_lines
started_at_utc
finished_at_utc
camera_id
lens_label
zoom_ratio
image_width
image_height
total_jpeg_bytes
validation_status
warnings
```

Validation status:

```text
VALID
WARNING
INVALID
```

`INVALID` блокирует processing, но не удаляет bundle автоматически.

---

## 8. Pipeline contract

Первый job:

```text
MAKLERTOUR_AUTO_PHOTO_PREPARE
```

PREPARE должен:

1. получить TGZ через server-resolved path;
2. использовать безопасно проиндексированный bundle;
3. подготовить frames directory;
4. сохранить camera metadata;
5. сохранить IMU reference;
6. создать `result.json`.

После успешного PREPARE:

```text
COLMAP_SPARSE
```

Дальше максимально переиспользовать существующий pipeline:

```text
COLMAP_SPARSE
→ COLMAP_RECONSTRUCTION_PREVIEW / STANDARD / FULLHD
→ COLMAP_MESH
→ Generated Models
```

Не создавать параллельный SfM engine.

---

## 9. Запрещённые изменения

Без отдельной задачи нельзя:

- переписывать Android auto-photo capture;
- создавать вторую upload queue;
- создавать `photo_points` для каждого JPEG;
- менять существующие backend action names;
- ломать Video SfM;
- ломать synced depth;
- ломать legacy stereo;
- менять существующий COLMAP engine;
- менять generated models contract;
- менять manual alignment/merge flow;
- хардкодить тестовый bundle ID;
- принимать filesystem path от клиента.

---

## 10. Канонический task registry

### AUTO-000-DISCOVERY

Read-only discovery of the factual bundle, schema, storage, upload behavior,
existing UI, worker transport, and current pipeline boundaries.

### AUTO-000R-RUNTIME-BUNDLE

Runtime bundle verification and recorded evidence for the known Auto Photo
bundle, without widening the implementation scope.

### AUTO-B01A-SAFE-BUNDLE-INDEXER

Safe archive inspection and atomic normalized bundle index.

### AUTO-B01B-SAFE-PHOTO-MATERIALIZER

Safe, atomic JPEG materialization from a validated bundle index.

### AUTO-B02-AUTO-PHOTO-PREPARE

Validated Auto Photo prepare staging only.

### AUTO-B03-AUTO-PHOTO-SPARSE

Standalone sparse job from a completed prepare result, without automatic
post-sparse chaining.

### AUTO-B04-AUTO-PHOTO-SPARSE-REVIEW-EXPORT

Sparse review, strict model selection, exhaustive retry, and isolated PLY
export.

### AUTO-B05-AUTO-PHOTO-SIMPLE-VIEW

Simple View `Фото 3D` UI in four small slices:

- B05.1 / Patch 4A — pure UI DTO
- B05.2 / Patch 4B — read-only loader
- B05.3 / Patch 5A — read-only «Фото 3D» tab
- B05.4 / Patch 5B — select/retry/export actions

Future stages are intentionally not assigned task IDs until B05 acceptance.

---

## 11. Зависимости задач

```text
AUTO-000-DISCOVERY
  ↓
AUTO-000R-RUNTIME-BUNDLE
  ↓
AUTO-B01A-SAFE-BUNDLE-INDEXER
  ↓
AUTO-B01B-SAFE-PHOTO-MATERIALIZER
  ↓
AUTO-B02-AUTO-PHOTO-PREPARE
  ↓
AUTO-B03-AUTO-PHOTO-SPARSE
  ↓
AUTO-B04-AUTO-PHOTO-SPARSE-REVIEW-EXPORT
  ↓
AUTO-B05-AUTO-PHOTO-SIMPLE-VIEW
```

## 12. Definition of Done epic

Epic reaches the current milestone Definition of Done when:

1. AUTO-000-DISCOVERY и AUTO-000R-RUNTIME-BUNDLE зафиксированы.
2. AUTO-B01A и AUTO-B01B приняты.
3. AUTO-B02 Prepare принят на реальном bundle.
4. AUTO-B03 standalone sparse принят без автоматического post-sparse chain.
5. AUTO-B04 review/select/retry/export backend реализован и regression tests проходят.
6. Baseline job 746 и его output остаются неизменными.
7. AUTO-B05.1–B05.4 реализованы и приняты последовательно.
8. Simple View содержит отдельную вкладку «Фото 3D».
9. Model ID 0 корректно отображается и обрабатывается.
10. Select, exhaustive retry и isolated PLY export соблюдают permission, CSRF и locking.
11. Malformed data не вызывает fatal page error.
12. Video SfM и legacy flows проходят regression.
13. Никакой автоматический Preview, dense, mesh или legacy chain не запускается.
14. Production PLY export acceptance фиксируется отдельным evidence.

## Deferred long-term scope

Preview, dense, mesh and Generated Models integration remain possible
future product stages, but they are not assigned task IDs and are not
authorized until B05 acceptance.
