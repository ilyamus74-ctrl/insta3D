# LM02.7B.5.3.0.1 — 20 FPS stereo AprilTag temporal consensus

## Scope

This stage replaces unconditional single-camera AprilTag pose jumps with a
separate fast marker pipeline. Stereo depth remains on its existing lower-rate
budget; synchronized marker measurements are accepted at up to 20 pairs/s.

## Marker kit

- family: AprilTag 36h11;
- allowed IDs: 1–30 in arbitrary order;
- detection-square size: 0.160 m;
- one or several tags may be visible in a frame;
- tags may be fixed on walls or on the floor;
- no mandatory start tag or predefined world coordinates.

## Fast and slow rates

- Android reduced-frame producer permits up to 20 FPS;
- strict synchronized A/B pairs feed the marker worker with a 50 ms minimum
  interval;
- StereoSGBM and geometry-keyframe processing keep their existing lower-rate
  budget;
- plane fusion remains post-capture.

## Stereo measurement

For an ID detected by both cameras, the four corresponding corners are
triangulated with the calibrated camera-to-camera transform. A measurement is
stereo-valid only when:

- all four points have positive depth in both cameras;
- reconstructed mean side is consistent with 0.160 m;
- four side lengths are mutually consistent;
- corner planarity residual is bounded.

`solvePnPGeneric(..., SOLVEPNP_IPPE_SQUARE)` remains a temporal mono fallback,
but a mono result cannot independently promote a tag to an anchor.

## Temporal validation

Each ID owns a bounded history of 20 observations. State progression is:

`CANDIDATE -> STEREO_VERIFIED -> MAPPED -> ANCHOR`.

A tag becomes `STEREO_VERIFIED` only after repeated stable stereo geometry.
Mapping observations must come from an independent trusted camera pose or from
a different already trusted anchor. A tag is never validated by deriving the
camera pose from that same tag and immediately writing the tag back from that
pose.

## Safe live correction

An AprilTag estimate is recorded as a constraint before it is permitted to
replace live pose. A correction is accepted only when it is small and
consistent with the current pose, or when translation tracking is untrusted and
there is strong stereo anchor evidence. Large jumps are logged as
`APRILTAG_CONSTRAINT_REJECTED_LIVE` and do not move accumulated geometry.

## Diagnostic wall relations

The known installation is checked diagnostically, without hard forcing:

- ID 8 and ID 3: expected perpendicular wall normals, approximately 90°;
- ID 8 and ID 2: expected parallel wall normals, approximately 0° undirected.

The result is written to `apriltag_relations.json`.

## Session outputs

- `apriltag_stereo_observations.jsonl`;
- `apriltag_constraints.jsonl`;
- `apriltag_map.json`;
- `apriltag_map.ply`;
- `apriltag_relations.json`;
- `apriltag_status.json`;
- `apriltag_latest_a.jpg`;
- `apriltag_latest_b.jpg`.

## Exclusions

This stage does not implement global pose-graph optimization, bundle adjustment,
or retroactive re-fusion of all saved geometry. It establishes trustworthy
stereo marker constraints and safe live relocalization gates first.
