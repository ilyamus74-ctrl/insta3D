# LM02.7B.5.3.0.2 — distributed tag graph and window consensus

## Purpose

The laptop host shall preserve every decoded AprilTag ID as evidence even when the current camera translation is not trusted. A tag becomes stereo-verified from non-consecutive evidence inside a sliding window, not only from uninterrupted stereo frames.

## Required behaviour

- AprilTag family: 36h11.
- Allowed IDs: 1–30 in arbitrary order.
- Detection size: 0.160 m.
- Stereo consensus: at least 5 valid stereo observations in the latest 20 observations.
- A mono fallback observation shall not erase prior valid stereo evidence.
- Co-visible stereo-verified tags shall create a camera-pose-independent relative edge.
- A tag disconnected from the world map shall remain visible as `UNANCHORED_COMPONENT`.
- A confirmed graph edge may propagate a world coordinate from a mapped tag to a newly observed tag.
- Stereo rejection causes shall be counted and written to diagnostics.
- Reported FPS values shall be measured values and explicitly scoped to strict synchronized pairs.

## Outputs

- `apriltag_tag_graph.json`
- enhanced `apriltag_status.json`
- enhanced `apriltag_stereo_observations.jsonl`
- enhanced `apriltag_map.json`
