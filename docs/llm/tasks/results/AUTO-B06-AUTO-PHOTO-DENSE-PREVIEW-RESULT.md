# AUTO-B06 result

## Status
Implemented in the repository and synced to the web server. Focused PHP behavioral tests were executed on the server. No production dense job or production database mutation was performed as part of this acceptance.

## Implementation
A valid completed standalone Auto Photo sparse component with at least 10 registered images can enqueue an isolated `COLMAP_RECONSTRUCTION_PREVIEW` parent. It has `pipeline_run_id=NULL`, a new remote ID, exact merged PLY output path, Preview 640 settings snapshot and dense-only markers. Active/DONE preview duplicates are blocked transactionally.

The existing chunk planner/orchestrator/merge route is reused. Chunk jobs and retry jobs inherit the parent settings snapshot even when there is no pipeline run. The worker skips automatic mesh only if both dense-only markers are true. The Photo 3D UI renders the action, active processing state, dense metadata, and authenticated download link. The download endpoint revalidates the dense, sparse, prepare, bundle, manifest, output-path, and PLY boundaries before streaming the file.

## Verified
The following server-side behavioral tests returned `OK`:

- `auto_photo_dense_preview_enqueue_test.php`
- `auto_photo_dense_worker_contract_test.php`
- `auto_photo_dense_download_scope_test.php`
- `auto_photo_dense_ui_behavior_test.php`

## Runtime
Manual authorized GrafikStation execution is still required to prove real remote COLMAP processing, merged artifact fetch parity, and production PLY acceptance.
