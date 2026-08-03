# APP DUAL PHONE LM02.7B.4.3.1 — Live refresh and profile-coupled resolution

## Goal

Remove apparent live-preview freezes during static capture and make the operator
heatmap resolution follow the selected depth profile.

## Runtime rules

- The live contour remains latest-only and never accumulates old frames.
- A fresh synchronized pair replaces the pending source immediately.
- When no fresh synchronized pair arrives before the next live tick, the last
  valid pair is reprocessed as a heartbeat frame.
- Replayed input is explicitly reported and must never be presented as fresh.
- The live sequence remains monotonic across calibration/runtime resets so a
  browser cannot become stuck behind an earlier sequence number.
- The browser image loader is latest-only, has a timeout, and cannot remain
  permanently blocked by one stalled JPEG request.

## Profile-coupled live sizes

The live contour follows the selected geometry profile:

| Selection | Live portrait size | Target cadence |
|---|---:|---:|
| FHD_1920 | 1080×1920 | 1 FPS |
| ULTRA_960 | 540×960 | 2.5 FPS |
| HIGH_640 | 360×640 | 5 FPS |
| QUALITY_480 | 270×480 | 5 FPS |
| BALANCED_320 | 180×320 | 5 FPS |
| AUTO | 360×640 | 5 FPS |

FHD remains an upscaled load-test while the phones send 960×540.

## Diagnostics

`live_preview_status.json` and `live_preview.jsonl` expose:

- requested and active live profile;
- target and actual FPS;
- profile and output dimensions;
- publish age and source-pair age;
- source frame sequences;
- fresh/replayed input counters;
- stale profile-result counter;
- monotonic preview sequence.

The operator JPEG includes a small heartbeat marker. Green means fresh input;
orange means the last valid synchronized pair was replayed.

## Scope boundary

This patch changes only operator preview scheduling and presentation. Full
geometry filtering remains independent and is not weakened. Point-cloud,
plane extraction, room skeleton and texture projection belong to LM02.7B.5.
