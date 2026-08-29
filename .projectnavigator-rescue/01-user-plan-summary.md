# Insta3D rescue: user-plan summary

## Scope and evidence policy

The authoritative architecture is the 12-WorkItem project `Insta3D_refactoring` from `.projectnavigator-rescue-input/user-plan.json`. Its stored statuses are treated as placeholders. Repository code, executable audits and recorded runtime results take precedence over roadmap prose.

The input named `.projectnavigator-rescue-input/archaeology-proposal.json` is absent. The reconciliation therefore uses the actual prior proposal at `.projectnavigator-analysis/proposal.json` and the reports in `.projectnavigator-analysis/`. This substitution is explicit; no proposal content was inferred from the missing path.

Status meanings used here:

- **DONE** — the intended tile, including its end-to-end acceptance boundary, has evidence.
- **IN-PROGRESS** — a useful path exists, but the complete user intent is not closed.
- **PLANNED** — prerequisites or prototypes exist, but the intended deliverable is not accepted.
- **BACKLOG** — no current implementation evidence for the intended capability.

## Reconciled portfolio

| User WorkItem ID | User tile | Stored | Recommended | Archaeology temporaryKeys |
|---|---|---:|---:|---|
| `8801e3ab-2953-4113-b260-4cdb17238425` | Order, session and 360 tour MVP | planned | **DONE** | `tour-platform-mvp` |
| `4dcc4e53-cc41-4b0a-bb3a-ca2e47cca4d3` | APP Android capture and upload foundation | planned | **IN-PROGRESS** | `android-capture-upload`, `capture-topology-unification` |
| `da6e553d-a4ec-40c5-a431-b03941393d29` | APP SINGLE baseline | planned | **IN-PROGRESS** | `single-sfm-baseline`, `single-connectivity-drift`, `tof-imu-measurement`, `single-sensor-constraints` |
| `2498f509-0f87-41e1-9eba-fe0684511263` | APP Automatic photo capture | backlog | **IN-PROGRESS** | `auto-photo-sfm`, cross-cutting parts of `tof-imu-measurement` |
| `72378a61-7092-43c3-9671-4b365d929265` | APP MASTER + SLAVE | planned | **IN-PROGRESS** | `dual-phone-capture`, `capture-topology-unification` |
| `a94d2800-5bdf-4dea-83d9-cc05e8ac5152` | APP 2 Android phones + notebook/PC | backlog | **IN-PROGRESS** | `dual-phone-capture`, `capture-topology-unification`, relevant `stereo-global-fusion` host work |
| `4c7e40a0-5f6a-4563-b889-27e1f3286010` | APP Android + USB camera | planned | **IN-PROGRESS** | `usb-stereo-capture`, `stereo-global-fusion`, `capture-topology-unification` |
| `19dfb29a-4d50-4fb5-8848-39af4e78eb5d` | Insta360 capture | backlog | **DONE** for capture/tour scope | no dedicated tile; implemented portions of `android-capture-upload` and `tour-platform-mvp` |
| `c7ba832d-1307-475d-b543-59ac5c17ea6c` | Server storage and GrafikStation orchestration | planned | **IN-PROGRESS** | `server-processing-orchestration`, server parts of `auto-photo-sfm`, `capture-topology-unification`, `sfm-component-assembly` |
| `fa24df7a-87d4-4606-b4c8-3ce87eed24a1` | GrafikStation processing worker | planned | **IN-PROGRESS** | `single-sfm-baseline`, `stereo-global-fusion`, `tof-imu-measurement`, `sfm-component-assembly`, worker parts of `server-processing-orchestration` |
| `0b06374d-ccc3-4261-a376-676807e8ac12` | Globally consistent metric mesh with textures | planned | **PLANNED** | `metric-textured-model`, with prerequisites `single-sensor-constraints`, `stereo-global-fusion`, `sfm-component-assembly` |
| `633331e3-586d-4e7c-976f-e2c18e6c966c` | Dollhouse / floorplan / photorealistic viewer | planned | **IN-PROGRESS** | `photorealistic-viewer`, plus viewer portions of `tour-platform-mvp`, `auto-photo-sfm`, `sfm-component-assembly` |

The two DONE recommendations are deliberately narrow. Insta360 is DONE only as an acquisition/tour source, not as a metric-reconstruction path. The tour MVP is DONE for its recorded order/session/tour scope, not for every later 3D-product goal.

## Overall current state

The repository has a working business/tour foundation, several real capture paths, server job orchestration, and multiple COLMAP-based processing paths. Its central unfinished chain is not “capture exists”; it is **one canonical package accepted end to end, telemetry consumed as active constraints, globally optimized geometry produced, and metric/textured output accepted by measurement**.

The best next tile is `da6e553d-a4ec-40c5-a431-b03941393d29` (APP SINGLE baseline). It is the smallest already-running end-to-end path, has concrete job `job_180237696`, and isolates the known drift/constraint problem without adding stereo clock and role complexity.
