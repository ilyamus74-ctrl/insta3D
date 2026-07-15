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

## 10. Дочерние задачи

### AUTO-000 — Discovery

Read-only исследование:

- фактический bundle;
- schema;
- storage;
- upload behavior;
- existing UI;
- existing worker transport;
- existing pipeline chain;
- минимальный план реализации.

### AUTO-B01 — Safe bundle indexer

Создать безопасную библиотеку индексации и `index.json`.

### AUTO-B02 — JPEG/thumbnail endpoint

Authenticated file endpoint и thumbnail cache.

### AUTO-B03 — Gallery

Страница полной gallery и contact sheet.

### AUTO-B04 — Simple View Photo SfM tab

Отдельная вкладка и bundle cards.

### AUTO-B05 — Pipeline creation endpoint

Создание `sfm_pipeline_runs` и первого PREPARE job.

### AUTO-B06 — PREPARE runner

Подготовка JPEG frames и result artifacts.

### AUTO-B07 — Worker chaining

PREPARE `DONE` → `COLMAP_SPARSE`.

### AUTO-B08 — Processing UI

Preview/Standard/FullHD cards и metrics.

### AUTO-B09 — Generated Models integration

Photo SfM results в существующем model/merge flow.

### AUTO-B10 — End-to-end acceptance

Фактический Preview pipeline и regression существующих flows.

---

## 11. Зависимости задач

```text
AUTO-000
  ↓
AUTO-B01
  ↓
AUTO-B02 ──→ AUTO-B03
  ↓
AUTO-B04
  ↓
AUTO-B05
  ↓
AUTO-B06
  ↓
AUTO-B07
  ↓
AUTO-B08
  ↓
AUTO-B09
  ↓
AUTO-B10
```

Некоторые UI-задачи могут выполняться параллельно после стабилизации `index.json`, но processing нельзя начинать до safe indexing.

---

## 12. Definition of Done epic

Epic считается завершённым, когда:

1. фактический auto-photo bundle безопасно индексируется;
2. counts и metadata отображаются;
3. thumbnails защищены access control;
4. в Simple View есть отдельная вкладка Photo SfM;
5. auto-photo не отображается как synced stereo dense;
6. Preview pipeline запускается без `EXTRACT_FRAMES`;
7. PREPARE передаёт готовые JPEG в `COLMAP_SPARSE`;
8. sparse metrics отображаются;
9. dense/mesh flow переиспользован;
10. результат появляется в Generated Models;
11. duplicate active start возвращает controlled conflict;
12. Video SfM и stereo flows проходят regression;
13. все runtime результаты подтверждены evidence.
