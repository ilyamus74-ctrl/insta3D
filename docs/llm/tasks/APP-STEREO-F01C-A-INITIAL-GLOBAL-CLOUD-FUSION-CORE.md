# APP-STEREO-F01C-A — Initial Global Cloud Fusion Core

## Status

```text
IMPLEMENTED CORE
NOT WIRED INTO MAKLERTOUR_SYNCED_DENSE
RUNTIME ACCEPTANCE PENDING
```

## Parent

```text
APP-STEREO-F01 — Global Stereo Depth Fusion
APP-STEREO-F01A — Metric Pair Cloud Export
APP-STEREO-F01B — Metric Stereo Visual Odometry
```

## Goal

Transform every accepted F01A pair-local cloud by the F01B
`transform_cam0_to_world` pose and export one initial global colored PLY.

This stage performs direct pose-based fusion only:

```text
pair-local metric PLY
→ transform_cam0_to_world
→ concatenate accepted clouds
→ deterministic voxel downsample
→ initial global PLY
```

No ICP or loop-closure correction is performed in F01C-A.

## Inputs

The engine consumes an existing dense directory containing:

```text
pair_cloud_manifest.json
stereo_trajectory.json
pair_clouds/dense_pair_<index>_cloud.ply
```

Required contracts:

```text
pair cloud coordinate system:
    rectified_cam0_pair_local

pair cloud units:
    mm

trajectory pose convention:
    transform_cam0_to_world

trajectory coordinate system:
    stereo_f01_world
```

## Outputs

Default output directory:

```text
dense/global_fusion/
```

Artifacts:

```text
fused_global_no_icp.ply
global_fusion_manifest.json
```

The PLY coordinate frame is:

```text
stereo_f01_world
units: mm
```

## Transform convention

For each accepted pair:

```text
p_world = R_world_from_cam0 * p_cam0 + t_world_from_cam0
```

Colors are copied without geometric modification.

Rejected trajectory poses are not fused.

## Voxel downsample

Default:

```text
voxel_size_mm = 20
```

Points in each voxel are replaced by:

```text
mean XYZ
mean RGB rounded to nearest integer
```

Voxel ordering is deterministic and lexicographic.

## Validation

The engine validates:

- pair-cloud manifest coordinate system and units;
- trajectory coordinate system, units, and pose convention;
- 4×4 finite rigid transforms;
- positive finite local depth;
- actual PLY point count against manifest point count;
- binary little-endian or ASCII PLY vertex schema;
- at least one accepted cloud with usable points.

## Manifest semantics

`global_fusion_manifest.json` records:

```text
fusion_stage=initial_no_icp
global_alignment_available=true
icp_applied=false
global_fusion_complete=false
```

`global_fusion_complete` remains false because F01C-A has not performed ICP,
loop closure, viewer integration, or runtime acceptance.

## CLI

```bash
python3 web/remote_station/scripts/stereo_global_fusion.py \
  /path/to/job/dense \
  --voxel-size-mm 20
```

## Test

```bash
PYTHON=/tmp/insta3d-f01a-venv/bin/python \
php web/tests/stereo_global_fusion_test.php
```

Expected:

```text
OK
```

## Next patch

```text
APP-STEREO-F01C-B
→ wire initial fusion into MAKLERTOUR_SYNCED_DENSE
→ publish output paths and counters in result.json
→ add browser/runtime acceptance artifacts
→ keep ICP as a separate refinement stage
```
