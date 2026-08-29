# Dependency review

The export contains 12 active-looking links, including two duplicate Tour MVP → Server links (`4253399e-...` and `a54cb001-...`). The duplicate must be resolved by ProjectNavigator/human review; this advisory package does not modify it.

Under the requested definition, a dependency is HARD only when the successor cannot begin. Fixtures, contracts and recorded data let nearly all current branches proceed in parallel. Therefore all current links should be **SOFT for development**; end-to-end ordering belongs in the successor acceptance gate.

| User dependency ID | From → to | Current | Recommended | Rationale |
|---|---|---|---|---|
| `0565163f-2737-4c72-aea5-027af681854b` | Android foundation → Android+USB | hard | **SOFT** | UVC capture and stereo tooling already developed while the common foundation contract remains incomplete. |
| `0add6d93-5546-4022-8668-a9750e1f8ce2` | Server → metric mesh | hard | **SOFT** | Reconstruction research can run on frozen datasets; final metric acceptance still requires server/worker publication evidence. |
| `13dc18e0-300d-4666-915e-52d0d6d127f5` | Android foundation → SINGLE | hard | **SOFT** | SINGLE already runs against its current package; contract unification and sensor-prior work can proceed concurrently. |
| `4253399e-bda8-4256-832d-37f42a25fada` | Tour MVP → Server | hard | **SOFT** | Business UI and server orchestration share identity/storage but can evolve against schema/fixtures. |
| `a54cb001-fa65-44a1-9c42-0bd813070c70` | Tour MVP → Server | hard | **SOFT / DUPLICATE** | Same endpoints as `4253399e-...`; retain only one relationship after human confirmation. |
| `49255588-415f-45ed-b3f6-6cb8c597c1fc` | Android foundation → 2 phones+PC | hard | **SOFT** | Laptop-live path is already a separate prototype and can converge on the contract in parallel. |
| `4fa1dfa5-92f4-499a-bd81-4b39b62b60be` | Android foundation → Automatic Photo | hard | **SOFT** | B01–B06 proceeded against a dedicated endpoint; shared-envelope convergence need not block experimentation. |
| `91d901b0-9049-4f49-923a-2e35e5a8c840` | Server → Android foundation | hard | **SOFT** | Client/server contract is co-designed; neither whole tile must finish before the other begins. |
| `a8b597f8-1475-48d9-8c0d-3d1b7ae0a45b` | Android foundation → MASTER+SLAVE | hard | **SOFT** | Role/control capture already exists; server acceptance can be fixed against a fixture in parallel. |
| `be2c5370-2340-4f7d-9c30-a95bacba8d4c` | Server → GrafikStation worker | hard | **SOFT** | Worker runners can be developed/tested from frozen jobs; integration acceptance requires both. |
| `be9c1f3e-a0ea-46b0-bab1-65ac0649c09c` | Android foundation → Insta360 | hard | **SOFT** | Camera integration and tour ingestion are independently testable. |
| `e8ba8b8e-2f85-4440-b9b3-80d18994fc4f` | Metric mesh → viewer | hard | **SOFT** | Viewer UX can use fixture assets; production acceptance requires an accepted metric/textured artifact. |

## Recommended acceptance gates (not start blockers)

- A capture tile cannot be DONE until its package passes `ac-foundation-01..04`, relevant telemetry criteria, server acceptance and downstream-consumption evidence.
- The metric-model tile cannot be DONE until worker global optimization, active metric constraints and physical-ground-truth criteria pass.
- The photorealistic viewer cannot be DONE until it consumes an accepted artifact from the metric-model tile, although viewer development remains parallel.

## Recommended HARD dependencies

None of the current links qualifies as HARD under “successor cannot begin.” Consequently there is no artificial serial critical path and no HARD rationale to invent. If ProjectNavigator later supports separate **acceptance milestones**, “accepted metric artifact → production viewer release” would be a valid HARD release gate, but it should not block viewer implementation.
