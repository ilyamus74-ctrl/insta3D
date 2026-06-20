# Phone Scan MVP Status

## Current Working MVP

The phone scan MVP currently supports an end-to-end video capture, upload, storage, order-page visibility, and sparse reconstruction workflow.

- Phone camera fullscreen recording works.
- Local video capture is saved under `sessions/<sessionId>/phone_scans/<scanId>/video.mp4`.
- The upload queue sends `PHONE_CAMERA` video assets to the server.
- The server stores uploaded MP4 files under `/home/storage/orders/<orderId>/sessions/<appSessionUuid>/videos/`.
- The web order page shows uploaded phone video files.
- The SfM worker can run the `EXTRACT_FRAMES`, `COLMAP_SPARSE`, and `EXPORT_PLY` stages.
- GrafikStation executes COLMAP through Podman with NVIDIA GPU acceleration.

## Known Limitations

- PLY browser download and viewing are still unstable.
- MeshLab viewing is not the primary validation path yet.
- Current videos are short, so sparse reconstruction quality is limited.
- Camera calibration has not been implemented yet.
- IMU upload and IMU use are not implemented yet.
- BLE VL53L08 integration is not implemented yet.

## Validation Focus

For the current MVP, validation should focus on confirming that a phone video can be recorded, uploaded, listed on the web order page, and processed through the SfM worker stages. Reconstruction output should be treated as a pipeline smoke test rather than a final quality benchmark until longer capture guidance, calibration, IMU support, and depth-sensor integration are available.