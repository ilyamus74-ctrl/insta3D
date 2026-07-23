# AUTO-B06-AUTO-PHOTO-DENSE-PREVIEW

## Goal
Add a standalone, diagnostic `preview` dense reconstruction for one completed Auto Photo standalone sparse component.

## Contract
The order action accepts only a sparse DB ID and model ID, re-resolves the standalone Auto Photo sparse/prepare chain and the server-side component manifest, then creates one independent `COLMAP_RECONSTRUCTION_PREVIEW` row with `pipeline_run_id=NULL`, `dense_only=true`, and `standalone_auto_photo_dense=true`. The source sparse row and output are read-only.

## Processing
The established chunk planner, `COLMAP_DENSE_CHUNK`, retry orchestration, and dense merge are reused. Parent settings contain the normalized Preview 640 dense snapshot; every chunk and retry copies that snapshot. The two dense-only markers suppress automatic `COLMAP_MESH` only when both are present.

## Security
POST uses the existing order authorization and `secCode` CSRF boundary. Downloads use the existing authenticated endpoint and are allowed only for a DONE scoped standalone dense job whose exact expected PLY is non-symlink, regular, non-empty, contained in its job directory, and has a positive vertex count in a valid header.

## Non-goals
No Android, capture, prepare, sparse, `sfm_pipeline_runs`, schema, remote script, Video SfM, or mesh-chain redesign.

## Completed focused checks
The following behavioral tests were applied to the repository, synced to the web server, and returned `OK`:

- `web/tests/auto_photo_dense_preview_enqueue_test.php`
- `web/tests/auto_photo_dense_worker_contract_test.php`
- `web/tests/auto_photo_dense_download_scope_test.php`
- `web/tests/auto_photo_dense_ui_behavior_test.php`

The patch application workflow also completed PHP lint for changed production PHP files. A real GrafikStation `COLMAP_RECONSTRUCTION_PREVIEW` execution and resulting artifact acceptance remain manual and separately authorized.
