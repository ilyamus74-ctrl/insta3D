# LM02.7B.5.2.4 — robust local stereo cloud and global plane fusion

## Purpose

Convert gyro-registered stereo keyframes into a cleaner accumulated point cloud
and a multi-keyframe room-plane skeleton without adding load to the live capture
and depth workers.

## Input

The post-capture fusion tool consumes the files produced by LM02.7B.5.2.3:

- `keyframes/keyframe_<N>_local.ply`;
- `keyframes/keyframe_<N>_world.ply`;
- the world poses already applied by the gyro-assisted accumulator.

The tool does not change disparity, camera calibration, pose tracking or the live
preview. It is deliberately executed after the host exits and before the session
archive is created.

## Robust local cloud

For every keyframe the tool:

1. clips points to the indoor range `0.45–6.0 m`;
2. voxel-downsamples the local cloud;
3. removes points without enough three-dimensional neighbours;
4. suppresses long radial depth chains inside small angular cells;
5. writes local and world-space filtered PLY evidence.

Generated evidence:

- `keyframes/keyframe_<N>_local_filtered.ply`;
- `keyframes/keyframe_<N>_world_filtered.ply`.

## Accumulated filtered cloud

Filtered world-space points are accumulated in `4 cm` voxels. Two outputs are
kept separate:

- `point_cloud_accumulated_filtered_raw.ply` — every filtered keyframe;
- `point_cloud_accumulated_filtered.ply` — voxels seen by at least two keyframes.

The second file is the preferred cloud for visual inspection and later mesh work.

## Global plane fusion

Each filtered keyframe is processed independently with deterministic RANSAC.
Local plane observations are transformed in the already registered world frame
and grouped by normal angle and metric plane distance.

A plane is allowed into the room skeleton only when supported by at least three
unique keyframes and has sufficient fused area. Confirmed plane rectangles and
orthogonal intersections produce:

- `room_planes_accumulated.json`;
- `room_edges_accumulated.json`;
- `room_skeleton_accumulated.ply`.

Plane classes are provisional:

- `FLOOR_CANDIDATE`;
- `CEILING_CANDIDATE`;
- `WALL_CANDIDATE`;
- `OBLIQUE_CANDIDATE`.

Final floor/ceiling authority will use gravity locking in a later stage.

## Runtime integration

`pack_session.sh` runs `tools/fuse_room_geometry.py` before copying session
artifacts. Fusion failure never prevents creation of the diagnostics archive.
The tool can also be executed manually against any existing session.

## Diagnostics

- `room_fusion_status.json` — compact counters and output names;
- `room_fusion_diagnostics.json` — parameters and per-keyframe filtering counts;
- `room_fusion_console.json` — captured CLI result during automatic packaging.

## Acceptance

- existing live preview and StereoSGBM tests continue to pass;
- the Python tool uses only the standard library;
- at least three keyframes are required for a confirmed plane;
- filtered PLY files contain fewer isolated radial points than raw accumulated PLY;
- session packaging includes all generated geometry and filtered keyframe evidence.
