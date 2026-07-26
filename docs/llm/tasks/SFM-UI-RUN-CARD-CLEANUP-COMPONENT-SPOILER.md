# SFM-UI-RUN-CARD-CLEANUP — Component Spoiler

## Result

The Video SfM card keeps the run summary and assembly controls visible.

The large component table is collapsed by default:

```text
Компоненты Run: <total> · dense: <ready> — открыть список
```

The user can open it only when model-level diagnostics are needed.

The legacy `Модели и сборки` block is removed from the Video SfM card because
model and assembly management is now available in the dedicated
`Generated Models` tab.

## Test

```bash
php web/tests/sfm_video_run_card_cleanup_test.php
```

Expected:

```text
OK
```
