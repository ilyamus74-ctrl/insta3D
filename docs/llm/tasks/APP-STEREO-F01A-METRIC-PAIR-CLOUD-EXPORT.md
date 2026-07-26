# APP-STEREO-F01A — Metric Pair Cloud Export

## Status

```text
IMPLEMENTED AND WIRED INTO MAKLERTOUR_SYNCED_DENSE
RUNTIME VISUAL ACCEPTANCE PENDING
```

## Parent

```text
APP-STEREO-F01 — Global Stereo Depth Fusion
```

## Goal

Extend the existing synced dense processor so each selected stereo pair
produces a colored metric point cloud in that pair's rectified cam0 coordinate
system.

This is an artifact/export task. It does not estimate camera motion and does
not create a global model.

## Allowed code scope

Primary files:

```text
web/remote_station/scripts/dense_depth_from_synced_capture.py
web/remote_station/scripts/process_maklertour_synced_dense.sh
web/remote_station/deploy_station.sh
web/DOCS/CAPTURE_BUNDLE_DENSE_CONTRACT.md
web/DOCS/CAPTURE_BUNDLE_STEREO_FUSION_CONTRACT.md
```

Tests may be added under:

```text
web/tests/
```

Android capture code, upload schema, DB schema and the current job type should
not change in F01A unless implementation evidence proves an unavoidable
contract gap.

## Input contract

Use the already validated inputs:

```text
bundle_manifest.json
capture/synced_depth_manifest.json
capture/pairs/*
calibration/stereo_extrinsics.json
```

Use the existing selected-pair list and existing depth validity mask.

## Geometry contract

For horizontal baseline, back-project a valid rectified cam0 pixel:

```text
Z = depth_mm
X = (u - cx) * Z / fx
Y = (v - cy) * Z / fy
```

Intrinsics come from rectified `P1`.

Canonical output:

```text
coordinate_system = rectified_cam0_pair_local
units = millimeters
```

For the vertical-baseline branch, disparity is calculated on identically
rotated rectified images. Before back-projection, pixel coordinates and color
lookup must be mapped back into the unrotated rectified cam0 image coordinate
system.

Applying unmodified `P1` directly to rotated pixel coordinates is forbidden.

## Point filtering

Export only points where:

- disparity is valid;
- depth is finite;
- `min_depth_mm <= Z <= max_depth_mm`;
- mapped source pixel is inside the rectified cam0 image;
- XYZ is finite.

The implementation may use deterministic pixel stride or deterministic voxel
filtering to limit file size. Sampling parameters must be recorded in the
manifest.

## PLY contract

Each pair PLY must contain at least:

```text
property float x
property float y
property float z
property uchar red
property uchar green
property uchar blue
```

Required path:

```text
dense/pair_clouds/dense_pair_<pair_index:04d>_cloud.ply
```

Color source:

```text
rectified cam0 image
```

OpenCV BGR must be written as PLY RGB.

## Manifest contract

Required file:

```text
dense/pair_cloud_manifest.json
```

Minimum schema:

```json
{
  "schema_version": 1,
  "coordinate_system": "rectified_cam0_pair_local",
  "units": "mm",
  "global_fusion_complete": false,
  "pair_clouds": [
    {
      "pair_index": 0,
      "cloud_file": "pair_clouds/dense_pair_0000_cloud.ply",
      "point_count": 1000,
      "valid_depth_ratio": 0.42,
      "stereo_capture_delta_ms": 4.2,
      "physical_orientation": "portrait_upright",
      "sampling": {
        "method": "pixel_stride",
        "stride": 2
      }
    }
  ]
}
```

The manifest must not contain a global pose in F01A.

## Result contract extension

`result.json` may add:

```json
{
  "pair_cloud_manifest": ".../dense/pair_cloud_manifest.json",
  "pair_cloud_count": 12,
  "global_fusion_complete": false
}
```

Existing fields remain backward compatible.

## Preview contract

Add a lightweight diagnostic artifact:

```text
dense/contact_pair_clouds.jpg
```

Browser-native 3D rendering is not required in F01A.

## Required tests

### Unit and behavior tests

- horizontal synthetic depth plane back-projects to expected XYZ;
- vertical rotation mapping returns correct unrotated pixel coordinates;
- BGR converts to RGB;
- invalid depth is excluded;
- NaN and Inf are excluded;
- PLY header matches vertex count;
- local cloud path is deterministic;
- pair manifest parses and contains required fields;
- `global_fusion_complete` is strictly `false`;
- existing synced-dense artifacts remain present.

### Runtime test

Use an existing valid synced stereo capture:

```text
run MAKLERTOUR_SYNCED_DENSE
→ open 3–5 pair PLY files in MeshLab
```

Confirm:

- recognizable local scene geometry;
- correct color;
- plausible metric size;
- positive forward depth;
- no mirrored vertical-baseline cloud;
- no claim of global alignment.

## Completion criteria

F01A is complete only when:

```text
tests pass
station deployment succeeds
existing artifacts remain compatible
pair_cloud_manifest.json is valid
at least 3 local pair clouds are visually inspected
metric scale is plausible
vertical branch is verified
documentation is updated
```

Implementation is complete; runtime inspection remains part of the current stereo acceptance gate.
