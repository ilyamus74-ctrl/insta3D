# APP-DUAL-PHONE-LM02.7B.5.3.4 — Anchored provisional geometry backfill

## Purpose

Keep one accumulated world map while closing short provisional tracking gaps
without treating a locally consistent but globally unanchored chain as a new
trusted map fragment.

## Runtime behaviour

1. Only successful full-stereo SE(3) links advance the provisional chain. A
   yaw-only `TRACKING_TRANSLATION_UNCERTAIN` frame resets the chain and is never
   cached for geometry fusion.
2. A full-stereo SE(3) pose reached through an untrusted reference stays
   provisional regardless of chain length. Local consistency alone does not
   create a globally trusted pose or a separate reconstruction chunk.
3. The runtime keeps a bounded queue of provisional stereo clouds and their
   measured provisional poses. None of these clouds is merged immediately.
4. When the same current frame is also recovered from a trusted reference, the
   runtime computes one rigid world correction from the trusted current pose and
   the provisional current pose. That measured correction is applied to cached
   provisional poses before their clouds are backfilled into the same global
   voxel map, and the closure is emitted as a relocalization keyframe.
5. If no trusted closure is available, or the segment, anchor, chain order or
   queue bound is violated, cached geometry is discarded instead of being
   published under an unrelated pose.
6. Backfilled frames are geometry observations only. They do not become trusted
   trajectory samples and do not rewrite previous trajectory records.
7. No IMU position integration, synthetic interpolation, hard yaw multiplier,
   hard COLMAP pose prior, secondary sparse model or best-chunk selection is
   introduced.

## Diagnostics

Status and keyframe diagnostics expose provisional queue depth, cached,
backfilled and discarded frame totals, anchored closure events, correction
reference metadata and raw/strict voxel growth.
