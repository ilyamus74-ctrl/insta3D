# MaklerTour / Insta3D Roadmap

## Current architecture

MaklerTour/Insta3D is a capture and tour platform for real estate scanning.

Core flow:

1. Broker creates order.
2. Operator takes order or creates own order.
3. Android app captures:
   - 360 photo points from Insta360 X4
   - video scan
   - local session metadata
4. App uploads media to server.
5. Server stores media per order/session.
6. Worker stitches Insta360 dual-fisheye photos into equirectangular panoramas.
7. Worker generates media derivatives.
8. Worker detects markers.
9. Web viewer shows private/public tour, map, photo points and links.

Storage rule:

Each order must have its own physical media copy:

`storage/orders/<order_id>/sessions/<app_session_uuid>/...`

No shared media path authorization at this stage.

---

## P0 — stabilize current MVP

- [x] Connect to Insta360 X4 over Wi-Fi
- [x] OSC `/osc/info`
- [x] OSC `/osc/state`
- [x] Photo point capture
- [x] Video scan start/stop
- [x] Switch camera mode image -> video
- [x] Switch camera mode video -> image
- [x] Save local session metadata
- [x] Room DB for local sessions, photo points, video scans and upload queue
- [x] Mobile login API
- [x] Mobile orders API
- [x] Mobile create session API
- [x] Mobile upload photo point API
- [x] Mobile upload video scan API
- [x] Upload queue
- [x] Queue item identity by order + session
- [x] Web orders page
- [x] Web order detail page
- [x] Broker/operator order visibility
- [x] Two-sided order closing:
  - operator close
  - broker close
  - completed after both sides closed
- [x] Private tour
- [x] Public tour by token
- [x] Public media access by token
- [x] 2D/3D map
- [x] Marker co-visibility links
- [x] Soft-delete photo point
- [x] Soft-delete capture session
- [ ] Operator-created order must be visible to operator
- [ ] Operator-created order should be assigned to creator automatically
- [ ] Add `COMPLETED` to `tour_orders.status` enum migration
- [ ] Fix media path source of truth:
  - `capture_sessions.order_id` is authoritative
  - do not trust stale mobile POST `order_id`
- [ ] Repair old mismatched media paths by copying files into correct order folder
- [ ] Simplify `media.php` after repair

---

## P1 — media processing pipeline

- [x] Server-side dual-fisheye stitcher C++/OpenCV
- [x] Insta360 X4 raw detection
- [x] Raw backup:
  `photos/raw_dualfisheye/`
- [x] Stitched equirectangular originals:
  `photos/originals/`
- [x] Preview derivatives:
  `photos/previews/` 1024x512
- [x] Viewer light derivatives:
  `photos/viewer_light/` 2048x1024
- [x] Viewer HD derivatives:
  `photos/viewer_hd/` 4096x2048
- [x] Processing job table
- [x] Marker worker
- [x] Marker detection from stitched originals
- [ ] Worker must not fail whole job on one failed photo
- [ ] Worker must update `original_size_bytes` after stitch
- [ ] Worker must regenerate derivatives with overwrite after stitch
- [ ] Web photo card shows spinner while job is QUEUED/PROCESSING
- [ ] Web session card shows processing state
- [ ] Manual “requeue processing” action from web
- [ ] Processing diagnostics in order page

---

## P2 — Matterport-like Lite

Goal: reliable 360 walkthrough + real 2D/3D map.

- [ ] Extract frames from uploaded video scan
- [ ] Detect AprilTag/ArUco markers in video frames
- [ ] Detect markers in photo panoramas
- [ ] Build marker co-visibility graph
- [ ] Estimate relative photo point positions
- [ ] Estimate session trajectory from video
- [ ] Align trajectory with marker graph
- [ ] Recover scale from known marker size
- [ ] Generate 2D floorplan draft
- [ ] Generate 3D point layout
- [ ] Link photo points to 2D/3D map
- [ ] Improve tour navigation using spatial graph
- [ ] Public viewer supports:
  - panorama mode
  - 2D map mode
  - 3D map mode

---

## P3 — Reconstruction backend

Goal: generate real 3D assets from video/photo data.

New tables:

- `reconstruction_jobs`
- `reconstruction_assets`
- `camera_poses`
- `reconstruction_points`
- `sensor_logs`

Pipeline:

- [ ] Create `reconstruction_jobs`
- [ ] Extract video frames
- [ ] Generate perspective views from 360 frames
- [ ] Run feature extraction
- [ ] Run feature matching
- [ ] Run SfM
- [ ] Save sparse point cloud
- [ ] Save camera poses
- [ ] Align poses with AprilTag markers
- [ ] Scale reconstruction to meters
- [ ] Export PLY point cloud
- [ ] Export GLB preview scene
- [ ] Store reconstruction assets under:
  `storage/orders/<order_id>/sessions/<uuid>/reconstruction/`

---

## P4 — Dense model / dollhouse

Goal: move from map to visual 3D model.

- [ ] Dense point cloud generation
- [ ] Mesh reconstruction
- [ ] Mesh simplification
- [ ] Texture projection
- [ ] GLB/OBJ export
- [ ] Web 3D viewer
- [ ] Dollhouse mode
- [ ] Floor clipping
- [ ] Room segmentation
- [ ] Measurement tools

---

## P5 — Sensor-assisted capture

Optional hardware module:

- ESP32
- BLE
- ToF distance sensor
- IMU
- battery
- camera/phone mount

Preferred prototype:

- VL53L5CX or VL53L8CX multi-zone ToF
- BNO085/BNO086 IMU

Cheap prototype:

- multiple VL53L0X sensors at fixed angles
- MPU6050 IMU

Required app features:

- [ ] BLE connection screen
- [ ] Sensor stream recorder
- [ ] Timestamp sync between phone and sensor module
- [ ] Save `sensor_log.jsonl`
- [ ] Attach sensor log to video scan
- [ ] Upload sensor log with video scan

Required server features:

- [ ] Parse sensor log
- [ ] Synchronize sensor timestamps with video frames
- [ ] Use distance measurements as wall constraints
- [ ] Use IMU for trajectory smoothing
- [ ] Use markers for drift correction
- [ ] Store sensor-assisted camera poses

---

## P6 — Photorealistic reconstruction

Long-term options:

- [ ] Gaussian Splatting pipeline
- [ ] NeRF-like reconstruction
- [ ] GPU worker node
- [ ] Splat/NeRF web viewer
- [ ] Hybrid viewer:
  - panorama tour
  - mesh
  - Gaussian splats
  - floorplan

---

## Immediate next actions

1. Fix operator-created orders:
   - operator sees own created orders
   - operator-created order is assigned to creator
2. Fix per-order media copy model:
   - future uploads use DB `capture_sessions.order_id`
   - old wrong paths repaired by copy, not move
3. Stabilize processing job UI:
   - spinner while processing
   - requeue button
   - worker logs visible in web
4. Start Matterport-like Lite:
   - video frame extraction
   - marker detection from video
   - trajectory draft
   - 2D/3D map refinement