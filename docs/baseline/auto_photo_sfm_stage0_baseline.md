# Auto Photo SfM Stage 0 — Partial Code Baseline

Date: 2026-07-15

Local Codex checkout SHA:
`67325c0c06bd91d02a3f8aa6433d2cbd263fffb7`

Origin/main freshness:
NOT VERIFIED — the Codex checkout had no configured origin remote.

## Scope

This is the Stage 0 baseline report for `docs/baseline/AUTO_PHOTO_SFM_STAGED_IMPLEMENTATION.md`.
No production code was changed. The report records the current Video SfM architecture and the current evidence available in this checkout for the known Auto Photo bundle UUID `b8b55de2-87ec-4665-912b-b1ee906e9569`.

## Repository state checks

- `git rev-parse HEAD` returned `67325c0c06bd91d02a3f8aa6433d2cbd263fffb7`.
- `git fetch origin` could not be completed because this checkout has no configured `origin` remote.
- `git diff --stat origin/main...HEAD` could not be completed for the same reason.

## Files reviewed

- `web/www/order.php`
- `web/www/order_simple.php`
- `web/templates/maklertour_order_simple.html`
- `web/tools/sfm_remote_worker.php`
- `web/remote_station/sfm_pipeline.php`
- `web/remote_station/sfm_cleanup.php`
- `web/remote_station/run_extract_frames_job.sh`
- `web/remote_station/run_colmap_sparse_job.sh`
- `web/remote_station/scripts/process_extract_frames.sh`
- `web/remote_station/scripts/process_colmap_sparse.sh`
- `web/remote_station/deploy_station.sh`
- `web/remote_station/fetch_job_result.sh`
- `web/remote_station/get_station_status.sh`
- `web/www/api/mobile.php`

## Current Video SfM pipeline

The pipeline table is `sfm_pipeline_runs`. It stores `order_id`, `capture_session_id`, optional `video_scan_id`, `pipeline_mode`, status/stage, progress, root remote job, output paths, parameters, sparse stats, diagnostics, cleanup-related terminal state, and timestamps.

Current stages are:

```text
QUEUED → EXTRACT_FRAMES → SPARSE → SPARSE_COMPLETE/DENSE_PLAN → DENSE → MERGE → MESH → FETCH_RESULT → DONE
```

The web entry point for a normal Video SfM run is `start_sfm_pipeline_run(...)` in `web/www/order.php`:

1. creates a row in `sfm_pipeline_runs`;
2. allocates a remote job id;
3. inserts an `EXTRACT_FRAMES` row into `sfm_remote_jobs`;
4. writes the root remote job id to `sfm_pipeline_runs.root_remote_job_id`;
5. moves the pipeline stage to `EXTRACT_FRAMES`.

## Exact transitions found

### `EXTRACT_FRAMES → COLMAP_SPARSE`

When an `EXTRACT_FRAMES` job finishes, the worker checks whether a child `COLMAP_SPARSE` already exists. If no child exists and the job is attached to a pipeline run, it logs extraction metadata, updates the pipeline to `SPARSE`, allocates a new remote job id, uses the parent frames directory as input, and inserts a queued `COLMAP_SPARSE` child with `parent_remote_job_id` pointing at the extract job.

If the `EXTRACT_FRAMES` job is not attached to a pipeline run, the worker treats it as standalone preparation/diagnostics and deliberately does not auto-queue sparse reconstruction.

### `COLMAP_SPARSE → COLMAP_RECONSTRUCTION_PREVIEW/HQ`

When a `COLMAP_SPARSE` job finishes, the worker selects the best sparse model, records sparse stats on the pipeline run, optionally completes a `SPARSE_ONLY` run, otherwise moves the pipeline to `DENSE_PLAN`, then queues one or more `COLMAP_RECONSTRUCTION_PREVIEW` jobs. Preview jobs are keyed by `parent_remote_job_id=<sparse remote job id>` and include the selected `model_id` and dense/chunk settings in `parameters_json`.

Manual web actions in `web/www/order.php` can also queue `COLMAP_RECONSTRUCTION_PREVIEW` or `COLMAP_RECONSTRUCTION_HQ` for a selected sparse model.

### `COLMAP_RECONSTRUCTION_PREVIEW/HQ → COLMAP_MESH`

When a reconstruction job finishes, the worker checks for an existing mesh child. If none exists, it allocates a new remote job id and queues `COLMAP_MESH` with the reconstruction output PLY as input. Manual web actions can also queue a mesh from a finished preview/HQ reconstruction.

### `COLMAP_MESH → DONE`

When mesh reaches remote `DONE`, the worker fetches job results, copies `mesh_final.ply` and the parent dense `merged_fused.ply` into the pipeline output directory, writes `pipeline_result.json`, logs mesh stats, marks `sfm_pipeline_runs` as `DONE`, and schedules remote cleanup.

## Runners and station scripts

- `run_extract_frames_job.sh` uploads/starts `process_extract_frames.sh` on the station and writes output under `$STATION_BASE/output/job_<job_id>`.
- `process_extract_frames.sh` creates selected frame output and extraction result metadata.
- `run_colmap_sparse_job.sh` uploads/starts `process_colmap_sparse.sh` with matcher/overlap/loop settings and optional parameters JSON.
- `process_colmap_sparse.sh` consumes a frames directory, runs COLMAP sparse reconstruction, and writes sparse outputs/diagnostics.
- `fetch_job_result.sh` copies `$STATION_BASE/output/job_<job_id>/`, status JSON, and logs back to local `remote_station/output/job_<job_id>/`.
- `get_station_status.sh` reads station-side status.
- `deploy_station.sh` deploys station-side scripts/configuration.

## Cancel, restart, fetch, cleanup, generated models, and Simple View

- Cancel is handled by `cancel_sfm_pipeline` in `web/www/order.php`; the worker later observes cancelling jobs/runs and transitions terminal state to `CANCELLED` while scheduling cleanup.
- Restart is handled by `restart_sfm_pipeline` and `restart_sfm_pipeline_same_settings` in `web/www/order.php`; restart creates a new pipeline run and marks the previous run restarting/cancelled as appropriate.
- Fetch is centralized through `web/remote_station/fetch_job_result.sh` and worker calls around terminal remote statuses, especially mesh completion.
- Cleanup is implemented by `web/remote_station/sfm_cleanup.php`; terminal pipeline runs and eligible standalone jobs are scheduled in `sfm_remote_cleanup_runs`, validated for required artifacts/dependencies, and cleaned by a station cleanup script.
- Generated Models and Simple View are populated in `web/www/order_simple.php` and rendered in `web/templates/maklertour_order_simple.html`. The current generated-model UI is based on finished dense reconstruction jobs, mesh jobs, and generated merge records.

## Capture bundles and known UUID

`web/www/api/mobile.php` contains the `upload_capture_bundle` API action. It validates an uploaded `.tgz`, parses bundle metadata, creates `capture_bundles` if missing, and inserts rows with:

```text
order_id
capture_session_id
app_bundle_uuid
capture_type
filename
storage_path
size_bytes
status
```

A local filesystem search in this container did not find `maklertour_capture_bundle_auto_photo_session_b8b55de2-87ec-4665-912b-b1ee906e9569.tgz` or any `*b8b55de2-87ec-4665-912b-b1ee906e9569*.tgz` file under `/workspace/insta3D`, `/home`, or `/tmp`.

Because the TGZ is absent in this environment and no database connection credentials were exercised during Stage 0, the following runtime facts could not be confirmed locally:

- `capture_bundle_id`
- `order_id`
- `capture_session_id`
- actual `status`
- actual `storage_path`
- actual `size_bytes`
- `photos_count`
- sidecar list
- JPEG filename sequence/pattern
- manifests content

Expected bundle structure, based on the Android packager and staged
implementation document, but not verified against the real server-side TGZ:

```text
bundle_manifest.json
capture/manifest.json
capture/camera_info.json
capture/photos_metadata.jsonl
capture/imu.jsonl
capture/quality.jsonl
capture/events.jsonl
capture/photos/frame_000001.jpg
capture/photos/frame_000002.jpg
...
```

## Stage 0 limitations

- The checkout has no configured `origin` remote, so origin/main freshness checks could not be completed.
- The known Auto Photo TGZ was not present in the container, so `tar -tzf` and `tar -xOzf` checks could not be run.
- No production code was changed.

## Readiness for Stage 1

The code architecture baseline is sufficient to design Stage 1.

Stage 0 runtime acceptance is NOT complete because the real database row
and TGZ were not available in this environment.

Stage 1 implementation may be prepared as a diff, but it must not be
deployed or accepted until the following server-side checks are completed:

- capture_bundle_id is confirmed;
- order_id and capture_session_id are confirmed;
- capture_bundles.status is confirmed;
- storage_path and size_bytes are confirmed;
- the real TGZ is listed with tar -tzf;
- bundle_manifest.json is inspected;
- capture/manifest.json is inspected;
- capture/camera_info.json is inspected;
- actual JPEG count and filename pattern are confirmed;
- actual sidecar list is confirmed.


## Pending server-side verification

- [ ] Configure or verify the repository origin remote.
- [ ] Compare the deployed checkout with origin/main.
- [ ] Query the capture_bundles row for the known UUID.
- [ ] Confirm the real bundle storage path.
- [ ] Confirm the real bundle file size.
- [ ] Run tar -tzf against the real TGZ.
- [ ] Read bundle_manifest.json.
- [ ] Read capture/manifest.json.
- [ ] Read capture/camera_info.json.
- [ ] Confirm photos_count equals the number of JPEG files.
- [ ] Confirm photos_metadata.jsonl sequence coverage.