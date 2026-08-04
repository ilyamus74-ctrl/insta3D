# LM02.7B.5.3.0.6.1 — Manhattan seed bootstrap hotfix

## Problem

`fuse_manhattan_room.py` required both source planes of the initial wall/wall
seed to already have multiview support. A valid room corner could therefore be
lost when the second physical wall was split into several individually
single-view plane groups. The process stopped before writing any Manhattan
output files.

## Behaviour

Direct multiview wall/wall seeds remain preferred.

When no direct seed exists, one single-view fragment may initialize the second
Manhattan axis only when all of the following hold:

- the other plane is already confirmed;
- both planes occur in at least one shared keyframe;
- their combined support covers at least three keyframes;
- the diagnostic wall/wall hypothesis is accepted;
- the raw orthogonality error remains within the existing seed limit.

This fallback does not directly confirm the single-view plane. The existing
downstream rules still require the merged wall clusters to have multiview
support, at least two shared corner keyframes, a supported rectangle
intersection, and a confirmed source anchor.

## Diagnostic marker

The selected seed contains:

`seed_selection_mode = CONFIRMED_WALL_FRAGMENT_BOOTSTRAP`

When a direct multiview seed is available, the marker is:

`seed_selection_mode = DIRECT_MULTIVIEW`
