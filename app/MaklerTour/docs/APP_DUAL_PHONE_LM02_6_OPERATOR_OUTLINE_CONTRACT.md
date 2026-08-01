# APP dual-phone LM02.6 operator outline contract

Baseline:

```text
537e5530fb1e288855162ff5d2d59beec543d7d7
```

## Operator view

`OUTLINE` is the default MASTER scan view. It keeps the room recognizable by
rendering the rectified MASTER image as the base layer, translucent DENSE metric
depth as context, and green boundaries derived from STRICT depth.

Before clock synchronization or the first stereo pair is ready, the live MASTER
camera remains visible. A black WAIT CLOCK screen is invalid.

## Metric palette

The same approximate distance keeps the same color across frames:

```text
0.5 m red
1.0 m orange
2.0 m yellow
3.0 m green
4.0 m cyan
6.0 m blue
```

## Freshness

```text
LIVE     <= 350 ms
HOLD     <= 900 ms
STALE    <= 2000 ms
EXPIRED  depth hidden; live camera shown
```

Depth published before the current stream start is treated as WAITING.

## Adaptive DENSE

DENSE selects TEXTURED, LOW_TEXTURE, MOVING, or STATIC_REFINE parameters with
hysteresis. STRICT correspondence and temporal consensus remain unchanged and
remain the only future measurement-grade input.
