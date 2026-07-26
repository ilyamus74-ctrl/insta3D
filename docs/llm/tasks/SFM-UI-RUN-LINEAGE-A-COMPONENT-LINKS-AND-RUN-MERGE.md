# SFM-UI-RUN-LINEAGE-A — Component Links and Run-Scoped Merge

## Status

```text
IMPLEMENTED
WEB DEPLOYMENT AND RUNTIME ACCEPTANCE PENDING
```

## Fixed link routing

Video SfM component jobs and standalone Photo 3D jobs both contain fields such
as `model_id` and `sparse_remote_job_id`.

The old candidate detector treated any one of those generic fields as a Photo
3D job marker. Video component viewer/download requests were consequently sent
through the standalone Photo 3D scope validator and returned `file_not_ready`.

Photo 3D routing now requires all three explicit markers:

```text
source_type=auto_photo_sparse
standalone_auto_photo_dense=true
dense_only=true
```

## Run lineage

The simple page now adds a run-scoped block:

```text
source video
→ pipeline Run ID and timestamps
→ sparse Model
→ dense DB job ID
→ dense remote job ID
→ immutable viewer/download links
→ merge status and artifacts
```

The legacy sparse-component table is hidden only after the enhanced block is
successfully rendered.

## Run-scoped merge button

The merge form includes only component DB jobs that:

- belong to the displayed pipeline Run;
- are `DONE`;
- have a valid dense PLY;
- share one sparse parent.

The existing `aligned_merge_generated_dense_clouds` action performs alignment
through shared COLMAP image poses.

## Limitation

When separate sparse components have no shared-image path, aligned merge can
remain anchor-only. The next recovery path is LightGlue bridge matching or
manual alignment.

## Test

```bash
php web/tests/sfm_video_run_lineage_ui_test.php
```

Expected:

```text
OK
```
