# LM02.7B.5.3.0 — online AprilTag anchor map and tag-assisted relocalization

## Scope

The laptop host detects MaklerTour Marker Kit v1 tags in rectified CAMERA_A frames and builds an online metric anchor map without requiring a tag at START.

## Marker kit

- Type: AprilTag.
- Dictionary: `36h11`.
- Allowed IDs: `1..30`; numeric order and placement order are irrelevant.
- Detection-square size: `0.160 m`.
- More than one tag may be visible in the same frame.
- Tags are assumed rigidly fixed to walls, floor, ceiling or other immovable surfaces.
- The initial CAMERA_A pose remains the world origin when the session starts without a known tag.

## Landmark lifecycle

Each unique tag ID is stored once and advances through:

1. `CANDIDATE` — first observations are being checked.
2. `MAPPED` — at least three geometrically consistent observations.
3. `ANCHOR` — at least five consistent observations and a repeat observation after a useful pair-index gap.

No sequential order such as 1,2,3,4 is required.

## Pose use

- An unknown tag is added using the current preliminary camera pose.
- A mapped or anchor tag produces an independent world camera pose.
- Several visible mapped tags are fused through a robust consensus.
- A known tag may recover pose when visual/PnP tracking is unavailable.
- A large correction is reported as `APRILTAG_RELOCALIZED`.
- A small correction is reported as `APRILTAG_ANCHORED`.
- This stage records constraints but does not yet perform full pose-graph optimization or rewrite earlier keyframes.

## Safety gates

Observations are rejected when:

- ID is outside `1..30`;
- pose estimation fails;
- reprojection error is above 3 px;
- estimated distance is outside `0.20..8.0 m`;
- multiple known tags disagree beyond the consensus gates.

## Files

- `apriltag_observations.jsonl`
- `apriltag_constraints.jsonl`
- `apriltag_map.json`
- `apriltag_map.ply`
- `apriltag_status.json`
- `apriltag_latest.jpg`

## Coordinate system

`X_right_Y_up_Z_forward_meters`.

The tag map uses the initial CAMERA_A pose as world origin until a later pose-graph stage introduces a global optimized frame.
