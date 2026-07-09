# MaklerTour stereo camera contract

## Цель

В проекте используется стереопара:

- `cam0` — встроенная камера телефона через CameraX.
- `cam1` — USB UVC камера через native UVC / TextureView.

Главное правило:

> UI-ориентация может быть удобной для человека, но сохранённые кадры, detector input, synced depth и calibration math должны оставаться в одной стабильной системе координат.

---

## 1. Saved frames / detector / synced depth

Данные для калибровки и depth должны оставаться честными:

```text
cam0 saved frame = 1280x720
cam1 saved frame = 1280x720
rotation applied to saved frames = 0

Нельзя применять CameraX imageProxy.imageInfo.rotationDegrees к сохранённым кадрам.

Разрешено:

UI display rotation only
operator preview rotation only
overlay display rotation only

Запрещено:

rotating saved JPEG
rotating detector input secretly
using UI-rotated bitmap for calibration math
2. cam0 live preview

cam0 использует PreviewView.

Рекомендуемое состояние:

scaleType = PreviewView.ScaleType.FIT_CENTER
implementationMode = PreviewView.ImplementationMode.COMPATIBLE

cam0 не должен вручную поворачиваться через graphicsLayer.

3. cam1 live preview

cam1 использует TextureView.

Рабочая схема:

FrameLayout
  clipChildren = true
  clipToPadding = true
  TextureView

TextureView поворачивается как Android View:

textureView.rotation = CAM1_PREVIEW_ROTATION_DEGREES

Размер внутреннего TextureView рассчитывается вручную:

raw frame: 1280x720 = 16:9
after 90° display rotation: 720x1280 = 9:16

Используется layout внутреннего TextureView, а не TextureView.setTransform().

Запрещено для cam1 live preview:

Modifier.graphicsLayer { rotationZ = CAM1_PREVIEW_ROTATION_DEGREES }
textureView.setTransform(...)
cropScale = maxOf(...)

Почему:

graphicsLayer поворачивает слой после Compose layout и может вылезать за границы.
setTransform + cropScale увеличивает/обрезает изображение.
setTransform без корректного layout даёт странное вписывание.
Правильнее вращать сам TextureView и вручную считать его layout.
4. Main Stereo Capture screen

На главном экране:

live preview cam0/cam1 должен быть сверху;
cam0 и cam1 должны иметь одинаковые UI-контейнеры;
Rig / status находится ниже preview;
кнопки управления находятся ниже status.

Кнопки на главном экране:

Левая колонка:

Record stereo video (legacy)
Record synced depth frames

Правая колонка:

Calibration
Open settings

Нижняя строка:

Show diagnostics
Refresh lenses

Удалены из главного UI:

Probe phone dual camera
Show phone dual camera probe JSON path

Функции probe можно оставить в коде, но кнопки не должны занимать место на основном экране.

5. Calibration UI

Калибровка использует bitmap preview из ring-buffer / capture buffer.

Для отображения оператору разрешён display-only поворот cam1, но:

detector input не меняется
saved JPEG не меняется
calibration math не меняется

Overlay ChArUco должен поворачиваться вместе с preview.

Правильная схема:

1. detector работает по исходному bitmap
2. overlay рисуется в исходных координатах bitmap
3. preview bitmap и overlay bitmap поворачиваются одинаково для display
4. оба показываются через один ContentScale.Fit

Нельзя:

повернуть только картинку без overlay
повернуть overlay отдельно через несовпадающую Canvas-геометрию
использовать graphicsLayer rotation для bitmap preview, если это ломает bounds
6. Stereo calibration

Step 3 должен использовать реальные синхронные пары:

manager.getNearestStereoCalibrationFrames(30)

Manifest должен писать:

calibration_workflow_mode = STEREO_EXTRINSICS
stereoPairSelection = nearest_ring_buffer
stereoMaxDeltaMs = 30

Stereo processor должен матчить ChArUco точки по common IDs:

commonIds = cam0.ids ∩ cam1.ids

Запрещено использовать порядок массива точек как источник соответствия для ChArUco.

Stereo calibration должна использовать intrinsics как fixed:

Calib3d.CALIB_FIX_INTRINSIC
7. Synced depth

Для depth использовать только:

Record synced depth frames

Legacy video:

Record stereo video (legacy)

не является источником для нормального stereo depth, потому что cam0.mp4 и cam1.mjpeg не гарантируют корректные frame pairs.

8. Что проверять после изменений

Перед commit запускать:

python3 tools/stereo_contract_audit.py
./gradlew :app:assembleDebug

Audit должен падать, если вернулись старые проблемы:

cam1 снова крутится через graphicsLayer;
cam1 снова использует TextureView.setTransform;
появился cropScale = maxOf(...);
убрали FrameLayout clipping;
Step 3 перестал брать nearest ring-buffer pair;
Stereo processor перестал использовать ChArUco common IDs;
saved cam0 frames снова применяют CameraX rotation;
probe-кнопки вернулись в главный UI.

---

## 9. Calibration capture UI contract

During active calibration capture (`CAPTURING_CAM0`, `CAPTURING_CAM1`, `CAPTURING_STEREO`) the UI must be operator-first:

```text
full screen preview
  top translucent info overlay
  bottom translucent controls overlay

The preview must occupy most of the screen. Information and controls must not push preview out of the layout during capture.

Capture mode layout

Required structure:

if (isCapturing) {
    Box(fillMaxSize, black background) {
        CapturePreview()
        CalibrationInfoCard(overlay = true)
        CaptureControls()
    }
}
Top info overlay

The info block must be collapsible.

Collapsed mode should show only operational data:

Step title
Captured: X / Y
actual size
board found / board not found
corners count
Tap to expand

Expanded mode may show detailed instructions, resolution details, warnings, messages, RMS/result details.

The overlay must be translucent:

Color(0xFF1E2A3A).copy(alpha = 0.70f)
Bottom controls overlay

During capture, only operational controls should be visible:

Auto / Auto stereo
Capture frame / Capture stereo pair
Cancel

Auto must be available in stereo mode too. The intended workflow is:

press Auto stereo
move board slowly
press Auto stereo again to pause
manual Capture remains available

Auto and Capture should be placed next to each other in the same row.

Service buttons

These buttons must not be visible during active capture:

Clear old calibration sessions
Write diagnostics JSON

They are allowed only before calibration starts, usually in IDLE.

Preview rendering

PreviewBitmapPanel must render in-memory bitmaps directly:

Image(bitmap = displayBitmap.asImageBitmap())
Image(bitmap = overlayBitmap.asImageBitmap())

Do not use AsyncImage for calibration bitmap preview.

Reason:

AsyncImage/Coil is unnecessary for in-memory Bitmap and can introduce flicker.
Image(asImageBitmap) is simpler and smoother.
Cropping policy

Calibration preview must not use ContentScale.Crop by default.

Required default:

ContentScale.Fit

Reason:

Calibration must see the whole board.
Crop can hide edge markers/corners and mislead the operator.

Allowed:

display-only resize-to-fit
display-only rotation
translucent overlays

Forbidden:

ContentScale.Crop for calibration preview
ContentScale.FillBounds for calibration preview
AsyncImage for in-memory calibration Bitmap
graphicsLayer rotation in PreviewBitmapPanel
Canvas overlay not synchronized with rotated bitmap
Stereo capture preview

Stereo step should show both cameras in the same operator orientation. On a portrait phone screen, vertical stacking is acceptable and usually better than two tiny side-by-side previews:

cam0 preview
cam1 preview

The detector/saved/calibration math must still use the original unmodified bitmaps.
## Stereo ChArUco quality gates (2026-07-09)

Step 3 (`STEREO_EXTRINSICS`) uses stereo-specific ChArUco overlap gates in addition to the existing per-camera `found` state. Intrinsics capture keeps the existing minimum ChArUco corner rule (12); the stricter overlap thresholds apply only to stereo extrinsics.

- Manual stereo capture requires both cameras to detect the board, stereo inputs to be ready, and at least **35 common ChArUco IDs** between cam0 and cam1.
- Auto stereo capture requires both cameras to detect the board, stereo inputs to be ready, and at least **38 common ChArUco IDs** between cam0 and cam1.
- The Step 3 overlay reports `common ids: X/40` plus `stereo quality: OK / need more overlap` so the operator can move the board fully into both cameras before saving a pair.
- A manual capture below the 35-ID threshold is rejected without saving frames and instructs the operator to increase overlap.

`StereoCalibrationProcessor` also protects final stereo calibration:

- ChArUco stereo pairs with fewer than **35 common IDs** are rejected before `stereoCalibrate`.
- At least **10 filtered stereo pairs** are required; otherwise calibration fails clearly instead of returning a high-RMS result.
- One-pass outlier filtering is not enough: a refit can expose additional high-error pairs after the fundamental matrix changes.
- The processor performs an initial calibration on candidates, computes per-pair epipolar errors, and then uses iterative epipolar-error filtering (maximum 5 iterations) before accepting the final model.
- Each iteration removes pairs above **6.0 px** epipolar error only while preserving at least **10 filtered stereo pairs**, refits `stereoCalibrate`, and recomputes final per-pair errors from the new fundamental matrix.
- The final result must not contain removable pairs above **6.0 px**. If high-error pairs remain and enough pairs are available, the processor must remove them and refit once more; if not enough pairs remain, calibration fails with a clear list like `pair X=Y px`.
- Result JSON records total pairs, candidates after common-ID filtering, used pairs, rejected pair indexes/reasons with actual epipolar error values, outlier iteration count, initial/final RMS, initial/final per-pair epipolar errors, and common ChArUco IDs per accepted pair.
- The stereo RMS acceptance threshold is **not lowered**; final stereo RMS must still pass the existing threshold.

## Depth / rectification baseline-axis contract

Operators may capture the stereo rig in portrait or landscape, and calibration UI may also be portrait or landscape for human convenience. Operator, UI, display-rotation, and IMU physical orientation are diagnostics/display metadata only; calibration math and depth axis selection must use the raw saved frames and the rectified projection matrices (`P2`/`T`), not the preview orientation. The IMU physical orientation (`portrait_upright`, `portrait_upside_down`, `landscape_left`, `landscape_right`, `face_up`, `face_down`, or `unknown`) is recorded only to understand how the operator held the phone/rig during capture and must not rotate, rectify, or otherwise alter image processing.

Saved `cam0`/`cam1` synced depth frames remain unrotated raw frames. The saved-frame contract is still `rotation_degrees_applied = 0` with raw width/height recorded in manifests.

After `stereoRectify`, the pipeline must inspect `P2[0,3]` and `P2[1,3]`:

- if `abs(P2[0,3]) >= abs(P2[1,3])`, the rectified baseline is horizontal and disparity is on `x`;
- otherwise the rectified baseline is vertical and disparity is on `y`.

OpenCV `StereoBM` / `StereoSGBM` search along X. Therefore a vertical rectified baseline must be converted for the matcher by rotating both rectified images identically by 90 degrees for depth/disparity processing only. Raw frames, calibration results, and saved captures must not be modified by this transform.

If rectified images are rotated before disparity, `Q` from the original `stereoRectify` output must not be reused blindly for `cv2.reprojectImageTo3D`. The depth pipeline must either adapt/recompute `Q` for the rotated disparity image, or skip `Q` and compute depth explicitly (for example `Z = f * B / disparity`) while marking the debug output with the selected depth method.

## IMU orientation metadata contract

The operator may rotate the physical rig during synced-depth capture. This is allowed, but it is metadata only: raw saved frames remain unrotated, and calibration/depth math must not use IMU orientation to rotate, rectify, select axes, or otherwise alter image processing.

Per saved stereo pair, the manifest must record the physical orientation that was closest to that pair in time:

- `pair_orientation_timestamp_ns` is the midpoint timestamp: `(pair.cam0.timestampNs + pair.cam1.timestampNs) / 2L`.
- The IMU sample must be selected by nearest `SensorEvent.timestamp` using `nearestSample(timestampNs)`.
- IMU orientation is diagnostics only.
- Calibration/depth math must not use IMU orientation.
- Raw frames remain unrotated.

Required pair fields:

- `pair_orientation_timestamp_ns`
- `physical_orientation`
- `physical_orientation_source`
- `physical_orientation_confidence`
- `imu_orientation_stale`
- `imu_sample_timestamp_ns`
- `imu_sample_delta_ms`
- `imu_gravity_x`
- `imu_gravity_y`
- `imu_gravity_z`
- `config_orientation`
- `display_rotation_degrees`

Required root manifest summary:

- `physical_orientation_counts`
- `display_rotation_counts`
- `config_orientation_counts`
- `orientation_transition_count`
- `first_pair_physical_orientation`
- `last_pair_physical_orientation`

## Safe manifest IO contract

Manifest JSON must be read via a safe read helper. Empty, partially written, or corrupt manifest files must not crash recording, append, or rewrite flows.

Required behavior:

- Read manifest JSON through `readJsonObjectOrNull` or the current safe read helper equivalent.
- Empty/corrupt manifest content must be treated as missing and rebuilt when possible, not propagated as an uncaught `JSONException`.
- JSON writes should use temp file + rename, for example `writeJsonObjectAtomic` with a `.tmp` file and `renameTo`.
- `appendSyncedDepthManifestPair` and `writeSyncedDepthManifest` must use the safe read path before modifying existing manifest JSON.

## Dense depth contract

Dense depth must use rectified images. Axis/orientation for dense matching is determined from stereo rectification output, not from UI orientation or IMU orientation.

Required behavior:

- Horizontal baseline may use `Q` only when disparity was not rotated.
- Vertical baseline must rotate both rectified images identically before `StereoBM` / `StereoSGBM`, because OpenCV block matchers search along X.
- For vertical rotated disparity, `Q` from original `stereoRectify` must not be reused blindly.
- Dense depth for the vertical branch must use manual Z computation:

```text
Z = f * B / disparity
```

Dense depth debug JSON must state:

- `rectified_baseline_axis`
- `disparity_axis`
- `depth_input_rotation`
- `depth_method`
- `q_valid_for_rotated_disparity`
- `baseline_magnitude`
- `focal_for_depth`
- `num_disparities`
- `block_size`
- `min_disparity`
- `valid_depth_ratio`

## Capture Bundle Upload Contract

After capture, the app packages the required capture files into a `.tgz` bundle under `files/upload_packages/` and adds the completed package to the existing upload queue as `CAPTURE_BUNDLE`.

Packaging is asynchronous and must use `Dispatchers.IO` or WorkManager. Stopping a synced-depth recording must not block the next recording: the UI returns to idle/ready immediately while tar/gzip packaging and upload happen in the background.

A synced depth capture bundle contains:

- `bundle_manifest.json`
- `capture/synced_depth_manifest.json`
- `capture/pairs/` with raw saved JPG pairs and pair metadata
- `calibration/stereo_extrinsics.json` and calibration sidecar JSON when a calibration session is available
- `rig/active_rig_profile.json`

Dense/processing is not run on the phone. Server/GrafikStation processing starts only after the capture bundle has been uploaded through the queue. Raw frames remain unrotated and are archived as saved; the app must not rotate or recompress JPG frames during bundle creation.
