# AUTO-B06-AUTO-PHOTO-DENSE-PREVIEW

## Goal
Add a standalone diagnostic `preview` dense reconstruction and authenticated browser viewer for one completed Auto Photo standalone sparse component.

## Contract
The order action accepts only a sparse DB ID and model ID, re-resolves the standalone Auto Photo sparse/prepare chain and the server-side component manifest, then creates one independent `COLMAP_RECONSTRUCTION_PREVIEW` row with `pipeline_run_id=NULL`, `dense_only=true`, and `standalone_auto_photo_dense=true`. The source sparse row and output are read-only.

## Processing
The established chunk planner, `COLMAP_DENSE_CHUNK`, retry orchestration, and dense merge are reused. Parent settings contain the normalized Preview 640 dense snapshot; every chunk and retry copies that snapshot. Dense-only JPEG copies remove APP1, APP13, and COM metadata before `image_undistorter` while preserving the compressed image stream and leaving source photos unchanged. The two dense-only markers suppress automatic `COLMAP_MESH` only when both are present.

## Security
POST uses the existing order authorization and `secCode` CSRF boundary. Downloads and the browser viewer use authenticated endpoints and the same complete dense → sparse → prepare → bundle → manifest → exact PLY scope resolution. The viewer does not expose a server filesystem path.

## Non-goals
No Android capture changes, prepare or sparse redesign, `sfm_pipeline_runs`, schema, Video SfM, automatic dense/mesh chaining, or production mesh generation.

## Completed focused checks
The following behavioral tests were applied to the repository, synced to the web server, and returned `OK`:

- `web/tests/auto_photo_dense_preview_enqueue_test.php`
- `web/tests/auto_photo_dense_worker_contract_test.php`
- `web/tests/auto_photo_dense_download_scope_test.php`
- `web/tests/auto_photo_dense_ui_behavior_test.php`
- `web/tests/auto_photo_dense_image_sanitizer_test.php`
- `web/tests/auto_photo_dense_viewer_contract_test.php`

## Runtime acceptance

AUTO-B06 was accepted with a real GrafikStation run:

- sparse DB job `752`, sparse remote job `658883972`;
- dense DB job `759`, dense remote job `897481444`;
- model ID `0`;
- accepted artifact `job_897481444_merged_fused.ply`;
- MeshLab opened the downloaded PLY and reported `157417` vertices;
- the authenticated web viewer opened the same standalone dense artifact successfully.

The first runtime attempts exposed an OpenImageIO IPTC assertion. R8/R9 added dense-only metadata sanitization without changing the source photos or sparse job. AUTO-B06 is closed.
