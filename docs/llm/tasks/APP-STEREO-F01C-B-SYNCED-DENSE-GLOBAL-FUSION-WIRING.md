# APP-STEREO-F01C-B — Synced Dense Global Fusion Wiring

## Status

```text
IMPLEMENTED
DEPLOYMENT DEFERRED UNTIL ACTIVE FHD60 JOB FINISHES
```

## Parent

```text
APP-STEREO-F01C-A — Initial Global Cloud Fusion Core
```

## Goal

Run pose-based global point-cloud fusion automatically after F01B odometry in
every new `MAKLERTOUR_SYNCED_DENSE` job.

## Pipeline order

```text
capture bundle validation
→ F01A metric depth and pair-local clouds
→ F01B metric stereo trajectory
→ F01C-A initial global fusion without ICP
→ strict artifact validation
→ result.json
```

## Runtime command

```text
stereo_global_fusion.py <dense_dir> --voxel-size-mm 20
```

The voxel size is explicit in the job command and result contract.

## New artifacts

```text
dense/global_fusion/fused_global_no_icp.ply
dense/global_fusion/global_fusion_manifest.json
```

## New result fields

```text
global_fusion_manifest
fused_global_no_icp
fusion_stage
included_cloud_count
excluded_cloud_count
fused_points_before_voxel
fused_points_after_voxel
voxel_size_mm
icp_applied=false
global_fusion_complete=false
```

Existing F01A and F01B fields remain present.

## Validation gates

The job fails when:

- pair-cloud and trajectory pair counts differ;
- accepted plus rejected poses do not equal trajectory pair count;
- trajectory status is outside the documented enum;
- fusion stage is not `initial_no_icp`;
- ICP or global completion is claimed;
- no pair cloud is included;
- included cloud count exceeds accepted pose count;
- fused point count is zero or grows after voxel downsample;
- voxel size is non-positive;
- output PLY path is absolute or escapes the dense directory;
- output PLY is missing or too small.

## Completion semantics

These trajectory states remain valid:

```text
origin_only
partial
complete_pair_sequence
```

A weak trajectory may still produce a valid diagnostic initial global PLY.
The job remains `DONE`, but the result continues to declare:

```text
icp_applied=false
global_fusion_complete=false
```

## Progress

```text
40%  F01A metric pair depth/clouds
74%  F01B metric visual odometry
88%  F01C-A initial global fusion
96%  contract validation and packaging
100% DONE
```

## Test

```bash
php web/tests/stereo_global_fusion_job_wiring_test.php
```

The test uses fake station processors and verifies command order, result fields,
point counters, artifact paths, and the false ICP/completion flags.

## Runtime acceptance

After the active FHD60 job finishes:

1. deploy the updated station scripts;
2. start a new `MAKLERTOUR_SYNCED_DENSE` job;
3. inspect trajectory acceptance;
4. open `fused_global_no_icp.ply`;
5. verify scale, orientation, duplicate surfaces, drift, and cloud gaps;
6. use the result to decide F01C-C ICP refinement thresholds.
