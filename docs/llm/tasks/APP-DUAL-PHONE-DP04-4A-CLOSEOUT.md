# APP dual-phone DP04.4A — capture pipeline closeout and post-capture architecture

## Status

```text
CLOSED
REPOSITORY BASELINE: 47b8d36ce47bef9613fc51fbc1517acaa1853a5e
ACCEPTED REAL CAPTURE: 6f3920b5-9025-4309-901b-845df393ddb5
ACCEPTED AGGREGATE SHA-256: 7d937a58f82d34b45c36918e156a500d47e9b03b31186405181b6cf8e72be7e9
```

This document closes the DP04.4A capture-pipeline milestone and supersedes the
older roadmap status text that still describes DP04.4 as immediate work.

DP04.4A proves reliable dual-phone capture, local timeline truth, role packaging,
Slave-to-Master transfer, aggregate creation and a hardened two-device diagnostic
workflow. It does not claim calibrated stereo depth, a cross-phone common timeline,
live stereo SLAM or a final room reconstruction.

## Accepted runtime evidence

The accepted aggregate contains:

```text
bundle_manifest.json
roles/master.tgz
roles/slave.tgz
```

The aggregate manifest reports:

```text
bundle_type: maklertour_capture_bundle
capture_type: dual_phone_stereo_video
dual_capture_id: 6f3920b5-9025-4309-901b-845df393ddb5
aggregate_complete: true
```

The aggregate file passed gzip and tar validation. The role-package hashes in
`bundle_manifest.json` match the embedded archives, and the per-file hashes in both
`role_package_manifest.json` files match their role artifacts.

### Master role

```text
video: parseable H.264 MP4
actual mode: 1920x1080@60
capture-result FPS: 59.97364097281652
encoder FPS: 59.9754952557819
encoded samples: 1458
mapped samples: 1458
unmatched encoded samples: 0
unmatched capture results: 13
capture-result gaps: 0
encoder gaps: 0
mapping residual P50: 0.186 ms
mapping residual P95: 0.427 ms
mapping residual MAX: 0.505 ms
mapping quality: GOOD
```

### Slave role

```text
video: parseable H.264 MP4
requested mode: 1920x1080@60
actual mode: 1920x1080@30
capture-result FPS: 29.986931881031413
encoder FPS: 30.0
encoded samples: 676
mapped samples: 676
unmatched encoded samples: 0
unmatched capture results: 12
capture-result gaps: 0
encoder gaps: 0
mapping residual P50: 0.006461 ms
mapping residual P95: 0.020418 ms
mapping residual MAX: 0.085211 ms
mapping quality: GOOD
```

This acceptance establishes an important product rule: requested FPS is not stream
truth. Every later stage must use actual timestamps and actual finalized cadence.
The current rig provides approximately 60 FPS on Master and 30 FPS on Slave. The
maximum direct stereo-pair rate is therefore bounded by the slower role, while
additional Master frames remain useful for visual odometry, relocalization and
texture selection.

## Closed implementation scope

The following capabilities are accepted:

```text
DP04.4 asynchronous pre-roll/post-roll timeline
DP04.4A1 recorder health and regular-FPS fallback
DP04.4A2 visible PreviewView-backed CameraX recording
DP04.4A2.1 Finalize diagnostics and stale ARM recovery
DP04.4A3 clock-independent ARM/START/STOP and visible REC state
DP04.4A4 actual stream truth and Camera2-to-MP4 PTS mapping
DP04.4A4.1 package barrier and hardened parallel ADB collector
```

The accepted state machine is:

```text
CONNECT
→ ARM starts independent physical recording on both phones
→ START writes a durable logical capture-window marker
→ STOP writes a durable stop marker
→ bounded post-roll
→ independent Finalize on each phone
→ per-role package
→ Slave role package transferred to Master
→ Master aggregate bundle
→ server upload queue
```

Clock quality may downgrade the START marker to `DEGRADED_ASYNC_MARKER`, but it
does not block physical recording or STOP. The server remains responsible for
building the final common timeline and rejecting pairs outside measured timing
quality.

## Canonical upload topology

The canonical version-one post-STOP path is:

```text
Slave
  local role package
    → authenticated LAN transfer with size and SHA-256
Master
  local role package
  + verified Slave role package
    → one immutable aggregate bundle
      → one server upload
```

This is preferred over two independent server uploads because it gives the backend
one atomic capture object, removes server-side join races, centralizes retry state
on the coordinator phone and works when the two phones have local connectivity but
only Master has a usable backend route.

The cost is temporary duplication of Slave data on Master. Before ARM or before
aggregate construction, Master must eventually enforce a storage budget covering:

```text
Master role package
+ incoming Slave role package
+ aggregate bundle
+ retry margin
```

Files must not be deleted merely because an upload was attempted. Cleanup is
permitted only after a durable server receipt identifies the same
`dual_capture_id`, aggregate SHA-256 and stored object version.

Independent direct uploads from Master and Slave are a deferred fallback, not the
primary workflow. If implemented later, the server must join them by
`dual_capture_id`, role, device identity, calibration identity and role-package
hash, and must never start processing from a partial pair.

## Server intake boundary

The next server milestone is storage and validation only. Receiving a bundle must
not automatically start COLMAP, VGGT, MVS, meshing or texturing.

Recommended state model:

```text
RECEIVING
→ STORED
→ VALIDATING
→ VALIDATED_CAPTURE
→ WAITING_FOR_CALIBRATION
→ READY_FOR_PROCESSING
```

Failure states include:

```text
REJECTED_CONTAINER
REJECTED_MANIFEST
REJECTED_HASH
REJECTED_ROLE_SET
REJECTED_VIDEO
REJECTED_TIMELINE
REJECTED_CALIBRATION_IDENTITY
```

Server validation must verify at least:

1. immutable object storage and aggregate SHA-256;
2. gzip/tar safety, bounded entry count and safe relative paths;
3. `bundle_manifest.json` schema, `aggregate_complete` and `dual_capture_id`;
4. exactly one Master and one Slave role archive;
5. nested role-package SHA-256 and all per-file SHA-256 values;
6. required role artifacts and consistent capture/role identity;
7. MP4 parseability, a video track, a keyframe and non-zero duration;
8. actual FPS, timestamp continuity and local mapping reports;
9. matching camera/mode/zoom/orientation identity fields;
10. calibration availability and exact calibration-identity match.

A valid capture without an accepted calibration profile is stored as
`WAITING_FOR_CALIBRATION`. It is not rejected, but it is also not eligible for
metric stereo processing.

Processing starts only through an explicit job transition after all required gates
are satisfied.

## Product architecture after DP04.4A

The product target remains HYBRID:

```text
during capture:
  immediate simplified room shell, trajectory, coverage and tracking feedback

after capture:
  detailed server reconstruction, optimized geometry, mesh and textures
```

### Phone responsibilities

Both phones preserve original local FHD video and timestamped IMU. Full-resolution
video is not streamed during capture.

For live work, Slave sends a bounded reduced-resolution analysis stream plus
timestamps and IMU to Master. Master owns pairing, rectification, live depth,
stereo-inertial odometry, room-shell updates and user feedback.

The live pipeline must not assume that both phones produce 60 FPS. It pairs by the
common timeline at the cadence supported by both cameras and may deliberately run
the analysis stream at 15 or 30 FPS to control thermal load, latency and bandwidth.

The minimum calibrated live path is:

```text
separate cam0/cam1 intrinsics
+ rigid stereo extrinsics and baseline
+ timestamp pairing
→ rectification
→ stereo disparity/depth with confidence
→ stereo-inertial odometry or SLAM
→ simplified metric submaps / room shell
→ coverage and tracking-quality UI
```

The live model is an operator aid and metric prior, not the final production mesh.

### Optional learned depth

A lightweight monocular-depth model may be evaluated after the calibrated stereo
baseline works. Model families such as Depth Anything or Metric3D are candidates,
not fixed dependencies.

Learned depth must be treated as an assistive prior for cases such as:

```text
low-texture stereo regions
temporary disparity failure
coverage holes
uncertain surface completion
candidate keyframe scoring
```

It must not silently replace calibrated stereo scale. Any learned depth fused into
the metric map requires confidence gating and scale alignment to the calibrated
stereo/trajectory solution.

Triggering it only when “SLAM is uncertain” is too broad. The initial trigger
should use explicit signals such as stereo confidence, tracked-feature count,
reprojection error, IMU consistency and coverage gaps.

### Original video versus keyframes

The current quality path keeps both original videos. Uploading only keyframes is a
future optimization and must not precede a validated keyframe selector.

The server may later extract and retain a compact keyframe set, but the first
production pipeline keeps originals because they are required for:

```text
re-running timestamp pairing
changing keyframe policy
recovering from tracking failure
high-resolution texture selection
debugging calibration or rolling-shutter problems
comparing reconstruction backends
```

## Calibration-first dependency

No component may claim metric dual-phone stereo until the exact physical rig is
calibrated.

The calibration identity includes at least:

```text
rig topology and rig ID
mount revision
Master and Slave device IDs
physical camera IDs
actual image dimensions and cadence
effective zoom
stabilization mode
orientation contract
camera intrinsics and distortion for each role
stereo R, T and metric baseline
```

Changing a phone, lens, zoom, stabilization behavior, recording geometry or rigid
mount invalidates the applicable stereo profile.

The required calibration sequence is:

```text
CAL01 — per-phone intrinsics and distortion
CAL02 — rigid stereo extrinsics and metric baseline
CAL03 — rectification maps and calibration acceptance
```

Acceptance requires low reprojection error, usable stereo overlap, stable
extrinsics across repeated captures and an explicit profile identity hash.

## Common timeline after calibration

After calibration is accepted, the server builds the cross-phone timeline:

```text
piecewise clock-sync history
+ logical START/STOP events
+ local Camera2-to-encoder maps
+ IMU angular-motion correlation
+ calibrated visual/epipolar consistency
→ Slave timestamps expressed in Master time
→ monotonic frame pairing
```

Output contract:

```text
common_timeline.json
paired_frames.jsonl
timeline_alignment_report.json
```

Pair classes:

```text
DIRECT
WARP_REQUIRED
REJECTED
```

The current capture used `DEGRADED_ASYNC_MARKER`, so clock synchronization is an
initial estimate, not final pair truth. Calibration-aware visual refinement and
IMU correlation are mandatory before depth generation.

## Server reconstruction boundary

After intake, calibration and timeline gates, the server may create a deterministic
baseline:

```text
validated aggregate
→ calibrated frame extraction
→ common-timeline pairing
→ rectification
→ stereo depth and quality gates
→ metric trajectory
→ loop closure / global optimization
→ accepted depth fusion
→ mesh
→ texture projection from original FHD frames
```

COLMAP, VGGT and MVS are modules behind this boundary, not an unconditional chain
triggered by upload:

- COLMAP may provide feature matching, SfM, bundle adjustment and a comparison path;
- VGGT may be evaluated as a pose/geometry initializer or cross-check;
- MVS may refine dense geometry after accepted poses;
- metric scale remains anchored by the calibrated stereo baseline and accepted
  trajectory constraints, not by an unverified learned prediction.

The first implementation should keep a deterministic calibrated-stereo baseline so
that learned or alternative backends can be measured against an inspectable result.

## Ordered next milestones

```text
SV01  Server aggregate intake, immutable storage and validation
CAL01 Per-phone intrinsics/distortion at the exact operating configuration
CAL02 Dual-phone stereo R/T, metric baseline and identity profile
CAL03 Rectification maps and calibration acceptance dataset
TL01  Common timeline, IMU refinement and calibrated frame pairing
LM01  Bounded reduced-frame Slave-to-Master transport
LM02  Live rectified stereo depth and confidence
LM03  Stereo-inertial odometry / SLAM on Master
LM04  Simplified room shell, measurements, coverage and quality UI
LM05  Optional learned-depth assistance after benchmark and confidence gates
MM01  Server frame/keyframe dataset and deterministic stereo baseline
MM02  Global trajectory optimization and dense reconstruction experiments
MM03  Mesh cleanup, texture projection and GLB export
```

The immediate next implementation task is `SV01`. In parallel, the physical
calibration capture procedure and profile schema may be prepared, but metric depth
and live SLAM wait for accepted `CAL01–CAL03`.

## Closeout limitations

DP04.4A intentionally leaves these items open:

```text
server upload endpoint and durable receipt
automatic retention/cleanup after server acknowledgement
dual-phone intrinsics and stereo extrinsics
cross-phone common timeline and pair quality gates
live reduced-frame transport
live stereo depth and stereo-inertial SLAM
detailed server reconstruction
```

These are next-stage dependencies, not failures of the accepted capture pipeline.
