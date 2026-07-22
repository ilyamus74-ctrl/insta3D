# AUTO-B04 — Auto Photo sparse review and isolated PLY export result

## Status

`PARTIAL` — implemented backend routes and server-side validation are documented below. Production photo export acceptance is still pending.

## Deployment parity record

- Isolated export parity was restored and deployed on `2026-07-22`.
- The service, order route, and download endpoint were deployed.
- Deployment backup: `/home/makler/deploy_backups/rsync_20260722_142404`.
- Production regressions passed.
- Real production PLY export acceptance remains pending until a real PLY smoke test; this is not a declaration of full production export acceptance.

## Implemented parts

- **Model selection route.** The existing authenticated order POST route delegates model selection to the sparse web service. The service locks the standalone sparse job, validates order/bundle/prepare-chain identity and DONE state, validates the strict manifest model ID (including `0`), and stores `selected_model_id` in the job parameters.
- **Exhaustive retry route.** The order POST route delegates to a transaction that locks the source and related standalone sparse rows, applies the exhaustive retry policy, preserves validated prepare identity, creates a separate `COLMAP_SPARSE` job with `retry_mode=exhaustive`, exhaustive matcher, loop detection, new remote paths, and `pipeline_run_id=NULL`.
- **Photo export route.** Implemented review/export services resolve and validate standalone sparse scope, component-backed model choice, related export state, and separate `EXPORT_PLY` identity. The worker recognizes the photo-only markers and uses the isolated export plan rather than the sparse output directory.
- **Service helpers.** The intended B04 helper contract includes strict model parsing, selected/recommended model handling, run recommendation, resolver precedence, prepare-chain validation, retry policy and export priority. The required helper and web-service parity was restored and validated on production on 2026-07-22. Real isolated PLY export acceptance remains pending.
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

## Resolved deployment parity issue

The previously recorded helper/service parity gap was resolved and deployed
on 2026-07-22. Production syntax checks, sparse/UI/web regressions, worker
tests, shell tests, and the four required web-service function checks passed.

This resolution does not constitute real production PLY export acceptance.
That acceptance remains pending until an explicitly authorized export smoke
test completes successfully.

## Not claimed

No claim is made that a real production PLY export has already completed successfully. No deployment or production export is performed by this documentation task.

```text
Production photo export acceptance: pending
```
