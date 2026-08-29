# Bounded rescue sequence

## Recommended first WorkItem

Continue `da6e553d-a4ec-40c5-a431-b03941393d29` — **APP SINGLE baseline**.

Why: it is already the most complete sparse end-to-end path; `job_180237696` provides a fixed dataset; the visual-only limit is measured; and SINGLE avoids adding multi-camera clock, role, calibration and package-acceptance variables before the project establishes whether visual links alone can solve the target.

## Sequence

```text
CURRENT VERIFIED STATE
SINGLE capture uploads video + IMU + ToF; visual baselines and diagnostics exist
        ↓
1. Reconcile the isolated Hybrid v2 runner with its documented contract
        ↓
2. Run Hybrid v2 controlled long-range visual pairs on job_180237696
        ↓
VISUAL ACCEPTANCE GATE
registration/components + trajectory/reprojection + endpoint/ground-truth interpretation
        ↓
3. If drift remains, run one active IMU pose/gravity-prior A/B experiment
        ↓
4. Run one active ToF metric-constraint A/B experiment
        ↓
SINGLE METRIC-PRIOR MILESTONE
choose the smallest evidenced constraint path
        ↓
5. Freeze the canonical capture/processing fixture around that path
        ↓
6. Fix and accept one stereo branch end to end (Android+USB recommended)
        ↓
7. Global fusion/optimization → metric cloud → mesh/texturing acceptance
        ↓
8. Production dollhouse/floorplan/photorealistic viewer acceptance
```

## Step details

### 1. Contract/run reconciliation

- **Why:** current Hybrid v2 documentation says loop detection enabled while the isolated runner disables it; a result would otherwise be ambiguous.
- **Blocked by:** choosing the authoritative v2 matcher settings, without touching production.
- **Depends on:** existing SINGLE dataset/artifacts only.
- **Done when:** README/command/audit agree and dry-run diagnostics show a bounded non-exhaustive pair graph.
- **Affected:** isolated `web/remote_station/single_hybrid_v2/`, SINGLE audit documents; no production pipeline.

### 2. Controlled visual A/B

- **Why:** test whether sparse long-range links improve connectivity without exhaustive cost.
- **Blocked by:** step 1 and dataset availability.
- **Depends on:** `job_180237696` baseline metrics.
- **Done when:** registered images, components, points, reprojection, trajectory, first/last frame and endpoint distance are recorded; pair graph proves it was not exhaustive.
- **Affected:** isolated experiment outputs and SINGLE audit only.

### 3–4. Active sensor-prior A/B

- **Why:** audits prove telemetry presence but not geometric participation.
- **Blocked by:** defined coordinate frames/units/timestamp association and a ground-truth gate.
- **Depends on:** visual A/B result; canonical fixture fields from the cross-cutting contract.
- **Done when:** each experiment demonstrates whether optimization changed and reports metric/trajectory delta versus the same visual baseline.
- **Affected:** experimental worker configuration/tooling and diagnostics; later adopted into `fa24...` only after acceptance.

### 5. Contract fixture gate

- **Why:** prevent the historical captured-but-dropped/ignored failure mode before multiplying capture branches.
- **Blocked by:** deciding required versus optional IMU/ToF and authoritative modes.
- **Depends on:** selected SINGLE constraint path.
- **Done when:** Android/server/worker validate one versioned fixture and report telemetry as absent/diagnostic/active.
- **Affected:** future work in Android foundation `4dcc...`, server `c7ba...`, worker `fa24...`; project-level contract document.

### 6. One stereo branch

- **Recommendation:** Android+USB `4c7e...`, because camera roles/calibration/pair processing and F01/F02 tooling already exist in one-device timing scope.
- **Blocked by:** physical-device F02 acceptance, durable telemetry package and worker runtime acceptance.
- **Depends on:** contract fixture gate; can prepare in parallel, but cannot be declared DONE without it.
- **Done when:** calibrated pairs and required telemetry are accepted and consumed through global optimized output.
- **Affected:** Android+USB tile, server, worker; MASTER/SLAVE and laptop branches remain bounded follow-ons.

### 7–8. Product output and viewer

- **Why:** generic COLMAP mesh/viewer existence is not the intended global metric product.
- **Blocked by:** accepted trajectory/scale/global fusion and declared physical thresholds.
- **Depends on:** chosen sensor/stereo path.
- **Done when:** reproducible metric textured artifact passes physical checks, then dollhouse/floorplan/photorealistic UX consumes it within performance/quality targets.
- **Affected:** `0b06...`, `6333...`, worker/server publication paths.

## Parallel work allowed

While steps 1–4 run, server and Android owners can build contract fixtures, and viewer developers can use frozen representative assets. These are SOFT relationships. No capture branch should be declared complete until its final server-consumption and result criteria pass.

## Self-review

- User architecture preserved: 12 target tiles; no replacement graph or new product tile.
- Stored placeholder statuses were independently reconciled.
- No branch is DONE merely because local code exists; narrow DONE boundaries are explicit.
- IMU and ToF are separated at capture, persistence, upload, acceptance, consumption, active constraint and result stages.
- Stereo pair use, trajectory, fusion, optimization, metric cloud and mesh/texturing are separately assessed.
- Every archaeology WorkItem and issue has a disposition.
- All current HARD links were reviewed against the requested “cannot begin” rule; no unjustified HARD edge remains recommended.
- Only files under `.projectnavigator-rescue/` were created by this task.
