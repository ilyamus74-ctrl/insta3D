# AUTO-B02 result

The worker uses the existing remote status/poll/fetch lifecycle. `capture_bundles` fields used by the current runtime are `id`, `app_bundle_uuid`, `capture_type`, `storage_path`, `order_id`, and `capture_session_id`; no DB `photos_count` or `archive_sha256` field is assumed and no migration is added. Those identities are validated from index and materialization artifacts.
