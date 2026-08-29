# Android capture architecture audit — MaklerTour

Дата аудита: 2026-08-26  
Область: текущие исполняемые исходники Android и соответствующий PHP/server contract в этом репозитории. Файлы `*.before_*`, build/generated-артефакты и архивные копии не считались текущей реализацией. Сборка и запуск приложения не выполнялись.

## Executive summary

В приложении нет одного унифицированного capture pipeline. Реально существуют как минимум пять разных трактов: обычный phone video, auto-photo, phone+USB stereo, dual-phone recorded video и reduced-frame live transport на laptop. Они различаются камерой, часами, IMU, упаковкой и серверным контрактом.

Наиболее важные выводы:

- **CRITICAL:** Android загружает aggregate dual-phone bundle с `capture_type=dual_phone_stereo_video`, но PHP endpoint принимает только `synced_depth_frames`, `stereo_video_legacy`, `auto_photo_session`. Текущий dual upload должен завершаться `400 invalid capture_type` до worker.
- **CRITICAL:** режим, называемый dual phone + ToF, не сохраняет ToF в recorded dual role packages. В dual manifest/required files отсутствуют `tof_frames.jsonl` и `tof_calibration.json`; `TofCaptureSidecarRecorder` используется только standalone phone-video трактом.
- **HIGH:** laptop live — отдельный ImageAnalysis/JPEG transport, не `PhoneCameraVideoRecorder`, не MP4 и не Android upload bundle. У каждого телефона создаётся собственный runtime `sessionId`; общего Android session UUID между CAMERA_A/B в найденном коде нет.
- **HIGH:** `pipeline_93` относится к standalone `PHONE_CAMERA`: один MP4 + IMU + ToF sidecars, затем server `EXTRACT_FRAMES`/COLMAP и ToF metric. Это не dual-phone и не laptop-live pipeline.
- **HIGH:** USB+ToF как сохраняемый bundle не реализован: ToF runtime/status доступен process-wide, но legacy USB stereo и synced-depth packagers не добавляют ToF sidecars.
- **MEDIUM:** запрос 60 fps не означает фактические 60 fps. Уже зафиксированный dual run имел MASTER около 60, SLAVE около 30; downstream обязан использовать измеренные timestamps/PTS.

## 1. Current architecture

### 1.1 Процессы и lifecycle

Android manifest объявляет одну `MainActivity` и `TofUsbPermissionReceiver`. Capture `Service` и WorkManager `Worker` не найдены. Длительные операции выполняются coroutine/executor-ами, принадлежащими Activity, ViewModel или process-scoped runtime.

Локальная business session создаётся так:

```text
UI create session
 -> AppStateViewModel.createSession()
 -> SessionRepository.createSession()
 -> CaptureSessionEntity(UUID)
 -> Room DAO
```

Серверная capture session — другая сущность. Она лениво создаётся перед upload через `MobileUploadApi.createSession()` → `mobile.php?action=create_session` с `order_id` и `app_session_uuid`.

Основные точки: `state/AppStateViewModel.kt:216`, `data/repository/Repositories.kt:1106`, `auth/MobileUploadApi.kt`, `web/www/api/mobile.php`.

### 1.2 Выбор режима и ролей

`ApplicationCaptureMode` содержит:

- `STANDALONE_COLMAP`;
- `DUAL_PHONE_MASTER`;
- `DUAL_PHONE_SLAVE`;
- `LAPTOP_STEREO_CLIENT`;
- `PHONE_USB_STEREO`.

Выбор идёт из `ApplicationCaptureModeSelector`, сохраняется `DualPhoneStereoSettingsStore`. MASTER/SLAVE назначаются непосредственно выбранным `ApplicationCaptureMode`; `phoneToPhoneRoleOrNull()` возвращает роль только для двух dual-phone вариантов. В остальных режимах верхнеуровневая dual role — `STANDALONE`.

USB host не является отдельной role: это Android-устройство в `PHONE_USB_STEREO`, использующее `UsbManager` и UVC interface. ToF device тоже не является role: `TofUsbRuntime` обнаруживает поддерживаемое USB устройство/интерфейс и получает permission. В rig semantics ToF привязан к CAMERA_A/MASTER calibration identity.

Ключевые точки: `ui/settings/ApplicationCaptureModeSelector.kt:23`, `data/dualphone/ApplicationCaptureMode.kt`, `MainActivity.kt:1379`, `data/tof/TofUsbRuntime.kt:54`.

### 1.3 Capture lifecycle по режимам

| Режим | Entry point / chain | Session / writer | Итог |
|---|---|---|---|
| Single camera video | `PhoneCameraScanScreen` → `AppStateViewModel.startPhoneVideoScan` → `PhoneCameraScanProvider.startVideoScan` | `files/sessions/<localSessionId>/phone_scans/<scanId>`; `PhoneCameraVideoRecorder`, `ImuRecorder`, optional `TofCaptureSidecarRecorder` | multipart video scan, не TGZ |
| Photo capture (Android auto-photo) | auto-photo UI → `AutoPhotoCaptureManager` | `auto_photo_sessions/<captureUuid>`; CameraX `ImageCapture`, metadata JSONL | `auto_photo_session` TGZ |
| Photo point (Insta360) | capture-point UI → camera provider/OSC `takePicture` | CapturePoint repository | preview/original multipart photo |
| Standalone camera + ToF | тот же phone-video button/chain | тот же scan dir; ToF sidecar включается только при connected/fresh runtime | video multipart + optional ToF files |
| USB camera + ToF | `StereoCaptureExperimentalScreen` → `StereoCaptureExperimentalManager` / synced-depth functions в `MainActivity` | phone CameraX + native libuvc; ToF writer в capture chain не найден | legacy TGZ или synced-depth TGZ, без ToF |
| Dual MASTER/SLAVE | session screen → `DualPhoneControlManager` ARM/START/STOP → registered `DualPhoneCaptureEndpoint` → `PhoneCameraScanProvider` | общий `dualCaptureId`; одинаковый recorder на обоих телефонах; role TGZ | MASTER aggregate TGZ с двумя role TGZ |
| Dual + ToF | тот же dual recorded chain | ToF sidecar recorder не вызывается | фактически тот же bundle, что dual без ToF |
| Dual + laptop live | settings/uplink card → `DualPhoneLaptopUplinkRuntime.start` → `DualPhoneReducedFrameProducer` | независимый runtime UUID на каждом phone; socket writer | JPEG/IMU/ToF packets, Android bundle отсутствует |

Обобщённая цепочка обычного видео:

```text
UI button
 -> MainActivity / Compose screen
 -> AppStateViewModel
 -> PhoneCameraScanProvider
 -> CameraX capture use cases + sidecar recorders
 -> scan directory
 -> MobileUploadApi multipart upload
```

В dual pipeline ViewModel не управляет физическим стартом каждого телефона: MASTER control manager посылает ARM/START/STOP, а endpoint каждого телефона запускает локальный recorder.

## 2. Capture modes matrix

| Режим | Камера | Recorder / format | IMU | ToF | Bundle / upload |
|---|---|---|---|---|---|
| Single video | CameraX + Camera2Interop | `PhoneCameraVideoRecorder`, MP4 | continuous JSONL | optional JSONL | multipart `VIDEO` |
| Auto-photo | CameraX | `ImageCapture`, JPEG | snapshot в photo metadata | нет | `auto_photo_session.tgz` |
| Insta360 photo | OSC/provider | JPEG | нет | нет | multipart photo point |
| Standalone + ToF | CameraX + Camera2Interop | тот же MP4 recorder | continuous | continuous sidecar | multipart video + sidecars |
| USB stereo legacy | phone CameraX + native libuvc | MP4 + MJPEG elementary stream | separate continuous JSONL | не сохраняется | `stereo_video_legacy.tgz` |
| USB synced depth | phone analysis + UVC frames | paired JPEG | continuous IMU в bundle не подтверждён | не сохраняется | `synced_depth_frames.tgz` |
| Dual recorded | CameraX + Camera2Interop ×2 | тот же MP4 recorder на каждой role | continuous per role | не сохраняется | aggregate dual TGZ; server mismatch |
| Dual recorded + ToF | как dual | как dual | как dual | **не сохраняется** | идентичен dual |
| Dual laptop live | CameraX ImageAnalysis ×2 | reduced JPEG packets | accel+gyro packets | registered packet только CAMERA_A | socket, без PHP bundle |

### Почему в UI нет отдельного VIDEO режима

`VIDEO` не является `ApplicationCaptureMode`. Обычная запись — capture action внутри `STANDALONE_COLMAP`; в dual control базовый non-live режим называется `SYNC_VIDEO`. Поэтому selector разделяет topology/роль, а не media primitive. На session screen VIDEO представлен кнопкой phone scan либо dual ARM/START/STOP, а не отдельным глобальным режимом. ToF также является optional sidecar/capability, а не самостоятельным UI mode.

## 3. Camera pipeline

### 3.1 Phone MP4 pipeline

Production recorder — `PhoneCameraVideoRecorder` (`data/phonecamera/PhoneCameraVideoRecorder.kt:124`). Это CameraX:

```text
ProcessCameraProvider
 -> Preview
 -> VideoCapture<Recorder>
 -> CameraX Recording
 -> video.mp4
```

Camera2 используется через `Camera2Interop` для capture callback/telemetry. Явного production-вызова `CameraDevice.createCaptureSession()` нет: session создаёт CameraX при `bindToLifecycle`. Прямой Camera2 probe существует в диагностическом `PhoneDualCameraProbe`, но это не основной recorder. CameraX, следовательно, используется; утверждение «чистый Camera2 recorder» неверно.

| Параметр | Реализация |
|---|---|
| Resolution | `PhoneVideoMode`; quality selector UHD/FHD/HD, плюс requested profile. Фактический размер фиксируется metadata/MP4, а не гарантируется одним request |
| FPS | `setTargetFrameRate(Range(fps,fps))`; фактический fps измеряется по Camera2 sensor timestamps и MP4 PTS |
| Sensor orientation | читается в camera info/auto-photo; raw analysis frame не физически поворачивается |
| Display rotation | `PreviewView.display.rotation` → `currentTargetRotation` |
| `rotationDegrees` | CameraX `ImageInfo.rotationDegrees` сохраняется как metadata для analysis/reduced frame |
| Encoder rotation | отдельного `MediaMuxer.setOrientationHint` нет; transform/orientation делегирован CameraX через `VideoCapture.setTargetRotation` |
| Timestamp source | Camera2 `SENSOR_TIMESTAMP`, источник из `SENSOR_INFO_TIMESTAMP_SOURCE`; app/IMU timeline — `CLOCK_BOOTTIME`/elapsed realtime; MP4 — encoder PTS |

`Preview` и `VideoCapture` получают `setTargetRotation(currentTargetRotation)` (`PhoneCameraVideoRecorder.kt:250`, `:490`). Значит display rotation применяется к CameraX use-case/encoded presentation. Для raw calibration/stereo analysis contract другой: `rotationDegreesApplied=0`, а CameraX rotation хранится отдельно. Landscape/portrait может изменить target rotation/MP4 presentation, но app не выполняет destructive pixel rotation raw stereo JPEG.

### 3.2 Photo pipeline

`AutoPhotoCaptureManager` использует CameraX `Preview + ImageAnalysis + ImageCapture`, всем передаёт display target rotation (`AutoPhotoCaptureManager.kt:259`). Он читает `SENSOR_ORIENTATION` (`:859`) и пишет dimensions/EXIF/rotation metadata. Поле `image_rotation_degrees_applied=0` означает отсутствие отдельной app post-rotation; оно не доказывает, что CameraX `ImageCapture` не записал EXIF orientation/не применил свой transform.

### 3.3 USB pipeline

CAM0 использует `PhoneCameraVideoRecorder`; CAM1 — другой pipeline: native libuvc (`StereoUsbUvcAdapter`) пишет MJPEG. Поэтому workflow асимметричен. В legacy manifests часть timestamp данных оценочная. В synced-depth path оба изображения приводятся к paired JPEG frames по ближайшему timestamp; это не MP4 pipeline.

USB UI временно фиксирует Activity в sensor-landscape. Это UI/display решение; raw calibration frames сохраняются с `rotationDegreesApplied=0`.

### 3.4 Dual phone MASTER и SLAVE

Обе роли используют один `PhoneCameraVideoRecorder` и один `PhoneCameraScanProvider`; отдельного SLAVE encoder нет. Pipeline отличается orchestration и network responsibility, но не camera writer:

- MASTER создаёт capture id, синхронизирует clock, отправляет команды, получает package;
- SLAVE выполняет команды и возвращает package;
- каждый телефон независимо согласует CameraX quality/fps со своим HAL.

Отсюда допустима разница actual resolution/fps. Request одинакового `60 fps` не гарантирует одинакового результата.

### 3.5 Laptop live

Это принципиально иной camera pipeline:

```text
CameraX ImageAnalysis
 -> DualPhoneReducedFrameProducer
 -> JPEG downscale/encode
 -> socket frames
 -> laptop live reconstruction
```

`PhoneCameraVideoRecorder` здесь не записывает MP4. Header несёт capture/sensor/host timestamps и `rotation_degrees`; JPEG остаётся с `rotationApplied=0`.

### 3.6 Сравнение с pipeline_93

По сохранённым pipeline evidence в репозитории `pipeline_93` имел один `PHONE_CAMERA` source: Motorola camera id `0`, 1920×1080, request 60 fps, фактически около 59.975 fps; ToF около 15 Hz и IMU sidecar. Server extraction применял `rotation=-90` к извлекаемым кадрам. Это server-side EXTRACT transform, а не доказательство Android display rotation или физического encoder rotation.

Реальный тракт `pipeline_93`:

```text
standalone phone MP4
 + imu.jsonl
 + tof_frames.jsonl + tof_calibration.json
 -> upload_video_scan
 -> EXTRACT_FRAMES (rotation -90)
 -> COLMAP
 -> ToF metric scale/alignment
```

Он совпадает со standalone `PhoneCameraScanProvider` pipeline, но не с dual aggregate, USB stereo или laptop live.

## 4. IMU pipeline

### 4.1 Основная реализация

```text
SensorManager
 -> gyro + accelerometer + gravity + rotation vector
 -> ImuRecorder.onSensorChanged
 -> imu.jsonl
 -> video multipart / dual role package
 -> server staging / EXTRACT_FRAMES
```

`ImuRecorder` регистрирует четыре sensor types с `SENSOR_DELAY_GAME` (`ImuRecorder.kt:53-62`). Это scheduling hint, не гарантированная Hz; фактическую частоту следует вычислять из `event.timestamp`. Timestamp — monotonic ns, metadata объявляет `CLOCK_BOOTTIME`. JSONL начинается schema/clock metadata, затем события с `t_ns`, `video_t_sec`, sensor name и values.

Standalone recorder запускается до видео и после CameraX `VideoRecordEvent.Start` вызывает `rebaseVideoTimeline()` (`PhoneCameraScanProvider.kt:168`). В dual pre-roll намеренный: ARM запускает IMU/recording раньше logical START; timeline и capture events нужны для вырезания общей области. Отдельный вызов rebase к logical START в dual path не найден.

USB legacy использует собственный `StereoImuJsonlRecorder`: accel, gyro, rotation vector, `SENSOR_DELAY_GAME`, без gravity. Auto-photo использует `AutoPhotoImuTracker` и кладёт последние accel/gyro/quaternion значения в metadata каждой фотографии, а не continuous `imu.jsonl`. Laptop live отправляет accel+gyro packets и не создаёт Android IMU file.

### 4.2 Матрица IMU

| Режим | IMU собирается | IMU сохраняется | IMU используется |
|---|---|---|---|
| Single video / pipeline_93 | да, 4 sensor types | `imu.jsonl` | да, server extraction/metric diagnostics |
| Standalone + ToF | да | `imu.jsonl` | да |
| Auto-photo | да, snapshots | внутри photo metadata | ориентация/quality metadata; отдельный server IMU pipeline не найден |
| USB stereo legacy | да, 3 sensor types | `imu.jsonl` | bundle сохраняет; dense consumer использования не найден |
| USB synced depth | orientation telemetry есть; continuous writer в capture chain не подтверждён | нет подтверждения | нет подтверждения |
| Dual MASTER | да | role `imu.jsonl` | intended для synchronization/downstream; server dual consumer отсутствует |
| Dual SLAVE | да | role `imu.jsonl` | то же |
| Dual + ToF | как dual | как dual | как dual |
| Laptop live | accel+gyro | нет, только network packets | да, laptop protocol; PHP pipeline не участвует |

## 5. ToF pipeline

### 5.1 Полный путь

```text
VL53L8CX
 -> RP2040 / USB serial protocol TOF1
 -> TofUsbRuntime
 -> frame parser + CRC
 -> TofFrameV1
 -> RP2040-to-Android clock mapping
 -> TofCaptureSidecarRecorder / registered-RGB runtime
 -> JSONL sidecar or CAMERA_A live packet
 -> standalone server metric pipeline or laptop
```

`TofFrameV1` хранит grid dimensions/frequency/temperature/sequence, `rp2040TimestampUs`, host receive elapsed realtime, IRQ validity и массивы `distanceMm`, sigma, status, target count (`data/tof/TofFrameV1.kt:3`). Parser принимает 4×4/8×8 frames и проверяет CRC.

`TofCaptureSidecarRecorder` пишет metadata и frames JSONL; contract объявлен `AXIAL_PERPENDICULAR_Z_MM` (`TofCaptureSidecarRecorder.kt:72`). Clock sync через TSY1 maps RP2040 IRQ time в Android elapsed realtime; fallback — host receive timestamp. Видео связывается с ToF через общую mapped monotonic timeline, не по wall clock.

### 5.2 Axial/radial, R2P и calibration

В коде явно зафиксировано: VL53L8CX firmware уже применяет radial-to-perpendicular, поэтому `distanceMm` — axial Z. Android повторный R2P не применяет (`TofCameraExtrinsicsProfile.kt:197`, `TofCameraExtrinsicsSolver.kt:506`).

Calibration snapshot хранится в `TofCameraCalibrationStore`/`tof_calibration.json`. Projection строит 3D point из axial Z и camera ray, затем применяет `rotation_tof_to_camera` и `translation_tof_to_camera_mm`, после чего camera intrinsics/distortion. Extrinsic transform, таким образом, применяется в `TofCameraProjector`/registered RGB anchor path; raw sidecar сохраняет исходные distances и calibration отдельно.

### 5.3 Матрица ToF

| Режим | ToF работает | ToF сохраняется | ToF используется |
|---|---|---|---|
| Single video / pipeline_93 | да при fresh USB runtime | `tof_frames.jsonl` + calibration | да, metric server path |
| Standalone + ToF | да | да, optional sidecars | да при наличии/валидности sidecars |
| Auto-photo | runtime может быть process-wide | нет | нет |
| USB stereo legacy | runtime/status может работать | нет | нет в bundle/worker |
| USB synced depth | runtime/status может работать | нет | dense stereo не использует ToF |
| Dual recorded MASTER | live registration возможна локально | нет | не используется recorded bundle |
| Dual recorded SLAVE | обычно ToF не является calibration authority | нет | нет |
| Dual recorded + ToF | capability есть | **нет** | **нет в recorded server path** |
| Laptop CAMERA_A | да | не на диск Android | да, registered ToF packet |
| Laptop CAMERA_B | нет/не authority | нет | нет |

`registeredTofSnapshot` в `DualPhoneReducedFrame` помечен process-local и обычным phone-to-phone reduced transport не сериализуется. Laptop uplink сериализует его явно и только для CAMERA_A (`DualPhoneLaptopUplinkRuntime.kt:577`).

## 6. Dual phone pipeline

### 6.1 Control and data flow

```text
MASTER                                      SLAVE
  UI / DualPhoneControlManager                listener/control manager
  create dualCaptureId                        receive shared dualCaptureId
  clock sync <---------------- network ----------------> clock samples/model
  ARM / START / STOP ------------------------> local endpoint
  local PhoneCameraVideoRecorder              local PhoneCameraVideoRecorder
  local role package                          role package
  receive slave.tgz <------------------------ package transfer
  aggregate master.tgz + slave.tgz
```

MASTER запускает control connection, clock sync, ARM/START/STOP, пишет собственную role запись, принимает reduced SLAVE preview/live frames и итоговый SLAVE TGZ, затем формирует aggregate. SLAVE подключается/слушает согласно control configuration, пишет такой же локальный набор и передаёт package. Physical starts не атомарны; согласование выполняется command schedule, clock model/history, capture events и encoder PTS.

Единая logical capture есть: обе role используют общий `dualCaptureId`. Локальные Android/Room sessions могут быть различными; aggregate identity задаётся именно dual capture id и role metadata. Pairing видео не создаёт новый paired movie на Android — downstream должен сопоставлять timelines.

### 6.2 Файлы role packages

MASTER и SLAVE пишут одинаковый обязательный набор:

```text
video.mp4
dual_capture_manifest.json
frames.jsonl
encoder_pts.jsonl
frame_encoder_map.jsonl
local_timeline_report.json
imu.jsonl
camera_info.json
clock_sync.json
capture_events.jsonl
clock_sync_history.jsonl
```

MASTER дополнительно создаёт aggregate:

```text
maklertour_capture_bundle_dual_phone_stereo_video_<captureId>.tgz
  bundle_manifest.json
  roles/master.tgz
  roles/slave.tgz
```

ToF files ни в role required list (`DualPhoneBundleTransfer.kt:526-535`), ни в aggregate не входят. Значит ToF pairing в recorded dual bundle отсутствует.

### 6.3 LIVE, HYBRID, MEDIA READY

UI кнопки `LIVE`/`HYBRID` находятся в `DualPhoneLiveStreamSessionCard` и full-screen workspace (`DualPhoneLiveStreamSessionCard.kt:154-172`). Только MASTER может вызвать `DualPhoneApplicationRuntime.enterWorkMode()`; он переводит режимы в `WORK_LIVE` или `WORK_HYBRID`, посылает SLAVE managed-mode command и поднимает control + reduced media channels (`DualPhoneApplicationRuntime.kt:196`). Базовый recorded режим — `SYNC_VIDEO`/`WORK_APP`.

`MEDIA READY` не является selectable capture mode. `READY` — состояние `DualPhoneLiveStreamController`/reduced media transport после prepare/handshake; UI просто отображает `MEDIA ${snapshot.mediaTransport.state.name}`. Затем transport переходит в `STREAMING`.

Смысл режимов:

- `SYNC_VIDEO`: recorded dual video, stream disabled;
- `LIVE_METRIC`: reduced frames/live depth, без обязательного MP4 bundle;
- `HYBRID`: live stream плюс recorded-work semantics, но ToF всё равно не добавлен в dual role package найденным writer-ом.

## 7. Bundle format

### 7.1 Где создаётся `.tgz`

- generic USB/synced/auto-photo: `data/capture/CaptureBundlePackager.kt`;
- dual role и aggregate: `data/dualphone/DualPhoneBundleTransfer.kt:128,373`.

Generic bundle:

```text
capture_bundle/
  bundle_manifest.json
  capture/
    <capture files>
  calibration/                  # synced depth / legacy where supplied
  rig/active_rig_profile.json   # synced depth / legacy where supplied
```

Auto-photo:

```text
bundle_manifest.json
capture/
  manifest.json
  camera_info.json
  photos_metadata.jsonl
  quality.jsonl
  events.jsonl
  photos/*.jpg
```

Standalone phone video не создаёт `.tgz`; upload отправляет `video.mp4` и sidecars отдельными multipart parts. Dual aggregate использует nested role TGZ, а не generic `capture/` layout.

### 7.2 Различия single / dual / dual+ToF

| Вариант | Container | ToF |
|---|---|---|
| single | multipart MP4 + JSON sidecars | optional `tof_frames.jsonl`, `tof_calibration.json` |
| dual | aggregate TGZ → two role TGZ | отсутствует |
| dual+ToF | тот же aggregate | отсутствует; формат не отличается |

## 8. Server contract

### 8.1 Upload chain

```text
Android MobileUploadApi
 -> web/www/api/mobile.php
 -> DB capture_sessions / videos / capture_bundles
 -> web/storage/orders/<orderId>/sessions/<appSessionUuid>/...
 -> sfm_remote_jobs / worker polling
 -> remote processing scripts
```

Phone video endpoint — `action=upload_video_scan`. Bundle endpoint — `action=upload_capture_bundle` (`MobileUploadApi.kt:352`, `mobile.php:1041`). Bundle multipart содержит `order_id`, numeric `capture_session_id`, `upload_type=CAPTURE_BUNDLE`, `capture_type`, `app_bundle_uuid`, file.

Storage для bundle: `web/storage/orders/<orderId>/sessions/<safeAppSessionUuid>/capture_bundles/<safeBundleUuid>_<filename>`. Видео сохраняются под `.../videos`.

### 8.2 Contract compatibility

| Android type | PHP принимает | Processing consumer |
|---|---|---|
| phone `VIDEO` / video scan | да | `EXTRACT_FRAMES` → COLMAP preview/HQ/dense/mesh; IMU/ToF sidecars stage-ятся |
| `synced_depth_frames` | да | `MAKLERTOUR_SYNCED_DENSE` |
| `stereo_video_legacy` | да | специализированный automatic consumer не найден |
| `auto_photo_session` | да | auto-photo indexing/preparation pipeline |
| `dual_phone_stereo_video` | **нет** | consumer не достижим/не найден |

PHP allowlist находится в `web/www/api/mobile.php:1066`; Android dual enqueue — `state/AppStateViewModel.kt:777`. Это прямое несовпадение, не предположение.

Upload queue обрабатывается ViewModel coroutine при подходящей сети, а не гарантированным persistent Worker/Service. Убийство процесса может отложить upload до следующего восстановления UI/runtime.

## 9. Known inconsistencies

### CRITICAL

1. **Dual bundle type отвергается сервером.** Android: `dual_phone_stereo_video`; PHP allowlist такого значения не содержит.
2. **Dual+ToF не является сохраняемым режимом.** UI/runtime capability может показывать ToF, но role/aggregate bundle не содержит ни frames, ни calibration. Название режима создаёт ложное ожидание metric reconstruction.

### HIGH

1. **USB+ToF не доходит до bundle/server.** USB stereo packagers сохраняют stereo data/calibration, но не ToF.
2. **Laptop live — отдельный протокол без единого обнаруженного phone session UUID.** Каждый `DualPhoneLaptopUplinkRuntime.start` генерирует UUID локально; общий pairing должен гарантировать laptop, иначе frames двух телефонов могут попасть в разные sessions.
3. **Нет server consumer для dual recorded aggregate.** Даже после исправления allowlist требуется unpack/validate/pair/process contract.
4. **Rotation semantics неоднородны.** MP4 presentation следует CameraX target rotation; raw analysis JPEG сохраняется unrotated; auto-photo делегирует orientation CameraX/EXIF; pipeline_93 дополнительно получил server `-90`. Поле `rotation` без указания слоя неоднозначно.
5. **Разные ToF transport semantics.** Standalone хранит raw grid+calibration; laptop отправляет registered CAMERA_A snapshot; phone-to-phone reduced transport registered snapshot не сериализует.

### MEDIUM

1. **Requested fps смешивается с actual fps.** MASTER/SLAVE могут получить разные фактические rates; pairing должен опираться только на sensor/encoder timestamps.
2. **IMU schemas отличаются.** Main video: 4 sensors continuous; USB legacy: 3; photo: snapshots; laptop: accel+gyro packets.
3. **Timeline origin отличается.** Standalone rebased к CameraX start; dual содержит ARM pre-roll/logical START mapping; USB legacy включает estimated timestamps.
4. **Bundle topologies различны.** multipart single, generic capture TGZ, nested dual TGZ и socket-only live требуют разных validators.
5. **Upload не принадлежит persistent Android worker.** ViewModel-driven queue слабее переживает process death.

### LOW

1. `MEDIA READY` визуально похоже на режим, хотя это transport state.
2. Глобальный selector смешивает topology (`PHONE_USB_STEREO`), processing intent (`STANDALONE_COLMAP`) и role (`MASTER/SLAVE`), поэтому UI nomenclature трудно сопоставить с файлами.
3. ToF подключён process-wide, что позволяет показывать статус на экранах, где capture writer его не потребляет.

## 10. Recommended cleanup plan

Рекомендации архитектурные; в рамках аудита изменения не выполнялись.

1. **Сначала зафиксировать единый versioned server contract.** Добавить canonical matrix `capture_type → archive layout → validator → processor`; согласовать Android/PHP dual type до следующего production capture.
2. **Разделить topology, capture product и capabilities.** Например: topology `SINGLE/DUAL/PHONE_USB/LAPTOP`, product `PHOTO/VIDEO/SYNCED_FRAMES/LIVE`, capabilities `IMU/TOF`. Это объяснит UI и исключит «dual+ToF», когда writer ToF не активен.
3. **Ввести общий `CaptureSessionDescriptor`.** Один UUID, role/slot, clock domain, camera identity, calibration ids и schema versions должны передаваться всем writers и network peers.
4. **Унифицировать orientation contract.** Отдельные поля: sensor orientation, display rotation, CameraX rotation degrees, pixels-rotation-applied, MP4 transform, server extraction rotation. Никогда не использовать одно безымянное `rotation`.
5. **Унифицировать timestamp contract.** Для каждого sample фиксировать source clock, raw timestamp, mapped session timestamp и uncertainty; pairing использовать actual PTS/sensor times, не requested fps.
6. **Сделать ToF writer явной частью session plan.** Если capability включена, preflight должен требовать raw ToF sidecar, frozen calibration и clock-sync report; иначе UI не должен обещать ToF capture.
7. **Добавить server-side dual processor contract.** Проверка обоих role TGZ, общего capture id, role uniqueness, clock history, MP4/PTS mapping, calibration и optional ToF до постановки job.
8. **Согласовать IMU schema.** Общая event envelope и явные optional sensors для video/USB/photo/live; документировать фактический rate из timestamps.
9. **Перенести upload queue в persistent mechanism.** WorkManager с idempotency key `app_bundle_uuid`, сохранив текущую Room queue как источник состояния.
10. **Добавить contract tests без hardware.** Android-produced manifest fixtures должны проходить PHP allowlist/validator; отдельные fixtures — single, USB, dual, dual+ToF и laptop session handshake.

## Source index

- Android entry/UI: `app/MaklerTour/app/src/main/java/com/example/maklertour/MainActivity.kt`
- State/upload queue: `.../state/AppStateViewModel.kt`
- Phone recorder/session: `.../data/phonecamera/PhoneCameraVideoRecorder.kt`, `PhoneCameraScanProvider.kt`
- IMU/ToF sidecars: `.../data/phonecamera/ImuRecorder.kt`, `TofCaptureSidecarRecorder.kt`
- Auto-photo: `.../data/phonecamera/AutoPhotoCaptureManager.kt`
- USB stereo: `.../data/phonecamera/StereoCaptureExperimental.kt`
- Generic packager: `.../data/capture/CaptureBundlePackager.kt`
- Dual control/package/live: `.../data/dualphone/DualPhoneControlManager.kt`, `DualPhoneBundleTransfer.kt`, `DualPhoneApplicationRuntime.kt`, `DualPhoneReducedFrameProducer.kt`, `DualPhoneLaptopUplinkRuntime.kt`
- ToF runtime/calibration: `.../data/tof/TofUsbRuntime.kt`, `TofFrameV1.kt`, `TofCameraExtrinsicsProfile.kt`
- Android HTTP: `.../auth/MobileUploadApi.kt`
- PHP contract/processing entry: `web/www/api/mobile.php`, `web/www/order.php`, `web/www/api/create_capture_bundle_dense_job.php`

