# AUTO-B01B-SAFE-PHOTO-MATERIALIZER

Parent: `AUTO-B01A-SAFE-BUNDLE-INDEXER`

## Goal

Safely and atomically materialize JPEG files from a validated Auto Photo TGZ bundle.

Input is only:

```text
capture_bundle_id
```

Production code must resolve filesystem paths from the `capture_bundles` DB row and cached `index.json`; callers must not provide production paths or production index overrides.

## Source of truth

* `capture_bundles` DB row.
* Cached `auto_photo_bundles/<id>/index.json` created by B01A.
* Original TGZ referenced by the DB row.

## Preconditions

Before extraction or dry-run planning, validate:

* `index.schema_version`.
* `index.validation_status = VALID`.
* `index.capture_bundle_id = DB capture_bundle_id`.
* `index.app_bundle_uuid = DB app_bundle_uuid`.
* `index.archive_sha256 = current SHA-256(TGZ)`.
* `index.photos` is an array.
* `index.photos_count_actual = count(index.photos)`.
* `index.total_jpeg_bytes` equals the sum of indexed JPEG sizes.
* `index.blocking_errors` is empty.
* `index.photos[*].archive_path` and `filename` are canonical and unique.
* Indexed size, width and height are positive.

## Materialized layout

```text
auto_photo_bundles/<id>/
├── index.json
├── photos/
│   ├── frame_000001.jpg
│   ├── frame_000002.jpg
│   └── ...
└── materialization.json
```

`materialization.json` must not contain absolute server paths.

## Safety rules

Only JPEG files listed in `index.photos[*].archive_path` may be materialized.

Each archive member must pass the B01A streaming TAR checks, including checksum, typeflag, duplicate member, unsafe path, declared size, member count, member size, total unpacked size, JPEG size and truncation/padding checks.

Forbidden:

* absolute paths;
* `..`;
* backslash;
* symlink;
* hardlink;
* device;
* FIFO;
* directory member;
* duplicate member;
* unexpected JPEG;
* missing indexed JPEG.

Do not use `tar -x`, `PharData` extraction or shell extraction.

## Atomic publish

Under a bundle materialization lock:

1. Re-check existing `photos/` and `materialization.json` after acquiring the lock.
2. Create a staging directory.
3. Extract/write all indexed JPEGs into staging.
4. Validate each JPEG and fsync written files.
5. Create and fsync staging `materialization.json`.
6. Publish `photos/` and `materialization.json`.
7. On any failure, delete all artifacts created by the current run.
8. If `photos/` was published but `materialization.json` publish fails, rollback the current run's `photos/`.

If an existing materialization exactly matches the current index/archive/files, return `READY` with `idempotent=true`.

If existing state is incomplete or mismatched, return controlled error:

```text
existing_materialization_mismatch
```

## Dry-run

`--dry-run` must:

* load real `index.json` in production;
* verify current TGZ SHA-256;
* scan the full TAR stream;
* validate all members;
* verify the complete JPEG set;
* validate JPEG size, SOI, SOF, dimensions and EOI;
* create no base directory, lock, temporary file, `photos/` directory or materialization file.

## CLI

```bash
php web/tools/auto_photo_bundle_materialize.php --capture-bundle-id=7 --dry-run
php web/tools/auto_photo_bundle_materialize.php --capture-bundle-id=7
```

Exit codes:

* `0` — READY, DRY_RUN success or already correctly materialized.
* `2` — warning state if introduced by a future caller contract.
* `3` — validation/materialization error.
* `1` — internal/runtime error.

## Allowed files

Implementation may modify only B01B materializer-related files:

* `web/libs/auto_photo_bundle_materialize_lib.php`
* minimal shared streaming API in `web/libs/auto_photo_bundle_lib.php`
* `web/tools/auto_photo_bundle_materialize.php`
* `web/tests/auto_photo_bundle_materialize_test.php`
* this task file
* `docs/llm/tasks/results/AUTO-B01B-SAFE-PHOTO-MATERIALIZER-RESULT.md`

## Not allowed

Do not:

* run materialization for production bundle `#7`;
* accept production filesystem paths from callers;
* accept production `options['index']` override;
* use `tar -x`, shell extraction or `PharData` extraction;
* weaken B01A index validation;
* delete or mutate an existing mismatching materialization automatically;
* deploy, push, or run production DB/storage changes as part of this task.

## Lock

Use a per-bundle lock file named:

```text
.materialize.lock
```

Existing materialization must be re-checked after acquiring the lock. Concurrent runs must converge to one publisher and subsequent idempotent readers.

## Tests

Required materializer tests include:

* valid materialization;
* full dry-run scan;
* archive SHA mismatch and archive changed during materialization;
* index ID/UUID/status mismatch;
* missing indexed JPEG;
* unexpected JPEG;
* duplicate JPEG/member;
* unsafe path;
* special TAR types;
* invalid TAR size;
* member and JPEG limits;
* wrong file size;
* wrong dimensions;
* invalid SOI/SOF;
* missing EOI;
* truncated payload;
* partial disk write;
* atomic rollback after `photos/` publish;
* temporary cleanup;
* matching existing state;
* existing JPEG symlink rejection;
* extra file in existing photos;
* extra/ghost materialization JSON entry rejection;
* mismatching existing state;
* real parallel lock/idempotency;
* production index override forbidden;
* 100 JPEG × 1 MiB dry-run and materialization under `memory_limit=128M`.

Every negative test must assert a concrete controlled error code.

## Required checks

```bash
php -l web/libs/auto_photo_bundle_lib.php
php -l web/libs/auto_photo_bundle_materialize_lib.php
php -l web/tools/auto_photo_bundle_materialize.php
php -l web/tests/auto_photo_bundle_materialize_test.php

php web/tests/auto_photo_bundle_lib_test.php
php web/tests/auto_photo_bundle_materialize_test.php

php -d memory_limit=128M web/tests/auto_photo_bundle_lib_test.php
php -d memory_limit=128M web/tests/auto_photo_bundle_materialize_test.php

git diff --check
git status --short
```

## No production run

Do not run production bundle `#7` or any production materialization as part of this task. Use only synthetic fixtures in tests.

## No commit/push/deployment

Task-level instruction: do not push or deploy. Repository-level automation may still require local commit/PR metadata after changes; production deployment remains forbidden.
