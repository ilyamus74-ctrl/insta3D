# Issues and risks

## Critical/high issues

| ID | Severity | Finding | Evidence | Impact |
|---|---|---|---|---|
| `PN-01` | critical | Android dual aggregate capture type is rejected by PHP allowlist | Android architecture audit; `AppStateViewModel.kt`; `mobile.php` | Dual-phone end-to-end processing cannot start. |
| `PN-02` | high | Dual-phone and USB stereo captures do not persist ToF although modes/UI can imply ToF availability | architecture audit, packagers/recorders | Misleading capability and no sensor-assisted downstream evidence. |
| `PN-03` | high | IMU is not a COLMAP pose/BA constraint; gravity alignment is identity | IMU audit; `build_world_alignment.py` TODO | Visual drift remains physically unconstrained. |
| `PN-04` | high | ToF is measurement-only and never applies scale/fusion | ToF audit; S01H reports | Metric evidence does not make final model metric. |
| `PN-05` | high | Stereo F01 source is wired but runtime/device/station acceptance is pending | F01/F02/runtime-gate task headers | Source-complete path may fail or produce poor geometry in reality. |
| `PN-06` | high | Hybrid v2 documentation and station runner disagree on loop detection/vocabulary-tree contract | `docs/SINGLE_HYBRID_V2_AUDIT.md` vs `web/remote_station/single_hybrid_v2/run.sh` | Experiment is not reproducibly specified; runtime is NOT_RUN. |
| `PN-07` | high | Sparse connectivity improvement is not proof of physical loop closure or geometry stability | loop A/B and Hybrid result docs | Risk of promoting visual matching while spiral/self-intersection persists. |

## Architecture and operational debt

- `MainActivity.kt` remains composition root, navigation host, UI collection, calibration/stereo controller and file orchestration: high coupling and device-test blast radius.
- At least five capture pipelines have different clocks, packages and server consumers; names such as VIDEO/ToF describe capability inconsistently.
- Server schema evolution is partly runtime `CREATE TABLE IF NOT EXISTS`/`ALTER TABLE` inside PHP, increasing drift risk versus explicit migrations.
- Worker/status semantics differ across tables (`PROCESSED`, `DONE`, `ERROR` families); consumers must not infer success from exit code.
- Remote station uses SSH-controlled deployment and mutable config/image (`colmap:latest` appears in config/docs), weakening reproducibility unless deployed versions are recorded.
- Git history mostly says `sync local -> remote`; intent, review scope and release boundaries are hard to recover.
- Repository contains build outputs, IDE state, debug logs, generated templates, backup source copies and a dirty COLMAP submodule. These are provenance/hygiene risks and were excluded as current source.
- `cam1_uvc.cpp` explicitly does not implement non-MJPEG/compressed raw recording.
- PLY browser download/viewing and short-capture reconstruction quality are described as unstable/limited in phone MVP status.
- Legacy roadmap still calls implemented reconstruction stages unstarted; using it alone produces false planning state.
- Several task documents carry historical repository baseline hashes older than HEAD; their status must be reconciled with current code before execution.
- Tests are numerous but many PHP tests are contract/source-text checks; they do not replace Android device, physical calibration, station GPU or independent-capture acceptance.

## Contradictions recorded

1. `ROADMAP.md` P3/P4 unchecked vs implemented remote sparse/dense/mesh and recorded jobs.
2. `APP-STEREO-CURRENT-STATUS...` names DP04.2 as immediate next using an older baseline, while dual-phone roadmap says DP04.2 source exists and runtime acceptance is next.
3. F01C-A header says core not wired, while newer F01C-B/F01 parent say wired; newer wiring documents/code take precedence.
4. Hybrid v2 audit/README invocation expects loop detection/vocab tree, but remote runner disables loop detection.
5. `APP_CAMERA_STEREO_CONTRACT` and feature task claims need device evidence; source status cannot be treated as operational completion.

## Dead-end or quarantined directions

- Literal first-30 x last-30 endpoint pairs: 900 requested, zero verified; not a viable closure rule for the reference capture.
- Full exhaustive matching: useful upper-bound diagnostic, not production policy due cost and mixed geometry quality.
- PRE-CW90 ToF dense deformation evidence: retained historically but explicitly not current POST-CW90 truth.
- LightGlue, ICP, Gaussian splats and NeRF are options/future research, not active dependencies.
