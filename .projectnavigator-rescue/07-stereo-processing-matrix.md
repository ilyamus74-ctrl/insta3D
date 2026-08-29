# Stereo and global-fusion matrix

| Capability | MASTER+SLAVE `7237...` | 2 phones+PC `a94d...` | Android+USB `4c7e...` | Server `c7ba...` / worker `fa24...` evidence |
|---|---|---|---|---|
| Stable camera roles | YES, MASTER/SLAVE | PARTIAL, independent live clients | YES, PHONE/USB | contract not unified across all modes |
| Clock relation / pairing | BLE samples, runtime bundle acceptance pending | PARTIAL; no common authoritative session/clock | synchronized-pair source exists | processing tolerance/quality gate not uniform |
| Calibration preserved | PARTIAL | NOT_VERIFIED end to end | calibration artifacts/preflight exist | storage-to-runner propagation not fully accepted |
| Server package accepted | **NO**, capture type mismatch | separate host path, not normal accepted package | NOT_VERIFIED | current PHP whitelist is a concrete blocker |
| Stereo pair use | NOT_VERIFIED downstream | NOT_VERIFIED as global pipeline | YES in stereo tooling | `web/remote_station/scripts/process_maklertour_synced_dense.sh` / audit tooling use pairs |
| Depth / pair cloud | NO accepted result | prototype/NOT_VERIFIED | implemented in F01 tooling | runtime acceptance pending |
| Trajectory estimation | NO accepted result | NOT_VERIFIED | ORB odometry implemented | algorithm exists; acceptance pending |
| Global alignment | NO | NO accepted evidence | PARTIAL initial transforms/accumulation | no established complete multi-session alignment |
| Fusion | NO | NO accepted evidence | PARTIAL initial global fusion | complete fusion/ICP not established |
| Optimization / loop handling | NO | NO | **NO accepted global optimization** | visual Hybrid work belongs mainly to SINGLE, not stereo closure |
| Metric global cloud | NO | NO | NOT_VERIFIED | no physical-ground-truth acceptance |
| Mesh / texturing | NO | NO | NO accepted end-to-end result | generic COLMAP mesh exists, not proof for stereo global output |

## Interpretation

Stereo capture foundations are substantive, but none of the three branches closes the full chain from calibrated pair acquisition through accepted package, trajectory, global optimization, metric cloud, mesh and texture. Therefore the APP tiles remain IN-PROGRESS, the server/worker tiles remain IN-PROGRESS, and the metric-model tile remains PLANNED.

Archaeology `stereo-global-fusion` should be merged into the existing GrafikStation worker and metric-output tiles, with capture-side criteria on `7237...`, `a94d...` and `4c7e...`. It is substantial work, but it does not require a new top-level user tile: the user already has explicit capture branches, a processing worker and a final metric deliverable.

Primary evidence: `docs/llm/tasks/APP-STEREO-F01-GLOBAL-STEREO-DEPTH-FUSION.md`, `docs/llm/tasks/APP-STEREO-F02-A-ANDROID-CAPTURE-BUNDLE-PREFLIGHT.md`, `docs/llm/tasks/APP-DUAL-PHONE-STEREO-ROADMAP.md`, `docs/ANDROID_CAPTURE_ARCHITECTURE_AUDIT.md`, `app/MaklerTour/tools/stereo_contract_audit.py`, `web/remote_station/scripts/process_maklertour_synced_dense.sh`.
