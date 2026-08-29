# Archaeology disposition

Nothing from the 15-item archaeology graph or its nine recorded issues is silently discarded.

## WorkItems

| Archaeology temporaryKey | Disposition | User WorkItem ID(s) / rationale |
|---|---|---|
| `tour-platform-mvp` | MERGE INTO EXISTING USER TILE | `8801e3ab-...`; exact order/session/tour end-to-end responsibility. |
| `android-capture-upload` | MERGE | `4dcc4e53-...`; shared Android capture/persistence/upload implementation, plus Insta360 acquisition evidence for `19dfb29a-...`. |
| `auto-photo-sfm` | MERGE | `2498f509-...` capture/result path; server part also belongs to `c7ba832d-...`. |
| `server-processing-orchestration` | MERGE | `c7ba832d-...` and worker protocol part of `fa24df7a-...`. |
| `single-sfm-baseline` | MERGE | `da6e553d-...`; processing implementation also informs `fa24df7a-...`. |
| `single-connectivity-drift` | MERGE | active work inside `da6e553d-...`, not a new product tile. |
| `tof-imu-measurement` | MERGE / DOCUMENT | cross-cutting diagnostics in relevant APP tiles, server `c7ba...` and worker `fa24...`; DONE only as measurement work. |
| `single-sensor-constraints` | MERGE | next experiment inside SINGLE `da6e...` and worker `fa24...`; acceptance feeds metric tile `0b06...`. |
| `capture-topology-unification` | DOCUMENT / CONTRACT ONLY + active ownership | project contract artifact; implementation work owned by Android foundation `4dcc...`, server `c7ba...`, worker `fa24...`. |
| `dual-phone-capture` | MERGE | MASTER+SLAVE `7237...` and laptop branch `a94d...`; different transports remain explicitly distinguished. |
| `usb-stereo-capture` | MERGE | Android+USB `4c7e...`. |
| `stereo-global-fusion` | MERGE | worker `fa24...` + metric output `0b06...`, with capture acceptance on three stereo tiles. |
| `sfm-component-assembly` | MERGE | worker `fa24...`, server orchestration `c7ba...`, final metric output `0b06...`. |
| `metric-textured-model` | MERGE | `0b06374d-...`. |
| `photorealistic-viewer` | MERGE | `633331e3-...`. |

No archaeology WorkItem requires a new top-level tile. None is classified historical/superseded. The visual-loop experiments are historical evidence **and** active input to the SINGLE decision, so they remain attached rather than discarded.

## Issues

| Archaeology issue key | User ownership |
|---|---|
| `dual-capture-type-mismatch` | Android foundation `4dcc...`, MASTER+SLAVE `7237...`, server `c7ba...` |
| `sensor-sidecar-gaps` | all relevant capture tiles plus server `c7ba...` |
| `imu-not-active-prior` | SINGLE `da6e...`, worker `fa24...`, metric output `0b06...` |
| `tof-measurement-only` | SINGLE `da6e...`, worker `fa24...`, metric output `0b06...` |
| `hybrid-v2-contract-conflict` | SINGLE `da6e...`, server `c7ba...`, worker `fa24...` |
| `stereo-runtime-pending` | MASTER+SLAVE `7237...`, laptop `a94d...`, Android+USB `4c7e...`, worker `fa24...` |
| `mainactivity-coupling` | Android foundation `4dcc...`; architectural debt, not a new tile |
| `documentation-drift` | project-level worklog/decision hygiene; affects confidence across tiles |
| `repository-provenance-hygiene` | project-level repository hygiene; no product tile |

## Human decisions still needed

- Whether Insta360 is intended only for tour media (the DONE boundary used here) or must become an SfM/metric source.
- Which physical accuracy thresholds define “достоверные метрические данные.”
- Whether laptop-live must converge on the same durable upload envelope or remain a local-only product mode.
- Whether ToF is mandatory in every stereo branch or optional with explicit capability negotiation.
