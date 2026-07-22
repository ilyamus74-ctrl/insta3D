# AUTO-B05 Auto Photo Simple View result

## Status

`IN PROGRESS`

## B05.2 production evidence

- Bundle: `7`; capture session: `63`; photographs: `178`.
- Prepare: DB job `745`, remote job `857972911`, `DONE`.
- Recommended sparse: DB job `746`, remote job `434136404`, `DONE`.
- Model `0`: `118` registered images and `23230` points.
- Model `1`: `39` registered images and `11784` points.
- Historical sparse DB job `747`: `ERROR`, with no models.
- `active_jobs: false`.

## Boundaries retained

- Baseline sparse job `746` was not changed.
- The B05.2 loader remains read-only.
- B05.3 is accepted/deployed; production deployment date: `2026-07-22`.
- B05.3 uses direct Smarty variable insertion; no post-render HTML replacement.
- Production export acceptance from B04 remains pending.
- B04 parity deployment backup: `/home/makler/deploy_backups/rsync_20260722_142404`.
- Production regression suite passed and the four required web services reported `OK`.
- B05.4 adds only POST forms, the `secCode` CSRF boundary, and Simple View anchors; it uses existing B04 services.
- POST redirects use `#simple-photo-sfm`; the Simple View template activates the Bootstrap Photo 3D pill on window load only for the exact `#simple-photo-sfm` URL hash.
- Overview remains the default active tab for ordinary page loads.
- Production actions were not performed, job `746` was not changed, and real production PLY acceptance remains pending.

This result does not declare the B05 epic complete. B05.4 is accepted and
deployed; B05.5 production deployment and real uploaded-bundle action
acceptance remain pending.

## B05.5 prerequisite

- B05.5 is IMPLEMENTED, PENDING REVIEW/DEPLOYMENT; B03.1 is ACCEPTED / DEPLOYED (2026-07-22).
- Bundle 8 materialization completed and prepare dry-run confirmed 87 frames; no real prepare job was created.
- B03.1 adds only the missing prepare-DONE → standalone-sparse chain.

Implemented, pending review/deployment. Bundle 8 (order 31, session 65) has 87 valid photos; index/materialization were completed and prepare dry-run succeeded without creating a real prepare job. B03.1 was accepted and deployed on 2026-07-22, unblocking this web start flow. The B05 epic remains open.
