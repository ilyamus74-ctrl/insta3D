# AUTO-000-DISCOVERY — фактическое состояние Auto Photo / Photo SfM

> Parent epic: `AUTO-PHOTO-EPIC.md`  
> Режим: `REVIEW`  
> Тип: read-only discovery  
> Приоритет: P0 для последующей реализации  
> Изменение source code: запрещено

---

## 1. Цель

Зафиксировать фактическое серверное состояние `auto_photo_session` перед реализацией Photo SfM.

Android auto-photo уже работает.

Не исследовать задачу как новый Android feature.

Нужно подтвердить:

```text
Android TGZ upload
→ capture_bundles
→ storage
→ current UI
→ current worker transport
→ existing COLMAP chain
```

После исследования определить минимальные boundaries задач `AUTO-B01...AUTO-B10`.

---

## 2. Разрешённые изменения

Source code изменять запрещено.

Разрешено создать только отчёт:

```text
docs/llm/tasks/results/AUTO-000-DISCOVERY-RESULT.md
```

Не изменять другие файлы.

Не делать commit, push или deployment.

---

## 3. Обязательное чтение

Прочитать:

```text
AGENTS.md
docs/llm/00_PROJECT_OVERVIEW.md
docs/llm/02_ARCHITECTURE.md
docs/llm/03_MODULES.md
docs/llm/04_CONTRACTS.md
docs/llm/05_DATA_FLOWS.md
docs/llm/06_DEPENDENCIES.md
docs/llm/07_BUILD_AND_TEST.md
docs/llm/08_KNOWN_PROBLEMS.md
docs/llm/09_REFACTORING_ROADMAP.md
docs/llm/10_LLM_WORK_RULES.md
docs/llm/tasks/AUTO-PHOTO-EPIC.md
```

Профильные contracts:

```text
app/MaklerTour/docs/APP_CAMERA_STEREO_CONTRACT.md
web/DOCS/CAPTURE_BUNDLE_DENSE_CONTRACT.md
```

---

## 4. Известный bundle для поиска

Использовать как диагностический search key:

```text
app_bundle_uuid =
b8b55de2-87ec-4665-912b-b1ee906e9569
```

Ожидаемое имя:

```text
maklertour_capture_bundle_auto_photo_session_b8b55de2-87ec-4665-912b-b1ee906e9569.tgz
```

Размер, показанный Android:

```text
572818552 bytes
```

Не хардкодить найденные DB ID и paths в будущей реализации.

---

## 5. Обязательные области исследования

### 5.1 Android producer — только подтверждение контракта

Прочитать релевантные sections:

```text
app/MaklerTour/app/src/main/java/com/example/maklertour/auth/MobileUploadApi.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/data/capture/CaptureBundlePackager.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/state/AppStateViewModel.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/
app/MaklerTour/app/src/main/java/com/maklertour/data/repository/Repositories.kt
app/MaklerTour/app/src/main/java/com/maklertour/data/local/
```

Подтвердить:

- canonical `capture_type`;
- `app_bundle_uuid`;
- archive filename;
- archive entries;
- manifest filename;
- metadata filenames;
- queue item type;
- upload action;
- multipart fields;
- server capture session behavior;
- retry identity;
- удаляются ли originals после upload.

Не предлагать новый Android implementation.

### 5.2 Backend upload receiver

Исследовать:

```text
web/www/api/mobile.php
```

и связанные helpers.

Подтвердить:

- action для capture bundle;
- auth;
- order/session checks;
- accepted fields;
- size behavior;
- storage path creation;
- temp/final move;
- DB insert/update;
- idempotency;
- duplicate `app_bundle_uuid`;
- PHP/web-server upload limits;
- наличие или отсутствие chunk/resumable upload для bundle.

### 5.3 Database

Использовать актуальный schema-only dump.

Найти фактическую структуру:

```text
capture_bundles
capture_sessions
tour_orders
sfm_pipeline_runs
sfm_remote_jobs
processing_jobs
video_sfm_runs
```

Зафиксировать:

- columns;
- indexes;
- unique constraints;
- statuses;
- nullable relationships;
- fields, пригодные для Photo SfM;
- необходимость migration для первого этапа.

Не предлагать migration без доказательства необходимости.

### 5.4 Фактический runtime bundle

Если доступ к БД и storage существует, найти bundle:

```sql
SELECT *
FROM capture_bundles
WHERE app_bundle_uuid = 'b8b55de2-87ec-4665-912b-b1ee906e9569'
   OR filename LIKE '%b8b55de2-87ec-4665-912b-b1ee906e9569%'
ORDER BY id DESC
LIMIT 1;
```

Зафиксировать:

- capture bundle ID;
- order ID;
- capture session ID;
- capture type;
- status;
- storage path;
- filename;
- DB size;
- actual filesystem size;
- file existence;
- SHA-256, если безопасно и разумно.

Не выводить credentials или персональные данные.

### 5.5 Фактическая структура TGZ

Архив не распаковывать небезопасной командой в production path.

Сначала получить listing.

Проверить:

- top-level paths;
- absolute entries;
- `../`;
- symlink;
- hardlink;
- device entries;
- number of files;
- declared/unpacked sizes, если доступны.

Определить фактические имена:

- bundle manifest;
- capture manifest;
- camera info;
- photo metadata;
- IMU;
- quality;
- events;
- JPEG directory.

Посчитать:

- JPEG count;
- manifest photo count;
- metadata record count;
- IMU record count;
- total JPEG bytes;
- image dimensions distribution;
- first/last sequence;
- duplicate sequence;
- missing sequence.

### 5.6 Current order UI

Исследовать:

```text
web/www/order_simple.php
web/templates/maklertour_order_simple.html
web/www/order.php
```

Подтвердить:

- как сейчас загружаются `capture_bundles`;
- где показываются bundle;
- как формируются tabs;
- где находится Stereo;
- где находится Video SfM;
- где находятся Generated Models;
- какие actions доступны;
- есть ли special-case для `auto_photo_session`;
- существует ли безопасный download endpoint;
- можно ли переиспользовать UI cards.

### 5.7 Existing pipeline creation

Исследовать endpoints создания pipeline/jobs.

Найти:

- current Video SfM create endpoint;
- synced dense create endpoint;
- duplicate active run protection;
- CSRF handling;
- write access checks;
- parameters JSON;
- order status changes;
- pipeline mode representation.

### 5.8 Worker transport

Исследовать:

```text
web/tools/sfm_remote_worker.php
web/remote_station/
```

Подтвердить:

- claim logic;
- job type dispatch;
- station command format;
- input transfer;
- output path;
- status polling;
- result fetch;
- parent/child jobs;
- chaining после `EXTRACT_FRAMES`;
- chaining после `COLMAP_SPARSE`;
- dense/mesh continuation;
- stale recovery;
- cancellation;
- cleanup.

Определить минимальную точку добавления:

```text
MAKLERTOUR_AUTO_PHOTO_PREPARE
→ COLMAP_SPARSE
```

### 5.9 Existing COLMAP chain

Зафиксировать фактическую цепочку и job names:

```text
frame preparation
→ sparse
→ reconstruction mode
→ mesh
→ generated model
```

Не использовать conceptual names без сверки с кодом.

Определить:

- какой stage принимает frames directory;
- какие metadata можно передать;
- как выбирается sparse model;
- как считаются registered images;
- как считаются sparse components;
- как result попадает в viewer/models.

---

## 6. Запрещено

В рамках AUTO-000 запрещено:

- изменять PHP;
- изменять Android;
- изменять template;
- добавлять endpoint;
- добавлять job type;
- изменять schema;
- распаковывать TGZ в production output;
- запускать SfM;
- запускать worker;
- создавать pipeline run;
- менять order status;
- делать deployment;
- делать commit/push.

---

## 7. Формат доказательств

Для каждого вывода использовать один из типов:

```text
CODE:
file path + function/class + line range

SCHEMA:
table/column/index

RUNTIME:
command + exit code + sanitized output

ARTIFACT:
archive entry / JSON field / count

INFERRED:
вывод из нескольких подтверждённых фактов

UNKNOWN:
данных недостаточно
```

Не выдавать `INFERRED` за `CONFIRMED`.

---

## 8. Обязательная таблица состояния

В отчёте создать таблицу:

| Область | Статус | Доказательство | Следующий шаг |
|---|---|---|---|
| Android JPEG capture | IMPLEMENTED/PARTIAL/UNKNOWN | | |
| Manifest/metadata | | | |
| TGZ packaging | | | |
| Upload queue | | | |
| Server bundle upload | | | |
| Bundle idempotency | | | |
| Safe archive indexing | | | |
| Photo gallery | | | |
| Simple View Photo SfM tab | | | |
| Pipeline creation | | | |
| PREPARE job | | | |
| Worker chaining | | | |
| COLMAP reuse | | | |
| Generated Models integration | | | |

Допустимые статусы:

```text
IMPLEMENTED
PARTIAL
NOT_IMPLEMENTED
CONFLICT
UNKNOWN
```

---

## 9. Обязательный отчёт по фактическому bundle

Если runtime доступен:

```text
Bundle ID:
App bundle UUID:
Order ID:
Capture session ID:
Capture type:
Status:
Filename:
Storage path:
DB size:
Filesystem size:
SHA-256:

Archive entries:
JPEG count:
Manifest photo count:
Metadata records:
IMU records:
Total JPEG bytes:
Image dimensions:
Validation observations:
```

Если runtime недоступен, написать:

```text
RUNTIME_NOT_AVAILABLE
```

и дать точные безопасные команды для выполнения на web server.

---

## 10. Карта current flow

Отчёт должен содержать фактическую карту:

```text
Android producer
→ queue
→ mobile API action
→ capture_bundles row
→ server storage
→ current order UI
→ current processing entrypoint
→ remote worker
→ COLMAP
→ artifacts/viewer
```

Для каждого перехода указать:

- producer;
- consumer;
- identifier;
- status;
- file/path;
- confirmed/unknown.

---

## 11. Результат discovery

В конце сформировать:

### 11.1 Подтверждённые факты

Только evidence-backed.

### 11.2 Конфликты документации и кода

Например:

- другое имя field;
- другой status;
- другая archive structure;
- другой job name;
- другой path.

### 11.3 Неизвестные данные

Что требует runtime или production access.

### 11.4 Минимальные implementation tasks

Для каждой следующей задачи:

```text
Task ID:
Goal:
Files to inspect:
Files allowed to change:
Files forbidden:
Schema change:
Required tests:
Acceptance:
Dependencies:
```

Подготовить boundaries для:

```text
AUTO-B01
AUTO-B02
AUTO-B03
AUTO-B04
AUTO-B05
AUTO-B06
AUTO-B07
AUTO-B08
AUTO-B09
AUTO-B10
```

Не реализовывать их.

---

## 12. Проверки

Так как задача read-only:

```bash
git status --short
git diff --check
```

Ожидается, что source diff отсутствует.

Допускается только новый/изменённый report-файл:

```text
docs/llm/tasks/results/AUTO-000-DISCOVERY-RESULT.md
```

---

## 13. Итоговый статус

Использовать `PASS`, если code/schema map выполнена, runtime bundle проверен, worker transport подтверждён и next-task boundaries подготовлены.

Использовать `PARTIAL`, если отсутствует runtime DB/storage access, но code/schema discovery выполнено.

Использовать `FAIL`, если невозможно подтвердить даже repository-side flow.

---

## 14. Итоговый ответ Codex

Кратко вывести:

1. Result file.
2. Bundle ID и counts, если доступны.
3. Текущую точку готовности.
4. Главные gaps.
5. Рекомендуемую следующую задачу.
6. `PASS/PARTIAL/FAIL`.
