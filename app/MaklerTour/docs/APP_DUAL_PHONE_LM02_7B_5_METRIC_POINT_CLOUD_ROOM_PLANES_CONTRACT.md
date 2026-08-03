# LM02.7B.5 — metric point cloud and room planes

## Goal

Convert the accepted calibrated `HIGH_640` stereo depth result into a first
metric 3D representation of the visible room. This slice produces inspectable
PLY and JSON artifacts. It does not yet fuse camera motion, close a complete
room mesh, or apply FHD textures.

## Geometry source

- Preferred profile: `HIGH_640`.
- Input: filtered dense disparity and mask from `StereoDepthRuntime`.
- Metric depth: calibrated focal length and measured stereo baseline.
- Coordinate system: `X right`, `Y up`, `Z forward`, units in metres.
- Valid depth interval: 0.45–8.0 m.
- Voxel size: 0.04 m.

## Processing

1. Reproject filtered disparity into coloured metric points.
2. Voxel-downsample the cloud and cap it at 120000 points.
3. Fit up to eight dominant planes with deterministic RANSAC.
4. Refine each plane by PCA and calculate its rectangle, area and RMS error.
5. Classify candidates as floor, ceiling, wall, horizontal or slanted.
6. Calculate finite intersection segments between overlapping plane extents.
7. Publish the latest result asynchronously at no more than 1 Hz.

## Session artifacts

- `point_cloud_latest.ply` — coloured metric cloud with `plane_id` property.
- `room_skeleton_latest.ply` — plane rectangles and detected intersection edges.
- `room_planes_latest.json` — plane equations, extents and classifications.
- `room_edges_latest.json` — metric edge endpoints and source plane IDs.
- `room_geometry.jsonl` — processing chronology.
- `room_geometry_status.json` — final runtime status.

All artifacts are included by `pack_session.sh`.

## Non-goals

- No temporal/world-coordinate fusion between camera poses.
- No watertight mesh generation.
- No texture atlas or UV mapping.
- No AprilTag scale correction.
- No IMU gravity alignment yet; floor/ceiling labels are camera-frame candidates.
- No CUDA backend.

## Acceptance

- Existing live and geometry depth pipelines continue to run.
- Point-cloud processing is latest-only and does not block stereo ingest.
- At least one valid HIGH_640 frame creates both PLY files and both JSON files.
- Status reports point, plane and edge counts plus processing duration.
- Generated PLY uses metres and opens in MeshLab or CloudCompare.
