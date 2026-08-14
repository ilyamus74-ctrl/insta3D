# 3D capture and server reconstruction plan

## Decision

The authoritative production path is:

```text
capture now
    ->
persist synchronized evidence
    ->
upload/package
    ->
server-side SfM / COLMAP / Dense / fusion
    ->
final 3D model
```

The project is **not** targeting full authoritative COLMAP + dense reconstruction
in real time on the phones or laptop.

Live processing remains a separate operator-assistance path:

```text
live CAMERA_A / CAMERA_B
live stereo depth
live ToF registered anchors
future STEREO / TOF / FUSED preview
future coarse accumulated live 3D preview
```

Live output helps the operator understand coverage and capture quality. The final
model is produced asynchronously from persisted source data.

## Why the two paths are separate

The capture path must prioritize:

```text
stable camera event timestamps
MASTER/SLAVE synchronization
original image quality
known camera calibration
ToF/IMU sidecars
repeatable archival contracts
```

The final reconstruction path may then spend substantially more compute per
frame on feature extraction, matching, bundle adjustment, dense stereo, depth
fusion, meshing and texturing without imposing those costs on capture.

Therefore a fast live preview is never treated as the authoritative final model.

## Capture modes

### Single-camera capture

Input:

```text
CAMERA_A video / selected source frames
CAMERA_A intrinsics
IMU_A
optional ToF slot0..2 registered metadata
```

Use cases:

- conventional single-camera SfM;
- scenes where only one phone is available;
- fallback capture mode;
- ToF-assisted metric/depth validation when ToF is attached to CAMERA_A.

The server extracts/selects keyframes and runs the reconstruction pipeline after
capture.

### Stereo-camera capture

Preferred input when both phones are available:

```text
CAMERA_A frames
CAMERA_B frames
strict synchronized pair identity
known stereo calibration / rigid baseline
IMU_A / IMU_B
ToF slot0..2 registered to CAMERA_A
```

The stereo mode provides more geometry per capture instant and keeps known rig
information available to the server pipeline.

The current MASTER + ToF + SLAVE -> laptop topology is the primary development
path.

## Capture-time storage contract

The long-term capture package should retain original sources plus machine-readable
sidecars. It must not rely on screenshots or rendered web overlays.

Conceptual layout:

```text
capture/
  manifest.json

  CAMERA_A/
    frame_000001.jpg
    frame_000002.jpg
    ...

  CAMERA_B/
    frame_000001.jpg
    frame_000002.jpg
    ...

  IMU/
    imu_a.jsonl
    imu_b.jsonl

  TOF/
    pair_000001.json
    pair_000002.json
    ...

  calibration/
    stereo_profile.json
    tof_slot_0_profile.json
    tof_slot_1_profile.json
    tof_slot_2_profile.json
```

Exact packaging may reuse the existing laptop-host `colmap_frames` and
`colmap_pairs.jsonl` structure rather than creating a second competing archive.

## LM03.5D boundary

LM03.5D adds ToF evidence to the already selected SfM/COLMAP capture frames.

For every archived CAMERA_A/B pair, persist a ToF sidecar derived from the
same CAMERA_A frame header:

```text
pair index
CAMERA_A frame sequence/timestamp
CAMERA_B pair reference

for each ToF slot:
  tof sequence
  pair delta
  pair threshold
  zone index
  distance
  sigma
  target status
  detected target count

  registered CAMERA_A u/v
  registered CAMERA_A X/Y/Z
  inside-image flag
```

No new ToF pairing is performed on the server. The sidecar preserves the pairing
and registration decision made on CAMERA_A using the accepted LM03.3/LM03.4
contracts.

## Server reconstruction pipeline

Primary final-model pipeline:

```text
capture package
    |
    +--> validate manifest/calibration/timestamps
    |
    +--> choose/extract keyframes
    |
    +--> COLMAP feature extraction
    |
    +--> matching / rig-aware constraints
    |
    +--> sparse SfM / bundle adjustment
    |
    +--> dense MVS / depth maps
    |
    +--> ToF/stereo consistency + depth fusion
    |
    +--> dense point cloud
    |
    +--> mesh
    |
    +--> texture
    |
    v
final 3D model / tour
```

ToF must initially be used as an additional metric constraint and validation
source around the stock reconstruction pipeline, not as a reason to fork the
COLMAP solver prematurely.

## Relationship to live 3D

Future LM03.6-LM03.8 may provide:

```text
STEREO / TOF / FUSED cursor
metric live trajectory
coarse accumulated live 3D preview
```

That live model is an operator preview and coverage diagnostic.

It may answer questions such as:

```text
Did we scan this wall?
Are depth measurements stable?
Did we miss a room corner?
Is the operator moving too fast?
```

It is not required to equal the final server-generated COLMAP/Dense model.

A future online-SfM/SLAM implementation may be evaluated separately, but it is
not on the critical path for the first production-quality reconstruction.

## Current order

```text
LM03.5A  registered anchor runtime              CLOSED
LM03.5B  laptop/web registered overlay          CLOSED
LM03.5C  bounded diagnostics                    CLOSED

LM03.5D  SfM/COLMAP ToF sidecars                NEXT

LM03.6   live STEREO / TOF / FUSED
LM03.7   VIO + metric trajectory
LM03.8   coarse accumulated live 3D preview

SERVER PIPELINE
  capture validation
  keyframes
  COLMAP sparse
  COLMAP dense
  ToF/stereo fusion
  mesh + texture
```

The server pipeline and live LM03.6-LM03.8 path can evolve in parallel because
both consume the same synchronized/calibrated capture contracts.
