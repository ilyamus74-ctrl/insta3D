# AUTO-B04 — Auto Photo sparse review and isolated PLY export result

## Status

`PARTIAL` — implemented backend routes and server-side validation are documented below. Production photo export acceptance is still pending.

## Implemented parts

- **Model selection route.** The existing authenticated order POST route delegates model selection to the sparse web service. The service locks the standalone sparse job, validates order/bundle/prepare-chain identity and DONE state, validates the strict manifest model ID (including `0`), and stores `selected_model_id` in the job parameters.
- **Exhaustive retry route.** The order POST route delegates to a transaction that locks the source and related standalone sparse rows, applies the exhaustive retry policy, preserves validated prepare identity, creates a separate `COLMAP_SPARSE` job with `retry_mode=exhaustive`, exhaustive matcher, loop detection, new remote paths, and `pipeline_run_id=NULL`.
- **Photo export route.** Implemented review/export services resolve and validate standalone sparse scope, component-backed model choice, related export state, and separate `EXPORT_PLY` identity. The worker recognizes the photo-only markers and uses the isolated export plan rather than the sparse output directory.
- **Service helpers.** The intended B04 helper contract includes strict model parsing, selected/recommended model handling, run recommendation, resolver precedence, prepare-chain validation, retry policy and export priority. Deployment parity for this helper set must be revalidated before B05.1 acceptance.
- **Worker helper.** `auto_photo_export_worker_lib.php` validates photo-job markers, IDs, parent equality, exact output/log paths, safe local directory preparation, and terminal output existence before `DONE`.
- **Safe shell v2.** The six-argument photo mode validates IDs and exact destination, verifies sparse binary inputs, exports via a per-export remote temporary directory, copies into a local temporary file, verifies non-empty content, atomically renames it, and cleans local/remote temporary state on exit.
- **Legacy compatibility.** The original four-argument `EXPORT_PLY` invocation remains supported with its historical layout and completion behavior.
- **Automated tests.** Focused worker, shell, sparse-review, and sparse-web tests cover the implemented contracts with synthetic fixtures.
- **Reported regression validation.** The following test commands were reported as successful; this is not a claim of full repository or production parity:

```text
auto_photo_export_worker_test.php: OK
auto_photo_export_shell_test.sh: OK
auto_photo_sparse_review_test.php: OK
auto_photo_sparse_web_test.php: OK
```

## Baseline preservation

The recorded production sparse baseline is DB job `746`, remote job `434136404`, status `DONE`, with `pipeline_run_id=null`. It is treated as read-only: neither its output nor its DB row is the export destination. Export uses a separate `EXPORT_PLY` job, and the standalone sparse marker prevents automatic dense, mesh, and legacy chain stages.

## Known deployment parity issue

During B05.1 server validation,
`auto_photo_sparse_selected_model()` was unavailable in the deployed
`auto_photo_sparse_lib.php` helper set.

Therefore B05.1 production acceptance is blocked until repository/server
helper parity is restored and `auto_photo_sparse_ui_test.php` passes on the
server.

This finding does not claim that the B04 routes were executed or modified
by the documentation task.

## Not claimed

No claim is made that a real production PLY export has already completed successfully. No deployment or production export is performed by this documentation task.

```text
Production photo export acceptance: pending
```

B05.1 production integration is not part of this result and must be
validated separately against the deployed B04 helper set.
