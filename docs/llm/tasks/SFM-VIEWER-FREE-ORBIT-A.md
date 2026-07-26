# SFM-VIEWER-FREE-ORBIT-A

## Status

```text
IMPLEMENTED
WEB DEPLOYMENT AND RUNTIME ACCEPTANCE PENDING
```

## Dependency

```text
after SFM-ASSEMBLY-WORKBENCH-B
before SFM-MANUAL-VISUAL-ALIGN-A
```

## Goal

Allow inspection from any camera orientation without stopping at the upper or
lower orbit pole.

This task affects camera controls only.

It must not change:

```text
PLY coordinates
root model transform
saved orientation
floor alignment
merge transform
camera-pose data
```

## Affected pages

```text
web/www/sfm_3d_viewer.php
web/www/sfm_manual_align.php
```

The manual page has independent Anchor and Source viewers. Both need the same
navigation mode.

## Modes

### Horizon locked

Preserve the existing architectural orbit behavior and predictable view
presets.

### Free orbit 360°

Allow:

```text
camera roll
crossing the upper pole
crossing the lower pole
viewing the model upside down
continuous rotation without a stop
```

Use a suitable control such as TrackballControls or ArcballControls.

Do not emulate free orbit by rotating the model.

## UI

```text
Camera navigation
[ Horizon locked ]
[ Free orbit 360° ]
```

The manual page also provides:

```text
Apply mode to both viewers
```

Store the selected mode locally per page.

## Continuity

Changing mode preserves as closely as possible:

```text
camera position
target
distance
current framing
visibility settings
point size
```

Fit, reset and directional presets remain available.

## Acceptance tests

1. Camera crosses both poles continuously.
2. Full camera roll is possible.
3. Model transform does not change.
4. Fit Cloud still works.
5. Top, Front and Side presets still work.
6. Anchor and Source support the same mode.
7. Mode switching does not reload PLY.
8. Existing Auto level behavior remains unchanged.

## Implementation

The normal viewer and both manual-alignment viewers now support:

```text
Horizon locked → OrbitControls
Free orbit 360° → TrackballControls
```

Switching controls preserves camera position and target. Entering Horizon
locked resets camera roll to world-up. Entering Free orbit does not alter the
cloud or root model transform.

The normal viewer stores one local navigation preference.

The manual page stores:

```text
Anchor navigation mode
Source navigation mode
Apply mode to both viewers
```

No PLY reload is performed when switching camera-navigation modes.
