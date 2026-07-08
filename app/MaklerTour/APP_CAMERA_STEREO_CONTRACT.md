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
