# AUTO-000R-RUNTIME-BUNDLE — runtime-проверка Auto Photo bundle

> Parent task: `AUTO-000-DISCOVERY.md`
> Parent epic: `AUTO-PHOTO-EPIC.md`
> Режим: `REVIEW`
> Тип: production read-only inspection
> Source code changes: запрещены
> Database writes: запрещены
> Processing запускать: запрещено

---

## 1. Цель

Завершить runtime-часть `AUTO-000-DISCOVERY`.

Нужно найти фактически загруженный Android bundle:

```text
app_bundle_uuid =
b8b55de2-87ec-4665-912b-b1ee906e9569
```

и подтвердить:

```text
DB row
→ server storage file
→ TGZ structure
→ manifest
→ JPEG count
→ metadata count
→ IMU count
→ archive safety observations
```

После проверки обновить discovery evidence и определить точный контракт для `AUTO-B01-SAFE-BUNDLE-INDEXER`.

---

## 2. Environment

Работать на web server в актуальном checkout проекта.

Ожидаемый каталог:

```text
/home/makler/web
```

Перед началом выполнить:

```bash
pwd
git branch --show-current
git rev-parse HEAD
git status --short
```

Не выполнять:

```text
git pull
git commit
git push
deployment
service restart
worker start
database migration
```

---

## 3. Обязательное чтение

Прочитать:

```text
AGENTS.md
docs/llm/tasks/AUTO-PHOTO-EPIC.md
docs/llm/tasks/AUTO-000-DISCOVERY.md
docs/llm/tasks/results/AUTO-000-DISCOVERY-RESULT.md
docs/llm/04_CONTRACTS.md
docs/llm/07_BUILD_AND_TEST.md
docs/llm/08_KNOWN_PROBLEMS.md
```

Также проверить актуальные реализации:

```text
web/www/api/mobile.php
web/www/api/capture_bundle_file.php
web/www/order_simple.php
web/templates/maklertour_order_simple.html
```

---

## 4. Разрешённые изменения

Source code изменять запрещено.

Разрешено создать только:

```text
docs/llm/tasks/results/AUTO-000R-RUNTIME-BUNDLE-RESULT.md
```

Допускаются временные read-only inspection scripts только внутри:

```text
/tmp/
```

Они не должны добавляться в repository.

---

## 5. Поиск DB row

Найти запись:

```sql
SELECT
    id,
    order_id,
    capture_session_id,
    app_bundle_uuid,
    capture_type,
    filename,
    storage_path,
    size_bytes,
    status,
    created_at,
    updated_at
FROM capture_bundles
WHERE app_bundle_uuid = 'b8b55de2-87ec-4665-912b-b1ee906e9569'
   OR filename LIKE '%b8b55de2-87ec-4665-912b-b1ee906e9569%'
ORDER BY id DESC
LIMIT 1;
```

Использовать существующую application database configuration или отдельные read-only credentials.

Запрещено:

* выводить пароль;
* выводить DSN с секретом;
* добавлять credentials в report;
* изменять строку;
* выполнять `UPDATE`, `INSERT`, `DELETE`, `ALTER` или `CREATE`.

Зафиксировать:

```text
capture_bundle_id
order_id
capture_session_id
app_bundle_uuid
capture_type
status
filename
storage_path
size_bytes
created_at
updated_at
```

Ожидается:

```text
capture_type = auto_photo_session
status = UPLOADED
```

Расхождение не исправлять, а зафиксировать.

---

## 6. Безопасное разрешение storage path

Filesystem path брать только из найденной DB row.

Определить canonical storage root из текущего deployment/configuration.

Проверить:

```text
realpath(bundle)
starts with realpath(APP_STORAGE_DIR/orders)
regular file
not symlink
filename extension .tgz или .tar.gz
size > 0
```

Не принимать путь из command-line пользователя как источник истины.

Зафиксировать:

```bash
stat
sha256sum
```

Сравнить:

```text
DB size_bytes
actual filesystem size
Android reported size = 572818552
```

Несовпадение только записать в warnings.

---

## 7. Archive inspection

Не распаковывать архив в production storage.

Сначала выполнить read-only listing.

Проверить каждый member:

```text
path не абсолютный
нет ../
нет пустого имени
не symlink
не hardlink
не character device
не block device
не FIFO
не socket
```

Посчитать:

```text
member count
regular file count
directory count
declared total regular-file bytes
largest member
JPEG count
JSON count
JSONL count
other file count
```

Зафиксировать неизвестные или неожиданные entries.

---

## 8. Ожидаемые archive paths

Проверить фактическое наличие:

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

Не считать optional-файл обязательным только потому, что он указан в epic.

Для каждого пути установить:

```text
PRESENT
ABSENT_OPTIONAL
MISSING_REQUIRED
UNEXPECTED
```

---

## 9. JSON inspection

Читать JSON непосредственно из TGZ либо извлекать только конкретный проверенный regular member во временный каталог `/tmp`.

Ограничить размер читаемого JSON/JSONL.

Проверить:

### `bundle_manifest.json`

```text
bundle_schema_version
bundle_type
capture_type
app_bundle_uuid
photos_count
created_at_utc
app_package
app_version
```

### `capture/manifest.json`

```text
schema_version
capture_type
capture_uuid
local_session_id
order_id
server_capture_session_id
started_at_utc
finished_at_utc
camera_id
lens_label
zoom_ratio
photos_count
rejected_count
manual_photos_count
transition_events_count
settings
photos
```

Не требовать поля, которого фактически нет, без отдельного compatibility решения.

---

## 10. JPEG consistency

Посчитать:

```text
photos_count из bundle manifest
photos_count из capture manifest
количество JPEG entries
количество photo objects в manifest
количество строк photos_metadata.jsonl
```

Проверить sequence:

```text
first sequence
last sequence
duplicates
gaps
duplicate filenames
manifest file missing in TGZ
TGZ JPEG missing in manifest
zero-size JPEG
```

Для JPEG определить минимум:

```text
filename
size
width
height
valid JPEG header
```

Не извлекать все JPEG на production filesystem.

Допускается безопасное чтение JPEG headers из archive stream.

---

## 11. Metadata и IMU

Для `photos_metadata.jsonl` определить:

```text
record count
JSON parse errors
duplicate photo UUID
duplicate sequence
missing filename
filename without JPEG
physical_orientation distribution
missing orientation count
sharpness availability
angular velocity availability
```

Для `imu.jsonl` определить:

```text
line count
JSON parse errors
first timestamp
last timestamp
available sensor fields
```

Не включать сотни raw records в отчёт.

Привести только sanitized summary и один пример структуры без персональных данных.

---

## 12. Preliminary validation result

Без изменения кода рассчитать диагностический статус:

### `VALID`

* required manifests читаются;
* `capture_type=auto_photo_session`;
* UUID соответствует DB row;
* JPEG count больше нуля;
* manifest/JPEG/metadata counts согласованы;
* опасных archive entries нет.

### `WARNING`

Bundle можно обрабатывать, но имеются:

* отсутствующие optional metadata;
* count mismatch, который можно диагностировать;
* sequence gaps;
* часть orientation metadata отсутствует;
* DB size отличается от filesystem size.

### `INVALID`

* traversal;
* absolute path;
* link/device entry;
* unreadable TGZ;
* отсутствует required manifest;
* неправильный capture type;
* нет JPEG;
* manifest UUID не соответствует DB row;
* критически повреждённые JPEG.

Этот статус пока только report-level.

Не записывать его в DB и не создавать `index.json`.

---

## 13. Обязательный result-файл

Создать:

```text
docs/llm/tasks/results/AUTO-000R-RUNTIME-BUNDLE-RESULT.md
```

Структура:

```text
# AUTO-000R-RUNTIME-BUNDLE-RESULT

## Environment
Repository path:
Branch:
Commit:
Storage root:

## Database row
Bundle ID:
Order ID:
Capture session ID:
App bundle UUID:
Capture type:
Status:
Filename:
Storage path:
DB size:
Created:
Updated:

## Filesystem
Resolved path:
Regular file:
Symlink:
Filesystem size:
SHA-256:
Android reported size:

## Archive safety
Member count:
Regular files:
Directories:
Links:
Devices:
Traversal entries:
Declared unpacked bytes:
Largest member:
Unexpected entries:

## Bundle manifests
Bundle manifest:
Capture manifest:
Camera info:
Metadata:
IMU:
Quality:
Events:

## Counts
Bundle manifest photos:
Capture manifest photos:
Manifest photo objects:
Actual JPEG:
Metadata records:
IMU records:
Total JPEG bytes:

## JPEG properties
First sequence:
Last sequence:
Sequence gaps:
Duplicate sequences:
Duplicate filenames:
Dimensions:
Invalid JPEG count:
Zero-size JPEG count:

## Metadata summary
Orientation distribution:
Missing orientation:
Sharpness fields:
Angular velocity fields:
Parse errors:

## Validation
Status:
Warnings:
Blocking errors:

## Contract for AUTO-B01
Required paths:
Optional paths:
Limits inferred from actual bundle:
Index fields:
Compatibility notes:

## Evidence
Commands:
Exit codes:
Sanitized output:

## Conclusion
PASS / PARTIAL / FAIL
```

---

## 14. Запрещено

Не выполнять:

```text
tar -xzf в production storage
database writes
index creation
thumbnail generation
pipeline creation
worker start
COLMAP
order status change
service restart
deployment
commit
push
```

---

## 15. Acceptance criteria

Task получает `PASS`, когда:

1. DB row найдена.
2. Filesystem TGZ найден через DB path.
3. SHA-256 и размеры зафиксированы.
4. Все archive members проверены по типу и path.
5. Required manifests прочитаны.
6. JPEG и metadata counts посчитаны.
7. Preliminary validation установлен.
8. Exact contract для `AUTO-B01` сформирован.
9. Source code не изменялся.

`PARTIAL` допустим, если отсутствуют DB credentials или filesystem access, но нельзя заменять runtime evidence предположениями.
