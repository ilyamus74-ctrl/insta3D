# AUTO-B03 — Auto Photo standalone sparse

## Scope

Queue the existing `COLMAP_SPARSE` job from a completed
`MAKLERTOUR_AUTO_PHOTO_PREPARE` result. The parent is selected by DB job ID;
the caller cannot supply paths or remote job IDs.

## Reuse

AUTO-B03 reuses:

- `sfm_remote_jobs`;
- `sfm_job_id()`;
- the existing `COLMAP_SPARSE` worker branch;
- `run_colmap_sparse_job.sh`;
- existing remote status polling and result fetch;
- `sfm_settings_lib.php` system defaults and `sfm_mode_parameters()`.

It adds no shell or Python processor, service, scheduler, schema, GUI, dense,
preview, mesh, or new job type.

## Terminal behavior

The queued job stores:

```json
{
  "source_type": "auto_photo_prepare",
  "standalone_sparse": true
}
```

The worker has one narrow guard for that exact marker. After the existing sparse
fetch completes, the DB job is marked `DONE` and automatic `EXPORT_PLY`, dense,
preview, mesh, and other chain stages are skipped. Ordinary video
`COLMAP_SPARSE` jobs retain their existing behavior.

## Paths

The parent local contract is:

```text
/home/makler/web/remote_station/output/job_<PREPARE_REMOTE_ID>/frames
/home/makler/web/remote_station/output/job_<PREPARE_REMOTE_ID>/result.json
```

The sparse worker input is resolved from the existing parent contract:

```text
/home/makler_storage/output/job_<PREPARE_REMOTE_ID>/frames
```

## B03.1 status

- B03.1 — ACCEPTED / DEPLOYED. Production date: 2026-07-22.
- The deployed chain adds completed-prepare to standalone-sparse only; it does not add GUI, dense, mesh, preview, or export chaining.
- Verified pre-existing baseline limitation: `web/tests/auto_photo_prepare_test.php` fails identically at base `5f83f33` and this amended B03.1 HEAD (`RuntimeException: wrong expected`, exit `255`); it was not changed in this scope.
