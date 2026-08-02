# APP-DUAL-PHONE-LM02.7B.2 laptop uplink contract

- The Linux laptop is the only processing MASTER.
- Both Android phones must use local role `SLAVE`.
- Each phone selects one unique host slot: `CAMERA_A` or `CAMERA_B`.
- Phones initiate TCP connections to laptop port `48640`.
- The dashboard remains bound to `127.0.0.1` by default.
- CameraX frames use the existing bounded 960×540 JPEG producer.
- The Android sender keeps only the latest unsent frame.
- Clock offset is estimated with repeated NTP-style probes over the control TCP
  connection and frames carry `host_aligned_timestamp_ns`.
- Accelerometer and gyroscope samples are sent as JSON protocol messages.
- The host keeps bounded per-camera pairing queues and consumes each frame at
  most once.
- Pair telemetry counts completed nearest-timestamp pairs rather than every
  intermediate latest/latest comparison.
- Runtime output stays outside the Git checkout and JPEG archival remains
  disabled unless explicitly enabled.
- This slice proves real dual ingest and synchronization. StereoSGBM, metric
  depth and room skeleton remain the next host slice.
