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

MASTER creates one authoritative `stream_id` when LIVE/HYBRID starts and sends it
in `ENTER_WORK_MODE`. SLAVE replaces its locally reconciled stream ID with this
value before starting CameraX and both TCP channels. This keeps MASTER and SLAVE
frames in one pairing namespace while retaining strict mismatch blocking.

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

## LM02.2 — filtered depth and confidence map

Baseline:

```text
ead23db0d49c66bc1be3aa2bd1c132b7b2f60959
```

The raw `StereoBM` preview is replaced by bounded `StereoSGBM` processing with
spatial and temporal quality gates:

```text
raw disparity range gate
texture-gradient gate
3×3 morphology open/close
five-frame bounded temporal history
temporal median with at least three agreeing samples
confidence categories HIGH / MEDIUM / LOW / INVALID
```

MASTER exposes three diagnostic products:

```text
RAW        unfiltered SGBM disparity heatmap
FILTERED   temporal-median filtered depth heatmap
CONF       confidence map: green HIGH, orange MEDIUM, red LOW, black INVALID
```

The overlay reports raw valid, spatially filtered valid, temporally stable
coverage, high-confidence coverage, median depth and depth jitter. The existing
`validDisparityPercent` field remains a compatibility alias for filtered valid
coverage.

All histories are bounded to five disparity maps and are reset whenever
`stream_id`, mode or active depth session changes. LM02.2 still provides diagnostic
depth only; it does not claim walls, trajectory, measurements or a room skeleton.

## LM02.3 — display orientation and faster cadence

Runtime acceptance of LM02.2 exposed two independent issues: mathematical
processing orientation was shown directly in Compose, and the 5 FPS media cadence
allowed otherwise valid closest pairs to remain `LATE` around 50–100 ms.

LM02.3 keeps raw/calibration math unchanged and publishes explicit processing and
display rotations. The display transform is derived from MASTER
`image_proxy_rotation_degrees` minus the rotation applied only to the rectified
disparity input. RECT, RAW, FILTERED and CONF views consume the display transform;
StereoSGBM and temporal filtering continue consuming the existing processing
buffer.

```text
media producer target     10 FPS
depth start interval      250 ms
nominal depth target      4 FPS
READY pair gate           <= 35 ms
maximum accepted pair     <= 120 ms
```

MASTER additionally reports actual media/depth cadence, pair-quality ratio,
processing utilization and bounded sender drop/replacement counters. The higher
rate does not change latest-only transport, finite pair histories or the five-map
temporal window.

## LM02.4 — motion-aware high-quality depth

LM02.3 device testing showed enough compute headroom for five depth updates per
second, but the fixed same-pixel 3-of-5 filter erased most depth while the rig was
moving. LM02.4 adds motion modes, exposure normalization, reverse-disparity
consistency and an adaptive CPU/thermal budget.

The default quality profile uses 480x270 at a 200 ms start interval. Processing p95
and Android thermal state can downgrade to 320x240 or suspend only depth. LIVE media
continues at 10 FPS so control, operator preview and the future texture recorder
remain independent.
