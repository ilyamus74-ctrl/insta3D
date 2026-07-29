# APP dual-phone DP04.4 — asynchronous capture timeline

## Decision

Dual-phone capture does not require synchronous command execution or synchronous
acknowledgements. Android scheduling, CameraX initialization, encoder startup and
network delivery are independent on each phone. Control messages therefore do not
define stereo synchronization.

The authoritative synchronization inputs are the recorded data streams:

- Camera2 sensor timestamps in `frames.jsonl`;
- encoded MP4 sample PTS in `encoder_pts.jsonl`;
- both phone IMU streams in `imu.jsonl`;
- append-only command and recorder events in `capture_events.jsonl`;
- periodic offset/drift/uncertainty models in `clock_sync_history.jsonl`.

The server constructs the common timeline after upload.

## Capture semantics

### ARM

`ARM` prepares CameraX and immediately starts the physical MP4, frame telemetry and
IMU on each phone independently. This is a pre-roll recording. Master may start
several seconds before Slave without corrupting the final stereo dataset.

The UI enables the capture-window marker only after both phones report that their
local pre-roll recording is active.

### START

`START_AT` is a logical `CAPTURE_WINDOW_START` marker. It does not start the encoder.
Each role stores:

- command ID;
- Master command creation time;
- intended local marker time;
- actual command receive time;
- actual marker application time;
- marker delta.

A late command is accepted and written immediately. It is not rejected merely
because network or scheduler delay exceeded a control threshold.

### STOP

`STOP` is a logical `CAPTURE_WINDOW_STOP` marker. Each phone writes the marker,
continues local recording for a bounded post-roll period and finalizes independently.
Master does not assume that Slave finalizes at the same moment.

## Durable role spool

Each role directory contains:

```text
video.mp4
dual_capture_manifest.json
frames.jsonl
encoder_pts.jsonl
imu.jsonl
camera_info.json
clock_sync.json
capture_events.jsonl
clock_sync_history.jsonl
```

Files are retained until upload/transfer acknowledgement. The role manifest records
physical recording bounds separately from the logical capture-window bounds.

## Server alignment

For each role the server estimates a piecewise-linear mapping:

```text
t_common = a * t_local + b
```

where `b` is offset and `a` includes clock drift. Every history row preserves the
Master-clock model reference, offset at that reference, drift, uncertainty and the
local time at which the role recorded the model. The initial model comes from
`clock_sync_history.jsonl`; residual time offset may be refined from visual motion,
IMU correlation and stereo consistency.

Frames are paired by nearest common-timeline timestamp. Every pair records delta and
quality. Frames outside the accepted delta are excluded from stereo depth but may
still contribute to monocular visual odometry, loop closure or texturing.

## Metric room skeleton safety

The room skeleton is not integrated directly from every frame. The server must:

1. build the common timeline;
2. pair only temporally acceptable stereo frames;
3. reject pairs with excessive motion-compensated timing error;
4. estimate stereo depth using the calibrated fixed baseline;
5. estimate the rig trajectory with stereo visual-inertial constraints;
6. build bounded submaps;
7. perform loop closure and global pose-graph/bundle optimization;
8. integrate only accepted optimized depth into the metric volume/mesh;
9. project textures from sharp original full-resolution frames.

Timing uncertainty is carried into pair confidence. Fast translation and especially
fast rotation reduce valid stereo coverage; the capture UI must later warn the
operator when motion exceeds the accepted timing envelope.

## DP04.4A implementation scope

The first source implementation keeps the existing Master-controlled role-package
transfer while changing capture timing semantics:

- physical recording starts during ARM;
- START is a logical marker;
- late START is accepted;
- STOP is a logical marker plus post-roll;
- capture and clock histories are packaged;
- command IDs make repeated ARM/START operations idempotent at the capture endpoint.

Direct independent role upload and server-side logical aggregate registration remain
DP04.4B. They must reuse the same `dual_capture_id` and timeline contract.
