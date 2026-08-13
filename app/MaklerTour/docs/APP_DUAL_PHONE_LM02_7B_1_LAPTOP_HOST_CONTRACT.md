# APP-DUAL-PHONE-LM02.7B.1 laptop host contract

## Architecture

- Laptop is `PROCESSOR_HOST` and network MASTER.
- Phone 1 is `CAMERA_A`; phone 2 is `CAMERA_B`.
- Both phones initiate outbound TCP connections to laptop port 48640.
- No phone performs authoritative depth or session pairing in this branch.
- Browser dashboard is served by laptop on TCP/48641.

## Hello

One newline-terminated JSON object:

```json
{
  "type": "hello",
  "schema_version": 1,
  "slot": "CAMERA_A",
  "device_id": "uuid",
  "session_id": "uuid",
  "capture_mode": "960x540@5"
}
```

The host rejects unknown schemas, duplicate/invalid slots and missing device ID.

## Framed messages

Each binary message starts with two unsigned big-endian 32-bit lengths, followed
by a JSON header and payload. Limits are 64 KiB header and 2 MiB payload.

A frame header contains:

- `type=frame`
- `schema_version=1`
- `session_id`
- `frame_sequence`
- `sensor_timestamp_ns`
- optional `host_aligned_timestamp_ns` produced by laptop clock synchronization
- `capture_elapsed_ns`
- `width`, `height`, `rotation_degrees`
- `encoding=JPEG`
- `payload_crc32`

An IMU message uses `type=imu`, zero payload, sensor timestamp, sensor type and
three-axis values. The first implementation records IMU without fusion.

### Android CAMERA_A/CAMERA_B timestamp semantics

In laptop/live-stereo mode Android frames are produced by
`DualPhoneReducedFrameProducer` from CameraX `ImageAnalysis`.

```text
sensor_timestamp_ns
    raw ImageProxy.imageInfo.timestamp

capture_elapsed_ns
    SystemClock.elapsedRealtimeNanos() observed when ImageAnalysis receives
    the frame
```

These fields are not interchangeable. `capture_elapsed_ns` is callback receive
time, not camera exposure/event time. Local Android fusion must resolve
`SENSOR_INFO_TIMESTAMP_SOURCE`; REALTIME timestamps may be used directly,
otherwise the camera event timestamp must be mapped into elapsed-realtime before
comparison with Android IMU or locally attached ToF.

Laptop/live-stereo IMU is collected by `DualPhoneLaptopUplinkRuntime` as soon as
the uplink starts; starting video recording is not required.

## Pairing

The host keeps a latest-frame queue per camera. Pair delta uses `host_aligned_timestamp_ns` when available and provisional receive time before the Android clock-sync client is added. It never builds an unbounded
network backlog. A pair is diagnostic-ready when absolute sensor timestamp delta
is at most 25 ms. Every pair decision is appended to `pairs.jsonl`.

## Outputs

- browser GUI with CAMERA_A and CAMERA_B previews;
- `session.json`;
- `events.jsonl`;
- `pairs.jsonl`;
- `imu_a.jsonl`, `imu_b.jsonl`;
- archived JPEG and metadata under `camera_a/` and `camera_b/`.

## Deferred to following slices

- Android CAMERA_A/CAMERA_B client integration;
- laptop clock-model exchange;
- OpenCV rectification and StereoSGBM;
- raw disparity/depth/confidence;
- visual-inertial odometry;
- point cloud, planes and room skeleton.

## Implementation language

The host runtime and synthetic camera test client are C++20 binaries. Python is not required by LM02.7B.1.
