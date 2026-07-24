# Auto Photo visual movement contract

## Status

```text
CURRENT ROADMAP
APP-AUTO-M01 — Visual Movement Metrics
APP-AUTO-M02 — Movement-triggered Capture
APP-AUTO-M03 — Dense Acceptance
APP-STEREO-F01 — Global Stereo Depth Fusion
```

This document is the canonical Android-side roadmap after the accepted
server-side `AUTO-B06` Dense Preview milestone.

## 1. Current baseline

The existing Auto Photo implementation already produces a compatible
`auto_photo_session` bundle:

```text
capture/manifest.json
capture/camera_info.json
capture/photos_metadata.jsonl
capture/imu.jsonl
capture/quality.jsonl
capture/events.jsonl
capture/photos/frame_000001.jpg
...
```

The bundle is already accepted by the existing server flow:

```text
Auto Photo bundle
→ AUTO_PHOTO_PREPARE
→ COLMAP_SPARSE
→ standalone Dense Preview
→ authenticated PLY download
→ browser 3D viewer
```

The accepted baseline used:

```text
input photos:       87
registered photos:  70
registration rate:  80.5%
sparse points:       24703
dense points:        157417
```

## 2. Problem

The current capture decision is based on time, angular velocity, a short
stability dwell, sharpness, maximum photo count, and free storage.

It does not prove that the camera moved relative to the last accepted photo.
This may create repeated viewpoints, uneven overlap, and insufficient sampling
while the operator moves through the room.

## 3. Architectural boundary

Auto Photo and synced stereo are separate capture modes.

During `APP-AUTO-M01`, `APP-AUTO-M02`, and `APP-AUTO-M03`:

- do not modify `StereoCaptureExperimental.kt`;
- do not modify raw stereo pair coordinate systems;
- do not modify calibration, rig, or synced-depth bundle contracts;
- do not modify `MAKLERTOUR_SYNCED_DENSE`;
- do not add global stereo fusion.

The existing stereo flow remains:

```text
synced stereo pair capture
→ synced_depth_frames bundle
→ MAKLERTOUR_SYNCED_DENSE
→ per-pair depth diagnostics
```

Global pose estimation and fusion of stereo depth pairs into one room model
starts only in `APP-STEREO-F01`, after Auto Photo movement capture is accepted.

## 4. Movement measurement principles

Camera displacement must be estimated visually from `ImageAnalysis` frames.
Do not estimate translation in meters by integrating phone accelerometer
samples.

The initial visual method is:

```text
downscaled grayscale frame
→ feature detection
→ pyramidal Lucas–Kanade tracking
→ robust displacement statistics
```

The implementation must not retain an unbounded history of bitmaps or feature
arrays.

## 5. Reference-frame contract

The movement reference represents the last successfully saved Auto Photo.

1. The first successfully saved photo establishes the reference.
2. Analyzer frames must not silently replace the reference.
3. A failed `ImageCapture` must not advance the reference sequence.
4. A successful photo save atomically commits its analysis snapshot.
5. Start, cancel, terminal error, and release clear movement state.
6. The reference is bounded and downscaled, not a full-resolution JPEG.

## 6. Additive metadata contract

Existing bundle paths and required fields remain unchanged. Movement fields are
additive and may be recorded as:

```json
{
  "visual_movement": {
    "status": "ok",
    "method": "pyr_lk",
    "reference_sequence": 24,
    "analysis_timestamp_ns": 123456789,
    "analysis_width": 320,
    "analysis_height": 180,
    "detected_features": 120,
    "tracked_features": 83,
    "tracked_ratio": 0.6917,
    "median_displacement_px": 17.4,
    "p90_displacement_px": 28.1,
    "estimated_rotation_deg": 3.2
  }
}
```

Allowed status values:

```text
disabled
no_reference
insufficient_features
tracking_failed
ok
```

`APP-AUTO-M01` records metrics only. It must not use these values to accept or
reject a photo.

## 7. Staged implementation

### APP-AUTO-M01 — Visual Movement Metrics

- add a bounded visual movement tracker;
- log real movement metrics;
- preserve the current capture decision exactly;
- collect evidence for threshold selection.

### APP-AUTO-M02 — Movement-triggered Capture

After M01 runtime evidence:

- add minimum movement gating;
- add overlap/tracking-quality gating;
- reduce the fixed interval where justified;
- add a controlled maximum-interval fallback;
- add operator guidance.

Thresholds must be selected from M01 device evidence, not guessed in advance.

### APP-AUTO-M03 — Dense Acceptance

Capture the same route with old and new behavior and compare:

```text
saved photos
registered photos
registration rate
sparse points
dense points
connected components
visible holes
duplicate/rejected-frame statistics
```

Acceptance requires the existing bundle → sparse → Dense Preview → web viewer
flow to complete.

### APP-STEREO-F01 — Global Stereo Depth Fusion

Only after APP-AUTO-M03:

```text
synced depth pairs
→ pose estimation
→ transform pair clouds into one coordinate system
→ confidence filtering
→ voxel/TSDF/surfel fusion
→ global PLY
```

## 8. Required checks

```text
./gradlew :app:testDebugUnitTest
./gradlew :app:assembleDebug
python3 tools/stereo_contract_audit.py
```

Runtime checks include Auto Photo lifecycle, bounded memory, bundle upload,
Prepare/Sparse/Dense completion, and browser-viewer acceptance.

## 9. Non-goals

These stages do not implement real-time SLAM, live dense reconstruction,
accelerometer translation integration, stereo-contract changes, automatic
server Dense launch, or mesh generation.
