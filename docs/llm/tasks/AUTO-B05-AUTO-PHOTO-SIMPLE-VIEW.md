# AUTO-B05 — Auto Photo Simple View “Фото 3D”

Task ID: `AUTO-B05-AUTO-PHOTO-SIMPLE-VIEW`

Parent: `AUTO-B04-AUTO-PHOTO-SPARSE-REVIEW-EXPORT`

## Goal

Add a separately visible `Фото 3D` tab to the existing order Simple View. It presents the already implemented standalone Auto Photo sparse-review/export state without changing full order view behavior or creating another processing pipeline.

## Boundaries

Use server-resolved, order-scoped bundle/prepare/sparse/component/export data and the B04 service contracts. Treat component/export JSON and filesystem artifacts as untrusted/malformed inputs: skip or degrade a record with a controlled empty state rather than fail the page. The tab is read-only without write permission; actions are a later slice and must use existing POST routes, CSRF, permission checks, locking, and PRG. No automatic dense, mesh, export, or legacy auto-chain is allowed.

## Slice B05.1 — Patch 4A: pure UI DTO

Create a pure service that accepts already loaded arrays and produces a display DTO. It has no SQL, HTTP globals, rendering, filesystem reads, or redirects. It includes:

- bundle and prepare summaries;
- sparse runs, components/models, and exports;
- recommended run/model;
- persisted selection state;
- action flags and active-job state;
- registered images/percent, points, first/last image, frame ranges, shared images, and export/download state.

Use strict model IDs, including model `0`; deterministic recommendation/resolution; and malformed-record filtering. Add unit tests for empty/malformed data, recommendation, selection, active jobs, permissions, exports, and model `0`.

## Slice B05.2 — Patch 4B: load DTO

Add a server-side read-only loader for the DTO. It loads the current order's Auto Photo bundle, prepare job, standalone sparse runs, component manifests, and isolated export jobs, then calls the B05.1 builder. Do not change HTML in this slice.

Queries must be bounded and order/session scoped. When more than one eligible bundle exists, selection must be deterministic and server-side. The loader must document and test the chosen ordering rule. Propagate permission as a boolean rather than making read access conditional on it. Missing files, invalid JSON, malformed manifests, and stale export data must produce safe absent/empty DTO fields, not a fatal error. Add read-only integration coverage.

## Slice B05.3 — Patch 5A: read-only tab

Render a visible `Фото 3D` Simple View tab, compatible with existing tabs. Render:

- bundle summary and prepare status;
- sparse-run list and recommended-run badge;
- model table/cards with selected/recommended badges;
- registered images, registered percent, points, first/last image, frame ranges, and shared images;
- export status and download link when available.

This slice contains no POST controls. It must remain safe with no bundle, no runs, malformed component/export data, and model ID `0`.

## Slice B05.4 — Patch 5B: actions

Add controls for select model, exhaustive retry, and export PLY using the existing B04 POST routes/services. Preserve CSRF, authorization, order scope, POST-redirect-GET, prepare-chain validation, and related-job locking. Buttons derive exclusively from DTO flags, disable during active/duplicate related jobs, and refresh status after redirect/polling. No button may start dense, mesh, reconstruction, or a legacy chain.

## Acceptance

1. Simple View remains compatible with existing tabs and the full order view is not broken.
2. Model ID `0` is rendered and actionable where its DTO flags allow it.
3. Actions are unavailable without permission; read-only rendering remains available independently of permission.
4. Malformed component/export data never causes a fatal page error.
5. Baseline sparse job `746` remains unmodified; its output is never an export destination.
6. Existing video SfM behavior remains unchanged.
7. The tab shows the requested bundle/prepare/runs/models/export information, and the B05.4 controls obey B04 locking and CSRF/permission contracts.

## Implementation status

- B05.1 — ACCEPTED / DEPLOYED
- B05.2 — ACCEPTED / DEPLOYED (commit: `26bdc80`; production deployment date: `2026-07-22`)
- B05.3 — ACCEPTED / DEPLOYED (production deployment date: `2026-07-22`)
- B05.4 — ACCEPTED / DEPLOYED
- B05.5 — IMPLEMENTED, PENDING REVIEW/DEPLOYMENT.

## B05.4 implementation record

- Repository base HEAD before the patch: `ba8376d26c6324a613e74f2340a6a56d02108887`.
- B04 isolated export parity was restored and deployed on `2026-07-22`.
- B05.4 uses the existing B04 select, exhaustive retry, and isolated export services; it adds no backend service.
- POST forms use the existing `secCode` CSRF field and successful actions return to `#simple-photo-sfm`.
- POST redirects use `#simple-photo-sfm`; the Simple View template activates the Bootstrap Photo 3D pill on window load only for the exact `#simple-photo-sfm` URL hash.
- Overview remains the default active tab for ordinary page loads.

## B05.5 implementation evidence

- Bundle 8 materialization completed; its prepare dry-run confirmed 87 frames.
- No real prepare job was created for bundle 8.
- B03.1 is accepted and deployed; B05.5 is unblocked and pending review/deployment.

**B05.5 — IMPLEMENTED, PENDING REVIEW/DEPLOYMENT.** Production evidence for the unblocking case is order 31 / capture bundle 8 / session 65: 87 valid photos, index and materialization complete, and prepare dry-run successful. No real prepare job was created. B03.1 is deployed, so B05.5 is unblocked; this does not declare the B05 epic complete.
