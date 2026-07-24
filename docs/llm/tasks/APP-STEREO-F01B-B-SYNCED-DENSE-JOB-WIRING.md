# APP-STEREO-F01B-B — Synced Dense Job Wiring

## Status

```text
IMPLEMENTED
DEPLOYMENT DEFERRED UNTIL ACTIVE FHD60 JOB FINISHES
```

## Parent

```text
APP-STEREO-F01 — Global Stereo Depth Fusion
APP-STEREO-F01B-A — Metric Stereo Visual Odometry Core
```

## Goal

Run metric stereo visual odometry automatically after F01A pair-local depth
and point-cloud export inside every new `MAKLERTOUR_SYNCED_DENSE` job.

## Pipeline order

```text
validate capture bundle
→ F01A disparity and metric depth
→ F01A pair-local PLY export
→ F01B metric stereo visual odometry
→ validate artifact counters
→ result.json
```

## Runtime command

The job invokes:

```text
stereo_visual_odometry.py <dense_dir>
```

with explicit thresholds matching the F01B-A contract. The thresholds are not
left implicit so later default changes cannot silently alter deployed jobs.

## Result artifacts

The job must publish:

```text
dense/stereo_trajectory.json
dense/stereo_odometry_debug.json
```

`result.json` adds:

```text
stereo_trajectory
stereo_odometry_debug
trajectory_pair_count
trajectory_status
accepted_pose_count
rejected_pose_count
global_fusion_complete=false
```

Existing F01A result fields remain unchanged.

## Job completion semantics

These odometry outcomes are valid completed processing results:

```text
origin_only
partial
complete_pair_sequence
```

A weak scene may therefore finish as `DONE` with `origin_only` or `partial`.
The diagnostic artifacts remain available for threshold and capture analysis.

The job fails when:

- F01A artifacts are missing or malformed;
- the odometry engine raises an exception;
- trajectory JSON is malformed;
- `global_fusion_complete` is not strictly false;
- pair-cloud and trajectory pair counts differ;
- accepted plus rejected poses do not equal trajectory pair count;
- trajectory status is outside the documented enum.

## Status progress

```text
40%  F01A synced dense
78%  metric stereo visual odometry
94%  artifact validation and result packaging
100% DONE
```

## Safety boundary

This patch does not:

- deploy scripts while the FHD60 job is active;
- modify Android;
- perform global point-cloud fusion;
- run ICP;
- publish a fused PLY;
- set `global_fusion_complete=true`.

## Test

```bash
php web/tests/stereo_odometry_job_wiring_test.php
```

The test uses a temporary station and fake processing executables. It verifies:

- dense runs before odometry;
- both output manifests are required;
- result JSON publishes all F01B fields;
- partial trajectory remains a successful job;
- final status is `DONE`;
- global fusion remains false.

## Runtime acceptance after deployment

Run a new `MAKLERTOUR_SYNCED_DENSE` job and inspect:

```text
pair_cloud_count
trajectory_pair_count
accepted_pose_count
rejected_pose_count
trajectory_status
stereo_trajectory.json
stereo_odometry_debug.json
```

F01B runtime acceptance should precede F01C global fusion.
