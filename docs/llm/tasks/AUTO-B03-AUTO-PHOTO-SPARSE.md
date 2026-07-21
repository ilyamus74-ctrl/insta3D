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
- settings generated from `sfm_settings_lib.php` system defaults.

No worker, shell, Python, service, scheduler, schema, GUI, dense, preview, mesh,
or export changes are introduced.

## Terminal behavior

The queued sparse job has no `pipeline_run_id`. Existing worker behavior only
auto-chains completed `COLMAP_SPARSE` jobs attached to a pipeline run, so this
standalone job ends after sparse fetch and `DONE`.

## Paths

The parent local contract is:

```text
/home/makler/web/remote_station/output/job_<PREPARE_REMOTE_ID>/frames
/home/makler/web/remote_station/output/job_<PREPARE_REMOTE_ID>/result.json
```

The sparse worker input is the already published remote directory:

```text
/home/makler_storage/output/job_<PREPARE_REMOTE_ID>/frames
```
