# AUTO-B01A-SAFE-BUNDLE-INDEXER-RESULT

## Status

PASS

## Changed files

- `web/libs/auto_photo_bundle_lib.php`
- `web/tools/auto_photo_bundle_index.php`
- `web/tests/auto_photo_bundle_lib_test.php`
- `docs/llm/tasks/results/AUTO-B01A-SAFE-BUNDLE-INDEXER-RESULT.md`

## Public functions

- `auto_photo_bundle_load_row(mysqli $db, int $captureBundleId): array`
- `auto_photo_bundle_resolve_archive_path(array $bundleRow): string`
- `auto_photo_bundle_build_index(mysqli $db, int $captureBundleId, array $options = []): array`
- `auto_photo_bundle_build_index_from_row(array $row, array $options = []): array`
- `auto_photo_bundle_write_index_atomic(array $index, string $targetPath): void`
- `auto_photo_bundle_normalize_photo_path(string $value): string`
- `auto_photo_bundle_index_cache_path(array $bundleRow, string $archivePath): string`
- `auto_photo_bundle_cli_should_write(array $args): bool`
- `auto_photo_bundle_cli_main(array $args): int`

## Validation rules

- DB lookup accepts only `capture_bundle_id` and loads the required `capture_bundles` columns.
- Only `auto_photo_session` is accepted; other capture types raise `unsupported_capture_type`.
- Archive paths are resolved from `APP_STORAGE_DIR` plus DB `storage_path`, must be `.tgz` or `.tar.gz`, regular non-symlink files, and must remain under `APP_STORAGE_DIR/orders`.
- TGZ inspection reads directly from a gzip stream with `gzopen`/512-byte tar headers. It does not copy the TGZ and does not decompress a full TAR into `/tmp`; JPEG payload is never stored in memory.
- Tar checksum, member count, declared unpacked bytes, per-member size, allowed path, duplicate name, typeflag, and `max_single_jpeg_bytes` are checked before any full payload buffering.
- Only regular tar files (`NUL` or `0` typeflag) are accepted. Hardlinks, symlinks, devices, directories, FIFOs, GNU longname, PAX, and unknown typeflags are invalid.
- Photo references are normalized through one function to `capture/photos/<filename>.jpg` from `photos/<filename>.jpg`, bare `<filename>.jpg`, or canonical archive paths.
- `bundle_manifest.photos_count`, `capture_manifest.photos_count`, and `capture_manifest.photos` are required with correct JSON types.
- Present metadata/IMU JSONL members are compared to JPEG count even when empty; metadata/IMU references are validated in both directions; absent optional members produce `missing_optional` warnings.
- `camera_info.json` fields are copied into the index when present; absent `camera_info.json` leaves camera fields `null` and emits a warning.
- Metadata values are preferred before IMU fallback for per-photo orientation fields. JPEG dimensions are parsed from streamed SOF markers when metadata dimensions are unavailable.
- Atomic writes use a lock, temporary file cleanup in `finally`, `fflush`, optional `fsync`, checked `rename`, and guaranteed lock release/handle close.

## Index schema

The generated index uses schema version `1` and includes bundle identity, DB-derived order/session IDs, archive metadata including SHA-256, photo/metadata/IMU/quality/event counts, camera info fields, validation status, warnings, blocking errors, and normalized photo records. Absolute server filesystem paths are not included in the public index payload.

## Test cases actually executed

Synthetic TGZ archives are generated from a raw TAR helper in the system temp directory. No production TGZ, database dump, or real JPEG fixture is committed or read. Tests enable `AUTO_PHOTO_BUNDLE_TEST_MODE=true` for the test-only `archive_path` option; production callers must resolve archives through DB row + `APP_STORAGE_DIR`.

Covered cases:

1. Valid archive with string `manifest.photos`.
2. Valid metadata `file` path under `photos/`.
3. Valid bare metadata filename.
4. Object-style manifest photo references.
5. `../evil.php` path traversal with exact `unsafe_archive_member` assertion.
6. `/absolute/path` absolute path with exact `unsafe_archive_member` assertion.
7. Backslash path with exact `unsafe_archive_member` assertion.
8. Symlink typeflag `2` with exact `unsupported_tar_type:symlink` assertion.
9. Hardlink typeflag `1` with exact `unsupported_tar_type:hardlink` assertion.
10. Character device typeflag `3` with exact `unsupported_tar_type:character_device` assertion.
11. Block device typeflag `4` with exact `unsupported_tar_type:block_device` assertion.
12. FIFO typeflag `6` with exact `unsupported_tar_type:fifo` assertion.
13. Directory typeflag `5` with exact `unsupported_tar_type:directory` assertion.
14. Duplicate member name with exact `duplicate_member` assertion.
15. Invalid tar checksum with exact `invalid_tar_checksum` assertion.
16. Truncated tar header with exact `truncated_tar_header` assertion.
17. Truncated member payload with exact `truncated_member` assertion.
18. Declared unpacked size limit before payload processing with exact `unpacked_bytes_limit_exceeded` assertion.
19. Member count limit with exact `member_count_limit_exceeded` assertion.
20. JPEG single-file size limit with exact `single_jpeg_limit_exceeded` assertion.
21. Missing `bundle_manifest.photos_count`.
22. Missing `capture_manifest.photos_count`.
23. Missing `capture_manifest.photos`.
24. Empty metadata member count warning.
25. Empty IMU member count warning.
26. Bidirectional metadata reference validation: missing referenced JPEG is invalid and JPEG missing metadata is warning even when counts match.
27. Camera info fields copied into the index.
28. `--dry-run` decision path does not create index/cache/lock.
29. Failed atomic write does not leave `.tmp` files.
30. Zero-size JPEG.
31. Invalid JPEG header.
32. UUID mismatch.
33. Capture type mismatch.
34. Missing referenced JPEG from manifest.
35. Duplicate sequence.
36. Sequence gap warning.
37. Optional metadata absent warning.
38. Metadata parse error.
39. Atomic replacement.
40. Idempotent second run.
41. Photo path normalizer rejects traversal.
42. Memory regression: 100 highly-compressible JPEG members × 1 MiB under `memory_limit=128M`.
43. Production bypass guard: `archive_path` option is rejected unless `AUTO_PHOTO_BUNDLE_TEST_MODE=true`.
44. Production integration path resolves a TGZ from temporary `APP_STORAGE_DIR/orders/...` without `AUTO_PHOTO_BUNDLE_TEST_MODE` and without `archive_path_option_forbidden`.

## Commands and exit codes

- `php -l web/libs/auto_photo_bundle_lib.php` → 0
- `php -l web/tools/auto_photo_bundle_index.php` → 0
- `php -l web/tests/auto_photo_bundle_lib_test.php` → 0
- `php web/tests/auto_photo_bundle_lib_test.php` → 0
- `php -d memory_limit=128M web/tests/auto_photo_bundle_lib_test.php` → 0
- `git diff --check` → 0
- `git status --short` → 0

## Not checked

- CLI was not executed against production bundle `#7` by design.
- No production database, production TGZ, or full database dump was used.
- No UI, worker, extraction, gallery, SfM, deployment, schema migration, or Android flow was changed.

## Result

PASS: the backend library and CLI implement safe streaming Auto Photo TGZ indexing within the allowed task scope, and the raw-TAR synthetic safety tests pass.
