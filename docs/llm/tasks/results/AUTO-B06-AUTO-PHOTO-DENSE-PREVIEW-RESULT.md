# AUTO-B06 result

## Status
Implemented locally; no deployment, SSH, production database mutation, or production job was run.

## Implementation
A valid completed standalone Auto Photo sparse component with at least 10 registered images can enqueue an isolated `COLMAP_RECONSTRUCTION_PREVIEW` parent. It has `pipeline_run_id=NULL`, a new remote ID, exact merged PLY output path, Preview 640 settings snapshot and dense-only markers. Active/DONE preview duplicates are blocked transactionally.

The existing chunk planner/orchestrator/merge route is reused. Chunk jobs and retry jobs inherit the parent settings snapshot even when there is no pipeline run. The worker skips automatic mesh only if both dense-only markers are true. The Photo 3D UI renders the action and dense metadata, and the existing download endpoint enforces the standalone PLY boundary.

## Runtime
Manual authorized GrafikStation test is still required to prove remote COLMAP execution and artifact fetch parity.
