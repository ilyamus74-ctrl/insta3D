# APP-AUTO-M01 — Visual Movement Metrics

## Status

```text
AUTHORIZED NEXT TASK
```

## Goal

Instrument Android Auto Photo with bounded visual movement metrics relative to
the last successfully saved photo, without changing the current capture
decision.

## Baseline

Current `AutoPhotoCaptureRules.shouldCapture()` uses:

```text
running
capture_in_flight
angular velocity
stability dwell
minimum interval
sharpness
maximum photos
storage reserve
```

M01 must preserve this rule for identical inputs. The accepted backend path is
already `auto_photo_session → Prepare → Sparse → Dense Preview → web viewer`.

## Canonical contract

```text
app/MaklerTour/docs/AUTO_PHOTO_MOVEMENT_CONTRACT.md
docs/llm/03_MODULES.md — A07 Phone Camera
```

## Allowed production scope

Preferred files:

```text
app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/AutoPhotoCaptureManager.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/AutoPhotoMovementTracker.kt
```

Allowed tests:

```text
app/MaklerTour/app/src/test/java/com/example/maklertour/data/phonecamera/AutoPhotoCaptureManagerTest.kt
app/MaklerTour/app/src/test/java/com/example/maklertour/data/phonecamera/AutoPhotoMovementTrackerTest.kt
```

## Forbidden scope

Do not modify:

```text
StereoCaptureExperimental.kt
cam1_uvc.cpp
calibration processors
rig profiles
CaptureBundlePackager bundle structure
backend PHP
GrafikStation scripts
AUTO-B06 production code
MAKLERTOUR_SYNCED_DENSE
```

Do not change `MainActivity.kt` unless a minimal compile-only wiring change is
unavoidable. M01 adds no operator controls.

## Required implementation

### 1. Pure result model

At minimum:

```text
status
method
reference_sequence
analysis_timestamp_ns
analysis_width
analysis_height
detected_features
tracked_features
tracked_ratio
median_displacement_px
p90_displacement_px
estimated_rotation_deg
```

Statuses: `disabled`, `no_reference`, `insufficient_features`,
`tracking_failed`, `ok`.

### 2. Bounded tracker

```text
downscale preview luminance
→ grayscale
→ bounded feature detector
→ calcOpticalFlowPyrLK
→ invalid-track filtering
→ robust displacement statistics
```

No full-resolution history, unbounded collections, per-frame filesystem writes,
neural model, network dependency, or accelerometer translation integration.

### 3. Reference lifecycle

- before first accepted photo: `no_reference`;
- only `onImageSaved` commits the reserved analysis snapshot;
- `onError` does not commit it;
- start/reset/cancel/release clear state;
- duplicate callbacks cannot advance the reference twice.

### 4. Diagnostics only

M01 must not add movement inputs to `shouldCapture()` and must not introduce a
movement rejection reason. Record metrics through sampled quality diagnostics
and optionally accepted photo metadata.

### 5. Additive manifest declaration

```json
{
  "visual_movement_metrics_enabled": true,
  "visual_movement_method": "pyr_lk",
  "visual_movement_analysis_width": 320,
  "visual_movement_analysis_height": 180
}
```

These fields are descriptive only in M01.

## Required tests

1. no reference;
2. insufficient features;
3. identical synthetic frames produce low displacement;
4. translated frames produce higher displacement;
5. invalid tracks are filtered;
6. bounded feature count;
7. successful save establishes/advances reference once;
8. failed save does not advance reference;
9. reset clears reference;
10. existing capture rules remain unchanged;
11. serialization contains only finite values or explicit null/status.

## Static checks

```text
cd app/MaklerTour
./gradlew :app:testDebugUnitTest
./gradlew :app:assembleDebug
python3 tools/stereo_contract_audit.py
```

## Runtime acceptance

1. install the debug APK;
2. capture standing still, moving slowly, rotating, and walking;
3. verify movement metrics in diagnostics;
4. confirm capture behavior is not intentionally changed in M01;
5. package and upload the bundle;
6. run existing Prepare → Sparse → Dense Preview;
7. record runtime IDs and reconstruction statistics.

## Deliverable

One isolated patch with the tracker, Auto Photo diagnostic wiring, tests, and
only necessary docs. M02 gating is forbidden in this patch.
