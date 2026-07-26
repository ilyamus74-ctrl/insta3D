# SFM-UI-RUN-LINEAGE-B — Generated Models Merge and Timestamps

## Changes

### Permission mismatch

The Simple View previously passed only `canDeleteMedia` to the run-lineage
builder. The legacy merge backend accepts `canDeleteMedia || canEdit`.

The Simple View now enables merge creation for:

```text
administrator
order broker/owner
assigned operator
existing canDeleteMedia roles
```

### Model timestamps

Every Video SfM component exposes:

```text
created_at
started_at
finished_at
updated_at
```

The UI displays creation and ready/update timestamps.

### Generated Models

The Generated Models tab now contains a new run-scoped index before the legacy
table:

```text
source video
→ processing Run
→ start/finish time
→ component Model
→ created/ready time
→ dense DB and remote job IDs
→ viewer/download
→ run-scoped aligned merge button
```

Runs are ordered newest first.

## Test

```bash
php web/tests/sfm_video_run_lineage_generated_models_test.php
```

Expected:

```text
OK
```
