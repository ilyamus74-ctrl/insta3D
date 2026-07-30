# APP dual-phone DP04.4A4 — capture truth and local frame/PTS mapping

## Purpose

The requested CameraX mode is not authoritative evidence of the finalized stream.
DP04.4A4 derives actual cadence and continuity from recorded timestamps, then writes a
local monotonic mapping between Camera2 capture results and finalized MP4 samples.

## New role artifacts

- `frame_encoder_map.jsonl` — per encoded sample mapping to one Camera2 frame;
- `local_timeline_report.json` — local integrity, actual FPS, timestamp gaps and residuals.

Both files are required members of every role package.

## Actual stream truth

The role manifest preserves requested values and adds:

- `capture_result_fps_actual`;
- `encoder_fps_actual`;
- `effective_video_mode_actual`;
- `estimated_missing_capture_results` derived from median Camera2 timestamp gaps;
- `encoder_gap_count` derived from median encoded PTS gaps;
- keyframe and parseability status.

A nominal request such as `1920x1080@60` may therefore finalize as
`1920x1080@30` without being misreported as an actual 60 FPS stream.

## Mapping contract

The local mapping is provisional and does not claim cross-phone synchronization.
It is anchored with the CameraX Start elapsed-realtime timestamp when available,
then refined with a constant offset and monotonic nearest-timestamp matching.

Every mapping row contains:

- encoded sample index and PTS;
- Camera2 frame index and sensor timestamp;
- residual in nanoseconds;
- mapping status.

The server still constructs the Master/Slave common timeline from clock history, IMU,
visual motion and stereo consistency.

## Diagnostics

`collect_insta3d_dual_adb_diagnostics.sh --both` records Master and Slave in parallel.
The default archive excludes MP4 and full system logcat. Use `--full` only when binary
video or unrestricted system logging is required.
