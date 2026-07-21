# AUTO-B01B-SAFE-PHOTO-MATERIALIZER — Result

Date: 2026-07-21

## Implemented

* B01B materialization remains separated in `web/libs/auto_photo_bundle_materialize_lib.php`.
* `web/libs/auto_photo_bundle_lib.php` keeps the shared B01A-compatible streaming TGZ/TAR member API.
* Existing materialization validation now rejects symlink `photos/` directories and symlink JPEGs using `lstat()` without following symlinks.
* Existing `materialization.json` validation now requires an exact `photos` array, exact counts, exact `total_bytes`, unique archive paths and filenames, and exact equality with the indexed photo set.
* Test-only materialization publish failure now fires after `photos/` was published and before `materialization.json` publish, with rollback of current-run `photos/`.
* Test-only fault-injection hooks are guarded by `AUTO_PHOTO_BUNDLE_TEST_MODE=true`.
* The archive SHA-256 is verified again after scan/extraction and before publish; changes are reported as `archive_changed_during_materialization`.
* Lock filename is `.materialize.lock`.
* Added real `pcntl_fork()` concurrency coverage.
* Added 100 JPEG × 1 MiB streaming dry-run and materialization regression under 128 MiB memory limit.
* Cleanup tracks staging, published `photos/`, and published `materialization.json` independently, so a pre-existing mismatching materialization is never deleted or changed.
* The materialization base directory is now canonicalized from the resolved archive path and bundle ID; symlink/path-escape bases are rejected before reading the index, creating a lock, or creating staging.
* Production `index.json` and `.materialize.lock` must be non-symlink regular files; existing symlinked or dangling `photos/` and symlinked `materialization.json` are rejected without following them.
* Added regression coverage for retained mismatching state, index/materialization/lock symlinks, dangling `photos/` symlink, and materialization base-directory path escape.
* Dry-run now validates existing materialization after the full TAR/JPEG scan and final archive SHA-256 check: it reports `idempotent=true` for a matching state and rejects a mismatching state without creating a lock or changing files.
* JPEG members are counted during streaming before extraction, including unexpected members; exceeding `max_jpeg_count` now returns `jpeg_count_limit_exceeded` for dry-run and normal materialization.
* Added dry-run existing-state preservation and `max_jpeg_count` limit/boundary regression coverage.
* JPEG validation now locates the last `FF D9` marker, accepts up to 16 trailing NUL bytes, and rejects non-NUL trailers or excessive NUL padding with dedicated errors. The extracted JPEG bytes are not modified.
* Added JPEG regression coverage for end-of-file EOI, 1/5/6/8/16 NUL trailers, excessive padding, non-NUL trailers, missing EOI, an earlier EXIF-thumbnail EOI, and full-byte preservation through dry-run, materialization, and idempotent validation.

## Checks run

| Command | Exit code |
| --- | ---: |
| `php -l web/libs/auto_photo_bundle_lib.php` | 0 |
| `php -l web/libs/auto_photo_bundle_materialize_lib.php` | 0 |
| `php -l web/tools/auto_photo_bundle_materialize.php` | 0 |
| `php -l web/tests/auto_photo_bundle_materialize_test.php` | 0 |
| `php web/tests/auto_photo_bundle_lib_test.php` | 0 |
| `php web/tests/auto_photo_bundle_materialize_test.php` | 0 |
| `php -d memory_limit=128M web/tests/auto_photo_bundle_lib_test.php` | 0 |
| `php -d memory_limit=128M web/tests/auto_photo_bundle_materialize_test.php` | 0 |
| `git diff --check` | 0 |
| `git status --short` | 0 (the unrelated untracked `app/MaklerTour/.gradle/` runtime state remains present) |

## Not run

* No production bundle #7 materialization was run.
* No deployment was performed.
* No production database was modified.
* No push was performed.
