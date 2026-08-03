# LM02.7B.5.2.5 — Local stereo geometry validation and safe tripod pose

## Purpose

Separate local stereo-depth defects from camera-pose and global-fusion defects
before any mesh or texture work.

## Runtime changes

1. Metric XYZ now uses the principal point from the effective rectified P1
   matrix after vertical-baseline portrait orientation and work-resolution
   scaling. The former image-centre approximation is removed.
2. Tripod tracking keeps gyro/homography rotation-only whenever a rotational
   estimate exists. PnP translation is diagnostic-only in this mode.
3. PnP translation above 0.08 m is explicitly rejected when no safe rotational
   estimate is available.

## Offline validation

`tools/analyze_local_stereo_geometry.py` compares:

- local PLY dominant-plane normals and distances;
- recorded camera yaw;
- local-to-world PLY against the recorded rigid matrix;
- PnP translation against the temporary tripod safety limit;
- expected CAMERA_A pivot radius estimated as half the measured stereo base.

It creates:

- `local_stereo_validation.json`;
- `local_stereo_validation.txt`;
- `local_stereo_validation_console.json`.

## Controlled test

Use one matte textured wall without glass or reflections.

1. Hold the rig at 0 degrees.
2. Rotate the tripod approximately 10 degrees and hold.
3. Rotate to approximately 20 degrees and hold.
4. Stop with F8.

The same wall must remain one world plane. Its local camera-frame normal should
change by approximately the opposite camera yaw. If camera yaw changes while
the local normal remains near zero, the fault is still in rectification or
local disparity rather than global fusion.

## Non-goals

- no mesh reconstruction;
- no texture generation;
- no CUDA backend;
- no unrestricted moving-rig PnP translation;
- no relaxation of plane-fusion thresholds.
