# APP-DUAL-PHONE-LM02 — full-screen scan workspace and first depth

## Baseline

```text
bb25f4bf6931ecb8df3f149932d0729cddfc0ef0
```

## Goal

Replace the small LM01B diagnostic cards with a usable full-screen scan workspace
on both phones and calculate the first real rectified disparity/depth preview on
MASTER.

## MASTER workspace

LIVE or HYBRID opens a platform-width full-screen dialog. The existing
application-scoped runtime, CameraX producers and TCP/45831/TCP/45832 channels are
reused; the dialog does not create a second capture pipeline.

Overlay controls:

```text
MASTER camera
SLAVE camera
split view
DEPTH heatmap
LIVE
HYBRID
STOP
minimize
```

`minimize` hides only the dialog. `STOP` changes LIVE/HYBRID to passive `WORK_APP`.
An active stream can be reopened from the Camera card.

## SLAVE workspace

During LIVE/HYBRID the managed SLAVE surface becomes a full-screen local preview.
Status, FPS, media state and counters are overlays. The only session-affecting
local action remains emergency disconnect. A local INFO toggle may hide diagnostics
but does not change MASTER ownership or capture mode.

## Pairing

The producer samples `capture_elapsed_realtime_ns` before JPEG encoding. The
processor keeps at most eight MASTER and eight SLAVE frames. SLAVE elapsed
timestamps are translated to the MASTER clock domain with the current offset and
drift model.

```text
READY       delta <= 35 ms
LATE        35 ms < delta <= 120 ms
DROPPED     delta > 120 ms
```

Depth processing is throttled to approximately two updates per second so the UI,
network and CameraX encoder stay responsive.

## Rectification and depth

Input is the real unrotated LM01B JPEG pair. The active accepted calibration
profile supplies:

```text
MASTER K/D
SLAVE K/D
stereo R
stereo T
baseline_mm
```

OpenCV performs stereo rectification and remapping. If the rectified baseline is
vertical, both rectified frames are rotated equally for the horizontal StereoBM
input. StereoBM produces disparity; the preview uses a heatmap and reports valid
pixel percentage plus diagnostic median distance.

This is the first live depth preview. It is not yet trajectory, coverage, room
walls or a room skeleton.

## Next stage

```text
APP-DUAL-PHONE-LM03
rig trajectory, tracking quality, floor/wall extraction and live room skeleton
```
