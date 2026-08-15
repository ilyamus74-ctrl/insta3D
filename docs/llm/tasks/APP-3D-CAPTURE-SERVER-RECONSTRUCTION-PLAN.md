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

ToF is optional. A missing/disconnected ToF sensor must never block SINGLE
capture, upload, frame extraction, sparse reconstruction, dense reconstruction,
mesh generation or texturing.

### Camera optical state and calibration profiles

Camera intrinsics are not represented by one global `camera_intrinsics.json`.
Profiles are keyed by the optical state that can change image geometry:

```text
camera id
video resolution
zoom ratio
focus mode
```

Initial focus modes:

```text
AUTO
INFINITY_FIXED
```

The capture manifest records the requested/effective focus state. The production
UI will expose the same two focus choices already used by calibration workflows:

```text
[ AUTO FOCUS ]
[ infinity / INFINITY_FIXED ]
```

A reproducible fixed-focus profile is authoritative when available:

```text
camera_calibration/
  camera0_1920x1080_1x_infinity.json
  camera0_1920x1080_1x_auto_reference.json
```

`AUTO` is not assumed to be one fixed optical calibration. Its actual lens
position may change while recording. Runtime focus-distance telemetry may later
be used to choose/interpolate a profile. Until then AUTO may use Camera2 factory
metadata as diagnostic/prior information while COLMAP remains allowed to refine
intrinsics.

Capture metadata contract:

```text
focus_mode
focus_locked
focus_distance_diopters
intrinsics_source
calibration_profile_key
calibration_profile_id
```

Camera2 `LENS_INTRINSIC_CALIBRATION` and `LENS_DISTORTION` are preserved when the
device exposes them, but their native coordinate system is sensor/pre-correction
space. They must not be injected blindly as video-frame COLMAP parameters until
crop/zoom/resolution mapping or a verified calibration profile makes them valid
for the actual recorded frames.

Camera/ToF separation remains:

```text
ToF <-> CAMERA_A extrinsics: R/t      rigid mounting
CAMERA_A intrinsics:         K/D      optical/focus profile
```

Changing focus does not by itself redefine the rigid ToF mounting transform.
Projection into RGB uses the K/D profile matching the capture optical state.

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

The production implementation order is intentionally serial:

```text
1. SINGLE capture + server reconstruction
2. STEREO capture + server reconstruction
3. LIVE operator preview / fused 3D
```

Current milestones:

```text
LM03.5A  registered anchor runtime                    CLOSED
LM03.5B  laptop/web registered overlay                CLOSED
LM03.5C  bounded diagnostics                          CLOSED

SFM-S01A SINGLE capture without ToF baseline          PASS
SFM-S01D camera optical-state/intrinsics contract     CURRENT
SFM-S01E explicit server metadata paths               CURRENT
SFM-S01F optional ToF capture sidecar                 AFTER RGB baseline
SFM-S01G optional ToF <-> selected-frame association
SFM-S01H optional ToF-assisted dense/fusion
          -> SINGLE CLOSED

SFM-S02   STEREO capture + server reconstruction      AFTER SINGLE
          ToF remains optional
          -> STEREO CLOSED

LM03.6    live STEREO / TOF / FUSED                   AFTER STEREO
LM03.7    VIO + metric trajectory
LM03.8    coarse accumulated live 3D preview
```

`LM03.5D` is no longer a blocker before SINGLE RGB reconstruction. Its capture
sidecar work is folded into the optional ToF extensions of SFM-S01/SFM-S02.

The existing web-driven processing path remains authoritative:

```text
Android capture/upload
  -> IlyamusWWW web + MySQL + sfm worker
  -> GrafikStation
  -> EXTRACT_FRAMES
  -> COLMAP sparse
  -> COLMAP dense/fusion
  -> mesh
  -> web artifacts
```

Operators do not manually launch GrafikStation jobs in the normal workflow.
