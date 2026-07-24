# APP-AUTO-M02.1 — Guided Keyframe Capture

## Status

```text
IMPLEMENTED
RUNTIME ACCEPTANCE REQUIRED
```

## Reason for iteration

The first M02 room run produced more photos but worse sparse connectivity:

```text
M01 exhaustive: 45 input, 31 registered, 2 components
M02 sequential: 57 input, 26 registered in the recommended component, 3 components
```

The text-only guidance did not make it clear whether the operator had restored
overlap or whether a new reference photo had already been accepted.

## Contract changes

### Reference-safe capture

The movement reference advances only after:

```text
accepted_first_reference
accepted_movement
```

`RECOVER`, tracking failure, insufficient features, and default fallback do not
create a new keyframe. Fallback capture is disabled by default. If explicitly
enabled for diagnostics, it records a JPEG but sets `commit_reference=false`.

### Guided states

```text
MOVE      — continue moving; progress to the next keyframe is shown
HOLD      — sufficient movement; stabilize the phone
CAPTURED  — a keyframe was saved and confirmed
RECOVER   — overlap was lost; no photo is created
COMPLETE  — photo limit reached
ERROR     — camera or storage failure
```

The CAPTURED state remains visible for 800 ms.

### Ghost reference

The UI shows the downscaled grayscale frame belonging to the last successfully
committed keyframe. It is bounded to the existing 320×180 movement-analysis
frame and is not written as another bundle artifact.

During RECOVER, the operator must visually align the current view with this
ghost. New photos remain blocked until tracking becomes valid again.

### Directional recovery

Movement diagnostics now include:

```text
median_flow_dx_px
median_flow_dy_px
```

The UI uses these values to suggest moving the image left, right, up, or down
until the current view aligns with the ghost.

## Runtime-test defaults

```text
minimum interval:              600 ms
stable dwell:                  250 ms
minimum median displacement:     6 px
maximum median displacement:    30 px
maximum p90 displacement:       55 px
minimum tracked ratio:           0.55
minimum rotation:                2°
maximum rotation:               12°
fallback enabled:             false
capture confirmation:          800 ms
```

## Unchanged contracts

M02.1 does not modify:

- Auto Photo bundle paths;
- backend Prepare/Sparse/Dense;
- stereo capture or calibration;
- synced-depth processing;
- global stereo fusion.

## Required checks

```text
./gradlew :app:testDebugUnitTest
./gradlew :app:assembleDebug
python3 tools/stereo_contract_audit.py
```

## Runtime acceptance

Repeat the same room route and compare:

```text
input photos
accepted_first_reference
accepted_movement
accepted_fallback
RECOVER count
registered photos
registration rate
component count
frame ranges
```

Target direction:

```text
fallback keyframes: 0
one dominant component
at least 80% of input photos in the dominant component
no permanent split after a RECOVER event
```
