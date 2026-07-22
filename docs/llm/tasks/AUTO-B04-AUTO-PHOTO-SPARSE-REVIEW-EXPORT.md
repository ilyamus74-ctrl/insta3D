# AUTO-B04 — Auto Photo sparse review, model selection, exhaustive retry and isolated PLY export

Task ID: `AUTO-B04-AUTO-PHOTO-SPARSE-REVIEW-EXPORT`

Parent: `AUTO-B03-AUTO-PHOTO-SPARSE`

## Goal

Document and preserve the implemented server-side review boundary for standalone Auto Photo sparse jobs: validate a completed sparse run, select a real component model, optionally enqueue one exhaustive retry, and export one selected/resolved sparse model to an isolated PLY export job.

## Scope and reuse boundary

Reuse the existing `sfm_remote_jobs` table, remote job ID allocator, `COLMAP_SPARSE` and `EXPORT_PLY` worker branches, status polling, `sfm_remote_job_status.php` download mechanism, and `export_sparse_ply.sh`. Reuse only the narrow standalone markers already implemented:

```json
{"source_type":"auto_photo_prepare","standalone_sparse":true}
```

and for an isolated export:

```json
{"source_type":"auto_photo_sparse","standalone_photo_export":true}
```

No schema, pipeline run, dense, mesh, preview/reconstruction, gallery, Simple View rendering, scheduler, deployment, or production execution is in scope. In particular, this task does **not** automatically start dense, mesh, legacy auto-chain, or any export.

## Source of truth and baseline

The source of truth is the scoped DB rows (`capture_bundles`, `sfm_remote_jobs`) plus the validated prepare and sparse artifacts resolved from server-side job IDs. Client paths, remote IDs, component lists, output paths, and selected IDs are never trusted.

Production baseline to preserve:

```text
capture_bundle_id: 7
prepare_database_job_id: 745
prepare_remote_job_id: 857972911
sparse_database_job_id: 746
sparse_remote_job_id: 434136404
sparse_status: DONE
pipeline_run_id: null
input_images: 178
model_0_registered_images: 118
model_0_points3D_count: 23230
model_1_registered_images: 39
model_1_points3D_count: 11784
shared_images: 1
```

Database job `746` is a read-only baseline. Its output must not be changed or deleted. A photo export writes only to a separate `EXPORT_PLY` job. Dense, mesh, and legacy auto-chain stages must not run.

## Component and model contracts

`sparse_components.json` is the component manifest source of truth. It must decode to an object with a `models` array; a requested model exists only when one manifest member has a strict `model_id`. Each usable component carries the actual metrics when available: `registered_images`, `points3D_count`, first/last image or frame, `frame_ranges`, and `shared_images_with`.

A model ID is an unsigned decimal integer: `0` or a non-zero integer without sign, whitespace, decimal, exponent, or coercion. Model ID `0` is valid. Selection is persisted only as `selected_model_id` in the standalone sparse job `parameters_json`, after scope, prepare-chain, DONE-status, manifest, and model validation.

The recommended model is the valid component selected by the implemented recommendation rule (higher registered images, then higher sparse points, then deterministic model-ID tie-break). The recommended run is selected across valid runs by the implemented run recommendation ranking; it is advisory and does not mutate a job.

For an action that accepts an optional model, resolution precedence is strictly:

```text
explicit → selected → recommended
```

Every resolved candidate must still be found in that run's component manifest. No fallback may invent model `0`, accept a directory name as proof, or cross an order/session/bundle boundary.

## Prepare-chain validation and related-job locking

Before selection, retry, or photo export, validate standalone sparse scope, order, capture session, bundle ID/UUID, parent remote ID, prepare DB job/type/status/output/result contract, prepare result identity, frames count, and the relationship between the sparse job and prepare job. The sparse job must remain standalone Auto Photo, not merely a `COLMAP_SPARSE` row.

Mutating operations use a transaction and `FOR UPDATE` locking for the target sparse job and its related standalone sparse jobs sharing the prepare remote parent. This prevents concurrent model updates and duplicate exhaustive retries. Related `EXPORT_PLY` jobs for a model likewise block another export while `QUEUED`, `RUNNING`, or `DONE`; failed jobs do not become a successful export.

## Exhaustive retry contract

Only a non-exhaustive standalone source sparse job in `DONE`, `ERROR`, or `FAILED` is retryable. At most one related exhaustive retry may be active (`QUEUED`/`RUNNING`) or completed (`DONE`). The new job preserves the validated prepare identity and creates a new remote/output/job identity with `pipeline_run_id = NULL`; its parameters set `retry_mode: exhaustive`, sparse matcher `exhaustive`, and loop detection `true`. It does not overwrite, retry in place, or delete the source job.

## Photo export contract

Photo export is a separate `EXPORT_PLY` row whose parent remote ID and `parameters_json.sparse_job_id` both equal the validated source sparse remote ID. Parameters identify `source_type: auto_photo_sparse`, `standalone_photo_export: true`, and the resolved strict model ID. It uses the same order and capture session as the sparse job and an independently allocated positive export remote ID, which must differ from the sparse remote ID.

The output contract is exactly:

```text
<SFM_REMOTE_OUTPUT>/job_<EXPORT_REMOTE_JOB_ID>/sparse_<MODEL_ID>.ply
<SFM_REMOTE_OUTPUT>/job_<EXPORT_REMOTE_JOB_ID>/logs
```

It must never write `<SPARSE_JOB>/colmap/sparse/<MODEL_ID>/model.ply` in photo mode. Download is available only when the isolated export is `DONE` and the output is a non-empty regular file.

## Worker, shell, atomicity, cleanup, and compatibility

The worker recognizes photo export only through both standalone export markers, validates IDs/parent/path equality, prepares only the isolated root and logs directory without symlinks, launches the six-argument shell v2 form, captures stdout/stderr, and marks `DONE` only after a zero exit code and non-empty local PLY.

Shell v2 validates positive sparse/export job IDs, strict model IDs including `0`, exact destination equality, config, sparse `cameras.bin`, `images.bin`, and `points3D.bin`. It converts remotely in a per-export temporary directory, fetches to a local `mktemp` file in the destination directory, verifies non-empty content, then atomically renames it to the exact final path. Exit cleanup removes the local temporary file and only the expected remote per-export temporary directory. Thus failed/cancelled work does not leave the temporary artifact; it does not delete sparse baseline output or another export job's path.

The four-argument `EXPORT_PLY` shell invocation remains legacy-compatible and retains its legacy remote/local layout and completion behavior. Photo-only validation and isolated output apply only to v2.

## Permission and CSRF boundaries

All routes remain authenticated and order-scoped. Selection, exhaustive retry, and export are changing POST actions: require the existing write/manage permission (`$canDeleteMedia` in the current route) and the existing POST CSRF boundary before calling services. Read-only status/component/export display is not a grant of write permission. Route failures return controlled errors and redirects use POST-redirect-GET.

## Tests

Automated coverage must include strict ID parsing (including `0`), wrong scope/parent/prepare chain, malformed components, selection persistence, recommendation/resolver precedence, retry policy states and concurrent-related-job locks, duplicate export blocking, isolated paths, symlink/path rejection, missing/empty output, worker completion, shell v2 arguments/destination/cleanup/atomic rename, and legacy four-argument compatibility. Use synthetic fixtures; do not export the production baseline as this task's test.

## Acceptance criteria

1. Baseline job `746` and its output remain read-only and intact.
2. Only manifest-backed strict model IDs, including `0`, can be selected or exported.
3. Resolver precedence is explicit → selected → recommended.
4. Retry is exhaustive-only, separately queued, chain-validated, and duplicate-safe.
5. Photo PLY export is separately queued, locked, scoped, and isolated under its own export remote job path.
6. Photo writes are atomic locally and clean temporary local/remote state on every shell exit.
7. Legacy `EXPORT_PLY` behavior remains compatible.
8. Auth, authorization, CSRF, order/session/bundle validation, and controlled failures remain enforced.
9. No automatic dense, mesh, export, or legacy chain is introduced.
10. Required automated tests pass; production PLY acceptance remains a separate authorized run.
