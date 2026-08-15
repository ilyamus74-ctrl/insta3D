# SFM-S01 — SINGLE capture -> server reconstruction

## Goal

Close a production-quality single-phone reconstruction path before expanding to
stereo and before building live 3D.

Authoritative workflow:

```text
PHONE CAMERA
  -> capture video + sidecars
  -> upload through existing mobile API
  -> IlyamusWWW web / MySQL
  -> sfm_pipeline_runs / sfm_remote_jobs
  -> GrafikStation
  -> EXTRACT_FRAMES
  -> COLMAP sparse
  -> COLMAP dense
  -> mesh / texture
  -> web result
```

Normal operation is web-driven. Console job runners are diagnostic/service tools,
not the operator workflow.

## Dependency rule

Required:

```text
RGB video
```

Expected standard sidecars:

```text
camera_info.json
manifest.json
imu.jsonl
```

Optional:

```text
ToF
```

No ToF condition may block RGB capture, upload, sparse, dense or mesh.

## Status

```text
SFM-S01A  SINGLE recording without ToF baseline       PASS
SFM-S01B  existing upload/web pipeline reuse           CONFIRMED
SFM-S01C  existing GrafikStation chain reuse           CONFIRMED
SFM-S01D  camera optical-state/intrinsics contract     IMPLEMENTING
SFM-S01E  explicit metadata paths in video_scans       IMPLEMENTING
SFM-S01F  optional ToF capture sidecar                 PLANNED
SFM-S01G  ToF <-> selected frame association           PLANNED
SFM-S01H  ToF-assisted dense/fusion                    PLANNED
```

## S01A real-device baseline

Accepted no-ToF capture:

```text
resolution:          1920x1080
requested fps:       60
MP4 frames:          757
MP4 duration:        12.621733 s
MP4 size:            63108294 bytes
codec:               H.264
IMU records:         7022
IMU last video_t:    12.876598 s
```

The extra IMU tail is not an error. Server frame selection must use actual video
timestamps/duration and only consume temporally relevant IMU samples.

## S01D — optical state and intrinsics

### Focus states

The initial production control is:

```text
AUTO
INFINITY_FIXED
```

`INFINITY_FIXED` is reproducible and is preferred for a calibrated SfM profile
when scene distances allow it.

`AUTO` must not be treated as one immutable calibration. Actual lens position can
change. Future per-frame capture-result telemetry may provide focus distance in
diopters.

Every capture records:

```text
focus_mode
focus_locked
focus_distance_diopters
intrinsics_source
calibration_profile_key
calibration_profile_id
```

### Profile identity

Do not use one global camera intrinsics file. The profile key includes at least:

```text
camera_id
video width/height
zoom ratio
focus mode
```

Conceptually:

```text
camera_calibration/
  camera0_1920x1080_1x_infinity.json
  camera0_1920x1080_1x_auto_reference.json
```

A later profile store may add device identity/version/checksum without changing
the capture contract.

### Intrinsics source priority

```text
1. CALIBRATED_PROFILE
2. verified video-frame Camera2-derived prior
3. Camera2 factory sensor-space metadata for diagnostics
4. physical focal/sensor derived estimate
5. COLMAP self-estimation
```

Factory Camera2 values are preserved as evidence but are not automatically
declared frame-ready. Android reports intrinsic calibration in sensor
pre-correction coordinates; CameraX video crop/zoom/resolution mapping must be
resolved first.

### Distortion

When exposed by the device, preserve Android Camera2 Brown-Conrady coefficients:

```text
k1 k2 k3 p1 p2
```

The COLMAP adapter may later map a verified profile to an appropriate camera
model. A verified `colmap_camera_prior` object has this contract:

```json
{
  "usable_for_colmap": true,
  "source": "CALIBRATED_PROFILE",
  "model": "OPENCV",
  "params": [fx, fy, cx, cy, k1, k2, p1, p2]
}
```

If `usable_for_colmap` is false or parameters are incomplete, GrafikStation must
fall back to COLMAP self-estimation of the numeric camera parameters.

For a `PHONE_CAMERA` SINGLE video, fallback does **not** mean one camera object
per extracted image. All frames from the same video share one COLMAP camera
(`ImageReader.single_camera=1`) because camera id, resolution and zoom belong to
one physical stream. A verified calibration profile may later initialize K/D,
but profile absence must not allow independent focal/distortion drift per frame.

## S01E — server metadata identity

`video_scans` gains nullable explicit paths:

```text
camera_info_path
manifest_path
imu_path
tof_registered_path
```

Existing filename-based discovery remains a backward-compatible fallback.

The current Android/mobile API already uploads camera_info + manifest + IMU with
PHONE_CAMERA video. After the DB migration, the existing upload handler stores
the explicit paths automatically.

`tof_registered_path` is reserved for S01F and may remain NULL indefinitely.

## ToF separation

ToF is an enhancement, never a prerequisite:

```text
RGB + IMU
  -> complete SINGLE reconstruction

RGB + IMU + ToF
  -> same reconstruction
  -> additional metric/depth validation and fusion
```

Rigid and optical calibration are separate:

```text
ToF -> CAMERA_A R/t     mount-dependent
CAMERA_A K/D            optical-state-dependent
```

Focus profile selection changes K/D selection, not the accepted rigid ToF mount
transform by definition.

## Acceptance for S01D/E

1. A no-ToF PHONE_CAMERA recording still uploads and processes.
2. camera_info contains focus state and Camera2 factory intrinsics/distortion when
   the device exposes them.
3. manifest records focus/profile identity.
4. missing Camera2 intrinsic keys are non-fatal.
5. factory sensor-space intrinsics are not injected into COLMAP blindly.
6. a future verified `colmap_camera_prior` can set one shared camera and explicit
   camera parameters during feature extraction.
7. video_scans explicit metadata columns exist after the idempotent migration.
8. worker prefers explicit DB metadata paths and retains filename fallback.
9. existing web-driven pipeline behavior remains unchanged for old scans.
