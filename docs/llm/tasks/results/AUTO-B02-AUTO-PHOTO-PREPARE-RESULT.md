# AUTO-B02 result

The worker uses the existing remote status/poll/fetch lifecycle. `capture_bundles` fields used by the current runtime are `id`, `app_bundle_uuid`, `capture_type`, `storage_path`, `order_id`, and `capture_session_id`; no DB `photos_count` or `archive_sha256` field is assumed and no migration is added. Those identities are validated from index and materialization artifacts.

## Production acceptance

```text
capture_bundle_id: 7
database_job_id: 745
remote_job_id: 857972911
input_images: 178
remote_output_images: 178
web_output_images: 178
status: DONE
warnings: 0
idempotent: false
web_output:
/home/makler/web/remote_station/output/job_857972911
```

## Runtime contract confirmed in production

`index.json` requires `photos_count_actual`. `photos_count_manifest` is checked
when present. `index.json` photo entries may omit `sha256`.

`materialization.json` requires `photos_count`, and each photo entry requires
`sha256`; this is the integrity-verification source used by AUTO-B02.

AUTO-B02 validates the JPEG list, sizes, dimensions, and SHA values; uses the
existing remote status/poll/fetch lifecycle; removes incoming staging after
successful atomic publish; and does not automatically launch COLMAP.
