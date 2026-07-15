Создай и выполни задачу:

`docs/llm/tasks/AUTO-B01B-SAFE-PHOTO-MATERIALIZER.md`

Parent:

`AUTO-B01A-SAFE-BUNDLE-INDEXER`

## Цель

Реализовать безопасную атомарную материализацию JPEG из валидного Auto Photo TGZ.

Вход:

```text
capture_bundle_id
```

Источник истины:

```text
capture_bundles DB row
index.json
оригинальный TGZ
```

Production filesystem path нельзя принимать от caller.

## Preconditions

Перед extraction обязательно проверить:

```text
index.validation_status = VALID
index.capture_bundle_id = DB capture_bundle_id
index.app_bundle_uuid = DB app_bundle_uuid
index.archive_sha256 = текущий SHA-256 TGZ
index.photos_count_actual = count(index.photos)
index.blocking_errors пустой
```

При несовпадении прекратить работу без частичной materialization.

## Target

Для bundle #7 итоговый каталог должен иметь структуру:

```text
auto_photo_bundles/7/
├── index.json
├── photos/
│   ├── frame_000001.jpg
│   ├── frame_000002.jpg
│   └── ...
└── materialization.json
```

## Безопасность

Разрешено извлекать только JPEG, перечисленные в:

```text
index.photos[*].archive_path
```

Каждый path должен повторно пройти canonical validation:

```text
capture/photos/<safe-filename>.jpg
```

Запретить:

```text
absolute paths
..
backslash
symlink
hardlink
device
FIFO
directory member
duplicate member
неожиданный JPEG
файл, отсутствующий в index
```

Не использовать:

```text
tar -x
PharData extraction
shell extraction
```

Использовать существующий streaming TGZ/TAR reader из B01A или безопасно расширить его.

## Atomic operation

Извлекать во временный каталог:

```text
auto_photo_bundles/<id>/.photos.tmp.<random>/
```

После успешной проверки всех файлов:

```text
rename temporary directory → photos
```

До rename итоговый `photos/` не должен быть виден.

При любой ошибке:

```text
удалить temporary directory
не изменять существующий photos/
не создавать materialization.json
```

Если `photos/` уже существует и его `materialization.json` соответствует archive SHA-256 и полному списку файлов, вернуть успешный idempotent result.

Если существующий каталог не соответствует индексу — controlled error:

```text
existing_materialization_mismatch
```

Не удалять его автоматически.

## Проверка извлечённых JPEG

Для каждого файла проверить:

```text
имя
размер
JPEG SOI/SOF
width
height
соответствие index
```

Во время extraction рассчитать:

```text
sha256 каждого JPEG
```

## materialization.json

Минимальная структура:

```json
{
  "schema_version": 1,
  "capture_bundle_id": 7,
  "app_bundle_uuid": "...",
  "archive_sha256": "...",
  "photos_count": 178,
  "total_bytes": 577476199,
  "status": "READY",
  "photos": [
    {
      "filename": "frame_000001.jpg",
      "archive_path": "capture/photos/frame_000001.jpg",
      "size_bytes": 4636633,
      "width": 4096,
      "height": 3072,
      "sha256": "..."
    }
  ]
}
```

Не включать absolute server paths.

`materialization.json` писать атомарно после готовности `photos/`.

## CLI

Создать:

```text
web/tools/auto_photo_bundle_materialize.php
```

Пример:

```bash
php web/tools/auto_photo_bundle_materialize.php \
  --capture-bundle-id=7 \
  --dry-run
```

`--dry-run`:

```text
проверяет DB, index, TGZ и план extraction
не создаёт directory
не создаёт lock
не извлекает JPEG
```

Обычный запуск:

```bash
php web/tools/auto_photo_bundle_materialize.php \
  --capture-bundle-id=7
```

Exit codes:

```text
0 READY или уже корректно материализован
2 WARNING
3 validation/materialization error
1 internal/runtime error
```

## Lock

Использовать:

```text
.materialize.lock
```

Не допускать двух одновременных materialization одного bundle.

Lock должен всегда освобождаться через `finally`.

## Allowed files

```text
web/libs/auto_photo_bundle_materialize_lib.php
web/tools/auto_photo_bundle_materialize.php
web/tests/auto_photo_bundle_materialize_test.php
docs/llm/tasks/AUTO-B01B-SAFE-PHOTO-MATERIALIZER.md
docs/llm/tasks/results/AUTO-B01B-SAFE-PHOTO-MATERIALIZER-RESULT.md
```

Минимальное изменение B01A library допускается только для переиспользования streaming TAR reader.

Не изменять:

```text
mobile.php
Simple View
gallery
worker
pipeline
COLMAP
database schema
Android
```

## Tests

Добавить synthetic tests:

```text
valid materialization
178-style sequential names
dry-run creates nothing
archive SHA mismatch
index bundle ID mismatch
index UUID mismatch
index status WARNING/INVALID
index photo absent from TGZ
unexpected JPEG in TGZ
duplicate JPEG member
unsafe path
symlink/hardlink/device/FIFO
wrong JPEG size
invalid JPEG
wrong dimensions
truncated JPEG
atomic publish
cleanup after failure
existing matching materialization
existing mismatching materialization
parallel lock
memory_limit=128M
```

Проверять конкретные error codes, а не только общий статус.

## Required checks

```bash
php -l web/libs/auto_photo_bundle_materialize_lib.php
php -l web/tools/auto_photo_bundle_materialize.php
php -l web/tests/auto_photo_bundle_materialize_test.php

php web/tests/auto_photo_bundle_materialize_test.php
php -d memory_limit=128M web/tests/auto_photo_bundle_materialize_test.php

git diff --check
git status --short
```

Не запускать materialization production bundle #7 из Codex environment.

Не делать commit, push или deployment.
