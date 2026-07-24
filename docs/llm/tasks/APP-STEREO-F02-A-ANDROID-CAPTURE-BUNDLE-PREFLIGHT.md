# APP-STEREO-F02-A — Android Capture Bundle Preflight

## Status

```text
IMPLEMENTED
ANDROID BUILD AND DEVICE ACCEPTANCE PENDING
```

## Problem

The Android packager previously allowed a `synced_depth_frames` TGZ to be
created when calibration was absent. The bundle only received a warning, while
GrafikStation requires:

```text
calibration/stereo_extrinsics.json
```

This delayed a deterministic local error until remote processing.

## Goal

Reject an invalid synced-depth capture before archive creation and before the
bundle enters the upload queue.

## Validation gates

The pure Kotlin preflight validates:

- capture directory exists;
- `capture_type` is `synced_depth_frames`;
- at least one stereo pair exists;
- pair indexes are non-negative and unique;
- `cam0_file` and `cam1_file` are non-empty relative paths;
- pair files remain inside the capture directory;
- pair files exist, are regular files, and are non-empty;
- cam0 and cam1 do not reference the same file;
- calibration session directory exists;
- `stereo_extrinsics.json` exists and is non-empty;
- calibration status is `success`;
- K0/K1 contain valid 3×3 camera matrices;
- D0/D1 contain at least four finite coefficients;
- stereo R is a finite near-rigid 3×3 rotation;
- stereo T is finite and has positive baseline magnitude;
- available capture/profile/calibration rig IDs agree;
- capture and calibration resolutions agree when both sides publish dimensions.

## Architecture

```text
StereoCaptureBundlePreflight.kt
    pure Kotlin validation core
    no Android or JSON dependency

CaptureBundlePackager.kt
    parses current JSON schemas
    maps aliases accepted by server processing
    invokes preflight before creating TGZ
```

The pure core allows host-side execution without Android instrumentation.

## Bundle metadata

A successful synced-depth bundle adds:

```text
preflight_schema_version=1
preflight_status=passed
validated_pairs_count
validated_calibration_status=success
validated_baseline_magnitude
```

Legacy stereo-video packaging remains compatible and does not use this strict
preflight.

## Failure behavior

A failed preflight throws `CaptureBundlePreflightException`. No TGZ is created.
If archive writing itself fails, a partial output file is deleted.

## Test

```bash
php web/tests/stereo_capture_bundle_preflight_test.php
```

The test compiles the pure Kotlin core with `kotlinc`, executes behavioral
fixtures, and verifies packager wiring.

Expected:

```text
OK
```

## Android acceptance

After host tests:

```bash
cd app/MaklerTour
./gradlew :app:compileDebugKotlin
```

Device scenarios:

1. no calibration selected — package is rejected;
2. failed calibration selected — package is rejected;
3. missing/empty pair file — package is rejected;
4. rig or resolution mismatch — package is rejected;
5. valid capture — TGZ is created with `preflight_status=passed`.
