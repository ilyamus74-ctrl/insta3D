# LM02.7B.5.3.1 — Offline COLMAP rig reconstruction and live depth probe

## Problem

The online accumulated-map tracker can lose translational motion on low-texture
walls. Stereo depth may still be available locally, but merging those local
clouds through an incomplete live trajectory produces curved or moving walls.
The previous diagnostic sessions also did not retain enough synchronized source
frames to run an independent global reconstruction.

## Synchronized rig capture contract

- Strict CAMERA_A/CAMERA_B pairs are archived independently of the legacy
  `archive_every` setting.
- `MAKLER_COLMAP_PAIR_STRIDE` selects the strict-pair sampling stride and
  defaults to `3`; `0` disables this archive.
- Both camera images of a rig frame use the same twelve-digit pair name under
  `colmap_frames/CAMERA_A` and `colmap_frames/CAMERA_B`.
- `colmap_pairs.jsonl` records source sequences, dimensions, timestamps,
  synchronization error and relative image paths.
- Capture failure is diagnostic and must not stop the live host.

## Offline COLMAP contract

`run_offline_colmap_rig.sh` prepares a calibrated two-camera COLMAP rig from the
session archive. It scales accepted intrinsics to the archived JPEG dimensions,
uses CAMERA_A as the reference sensor, applies the fixed CAMERA_B-from-CAMERA_A
rotation and metric translation, disables rig and intrinsic refinement, performs
sequential matching and exports the best CAMERA_A trajectory.

The stereo baseline is the metric scale constraint. The exporter reports path
length, return displacement, registered stereo pairs, observed baseline and the
ratio to both the live trajectory and an optional physically measured path.
Dense PatchMatch and fusion are optional through `--dense` and run only after a
successful sparse rig reconstruction.

## Live depth probe contract

- The probe reads the same disparity field and validity mask that generated the
  currently published Live depth preview; it never estimates distance from the
  JPEG heat-map colour.
- Browser coordinates are mapped through `object-fit: contain` and the inverse
  display rotation into the processing disparity frame.
- A 5×5 neighbourhood returns the median metric distance, minimum, maximum,
  spread and valid sample count.
- Results contain the live preview sequence. The browser discards a response if
  the displayed JPEG has already advanced to another sequence.
- Invalid or texture-rejected pixels explicitly return `valid: false`.

## Expected workflow

1. Apply and build the patch.
2. Record a new synchronized session; older sessions do not contain
   `colmap_pairs.jsonl` and cannot be reconstructed by this workflow.
3. Stop the host and run `run_offline_colmap_rig.sh SESSION_DIR`.
4. Inspect `offline_colmap_summary.json`, trajectory JSON/PLY and optionally the
   dense `fused.ply`.
5. Hover over known walls, doors and furniture in Live depth preview to compare
   the reported local metric distance with physical measurements.
