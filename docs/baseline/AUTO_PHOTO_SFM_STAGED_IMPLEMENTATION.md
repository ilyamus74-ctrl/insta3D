# Auto Photo SfM — поэтапная реализация

## 1. Назначение

Документ задаёт последовательную сборку серверной обработки `auto_photo_session` в MaklerTour.

Цель — не создавать отдельную Photo SfM-подсистему, а поэтапно подключить готовые JPEG к существующему SfM/COLMAP pipeline:

```text
AUTO_PHOTO_PREPARE
→ COLMAP_SPARSE
→ COLMAP_RECONSTRUCTION_PREVIEW
→ COLMAP_MESH
```

После подтверждения Preview отдельно добавляются Standard, FullHD, cleanup и расширенный интерфейс.

Один этап означает:

1. одно отдельное задание для Codex;
2. один небольшой diff;
3. отдельный набор тестов;
4. отдельный deploy;
5. фактический runtime-тест;
6. запрет перехода дальше без приёмки текущего этапа.

---

## 2. Репозиторий и известный bundle

```text
Рабочее дерево: /home/makler/web
GitHub: https://github.com/ilyamus74-ctrl/insta3D
Ветка-источник: актуальный main
Web storage: /home/makler/web
GrafikStation storage: /home/makler_storage
```

Известный Auto Photo bundle:

```text
app_bundle_uuid:
b8b55de2-87ec-4665-912b-b1ee906e9569

filename:
maklertour_capture_bundle_auto_photo_session_b8b55de2-87ec-4665-912b-b1ee906e9569.tgz

known size:
572818552 bytes
```

Ожидаемая структура:

```text
bundle_manifest.json
capture/manifest.json
capture/camera_info.json
capture/photos_metadata.jsonl
capture/imu.jsonl
capture/quality.jsonl
capture/events.jsonl
capture/photos/frame_000001.jpg
capture/photos/frame_000002.jpg
...
```

---

## 3. Архитектурный принцип

### Video SfM

```text
EXTRACT_FRAMES
→ COLMAP_SPARSE
→ COLMAP_RECONSTRUCTION_PREVIEW/HQ
→ COLMAP_MESH
```

### Auto Photo SfM

```text
AUTO_PHOTO_PREPARE
→ COLMAP_SPARSE
→ COLMAP_RECONSTRUCTION_PREVIEW/HQ
→ COLMAP_MESH
```

Разница только в первом шаге.

Auto Photo уже содержит готовые JPEG. Для него запрещено создавать `EXTRACT_FRAMES`.

После появления каталога:

```text
/home/makler_storage/output/job_<prepare_remote_job_id>/frames
```

должна использоваться существующая цепочка Video SfM.

---

## 4. Общие правила для Codex

### 4.1. Работать только от актуального `main`

Перед каждым этапом:

```bash
git status --short
git rev-parse HEAD
git fetch origin
git diff --stat origin/main...HEAD
```

Не строить новый этап поверх неподтверждённого Auto Photo diff.

### 4.2. Большой diff использовать только как справочник

Большие Auto Photo diff-файлы нельзя применять целиком или продолжать исправлять. Из них разрешено брать только отдельные идеи, относящиеся к текущему этапу.

### 4.3. Не применять изменения автоматически

Codex должен:

1. исследовать актуальный код;
2. показать короткий план;
3. перечислить изменяемые файлы;
4. подготовить diff;
5. выполнить статические тесты;
6. показать результат;
7. не применять изменения без отдельного разрешения.

### 4.4. Не делать unrelated changes

Без прямой необходимости не менять:

- Android;
- Video SfM;
- Stereo;
- manual alignment;
- generated merge;
- viewer;
- существующую cleanup-архитектуру;
- настройки других pipeline.

### 4.5. Не создавать новые подсистемы

До отдельного этапа запрещены:

- bundle index worker;
- новый systemd service;
- web extraction cache;
- thumbnail worker;
- upload generation;
- lease/heartbeat indexer;
- новая очередь;
- новые таблицы индексации;
- отдельный dense/mesh pipeline для Auto Photo.

### 4.6. Проверки каждого этапа

```bash
git diff --check
php -l <каждый изменённый PHP>
bash -n <каждый изменённый shell script>
python3 -m py_compile <каждый изменённый Python>
```

Дополнительно выполняются только тесты текущего этапа.

### 4.7. Итоговый отчёт

Codex обязан показать:

```text
- base SHA;
- список изменённых файлов;
- назначение каждого изменения;
- полный diff;
- lint/test results;
- runtime IDs;
- runtime artifacts;
- найденные ограничения;
- что сознательно не реализовано;
- готовность к следующему этапу.
```

---

# Этап 0. Baseline

## Цель

Подтвердить актуальную архитектуру и фактическую структуру Auto Photo bundle без изменения production-кода.

## Исследовать

```text
web/www/order.php
web/www/order_simple.php
web/templates/maklertour_order_simple.html
web/tools/sfm_remote_worker.php
web/remote_station/sfm_pipeline.php
web/remote_station/sfm_cleanup.php
web/remote_station/run_extract_frames_job.sh
web/remote_station/run_colmap_sparse_job.sh
web/remote_station/scripts/process_extract_frames.sh
web/remote_station/scripts/process_colmap_sparse.sh
web/remote_station/deploy_station.sh
web/remote_station/fetch_job_result.sh
web/remote_station/get_station_status.sh
web/www/api/mobile.php
```

Найти существующие механизмы:

```text
создание sfm_pipeline_runs
создание root remote job
EXTRACT_FRAMES → COLMAP_SPARSE
SPARSE → PREVIEW/HQ
создание dense chunks
merge
mesh
Cancel
Restart
fetch
cleanup
Generated Models
Simple View
```

## Проверить реальный TGZ

```bash
tar -tzf <bundle.tgz> | sed -n '1,200p'
tar -xOzf <bundle.tgz> bundle_manifest.json
tar -xOzf <bundle.tgz> capture/manifest.json
tar -xOzf <bundle.tgz> capture/camera_info.json
```

Зафиксировать:

```text
capture_bundle_id
order_id
capture_session_id
capture_type
status
app_bundle_uuid
storage_path
size_bytes
photos_count
фактические sidecars
фактический шаблон JPEG
```

## Acceptance

Есть baseline-отчёт. Production-код не изменён.

## Промпт этапа 0

```text
Работаем в /home/makler/web, репозиторий ilyamus74-ctrl/insta3D.

Это только этап 0: исследование baseline. Production-код не менять.

1. Покажи актуальный HEAD SHA.
2. Исследуй Video SfM pipeline, worker, runners, cleanup,
   order.php, order_simple.php и Simple View.
3. Найди точные переходы:
   EXTRACT_FRAMES → SPARSE → PREVIEW/HQ → MESH.
4. Найди Cancel, Restart, fetch и cleanup.
5. Найди запись capture_bundles для UUID:
   b8b55de2-87ec-4665-912b-b1ee906e9569.
6. Безопасно исследуй TGZ и покажи manifests, sidecars,
   photos_count и шаблон JPEG.
7. Подготовь baseline-отчёт.
8. Не создавай diff.
```

---

# Этап 1. AUTO_PHOTO_PREPARE

## Цель

Реализовать один самостоятельный remote job:

```text
MAKLERTOUR_AUTO_PHOTO_PREPARE
```

На этом этапе pipeline заканчивается после PREPARE.

## Вход

```text
capture_bundle_id
app_bundle_uuid
capture_type=auto_photo_session
local TGZ path
remote_job_id
```

## Выход на GrafikStation

```text
/home/makler_storage/output/job_<remote_job_id>/
├── frames/
│   ├── frame_000001.jpg
│   └── ...
├── camera_metadata.json
├── scan_imu.jsonl
├── photos_metadata.jsonl
├── manifest.json
├── bundle_manifest.json
└── result.json
```

## Минимальный `result.json`

```json
{
  "ok": true,
  "job_type": "MAKLERTOUR_AUTO_PHOTO_PREPARE",
  "frames": 123,
  "manifest_photo_count": 123,
  "frames_path": "/home/makler_storage/output/job_123/frames",
  "camera_metadata_path": "/home/makler_storage/output/job_123/camera_metadata.json",
  "imu_path": "/home/makler_storage/output/job_123/scan_imu.jsonl",
  "photos_metadata_path": "/home/makler_storage/output/job_123/photos_metadata.jsonl"
}
```

## Разрешённые production-файлы

```text
web/migrations/<date>_add_auto_photo_prepare_stage.sql
web/remote_station/run_maklertour_auto_photo_prepare_job.sh
web/remote_station/scripts/process_maklertour_auto_photo_prepare.sh
web/remote_station/scripts/safe_extract_auto_photo_bundle.py
web/tools/sfm_remote_worker.php
```

Один тест:

```text
tests/auto_photo_prepare_regression_tests.py
```

## Требования к extractor

- не использовать `tar.extractall()`;
- запретить абсолютные пути и `..`;
- запретить `\\` в TAR path;
- запретить symlink/hardlink/device/FIFO/socket;
- разрешить только ожидаемые файлы;
- ограничить compressed и unpacked size;
- ограничить количество файлов;
- ограничить JPEG и JSON/JSONL;
- проверить manifests;
- проверить `capture_type` и `app_bundle_uuid`;
- проверить duplicate names и case collisions;
- проверить duplicate JPEG sequence;
- проверить JPEG SOI/SOF и размеры;
- проверить `photos_count`;
- проверить `camera_info.json` как JSON object;
- проверить `photos_metadata.jsonl`;
- проверить соответствие metadata sequences JPEG;
- устанавливать контролируемые права.

## Atomic publish

```text
staging
→ полная проверка
→ backup старого output
→ atomic publish
→ проверка опубликованного output
→ DONE
→ удаление backup
```

При ошибке:

```text
удалить новый output
→ восстановить backup
→ ERROR
```

При `INT`/`TERM`:

```text
CANCELLED или ERROR
→ восстановить backup
→ удалить staging
→ освободить flock
```

## Запрещено

- создавать `COLMAP_SPARSE`;
- dense/mesh;
- Photo SfM UI;
- Generated Models;
- Auto Photo cleanup;
- index worker/cache.

## Тесты

```text
missing manifest rejected
wrong UUID rejected
wrong capture_type rejected
duplicate TAR name rejected
case collision rejected
fake JPEG rejected
invalid camera JSON rejected
duplicate metadata sequence rejected
metadata without JPEG rejected
JPEG without metadata rejected
compressed size rejected
repeated prepare clears stale frames
failure after publish restores old output
SIGTERM restores old output
missing UUID releases lock
result paths exist after publish
```

## Runtime acceptance

```text
MAKLERTOUR_AUTO_PHOTO_PREPARE = DONE
```

Показать:

```text
remote_job_id
frames
manifest_photo_count
camera metadata path
IMU path
photos metadata path
remote status JSON
fetch result на web server
```

`COLMAP_SPARSE` отсутствует.

## Промпт этапа 1

```text
Реализуй только этап 1 из AUTO_PHOTO_SFM_STAGED_IMPLEMENTATION.md:
MAKLERTOUR_AUTO_PHOTO_PREPARE.

Не реализуй COLMAP_SPARSE, dense, mesh, Photo SfM UI,
Generated Models или Auto Photo cleanup.

Используй существующую SSH-controlled remote architecture.

Нужно:
1. одна migration для AUTO_PHOTO_PREPARE stage;
2. один safe extractor;
3. один station processing script;
4. один web-side runner;
5. минимальная поддержка job type в sfm_remote_worker.php;
6. один regression test.

Покажи diff и проверки. Не применяй автоматически.
После одобрения выполни реальный PREPARE для bundle UUID
b8b55de2-87ec-4665-912b-b1ee906e9569.
Не создавай COLMAP_SPARSE.
```

---

# Этап 2. PREPARE → COLMAP_SPARSE

## Предусловие

Этап 1 принят на реальном bundle.

## Цель

```text
AUTO_PHOTO_PREPARE = DONE
→ COLMAP_SPARSE = QUEUED
→ COLMAP_SPARSE = DONE
```

## Разрешённые файлы

```text
web/tools/sfm_remote_worker.php
web/remote_station/run_colmap_sparse_job.sh
web/remote_station/scripts/process_colmap_sparse.sh
один тест цепочки
```

PREPARE extractor не менять, если этап 1 прошёл.

## Child job contract

```json
{
  "source_type": "auto_photo_bundle",
  "capture_bundle_id": 123,
  "already_selected_frames": true,
  "input_images": 123,
  "camera_metadata_path": "/home/makler_storage/output/job_<prepare>/camera_metadata.json",
  "imu_jsonl_path": "/home/makler_storage/output/job_<prepare>/scan_imu.jsonl",
  "photos_metadata_path": "/home/makler_storage/output/job_<prepare>/photos_metadata.jsonl",
  "settings": {}
}
```

## Обязательные свойства

- parent remote job — PREPARE;
- input — remote PREPARE frames;
- `EXTRACT_FRAMES` отсутствует;
- child creation в транзакции;
- pipeline row читается `FOR UPDATE`;
- продолжение только для `QUEUED`/`RUNNING`;
- duplicate sparse невозможен;
- reconcile восстанавливает цепочку;
- station parameters передаются файлом/stdin, не shell argv;
- пути — удалённые, не web-local;
- source metadata сохраняется.

## Запрещено

- dense;
- mesh;
- UI;
- cleanup;
- Standard/FullHD.

## Тесты

```text
PREPARE DONE creates exactly one sparse
reconcile does not duplicate sparse
CANCELLING does not create sparse
CANCELLED does not create sparse
ERROR does not create sparse
station parameters contain remote paths
EXTRACT_FRAMES absent
worker restart recovers chain
```

## Runtime acceptance

```text
PREPARE = DONE
SPARSE = DONE
```

Показать:

```text
prepare remote_job_id
sparse remote_job_id
registered_images
sparse_points
sparse components
registration ratio
diagnostics path
```

## Промпт этапа 2

```text
Реализуй только этап 2:
AUTO_PHOTO_PREPARE → существующий COLMAP_SPARSE.

Этап 1 уже принят.
Не добавляй dense, mesh, UI или cleanup.

Используй существующий COLMAP_SPARSE runner и processing script.
Добавь только передачу Auto Photo source metadata
и атомарное создание child job.

Обязательно:
- EXTRACT_FRAMES отсутствует;
- remote metadata paths корректны;
- duplicate child невозможен;
- CANCELLING/CANCELLED/ERROR не продолжаются;
- параметры передаются файлом/stdin;
- Video SfM не меняется.

Покажи diff и тесты. Не применяй автоматически.
После одобрения выполни реальный PREPARE→SPARSE.
```

---

# Этап 3. SPARSE → Preview dense

## Предусловие

Этап 2 принят, sparse завершён на реальном bundle.

## Цель

```text
COLMAP_SPARSE
→ COLMAP_RECONSTRUCTION_PREVIEW
→ chunks
→ merge
→ merged_fused.ply
```

Mesh пока не обязателен.

## Требования

- использовать существующий выбор sparse model;
- использовать существующий chunk planner;
- использовать существующие dense chunks и merge;
- использовать Preview settings snapshot;
- наследовать `source_type` и `capture_bundle_id`;
- Cancel работает на parent/chunks;
- не создавать child при `CANCELLING`;
- результат открывается существующим viewer.

## Запрещено

- Standard/FullHD;
- mesh;
- Photo SfM UI;
- cleanup;
- отдельный dense pipeline.

## Runtime acceptance

```text
PREPARE = DONE
SPARSE = DONE
COLMAP_RECONSTRUCTION_PREVIEW = DONE
merged_fused.ply exists
```

Показать:

```text
selected sparse model
registered images
chunk count
done chunks
dense points
merged_fused.ply path
viewer URL
download URL
```

## Промпт этапа 3

```text
Реализуй только этап 3:
существующий COLMAP_SPARSE → существующий Preview dense pipeline.

Не создавай отдельный Auto Photo dense код.
Переиспользуй текущие reconstruction parent, chunk planning,
chunk jobs и merge.

Не добавляй mesh, Standard, FullHD, UI или cleanup.
Сохрани source_type=auto_photo_bundle и capture_bundle_id.

Покажи diff и тесты. После одобрения выполни реальный Preview
до merged_fused.ply.
```

---

# Этап 4. Минимальный Photo SfM UI

## Предусловие

Этап 3 создаёт валидный dense PLY.

## Цель

Добавить минимальное управление в Simple View.

## Разрешённые файлы

```text
web/www/order_simple.php
web/templates/maklertour_order_simple.html
web/www/order.php
```

Worker и station scripts не менять.

## UI показывает

- вкладку `Photo SfM`;
- только `auto_photo_session`;
- bundle ID, UUID, filename, size;
- количество кадров после PREPARE;
- pipeline ID;
- PREPARE/SPARSE/Preview IDs и statuses;
- progress;
- registered images;
- dense points;
- Start Preview;
- Cancel для active run;
- Restart для terminal run;
- viewer, PLY download, result JSON, log;
- GrafikStation metrics для active run.

## Обязательное поведение

- CSRF;
- серверная проверка прав;
- bundle принадлежит order/session;
- TGZ не читается при render;
- redirect:

```text
/order_simple.php?id=<order_id>#simple-photo-sfm
```

- hash активирует вкладку;
- Stereo показывает только `synced_depth_frames`;
- Video SfM не меняется.

## Запрещено

- thumbnails/gallery;
- расширенная диагностика;
- Standard/FullHD;
- mesh;
- cleanup;
- merge tools.

## Acceptance

Preview полностью запускается и контролируется из Simple View.

## Промпт этапа 4

```text
Реализуй только этап 4: минимальный Photo SfM UI.

Изменяй только order_simple.php, maklertour_order_simple.html
и минимальные POST handlers в order.php.

Не меняй worker, station scripts, pipeline или cleanup.

Добавь Photo SfM tab, Start Preview, status/progress,
Cancel, Restart terminal run, IDs/counters,
viewer/download/result/log, CSRF и redirect на #simple-photo-sfm.

Не добавляй gallery, thumbnails, Standard, FullHD, mesh
или Generated Models extensions.
```

---

# Этап 5. Mesh

## Предусловие

Preview стабильно завершается и открывается в viewer.

## Цель

```text
COLMAP_RECONSTRUCTION_PREVIEW
→ COLMAP_MESH
```

## Требования

- использовать существующий mesh runner;
- использовать Preview mesh settings;
- сохранять source metadata;
- pipeline `DONE` только после mesh;
- сохранять mesh path, vertices, faces;
- viewer/download работают;
- Cancel не создаёт mesh после отмены.

## Запрещено

- Standard/FullHD;
- cleanup;
- новая mesh реализация;
- расширенный UI.

## Runtime acceptance

```text
PREPARE = DONE
SPARSE = DONE
PREVIEW = DONE
MESH = DONE
PIPELINE = DONE
```

Показать:

```text
mesh remote_job_id
mesh vertices
mesh faces
mesh PLY path
viewer URL
download URL
```

## Промпт этапа 5

```text
Реализуй только этап 5:
Preview dense → существующий COLMAP_MESH.

Не добавляй новые mesh scripts.
Переиспользуй текущий mesh pipeline.
Не добавляй Standard/FullHD, cleanup или расширенный UI.
Проверь Cancel race перед созданием mesh.
```

---

# Этап 6. Standard и FullHD

## Предусловие

Preview pipeline стабилен до mesh.

## Цель

Разрешить:

```text
standard
fullhd
```

## Требования

- использовать существующие presets;
- использовать settings snapshot;
- не менять PREPARE/sparse contracts;
- отдельные runs по mode;
- блокировать duplicate active run для bundle+mode;
- сначала реальный Standard, затем FullHD.

## Промпт этапа 6

```text
Реализуй только этап 6: включи Standard и FullHD
для уже рабочего Auto Photo pipeline.

Не меняй PREPARE, sparse, dense и mesh архитектуру.
Используй существующие presets/settings snapshot.

После одобрения сначала выполни Standard.
FullHD запускать только после успешного Standard.
```

---

# Этап 7. Cleanup

## Предусловие

Preview/mesh artifacts подтверждены реальными runs.

## Цель

Подключить Auto Photo к существующему cleanup worker.

## Сначала только dry-run

Строгая проверка PREPARE:

```text
result.json valid
frames > 0
manifest_photo_count == actual JPEG count
camera_metadata.json valid
frames directory exists
JPEG count exact
нет активных зависимостей
```

## Затем controlled delete

После подтверждения dry-run:

```text
PREPARE workspace
SPARSE workspace
DENSE workspace
MESH workspace
```

по существующим правилам pipeline cleanup.

## Запрещено

- новый cleanup service;
- новая cleanup table;
- изменение standalone semantics;
- удаление до локальной верификации;
- удаление при active dependencies.

## Runtime acceptance

Показать:

```text
dry-run JSON
verified job IDs
deleted paths
freed bytes
локальные viewer artifacts доступны
pipeline logs сохранены
```

## Промпт этапа 7

```text
Реализуй только этап 7: подключение Auto Photo pipeline
к существующему cleanup worker.

Сначала только dry-run и строгая локальная верификация.
Не удаляй автоматически до отдельного одобрения.
Не создавай новый service или таблицу.
Не меняй cleanup других job types.
```

---

# Этап 8. Расширенный UI и диагностика

## Предусловие

Processing и cleanup приняты.

## Возможные отдельные подпункты

- thumbnail gallery;
- camera metadata summary;
- IMU summary;
- manifest summary;
- frame range;
- PREPARE warnings;
- sparse components table;
- registration ratio;
- reprojection diagnostics;
- GPU/RAM;
- Generated Models source label;
- manual alignment compatibility;
- incremental merge compatibility.

Каждый подпункт — отдельный diff.

## Ограничения

- не распаковывать TGZ при render;
- не читать большие JSONL целиком;
- UI не меняет processing contracts;
- не объединять gallery, diagnostics и merge UI в одном diff.

## Промпт этапа 8

```text
Реализуй только один согласованный подпункт этапа 8.
Не объединяй gallery, diagnostics, Generated Models
и merge UI в одном diff.

Pipeline, worker, station scripts и cleanup не менять,
если подпункт напрямую этого не требует.
```

---

## 5. Матрица этапов

| Этап | Результат | Реальный тест |
|---|---|---|
| 0 | Baseline | исследование TGZ |
| 1 | PREPARE DONE | TGZ → frames/result |
| 2 | SPARSE DONE | PREPARE → SPARSE |
| 3 | Preview dense DONE | merged_fused.ply |
| 4 | Минимальный UI | запуск из Simple View |
| 5 | Mesh DONE | mesh PLY/viewer |
| 6 | Standard/FullHD | два последовательных runs |
| 7 | Cleanup | dry-run → controlled delete |
| 8 | Расширенный UI | отдельные UX-тесты |

---

## 6. Стоп-условия

Следующий этап нельзя начинать, если:

- diff не прошёл lint;
- regression tests не прошли;
- runtime test не выполнен;
- нет remote job IDs;
- нет проверяемых artifacts;
- pipeline завис;
- Cancel не проверен;
- текущий этап содержит код следующего;
- Video SfM или Stereo получили регрессию;
- Codex не объяснил, какие существующие функции переиспользованы.

---

## 7. Формат отчёта Codex

```text
Stage:
Base commit:
Changed files:

Implementation:
- ...

Explicitly not implemented:
- ...

Static checks:
- git diff --check:
- php -l:
- bash -n:
- py_compile:
- regression tests:

Runtime:
- order_id:
- capture_session_id:
- capture_bundle_id:
- pipeline_run_id:
- prepare remote_job_id:
- sparse remote_job_id:
- preview remote_job_id:
- mesh remote_job_id:
- frames:
- registered_images:
- sparse_points:
- dense_points:
- mesh_vertices:
- mesh_faces:
- final status:

Artifacts:
- ...

Known limitations:
- ...

Ready for next stage:
YES / NO
```

---

## 8. Главное правило

```text
Не пытайся закончить весь Auto Photo SfM за один diff.

Реализуй только указанный этап.
Не добавляй код следующего этапа.
Не применяй изменения автоматически.
Не переходи дальше без фактического runtime acceptance.
```
## Delivery sequence

```text
SFM-C01
AUTO-B02
AUTO-B03
AUTO-B04
AUTO-B05
AUTO-B06
```
