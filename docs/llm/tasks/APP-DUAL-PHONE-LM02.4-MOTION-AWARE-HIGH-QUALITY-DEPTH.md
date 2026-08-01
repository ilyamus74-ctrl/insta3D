# APP-DUAL-PHONE-LM02.4 — motion-aware high-quality depth

## Baseline

```text
938b73fa417c1f9389034cd8c0ee67c25249fab6
```

## Goal

Keep the proven 10 FPS dual-phone media stream while increasing nominal live depth
to 5 FPS, retaining useful spatial depth during rig movement and reserving compute
headroom for the later full-resolution texture-video recorder.

## Processing profiles

```text
QUALITY_480
    480x270
    200 ms minimum start interval
    left-right consistency enabled

BALANCED_320
    320x240
    200 ms minimum start interval
    left-right consistency enabled

THROTTLED_320
    320x240
    333 ms minimum start interval
    left-right consistency disabled

THERMAL_PAUSED
    no depth work
    LIVE media/control remain active
```

The controller keeps thirty processing samples and reports p50/p95. After at least
eight samples, p95 above the reserved quality budget permanently downgrades the
current stream. Android MODERATE/SEVERE/CRITICAL thermal states impose balanced,
throttled or paused floors. Reset/new stream clears the performance floor.

## Stereo quality

MASTER and SLAVE grayscale rectified inputs receive independent CLAHE contrast
normalization. StereoSGBM computes left disparity. Quality/balanced profiles also
compute reverse disparity and require left-right agreement within 1.5 px before a
pixel reaches the spatial/temporal filtered product.

## Motion-aware temporal state

A bounded 80x60 grayscale frame-difference score selects:

```text
STATIC   motion < 2.5%   strict 3-of-5 consensus
MOVING   2.5–8.0%       keep at most one old map, then 2-frame consensus
RESET    >= 8.0%        release old maps and publish current spatial disparity
```

Changing processing resolution also releases motion, disparity and median-depth
history. No history exceeds five disparity maps.

## UI diagnostics

MASTER overlay reports:

```text
quality profile and work resolution
thermal state and target depth FPS
motion score and temporal mode
left-right accepted percentage
processing p50/p95 and utilization
existing media/pair/depth/confidence counters
```

## Texture recording boundary

This patch does not start final video recording. The later texture recorder must be
full-resolution and independently owned. LM02.4 therefore never changes selected
recording mode, codec, FPS, zoom, stabilization or file lifecycle and can reduce
only the diagnostic depth workload.

## Acceptance

1. Media remains approximately 10 FPS on both devices.
2. QUALITY_480 targets approximately 5 depth updates per second while cool.
3. Slow/fast rig motion changes temporal mode instead of blanking the entire map.
4. LR acceptance, motion, resolution, thermal state and p50/p95 are visible.
5. Heating or excessive p95 downgrades depth without disconnecting LIVE.
6. STOP/Settings/emergency release all bounded state.
7. No completed-room, wall, trajectory or measurement claim is added.

## Next stage

```text
APP-DUAL-PHONE-LM03.1
rig trajectory and confidence-weighted point-cloud accumulation
```
