# APP-STEREO-F02-B — Capture Bundle Operator Feedback

## Status

```text
IMPLEMENTED
ANDROID BUILD AND DEVICE ACCEPTANCE PENDING
```

## Parent

```text
APP-STEREO-F02-A — Android Capture Bundle Preflight
```

## Problem

F02-A blocks invalid `synced_depth_frames` bundles, but the ViewModel previously
logged packaging exceptions only to Logcat. An operator working on the Camera
or Draft tab received no immediate explanation.

The existing `uploadError` field is tied to upload processing and is rendered
only on the Queue tab. It is not suitable for a capture-packaging event.

## Goal

Show an immediate, localized, dismissible result after the operator requests a
synced-depth bundle:

```text
valid bundle
→ success dialog
→ bundle is in upload queue

invalid bundle
→ error dialog
→ no TGZ and no queue item
```

## State contract

New one-shot state:

```text
CaptureBundleNotice
CaptureBundleNoticeCode
```

Codes:

```text
QUEUED
CALIBRATION_NOT_SELECTED
CALIBRATION_INVALID
NO_STEREO_PAIRS
PAIR_FILES_INVALID
RIG_MISMATCH
RESOLUTION_MISMATCH
INVALID_CAPTURE
PACKAGING_FAILED
```

The notice flow participates directly in `uiState` combination, so setting or
dismissing it always triggers Compose recomposition.

## Operator UI

A global Material 3 `AlertDialog` is rendered above the current tab. The
operator does not need to open the Queue tab.

The normal message is localized for:

```text
English
Russian
Ukrainian
German
```

Raw exception detail is displayed only when extended debug mode is enabled.
This keeps normal operator messages short while preserving diagnostics.

## Success behavior

After `enqueueCaptureBundle()` returns successfully:

```text
code=QUEUED
```

The dialog confirms that validation passed and the package was added to the
upload queue.

## Error behavior

`CaptureBundlePreflightException` is mapped to a stable operator category.
Unexpected packaging exceptions use:

```text
code=PACKAGING_FAILED
```

No upload queue item is created on either failure path.

## Test

```bash
php web/tests/capture_bundle_operator_feedback_test.php
```

The test verifies:

- ViewModel notice flow wiring;
- specific preflight and generic error branches;
- dismiss action;
- global Compose dialog wiring;
- all four locale resource sets;
- mapper behavior when `kotlinc` is available.

## Android acceptance

```bash
cd app/MaklerTour
./MakeInstall.sh
```

Device scenarios:

1. valid capture — success dialog appears;
2. no calibration selected — calibration-selection error;
3. failed/missing calibration — calibration error;
4. missing pair image — pair-files error;
5. rig mismatch — rig error;
6. resolution mismatch — resolution error;
7. dialog closes and does not reappear after dismissal;
8. technical detail appears only in debug mode.
