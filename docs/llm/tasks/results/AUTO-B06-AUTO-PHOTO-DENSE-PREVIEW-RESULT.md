# AUTO-B06 result

## Status
Closed and accepted. The implementation is in the repository, deployed to the web server and GrafikStation, and verified with a real production Dense Preview artifact.

## Implementation
A valid completed standalone Auto Photo sparse component with at least 10 registered images can enqueue an isolated `COLMAP_RECONSTRUCTION_PREVIEW` parent. It has `pipeline_run_id=NULL`, a new remote ID, exact merged PLY output path, Preview 640 settings snapshot, and dense-only markers. Active or completed preview duplicates are blocked transactionally.

The existing chunk planner, orchestrator, retry, and merge route is reused. Chunk and retry jobs inherit the parent settings snapshot without requiring a pipeline run. Dense-only JPEG copies strip APP1, APP13, and COM metadata before COLMAP while preserving image scan data and leaving source photos unchanged. The worker suppresses automatic mesh only when both dense-only markers are true.

The Photo 3D UI now provides one primary `Создать 3D-модель` action. A completed result exposes `Открыть 3D` and `Скачать PLY`. The authenticated browser viewer and download endpoint both revalidate the complete dense, sparse, prepare, bundle, manifest, output-path, and PLY scope.

## Verified
The following server-side behavioral tests returned `OK`:

- `auto_photo_dense_preview_enqueue_test.php`
- `auto_photo_dense_worker_contract_test.php`
- `auto_photo_dense_download_scope_test.php`
- `auto_photo_dense_ui_behavior_test.php`
- `auto_photo_dense_image_sanitizer_test.php`
- `auto_photo_dense_viewer_contract_test.php`

## Runtime evidence
- Sparse DB job: `752`
- Sparse remote job: `658883972`
- Dense DB job: `759`
- Dense remote job: `897481444`
- Model ID: `0`
- Artifact: `job_897481444_merged_fused.ply`
- MeshLab vertex count: `157417`
- Authenticated PLY download: passed
- Authenticated web viewer: passed

The runtime IPTC failure found during acceptance was resolved by dense-only JPEG metadata sanitization. The accepted source photos and sparse reconstruction were not modified.
