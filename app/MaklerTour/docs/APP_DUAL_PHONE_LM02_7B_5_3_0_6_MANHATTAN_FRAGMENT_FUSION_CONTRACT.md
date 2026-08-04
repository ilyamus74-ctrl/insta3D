# APP DUAL PHONE LM02.7B.5.3.0.6 — Manhattan Fragment Fusion Contract

## Goal

Convert fragmented multi-keyframe wall candidates into one conservative room
corner model without overwriting the existing robust fusion outputs.

## Inputs

The post-capture tool consumes:

- `room_plane_candidates_accumulated.json`;
- `room_corner_hypotheses_accumulated.json`.

These inputs are produced by `analyze_multi_plane_corners.py` after the normal
room-plane fusion stage.

## Wall fusion rules

1. A multiview wall/wall hypothesis with near-orthogonal normals selects the two
   Manhattan wall axes.
2. Wall candidates are assigned to the nearest axis only when gravity alignment
   and angular tolerance are satisfied.
3. Parallel fragments are merged only when their plane offsets are within the
   configured tolerance.
4. Keyframe support is unioned across all fragments in a merged physical wall.
5. A second wall is promoted only when both merged walls have multiview support,
   share at least two keyframes, intersect over a supported line segment and one
   side has an already confirmed source-plane anchor.
6. The promoted pair produces one deduplicated physical `WALL_CORNER` edge.

## Horizontal-plane rules

A ceiling or floor is not promoted merely because it is orthogonal to the wall
axes. It must have multiview support, supported intersections with both promoted
walls and shared keyframes with both walls.

## Outputs

- `room_planes_manhattan_accumulated.json`;
- `room_edges_manhattan_accumulated.json`;
- `room_skeleton_manhattan_accumulated.ply`;
- `room_manhattan_fusion_status.json`;
- `room_manhattan_fusion_console.json`.

The original `room_planes_accumulated.json`, `room_edges_accumulated.json` and
`room_skeleton_accumulated.ply` remain unchanged for comparison and rollback.
