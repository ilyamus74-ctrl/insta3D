# APP-DUAL-PHONE DP04.3 — Automatic role packaging, Master relay and server queue

## Status

```text
IMPLEMENTED IN SOURCE
RUNTIME ACCEPTANCE PENDING
BASELINE: 950ca8c50133eb273674457e96fcd59fc513e38e
```

## Automatic flow

```text
STOP
→ both phones finalize DP04.2 artifacts
→ each phone creates a SHA-256 verified role .tgz
→ Slave opens a one-shot package transfer socket
→ STOP_ACK carries port, token, size and SHA-256
→ Master downloads and verifies the Slave role package
→ Master creates one dual_phone_stereo_video aggregate .tgz
→ selected app session/order enqueue the aggregate bundle
→ existing upload queue sends the bundle to upload_capture_bundle
```

ADB serial numbers and manual adb pull are diagnostic-only after this stage.

## Role package contract

Each role archive contains role_package_manifest.json and the complete DP04.2 role directory. Packaging fails when any mandatory artifact is missing or empty: video.mp4, dual_capture_manifest.json, frames.jsonl, encoder_pts.jsonl, imu.jsonl, camera_info.json or clock_sync.json.

## Transfer contract

The file channel is separate from the JSON control socket. Default TCP port is 48623. The STOP_ACK package offer includes dual_capture_id, role, file name, byte count, SHA-256 and a single-capture random token. Master verifies declared size and SHA-256 before acknowledging PACKAGE_RECEIVED.

## Aggregate and upload contract

The aggregate archive contains bundle_manifest.json, roles/master.tgz and roles/slave.tgz. capture_type is dual_phone_stereo_video and app_bundle_uuid equals dual_capture_id. AppStateViewModel enqueues through the existing Room upload queue and MobileUploadApi. If no selected session or server order exists, the aggregate remains local with READY_NOT_QUEUED instead of being deleted.

## Runtime acceptance

1. Record 15–20 seconds and press STOP.
2. Confirm Slave reaches TRANSFERRED_TO_MASTER.
3. Confirm Master reaches QUEUED_FOR_SERVER.
4. Confirm the aggregate .tgz exists and contains both role archives.
5. Confirm the upload queue creates CAPTURE_BUNDLE with capture_type=dual_phone_stereo_video.
6. Confirm backend acknowledgement before deleting any local package.
