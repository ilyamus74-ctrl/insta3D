# Logical development plan

## Main sequence

```text
CURRENT VERIFIED STATE
SINGLE visual reconstruction works; sensor evidence is synchronized/diagnostic;
dual/USB capture foundations and stereo fusion source exist
        |
        +----------------------+----------------------+
        v                      v                      v
bounded SINGLE gate     dual contract closure   stereo runtime baseline
        v                      v                      v
IMU pose-prior A/B       accepted dual bundle    accepted pair clouds/VO/fusion
        v                      +-----------+----------+
ToF metric-constraint A/B                 v
        +------------------------> stable global metric geometry
                                             v
                                  mesh + texture acceptance
                                             v
                                  product viewer/dollhouse
```

## Step 1 — Freeze a reproducible evidence baseline

- Why: task headers reference different commits and runtime artifacts live outside Git.
- Blocker: access to actual devices, DB/storage and GrafikStation.
- Depends on: existing capture/orchestration.
- Completion: record HEAD/deployed versions, immutable input hashes, station image/tool versions, current schema and representative result roots.
- Affected later: docs/runbooks, station config/deploy metadata, job provenance; no implementation is proposed by this analysis.

## Step 2A — Run one corrected bounded Hybrid v2 sparse experiment

- Why: visual non-local edges materially improved connectivity; bounded policy has not been tested.
- Blocker: reconcile runner/audit contract, obtain dataset/COLMAP and decide whether built-in loop detection is part of v2.
- Depends on: `single-sfm-baseline`, immutable baseline.
- Completion: components, unique/aggregate registrations, points, reprojection error, verified long-range edges, first/last frame, endpoint/path and trajectory outliers are captured; no dense/production mutation.
- Affected: isolated experiment and audit artifacts only.

## Step 2B — Close the dual-phone producer/consumer contract

- Why: current aggregate upload is deterministically rejected.
- Blocker: human decision on canonical capture type/schema and ownership of aggregate materialization.
- Depends on: capture-topology contract and Android upload foundation.
- Completion: same capture type/version accepted by Android and PHP; authorization/path/archive gates pass; both roles and shared capture identity are preserved; negative tests reject malformed bundles.
- Affected: Android bundle metadata/upload, PHP allowlist/materializer, contract tests, possibly schema via a separately approved migration.

## Step 2C — Execute stereo runtime acceptance

- Why: F01/F02 are source-complete but acceptance pending.
- Blocker: calibrated capture, Android build/device access and deployed station scripts.
- Depends on: USB stereo capture; dual path additionally depends on Step 2B.
- Completion: preflight/operator UX verified on device; station job produces validated pair clouds, trajectory and initial global PLY with preserved baseline metrics and artifact provenance.
- Affected: Android runtime evidence, station deployment/runtime, no algorithm promotion before results.

## Step 3A — IMU pose-prior experiment

- Why: if bounded visual connectivity does not remove drift, IMU is the next physical prior and timing data already exists.
- Blocker: device-to-COLMAP transform and explicit optimizer integration design are absent.
- Depends on: sensor timing evidence and Step 2A decision.
- Completion: controlled visual-only vs visual+IMU comparison with orientation/gravity residuals, trajectory diagnostics and fallback when IMU is absent/poor.
- Affected: experimental reconstruction/analysis path first; production promotion is a separate decision.

## Step 3B — ToF metric-constraint experiment

- Why: ToF observations are calibrated but currently only measure error.
- Blocker: accepted correspondence/robustness policy and explicit decision whether to constrain scale, depth or poses.
- Depends on: ToF measurement gate and preferably accepted pose behavior from Step 3A.
- Completion: controlled mutation is isolated, scale/residual improvement is reproduced on independent capture, RGB fallback remains valid, no PRE-CW90 evidence leakage.
- Affected: experimental geometry/optimization and S01H evidence path.

## Step 4 — Optimize global stereo geometry

- Why: current fusion is direct pose transform and explicitly incomplete.
- Blocker: accepted real trajectory/fusion baseline; algorithm choice (pose graph/BA/ICP/loop constraint) is not settled.
- Depends on: Step 2C.
- Completion: global fusion declares complete only after quantitative and visual gates; scale, closure, cloud overlap and rejected-pose diagnostics are preserved.
- Affected: stereo odometry/fusion station scripts and result contract.

## Milestone — Stable metric reconstruction

Reached when at least one supported capture topology produces repeatable, globally consistent metric geometry on an independent room capture, with source identity and error diagnostics. SINGLE remains reference; dual/USB parity follows rather than being assumed.

## Step 5 — Mesh, textures and viewer productization

- Depends on: stable global metric geometry; assembly if video components remain fragmented.
- Completion: non-empty manifold/usable mesh, texture provenance from original frames, bounded viewer performance and metric measurement checks.
- Affected: remote mesh/texture processing, artifact contracts, web viewers.

## Parallel maintenance branch

Auto Photo B01-B06 runtime/product acceptance and existing tour reliability can proceed alongside geometry research, provided capture/storage contracts are not silently forked.
