# AUTO-000-DISCOVERY-RESULT

Дата: 2026-07-15. Режим: REVIEW/read-only discovery. Source code не изменялся; создан только этот отчёт.

## Scope и доказательства

Типы доказательств: `CODE`, `SCHEMA`, `RUNTIME`, `ARTIFACT`, `INFERRED`, `UNKNOWN`.

## 1. Android bundle contract

- `capture_type` для Auto Photo канонически `auto_photo_session`: packager проверяет `capture/manifest.json` на это значение и пишет его в `bundle_manifest.json`. Доказательство: `CODE` `CaptureBundlePackager.packageAutomaticPhotoBundle`, строки 46-65.
- `app_bundle_uuid` равен `capture_uuid` из Android manifest; имя архива: `maklertour_capture_bundle_auto_photo_session_<capture_uuid>.tgz`. Доказательство: `CODE` `CaptureBundlePackager.packageAutomaticPhotoBundle`, строки 59-65.
- TGZ entries от packager: `bundle_manifest.json` плюс всё содержимое `captureDir` под prefix `capture/`; для auto-photo ожидаются фактические имена из captureDir, а не отдельный hardcoded список в packager. Доказательство: `CODE` `CaptureBundlePackager.packageAutomaticPhotoBundle`, строки 71-74; `CODE` `TarGzWriter.addDirectoryContents`, строки 136-145.
- Packager требует наличие `manifest.json`, `photos/`, равенство `photos_count` количеству `.jpg`, и non-empty JPEG. Доказательство: `CODE` строки 48-57.
- Upload action: `mobile.php?action=upload_capture_bundle`; multipart fields: `order_id`, `capture_session_id`, `upload_type=CAPTURE_BUNDLE`, `capture_type`, `app_bundle_uuid`, file part `capture_bundle`. Доказательство: `CODE` `MobileUploadApi.uploadCaptureBundle`, строки 210-243.
- Queue item type подтверждён на upload boundary как `upload_type=CAPTURE_BUNDLE`; полная Room queue identity/retry/original cleanup требуют более глубокого runtime/Room audit. Доказательство: `CODE` upload boundary; `UNKNOWN` для удаления originals после upload.

## 2. Backend upload receiver

- Receiver action: `upload_capture_bundle`. Требует bearer auth через `api_require_mobile_user`. Доказательство: `CODE` `web/www/api/mobile.php`, строки 1036-1038.
- Accepted `capture_type`: `synced_depth_frames`, `stereo_video_legacy`, `auto_photo_session`; accepted `upload_type`: `CAPTURE_BUNDLE`, `MAKLERTOUR_CAPTURE_BUNDLE`. Доказательство: `CODE` строки 1061-1067.
- Access checks: session must match order, user must be ADMIN or order operator/broker, order must not be READY/COMPLETED/CLOSED and not operator-closed. Доказательство: `CODE` строки 1069-1096.
- File validation: required part `capture_bundle`, upload error OK, `is_uploaded_file`, extension `.tgz` or `.tar.gz`, positive size. Доказательство: `CODE` строки 1104-1130.
- Storage path: `storage/orders/<order_id>/sessions/<safe_app_session_uuid>/capture_bundles/<safe_app_bundle_uuid>_<safe_original_name>`. Доказательство: `CODE` строки 1132-1163.
- DB insert/update: table is auto-created if missing, then `INSERT ... ON DUPLICATE KEY UPDATE`; duplicate key is only `(capture_session_id, filename)`, not `(capture_session_id, app_bundle_uuid)`. Доказательство: `CODE` строки 1188-1208; `SCHEMA` `capture_bundles` unique/indexes.
- Sidecar metadata `<tgz>.json` is written next to bundle. Доказательство: `CODE` lines 1169-1185.
- Upload is non-resumable for capture bundles; chunking exists for video upload but not in `uploadCaptureBundle`. Доказательство: `CODE` `MobileUploadApi.uploadCaptureBundle` sends single multipart, lines 221-239; `INFERRED` no bundle chunk endpoint found.
- PHP/web-server upload limits are not enforced in this handler beyond PHP upload error and positive size; repository docs mention high limits for large uploads. Доказательство: `CODE` lines 1108-1130; `CODE` `web/DOCS/sfm_web_upload_limits.md`.

## 3. Database schema

Schema source: `web/MySqlDump/maklertour_schema_20260715_163926.sql.gz`.

- `capture_bundles`: id, order_id, capture_session_id, app_bundle_uuid, capture_type, filename, storage_path, size_bytes, status, created_at, updated_at; unique `(capture_session_id, filename)`; indexes `(order_id,capture_session_id)`, `(capture_session_id,app_bundle_uuid)`. Доказательство: `SCHEMA`.
- `capture_sessions`: app_session_uuid unique, status enum `LOCAL_ONLY..FAILED`, soft-delete columns. Доказательство: `SCHEMA`.
- `tour_orders`: status enum includes `NEW`, `ASSIGNED`, `IN_PROGRESS`, `CAPTURED`, `UPLOADING`, `UPLOADED`, `PROCESSING`, `READY`, `COMPLETED`, `CLOSED`, `CANCELLED`; close fields. Доказательство: `SCHEMA`.
- `sfm_pipeline_runs`: tied to `video_scan_id` nullable; mode enum `preview/standard/fullhd`; stages do not include Photo PREPARE; no `capture_bundle_id`. Доказательство: `SCHEMA`; `INFERRED` Photo SfM first stage will need either schema extension or parameters-only linkage.
- `sfm_remote_jobs`: job_type string, parent_remote_job_id, pipeline_run_id nullable, parameters_json available, statuses include QUEUED/RUNNING/DONE/ERROR/CANCELLED variants. Доказательство: `SCHEMA`.
- `processing_jobs` success status is not a Photo SfM target; unique `(session_id,job_type)`. Доказательство: `SCHEMA`.
- `video_sfm_runs` is legacy/local Video SfM table, separate from current `sfm_pipeline_runs`. Доказательство: `SCHEMA`.

## 4. Runtime bundle `b8b55de2-87ec-4665-912b-b1ee906e9569`

`RUNTIME_NOT_AVAILABLE` in this container.

Repository filesystem search did not find the TGZ or DB row artifact:

```text
find . -name '*b8b55de2-87ec-4665-912b-b1ee906e9569*' -print
```

Exit code: 0; output contained only documentation references. Доказательство: `RUNTIME`.

Safe commands to run on web server:

```bash
mysql --defaults-extra-file=/path/to/readonly.cnf -N -e "SELECT id,order_id,capture_session_id,capture_type,status,filename,storage_path,size_bytes,created_at,updated_at FROM capture_bundles WHERE app_bundle_uuid='b8b55de2-87ec-4665-912b-b1ee906e9569' OR filename LIKE '%b8b55de2-87ec-4665-912b-b1ee906e9569%' ORDER BY id DESC LIMIT 1"
stat -c '%n %s' /resolved/storage/path/from/db
sha256sum /resolved/storage/path/from/db
LC_ALL=C tar -tvzf /resolved/storage/path/from/db | head -200
```

## 5. Фактическая структура TGZ

- Repository runtime TGZ not available, so counts are `UNKNOWN`: JPEG count, manifest photo count, metadata records, IMU records, total JPEG bytes, dimensions, first/last sequence, duplicates/missing sequence. Доказательство: `UNKNOWN`.
- Code-level expected top-level paths: `bundle_manifest.json` and `capture/*`. Expected inner files are whatever Auto Photo wrote into captureDir. Доказательство: `CODE` `CaptureBundlePackager`, lines 71-74.
- The Android packager writes regular tar entries only with typeflag `'0'`; it walks files only. It does not add symlinks/hardlinks/device entries. Доказательство: `CODE` `TarGzWriter`, lines 136-145; `INFERRED` archive safety still must be checked server-side before extracting.

## 6. Current Simple View UI

- `order_simple.php` queries `capture_bundles` by order and attaches `download_url` `/api/capture_bundle_file.php?capture_bundle_id=...` plus `inspect_url` `&sidecar=manifest`. Доказательство: `CODE` `web/www/order_simple.php`, line 168.
- Template shows total `Capture bundles`; Sources tab lists uploaded videos and capture bundles; Stereo tab lists capture bundles and offers `Run synced dense`. Доказательство: `CODE` `web/templates/maklertour_order_simple.html`, lines 25, 30, 36.
- No special-case UI for `auto_photo_session`; dense action is shown for every capture bundle in template, but API rejects non-`synced_depth_frames`. Доказательство: `CODE` template line 36; `CODE` API rejects line 15 in `create_capture_bundle_dense_job.php`; `CONFLICT` UI affordance vs API rule.
- Existing safe download endpoint validates login/order access, resolves DB path under `APP_STORAGE_DIR/orders`, allows `.tgz` and `.json`. Доказательство: `CODE` `capture_bundle_file.php`, lines 1-19.

## 7. Existing pipeline creation endpoints

- Synced dense endpoint: `web/www/api/create_capture_bundle_dense_job.php`; POST only; no CSRF token check in the API file; auth login required; order access check; duplicate active `MAKLERTOUR_SYNCED_DENSE` by `input_path` and status `QUEUED/RUNNING`; creates `sfm_remote_jobs`. Доказательство: `CODE` lines 1-21.
- Current Video SfM creation is represented by `sfm_pipeline_runs` and first remote job `EXTRACT_FRAMES`; Simple View builds per-video pipeline cards from `sfm_pipeline_runs` joined to `video_scans`. Доказательство: `CODE` `order_simple.php`, lines 169-171; `SCHEMA` `sfm_pipeline_runs`.
- Pipeline mode representation is `preview`, `standard`, `fullhd`; UI presets exist in `osv_pipeline_modes`. Доказательство: `CODE` `order_simple.php`, line 20; `SCHEMA`.

## 8. Worker transport (`sfm_remote_worker.php` / `web/remote_station`)

- Claim logic selects oldest `sfm_remote_jobs.status='QUEUED'` under transaction and marks RUNNING. Доказательство: `CODE` `sfm_remote_worker.php`, lines 1087-1103.
- Dispatch job types include `EXTRACT_FRAMES`, `COLMAP_SPARSE`, `COLMAP_RECONSTRUCTION_PREVIEW/HQ`, `COLMAP_MESH`, `MAKLERTOUR_SYNCED_DENSE`, dense chunks and exports. Доказательство: `CODE` worker dispatch/chaining search.
- Transfer uses remote station scripts with SSH/rsync/scp, e.g. `run_extract_frames_job.sh` creates station input/output dirs and transfers video/IMU. Доказательство: `CODE` `web/remote_station/run_extract_frames_job.sh`, lines 39-95.
- Result fetch uses `fetch_job_result.sh` with `remote_job_id`; worker then validates status/result and marks job DONE/ERROR. Доказательство: `CODE` `sfm_remote_worker.php`, lines 1458-1505.
- Chaining: `EXTRACT_FRAMES` done queues `COLMAP_SPARSE` unless run_scope is extraction-only; `COLMAP_SPARSE` done queues `COLMAP_RECONSTRUCTION_PREVIEW`; reconstruction queues `COLMAP_MESH`; mesh completes pipeline. Доказательство: `CODE` lines 293-324, 329-343, 347-381, mesh completion block.
- Cancellation and cleanup exist for remote jobs/pipelines. Доказательство: `CODE` lines 1524-1570.
- Minimal addition point for Auto Photo is a new first job `MAKLERTOUR_AUTO_PHOTO_PREPARE` that writes a result compatible with `COLMAP_SPARSE` input expectations, then worker chains it to `COLMAP_SPARSE`. Доказательство: `INFERRED` from existing `EXTRACT_FRAMES -> COLMAP_SPARSE` chaining and epic contract.

## 9. Existing COLMAP sparse/dense/mesh chain

- Frame preparation currently is `EXTRACT_FRAMES`; sparse job is `COLMAP_SPARSE`; dense job names are `COLMAP_RECONSTRUCTION_PREVIEW` and `COLMAP_RECONSTRUCTION_HQ`; mesh job is `COLMAP_MESH`. Доказательство: `CODE` worker chaining and `order_simple.php` job title mapping.
- Dense script reads sparse `result.json`, requires `frames_dir`, sparse model files `cameras.bin/images.bin/points3D.bin`, runs `image_undistorter`, `patch_match_stereo`, `stereo_fusion`, writes `fused.ply` and `result.json`. Доказательство: `CODE` `web/remote_station/scripts/process_colmap_dense.sh`, lines 84-123.
- Mesh script consumes parent reconstruction `merged/merged_fused.ply` and writes `mesh/mesh_final.ply` plus result JSON. Доказательство: `CODE` `process_colmap_mesh.sh`, lines 24-60.
- Generated Models integration is assembled in `osv_build_generated` from DONE pipeline runs and DONE manual reconstruction/mesh jobs with available PLY/result artifacts. Доказательство: `CODE` `order_simple.php`, lines 123-160.

## 10. Обязательная таблица состояния

| Область | Статус | Доказательство | Следующий шаг |
|---|---|---|---|
| Android JPEG capture | IMPLEMENTED | `CODE` packager requires JPEG photos and manifest count | Runtime inspect known TGZ |
| Manifest/metadata | PARTIAL | `CODE` `capture/manifest.json` required; optional metadata not runtime-confirmed | Index actual TGZ |
| TGZ packaging | IMPLEMENTED | `CODE` `bundle_manifest.json` + `capture/*` | Safe server indexer |
| Upload queue | PARTIAL | `CODE` upload action/fields confirmed; Room retry cleanup unknown | Audit queue entities |
| Server bundle upload | IMPLEMENTED | `CODE` mobile receiver stores DB+file | Add safe validation/index |
| Bundle idempotency | PARTIAL | `SCHEMA` unique filename only; app UUID indexed non-unique | Define duplicate UUID policy |
| Safe archive indexing | NOT_IMPLEMENTED | `UNKNOWN` no indexer endpoint/library found | AUTO-B01 |
| Photo gallery | NOT_IMPLEMENTED | `UNKNOWN` no auto-photo thumbnail/gallery found | AUTO-B02/B03 |
| Simple View Photo SfM tab | NOT_IMPLEMENTED | `CODE` tabs lack Photo SfM | AUTO-B04 |
| Pipeline creation | PARTIAL | `CODE` Video/Synced dense exists; no Photo SfM endpoint | AUTO-B05 |
| PREPARE job | NOT_IMPLEMENTED | `UNKNOWN` no `MAKLERTOUR_AUTO_PHOTO_PREPARE` | AUTO-B06 |
| Worker chaining | PARTIAL | `CODE` existing chain; no PREPARE branch | AUTO-B07 |
| COLMAP reuse | IMPLEMENTED | `CODE` sparse/dense/mesh chain exists | Adapt PREPARE result |
| Generated Models integration | PARTIAL | `CODE` Video models integrated | AUTO-B09 for Photo source labels |

## 11. Current flow map

```text
Android producer
→ queue
→ mobile API action upload_capture_bundle
→ capture_bundles row
→ server storage capture_bundles/<uuid>_<filename>.tgz
→ current order UI Sources/Stereo bundle card
→ current processing entrypoint only Video SfM or synced dense
→ remote worker
→ COLMAP sparse/dense/mesh for video flows
→ artifacts/viewer/generated models
```

Transition evidence:

- Android producer to API: `CODE` `MobileUploadApi.uploadCaptureBundle` fields.
- API to DB/storage: `CODE` `mobile.php` move + insert.
- DB/storage to UI: `CODE` `order_simple.php` query and template cards.
- UI to processing: `CODE` dense endpoint only for synced depth; Video SfM cards per video.
- Worker to COLMAP: `CODE` worker chain.
- Photo-specific processing: `UNKNOWN/NOT_IMPLEMENTED` beyond upload/storage.

## 12. Подтверждённые факты

1. Android Auto Photo bundle contract uses `capture_type=auto_photo_session`, `app_bundle_uuid=capture_uuid`, filename prefix `maklertour_capture_bundle_auto_photo_session_`, and entries `bundle_manifest.json` + `capture/*`. Доказательство: `CODE`.
2. Server accepts `auto_photo_session` in `upload_capture_bundle`, stores TGZ and sidecar JSON, records `capture_bundles`. Доказательство: `CODE`.
3. `capture_bundles` has no unique constraint solely on app bundle UUID. Доказательство: `SCHEMA`.
4. Current Simple View displays capture bundles but has no dedicated Photo SfM tab/gallery. Доказательство: `CODE`.
5. Existing COLMAP chain is reusable after a frames-preparation stage. Доказательство: `CODE` + `INFERRED`.

## 13. Конфликты документации и кода

- Epic expects Photo SfM tab; code has no Photo SfM tab. `CONFLICT`.
- Epic expects safe indexing and validation status; code stores raw TGZ without archive index. `CONFLICT`.
- Template displays synced dense button for all bundle cards, but API accepts only `synced_depth_frames`. `CONFLICT`.
- Duplicate app UUID policy is implied by retry identity, but schema unique key is filename-only; duplicate `app_bundle_uuid` can have multiple rows if filename differs. `CONFLICT/PARTIAL`.
- Epic first job `MAKLERTOUR_AUTO_PHOTO_PREPARE` does not exist in worker. `CONFLICT`.

## 14. Неизвестные данные

- Known bundle DB row, ID, order ID, session ID, status, storage path, file size, SHA-256: `UNKNOWN` due runtime unavailability.
- Actual TGZ listing, photo counts, dimensions, JSONL record counts, sequence gaps/duplicates: `UNKNOWN`.
- Android queue deletion of originals after upload: `UNKNOWN` in this report; upload boundary confirmed only.

## 15. Минимальные implementation tasks

### AUTO-B01
Goal: Safe bundle indexer creates `index.json` for `auto_photo_session` without unsafe extraction. Files to inspect/change: new backend library under `web/libs` or `web/tools`; readers in `capture_bundle_file.php`; possibly no schema. Forbidden: Android, worker, deployment. Schema change: avoid initially unless persistent DB status needed. Tests: `php -l`, tar traversal fixtures. Acceptance: counts/index/warnings for known bundle.

### AUTO-B02
Goal: Authenticated JPEG/thumbnail endpoint using indexed bundle paths. Files: `web/www/api/*`, new thumbnail cache helper. Forbidden: direct client paths. Schema: none preferred. Tests: path traversal, auth, `php -l`. Acceptance: first thumbnails served with order access.

### AUTO-B03
Goal: Gallery/contact sheet for indexed auto-photo bundle. Files: Simple View PHP/template plus JS/CSS if needed. Forbidden: full-resolution bulk load. Schema: none. Tests: template render smoke. Acceptance: sequence/timestamp/sharpness/IMU metadata displayed when present.

### AUTO-B04
Goal: Add top-level `Photo SfM` tab and bundle cards. Files: `order_simple.php`, `maklertour_order_simple.html`. Forbidden: processing changes. Schema: none. Tests: `php -l`, no template syntax errors. Acceptance: auto-photo cards separated from Stereo.

### AUTO-B05
Goal: Pipeline creation endpoint for Photo SfM Preview/Standard/FullHD. Files: new API endpoint, `sfm_pipeline.php`, UI form. Forbidden: worker execution. Schema: likely add `capture_bundle_id` or store in `parameters_json`; decide with migration audit. Tests: duplicate active conflict, CSRF/auth/access.

### AUTO-B06
Goal: PREPARE runner safely materializes JPEG frames and result.json. Files: remote_station script or web-side prep script, worker dispatch. Forbidden: COLMAP changes beyond input contract. Schema: none preferred. Tests: fixture TGZ, traversal rejection. Acceptance: frames dir and metadata generated.

### AUTO-B07
Goal: Worker chaining `MAKLERTOUR_AUTO_PHOTO_PREPARE -> COLMAP_SPARSE`. Files: `sfm_remote_worker.php`. Forbidden: Android/UI. Schema: none. Tests: unit/smoke with fake DONE PREPARE. Acceptance: sparse job queued once.

### AUTO-B08
Goal: Processing UI metrics for Photo SfM. Files: Simple View helpers/template, status APIs. Forbidden: pipeline creation changes. Schema: reuse existing metrics if possible. Tests: render DONE/RUNNING/ERROR states. Acceptance: registered images, ratio, sparse points/components, dense/mesh metrics.

### AUTO-B09
Goal: Generated Models integration labels Photo SfM results and supports existing viewer/download/manual merge. Files: `order_simple.php`, generated models APIs if source filters assume video. Schema: maybe source_type in parameters only. Tests: viewer URL/access. Acceptance: Photo models appear distinctly and merge candidates work.

### AUTO-B10
Goal: End-to-end acceptance on known bundle. Files: docs/test scripts only unless defects. Forbidden: new feature scope. Schema: none. Tests: full Preview pipeline, regression Video SfM/Stereo dense. Acceptance: evidence-backed PASS with counts and artifacts.

## 16. Checks

- `git status --short`: exit 0; only this report file is intended to be added/changed.
- `git diff --check`: to be run after report creation.

## 17. Итоговый статус

`PARTIAL` — repository-side code/schema/UI/worker discovery выполнено, но runtime DB/storage bundle and TGZ structure недоступны in this container.
