# LM02.7B.5.3.0.5 — Multi-plane corner diagnostics

## Purpose

The production room fusion may contain several valid wall and ceiling groups while
confirming only one plane. This stage keeps the production model unchanged and adds
a diagnostic pass over `room_planes_accumulated.json/all_groups`.

## Outputs

- `room_plane_candidates_accumulated.json` — every fused group, gravity class,
  support tier and explicit rejection reasons.
- `room_corner_hypotheses_accumulated.json` — near-orthogonal wall/wall,
  wall/ceiling and wall/floor relations, rectangle-intersection support and room
  triples.
- `room_candidate_skeleton_accumulated.ply` — all candidate rectangles and
  accepted diagnostic intersection lines.
- `room_multi_plane_status.json` — compact diagnosis and the best hypotheses.
- `room_multi_plane_console.json` — CLI result captured by `pack_session.sh`.

## Support tiers

- `CONFIRMED` — accepted by the existing production thresholds.
- `MULTIVIEW_CANDIDATE` — observed in at least two keyframes but not confirmed.
- `SINGLE_VIEW_CANDIDATE` — observed in one keyframe only.

## Important behavior

This patch is diagnostic-only. It does not promote candidate planes into
`room_planes_accumulated.json`, does not replace the existing skeleton and does
not change stereo depth, pose estimation or keyframe selection.

A diagnosis such as `SECOND_WALL_EVIDENCE_FRAGMENTED_ACROSS_GROUPS` means that
several unconfirmed groups form plausible orthogonal counterparts to a confirmed
wall across multiple keyframes. It identifies a plane-fusion/grouping problem,
not an absence of stereo depth evidence.
