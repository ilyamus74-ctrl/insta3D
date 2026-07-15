# AUTO-B01A-SAFE-BUNDLE-INDEXER — безопасная индексация Auto Photo TGZ

> Parent epic: `AUTO-PHOTO-EPIC.md`
> Depends on: `AUTO-000-DISCOVERY`, ручная runtime-проверка bundle `#7`
> Режим: `LOCAL_EDIT`
> Приоритет: P0
> Область: backend library
> Production execution: запрещено
> Deployment: запрещён

---

## 1. Цель

Реализовать серверную библиотеку, которая по `capture_bundle_id`:

1. получает запись `capture_bundles` из БД;
2. проверяет доступный `capture_type`;
3. разрешает TGZ path только через БД и `APP_STORAGE_DIR`;
4. безопасно инспектирует archive members;
5. читает manifest, metadata и IMU без распаковки в production storage;
6. проверяет consistency;
7. формирует нормализованный `index.json`;
8. атомарно сохраняет индекс в отдельный cache-каталог.

На этом этапе **не распаковывать JPEG**.

---

## 2. Подтверждённый фактический bundle contract

Проверенный bundle:

```text
capture_bundle_id = 7
app_bundle_uuid = b8b55de2-87ec-4665-912b-b1ee906e9569
capture_type = auto_photo_session
photos = 178
resolution = 4096x3072
```

Фактическая archive structure:

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
capture/photos/frame_000178.jpg
```

Фактические значения:

```text
archive members = 185
regular files = 185
unsafe entries = 0
JPEG count = 178
metadata records = 178
IMU records = 178
quality records = 715
events records = 2
total JPEG bytes = 577476199
largest JPEG = 5137777
```

---

## 3. Важные особенности формата

### 3.1 `manifest.photos`

В текущем schema version `1`:

```json
{
  "photos": [
    "photos/frame_000001.jpg",
    "photos/frame_000002.jpg"
  ]
}
```

Это массив строк, а не массив объектов.

Индексатор обязан поддерживать:

```text
string:
photos/frame_000001.jpg
```

Для forward compatibility допускается также объект:

```json
{
  "file": "photos/frame_000001.jpg"
}
```

или:

```json
{
  "filename": "frame_000001.jpg"
}
```

Но текущий подтверждённый формат — строка.

### 3.2 Metadata paths

В `photos_metadata.jsonl` и `imu.jsonl`:

```json
{
  "file": "photos/frame_000001.jpg",
  "filename": "frame_000001.jpg"
}
```

Canonical archive member:

```text
capture/photos/frame_000001.jpg
```

Нормализация:

```text
photos/frame_000001.jpg
→ capture/photos/frame_000001.jpg

frame_000001.jpg
→ capture/photos/frame_000001.jpg

capture/photos/frame_000001.jpg
→ capture/photos/frame_000001.jpg
```

Нельзя нормализовать в:

```text
capture/frame_000001.jpg
```

### 3.3 IMU format

`capture/imu.jsonl` содержит один IMU/orientation snapshot на сохранённую фотографию.

Запись содержит:

```text
photo_uuid
sequence
file
filename
timestamp_utc
timestamp_ms
device_rotation_degrees
physical_orientation
gravity
gyroscope
accelerometer
quaternion
camera_sensor_orientation
display_rotation
exif_orientation
image_rotation_degrees_applied
image_width
image_height
sharpness
duplicate_score
angular_velocity_deg_sec
acceleration_magnitude
```

Не требовать высокочастотного непрерывного IMU stream.

---

## 4. Target files

Основной новый файл:

```text
web/libs/auto_photo_bundle_lib.php
```

CLI для локальной проверки:

```text
web/tools/auto_photo_bundle_index.php
```

Тест:

```text
web/tests/auto_photo_bundle_lib_test.php
```

Если `web/tests/` отсутствует, разрешено создать каталог.

Не добавлять production TGZ или реальные JPEG в repository.

Тестовые archives должны генерироваться динамически во временном каталоге.

---

## 5. Files allowed to change

```text
web/libs/auto_photo_bundle_lib.php
web/tools/auto_photo_bundle_index.php
web/tests/auto_photo_bundle_lib_test.php
docs/llm/tasks/results/AUTO-B01A-SAFE-BUNDLE-INDEXER-RESULT.md
```

Допускается минимальное изменение существующего test runner только если он уже существует и без него тест нельзя запустить.

---

## 6. Files forbidden

Не изменять:

```text
web/www/order_simple.php
web/templates/maklertour_order_simple.html
web/www/api/mobile.php
web/www/api/capture_bundle_file.php
web/tools/sfm_remote_worker.php
web/remote_station/
Android
MySQL schema
migration files
existing pipeline endpoints
Generated Models
```

Не создавать:

```text
gallery
thumbnail endpoint
Photo SfM tab
PREPARE job
COLMAP job
```

---

## 7. Public library API

Предпочтительный минимальный API:

```php
auto_photo_bundle_load_row(
    mysqli $db,
    int $captureBundleId
): array;
```

```php
auto_photo_bundle_resolve_archive_path(
    array $bundleRow
): string;
```

```php
auto_photo_bundle_build_index(
    mysqli $db,
    int $captureBundleId,
    array $options = []
): array;
```

```php
auto_photo_bundle_write_index_atomic(
    array $index,
    string $targetPath
): void;
```

Названия могут быть скорректированы под текущий стиль проекта, но ответственность должна оставаться разделённой.

---

## 8. Database lookup

Принимать только:

```text
capture_bundle_id
```

Запрещено принимать от caller:

```text
storage_path
absolute path
order filesystem path
TGZ filename как источник истины
```

SQL должен получить:

```text
id
order_id
capture_session_id
app_bundle_uuid
capture_type
filename
storage_path
size_bytes
status
created_at
updated_at
```

Допустимый тип:

```text
auto_photo_session
```

Для других типов вернуть stable controlled error:

```text
unsupported_capture_type
```

---

## 9. Storage path rules

Путь строится:

```text
APP_STORAGE_DIR
+
capture_bundles.storage_path
```

Проверить:

```text
realpath archive существует
archive является regular file
archive не является symlink
archive находится внутри realpath(APP_STORAGE_DIR/orders)
extension .tgz или .tar.gz
size > 0
```

Не доверять `filename`.

Не включать absolute server path в public index JSON.

Внутренний индекс может хранить:

```text
storage_path
```

как DB-relative path.

---

## 10. Archive safety

Перед чтением содержимого проверить каждый archive member.

Запретить:

```text
absolute path
..
backslash path
NUL
symlink
hardlink
character device
block device
FIFO
неизвестный special member
duplicate archive member name
```

Разрешить только regular files с допустимыми путями.

Допустимые paths:

```text
bundle_manifest.json
capture/manifest.json
capture/camera_info.json
capture/photos_metadata.jsonl
capture/imu.jsonl
capture/quality.jsonl
capture/events.jsonl
capture/photos/*.jpg
```

Не разрешать произвольные дополнительные executable/config files.

Unexpected regular file должен вызывать:

```text
WARNING
```

или `INVALID`, если он находится вне разрешённого namespace.

Зафиксировать правило явно в коде и тестах.

---

## 11. Resource limits

Limits должны быть централизованными и configurable через options.

Defaults должны пропускать подтверждённый bundle:

```text
185 members
577949103 unpacked bytes
178 JPEG
largest JPEG 5137777 bytes
```

Минимально предусмотреть:

```text
max member count
max declared unpacked bytes
max single JPEG bytes
max JSON bytes
max JSONL bytes
max JPEG count
```

Нельзя загружать весь TGZ или все JPEG одновременно в память.

Inspection должна работать потоково.

---

## 12. Required paths

Обязательные:

```text
bundle_manifest.json
capture/manifest.json
минимум один capture/photos/*.jpg
```

Optional:

```text
capture/camera_info.json
capture/photos_metadata.jsonl
capture/imu.jsonl
capture/quality.jsonl
capture/events.jsonl
```

Отсутствующий optional-файл:

```text
WARNING
```

но не `INVALID`.

---

## 13. Manifest validation

### Bundle manifest

Проверить:

```text
bundle_schema_version
bundle_type
capture_type
app_bundle_uuid
photos_count
```

Ожидается:

```text
bundle_schema_version = 1
bundle_type = maklertour_capture_bundle
capture_type = auto_photo_session
app_bundle_uuid = DB app_bundle_uuid
```

### Capture manifest

Проверить:

```text
schema_version
capture_type
capture_uuid
photos_count
photos
```

Ожидается:

```text
schema_version = 1
capture_type = auto_photo_session
capture_uuid = DB app_bundle_uuid
```

Поля:

```text
order_id
server_capture_session_id
```

могут быть `null` в текущем Android bundle.

Связь с заявкой берётся из DB row, а не из Android manifest.

---

## 14. Path normalization

Создать одну отдельную функцию нормализации photo reference.

Пример:

```php
auto_photo_bundle_normalize_photo_path(
    string $value
): string;
```

Результат всегда:

```text
capture/photos/<safe-file-name>.jpg
```

Функция должна отклонять:

```text
../
absolute path
backslash
nested directory вне photos
не-JPEG extension
empty filename
```

Использовать одну и ту же функцию для:

```text
manifest photos
metadata file
metadata filename fallback
IMU file
```

---

## 15. Consistency validation

Посчитать:

```text
bundle manifest photos_count
capture manifest photos_count
capture manifest photos array count
actual JPEG count
metadata record count
IMU record count
quality record count
events record count
total JPEG bytes
```

Проверить:

```text
manifest photo reference существует в archive
каждый JPEG присутствует в manifest
metadata file reference существует
IMU file reference существует
sequence не дублируется
filename не дублируется
JPEG size > 0
JPEG header валиден
```

Sequence gaps:

```text
WARNING
```

Duplicate sequence:

```text
INVALID
```

Missing referenced JPEG:

```text
INVALID
```

JPEG отсутствует в manifest:

```text
WARNING
```

Count mismatch:

* manifest/JPEG mismatch — `INVALID`;
* optional metadata/JPEG mismatch — `WARNING`;
* parse error — `INVALID`.

---

## 16. Validation status

Возможные значения:

```text
VALID
WARNING
INVALID
```

### `VALID`

* archive безопасен;
* required manifests валидны;
* UUID и capture type совпадают;
* JPEG существуют;
* critical counts совпадают;
* JPEG headers валидны;
* blocking errors отсутствуют.

### `WARNING`

* optional metadata отсутствует;
* optional metadata count отличается;
* sequence gaps;
* часть orientation metadata отсутствует;
* unexpected non-critical metadata;
* DB size отличается от filesystem size.

### `INVALID`

* unsafe archive member;
* duplicate member;
* unreadable TGZ;
* required manifest отсутствует;
* JSON/JSONL parse error в required data;
* capture type mismatch;
* UUID mismatch;
* JPEG отсутствуют;
* duplicate sequence;
* zero-size JPEG;
* invalid JPEG header;
* required manifest references missing JPEG;
* limits exceeded.

---

## 17. `index.json`

Нормализованный индекс должен содержать минимум:

```json
{
  "schema_version": 1,
  "capture_bundle_id": 7,
  "capture_type": "auto_photo_session",
  "app_bundle_uuid": "...",
  "capture_uuid": "...",
  "order_id": 30,
  "capture_session_id": 63,
  "bundle_status": "UPLOADED",
  "archive_filename": "...",
  "archive_size_bytes": 572818552,
  "archive_sha256": "...",
  "photos_count_manifest": 178,
  "photos_count_actual": 178,
  "metadata_records": 178,
  "imu_records": 178,
  "quality_records": 715,
  "events_records": 2,
  "started_at_utc": "...",
  "finished_at_utc": "...",
  "camera_id": "0",
  "lens_label": "Main camera 1x",
  "zoom_ratio": 1.0,
  "sensor_orientation": 90,
  "focal_lengths_mm": [5.56],
  "image_width": 4096,
  "image_height": 3072,
  "total_jpeg_bytes": 577476199,
  "validation_status": "VALID",
  "warnings": [],
  "blocking_errors": [],
  "photos": []
}
```

Каждый элемент `photos`:

```json
{
  "sequence": 1,
  "archive_path": "capture/photos/frame_000001.jpg",
  "filename": "frame_000001.jpg",
  "photo_uuid": "...-1",
  "timestamp_utc": "...",
  "timestamp_ms": 0,
  "width": 4096,
  "height": 3072,
  "file_size_bytes": 4636633,
  "sharpness": 60.3,
  "duplicate_score": 0,
  "angular_velocity_deg_sec": 1.83,
  "physical_orientation": "portrait_upright",
  "exif_orientation": 6,
  "image_rotation_degrees_applied": 0
}
```

Недоступное значение:

```json
null
```

Не выдумывать metadata.

---

## 18. Index cache location

Предлагаемый путь:

```text
APP_STORAGE_DIR/orders/<order_id>/sessions/<session-storage-name>/
auto_photo_bundles/<capture_bundle_id>/index.json
```

Session storage root получить из безопасного DB-derived archive path:

```text
.../sessions/<session-storage-name>/capture_bundles/<archive>
```

Нельзя строить session storage name из client input.

Индекс сохранять:

```text
index.json.tmp.<random>
fsync/close, если доступно
rename → index.json
```

Использовать lock:

```text
.index.lock
```

Повторная индексация должна быть idempotent.

Если archive SHA-256 и index schema version совпадают, разрешено вернуть существующий index.

---

## 19. CLI

Пример:

```bash
php web/tools/auto_photo_bundle_index.php \
  --capture-bundle-id=7
```

CLI:

* использует project bootstrap/DB configuration;
* не принимает filesystem path;
* выводит compact JSON;
* возвращает exit code:

  * `0` для `VALID`;
  * `2` для `WARNING`;
  * `3` для `INVALID`;
  * `1` для runtime/internal error.

Добавить режим без записи:

```bash
--dry-run
```

В `--dry-run` индекс рассчитывается, но cache не изменяется.

---

## 20. Tests

Создать synthetic archives во временном каталоге.

Минимальные тесты:

1. Valid archive с manifest photos как strings.
2. Valid archive с metadata path `photos/frame_000001.jpg`.
3. Valid archive с bare metadata filename.
4. Manifest photos как objects для forward compatibility.
5. Path traversal.
6. Absolute path.
7. Symlink.
8. Hardlink.
9. Duplicate member name.
10. Missing bundle manifest.
11. Missing capture manifest.
12. No JPEG.
13. Zero-size JPEG.
14. Invalid JPEG header.
15. Manifest UUID mismatch.
16. Capture type mismatch.
17. Missing referenced JPEG.
18. JPEG absent from manifest.
19. Duplicate sequence.
20. Sequence gap.
21. Optional metadata absent.
22. Metadata parse error.
23. Limits exceeded.
24. Atomic index replacement.
25. Idempotent second run.

Не использовать production dump, production TGZ или реальные пользовательские фотографии.

---

## 21. Required checks

```bash
php -l web/libs/auto_photo_bundle_lib.php
php -l web/tools/auto_photo_bundle_index.php
php -l web/tests/auto_photo_bundle_lib_test.php
php web/tests/auto_photo_bundle_lib_test.php
```

Также:

```bash
git diff --check
git status --short
```

Не запускать CLI на production bundle в рамках Codex environment.

---

## 22. Result file

Создать:

```text
docs/llm/tasks/results/AUTO-B01A-SAFE-BUNDLE-INDEXER-RESULT.md
```

Указать:

```text
изменённые файлы
public functions
validation rules
index schema
test cases
commands
exit codes
непроверенное
PASS / PARTIAL / FAIL
```

---

## 23. Acceptance criteria

Задача получает `PASS`, если:

1. Bundle разрешается только через DB ID.
2. Path ограничен `APP_STORAGE_DIR/orders`.
3. Archive members проверяются до чтения content.
4. String-based `manifest.photos` поддерживается.
5. Metadata paths нормализуются правильно.
6. Dangerous entries отклоняются.
7. Inspection потоковая.
8. Actual photo count и metadata считаются.
9. `index.json` создаётся атомарно.
10. Повторный запуск idempotent.
11. Synthetic safety tests проходят.
12. UI, worker, database schema и Android не изменены.

---

## 24. Non-goals

Не реализовывать:

```text
JPEG extraction
thumbnail generation
gallery
Simple View tab
pipeline endpoint
PREPARE job
COLMAP
worker chaining
Generated Models
deployment
```
