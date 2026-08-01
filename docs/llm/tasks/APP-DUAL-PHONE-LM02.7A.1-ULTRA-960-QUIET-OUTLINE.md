# APP-DUAL-PHONE-LM02.7A.1 — ULTRA_960 and quiet operator outline

Baseline: `12d54288493e33d510cf301c6d924d1e8c46ff45`

## Goal

Test higher-resolution stereo directly on the MASTER phone while keeping the
existing bounded transport and thermal fallback. Reduce operator fatigue by
removing the neon STRICT border from the default operator surface.

## Acceptance

* CameraX capture preference remains 1280x720 and 16:9.
* transported frames are bounded to 960x540 and 512 KiB;
* the producer remains latest-frame-only and single-JPEG;
* `ULTRA_960` is the first adaptive profile and targets 2.5 depth FPS;
* sustained p95 overload falls back to HIGH_640 and lower profiles;
* WARM skips ULTRA/HIGH, HOT selects THROTTLED, CRITICAL pauses depth;
* STRICT registration pixels and geometry remain unchanged;
* OUTLINE suppresses the saturated STRICT bitmap;
* ASSIST uses low opacity and HEATMAP remains diagnostic;
* no queue becomes unbounded.

## Measurements to record

```text
capture resolution
transport resolution
media M/S FPS
active profile
depth FPS
processing p50/p95
thermal state
pair READY/LATE/DROP
DENSE and stable coverage
```
