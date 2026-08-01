# APP-DUAL-PHONE-LM02.6 — adaptive metric outline and freshness

Baseline:

```text
537e5530fb1e288855162ff5d2d59beec543d7d7
```

## Goal

Present stereo depth in an object- and contour-oriented form that remains
immediately understandable to a human operator.

## Default view

```text
rectified MASTER image
+ translucent DENSE metric depth
+ green STRICT boundaries
```

RAW, DENSE, STRICT, and CONF remain available for diagnostics.

## Startup and freshness

The live MASTER camera remains visible during WAIT CLOCK and WAIT FRAMES. Depth
is labelled LIVE, HOLD, STALE, or EXPIRED. EXPIRED depth is hidden instead of
being mistaken for a cached result.

## Adaptive DENSE profiles

```text
TEXTURED      LR 3.0 px, texture 5
LOW_TEXTURE   LR 4.5 px, texture 2
MOVING        LR 3.5 px, texture 4
STATIC_REFINE LR 2.5 px, texture 5
```

MOVING activates immediately. Other transitions require three consecutive
frames. STRICT remains fixed at the measurement contract.

VL53L8CX and a safe texture projector remain optional later inputs.
