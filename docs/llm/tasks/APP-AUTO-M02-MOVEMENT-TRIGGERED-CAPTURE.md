# APP-AUTO-M02 — Movement-triggered Capture

## Status

```text
IMPLEMENTED
RUNTIME ACCEPTANCE REQUIRED
```

## Goal

Increase useful Auto Photo coverage by creating photographs after measured
camera movement instead of relying on the fixed timer alone.

## Runtime evidence that triggered M02

The M01 device run produced 45 photographs. Sequential sparse registration
split into three components; exhaustive retry still produced two components:

```text
recommended component: 31 / 45
disconnected component:  9 / 45
```

The gap between frames `001-009` and `010-044` indicates insufficient overlap
or a movement jump. Dense generation was intentionally not used as acceptance
for that run.

## Capture policy

Base safety gates remain mandatory:

```text
camera running
no capture in flight
gyro below threshold
stable dwell complete
minimum interval complete
sharpness acceptable
photo limit not reached
storage reserve available
```

After those gates, visual movement decides:

```text
first photo                     → accept reference
useful displacement/rotation    → accept
too little displacement         → reject: move_camera
low tracked ratio / large jump  → reject: overlap_too_low
tracking unavailable            → controlled fallback after 2500 ms
```

Initial device-test defaults:

```text
minimum interval:             600 ms
stable dwell:                 150 ms
minimum median displacement:  5 px at 320×180
maximum median displacement: 55 px
maximum p90 displacement:    85 px
minimum tracked ratio:        0.35
minimum rotation:             2°
maximum rotation:            18°
maximum fallback interval:  2500 ms
```

These are conservative runtime-test defaults, not final production thresholds.

## Orientation correction

Photo metadata now records:

```text
physical_orientation
orientation_source
orientation_confidence
orientation_sample_delta_ms
roll_deg
pitch_deg
image_up_direction
image_up_vector
```

Orientation is sampled near capture start rather than after the JPEG callback.
`image_up_direction` is expressed in saved-image coordinates and accounts for
display rotation.

## Non-goals

M02 does not change:

- Auto Photo bundle paths;
- backend Prepare/Sparse/Dense contracts;
- stereo capture;
- synced-depth processing;
- global stereo fusion;
- mesh generation.

## Required checks

```text
cd app/MaklerTour
./gradlew :app:testDebugUnitTest
./gradlew :app:assembleDebug
python3 tools/stereo_contract_audit.py
```

## Runtime acceptance

Repeat the same room route and record:

```text
saved photos
rejection reasons
accepted_movement count
accepted_fallback count
overlap_too_low count
registered photos
registration rate
sparse component count
sparse points
dense points
```

Expected direction:

- more useful photos than the M01 run;
- one dominant component with fewer disconnected frames;
- no regression in bundle upload or server processing;
- phone UI correctly shows physical orientation and image-up direction.
