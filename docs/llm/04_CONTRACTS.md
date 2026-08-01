# insta3D / MaklerTour — контракты системы

> Файл: `docs/llm/04_CONTRACTS.md`
> Актуализация: 2026-07-15
> Статус: рабочий сводный контракт
> Назначение: зафиксировать границы между модулями, форматы данных, владельцев состояния, обязательные поля, ошибки и правила совместимости.

---

# 1. Назначение документа

Этот документ описывает контракты между основными частями системы:

```text
UI
↔ ViewModel
↔ Domain
↔ Camera implementations
↔ Room
↔ Android filesystem
↔ Upload API
↔ MySQL
↔ Server storage
↔ Processing jobs
↔ GrafikStation
↔ Processing artifacts
↔ Web viewer
```

Контракт определяет:

* производителя данных;
* потребителя данных;
* формат;
* обязательные поля;
* допустимые состояния;
* ошибки;
* владельца данных;
* правила изменения;
* обязательные проверки.

Изменение контракта только с одной стороны запрещено.

---

# 2. Статусы контрактов

| Статус         | Значение                                                                            |
| -------------- | ----------------------------------------------------------------------------------- |
| `STABLE`       | контракт используется рабочими сценариями и должен сохранять обратную совместимость |
| `MVP`          | контракт работает, но может расширяться                                             |
| `EXPERIMENTAL` | структура ещё развивается                                                           |
| `INTERNAL`     | внутренний контракт одного runtime-контура                                          |
| `CROSS-SYSTEM` | контракт пересекает Android, backend или GrafikStation                              |
| `CRITICAL`     | нарушение приводит к потере данных или неправильной обработке                       |

---

# 2.1. Исполняемый контракт Android stereo/capture

Нормативное описание Android stereo/capture contract:

```text
app/MaklerTour/docs/APP_CAMERA_STEREO_CONTRACT.md
```

Canonical static enforcement:

```text
app/MaklerTour/tools/stereo_contract_audit.py
```

Compatibility wrapper:

```text
app/MaklerTour/stereo_contract_audit.py
```

Запуск:

```bash
cd app/MaklerTour
python3 tools/stereo_contract_audit.py
```

Распределение ответственности:

```text
APP_CAMERA_STEREO_CONTRACT.md:
    определяет требуемые инварианты.

tools/stereo_contract_audit.py:
    статически проверяет выбранные инварианты
    и известные regression patterns.

runtime/device tests:
    подтверждают фактическую работу CameraX,
    USB UVC, timestamps, memory, calibration и depth.
```

Audit не является единственным источником истины и не заменяет runtime.

Если намеренно меняется contract:

1. сначала изменить нормативный документ;
2. изменить implementation;
3. изменить audit в той же contract-задаче;
4. выполнить audit;
5. выполнить необходимые runtime tests.

Запрещено ослаблять audit только для получения `PASS`.

---

# 3. Общие правила контрактов

## CONTRACT-RULE-001

Обязательное поле не должно заменяться фиктивным значением.

Неправильно:

```text
server_id = 0
file_path = ""
status = SUCCESS
```

если операция фактически не завершена.

## CONTRACT-RULE-002

Отсутствующее optional-поле должно передаваться как:

```text
null
```

или отсутствовать согласно документированному формату.

Нельзя использовать случайные строки:

```text
"unknown"
"none"
"0"
```

если они не являются частью enum-контракта.

## CONTRACT-RULE-003

Любой cross-system объект должен иметь стабильный идентификатор.

Примеры:

```text
order_id
capture_session_id
app_session_uuid
app_point_uuid
app_scan_uuid
app_bundle_uuid
job_id
```

## CONTRACT-RULE-004

Timestamp должен содержать указание временной шкалы или единицы измерения.

Примеры:

```text
created_at              ISO 8601 или DB datetime
timestamp_ns            monotonic sensor timestamp
timestamp_ms            epoch milliseconds
duration_sec            seconds
delta_ms                 milliseconds
```

## CONTRACT-RULE-005

Файл считается валидным только при выполнении минимальных условий:

```text
path существует
это regular file
size > 0
файл находится внутри разрешённого storage root
```

Для критических архивов и media дополнительно может требоваться checksum.

## CONTRACT-RULE-006

Статус `SUCCESS`, `CAPTURED`, `UPLOADED` или `CONFIRMED` должен означать фактическое завершение операции.

## CONTRACT-RULE-007

UI rotation, preview transformation и operator orientation не должны неявно менять raw-data contract.

## CONTRACT-RULE-008

При изменении JSON, multipart или DB-контракта необходимо обновить:

```text
producer
consumer
tests
documentation
migration/versioning
```

---

# 4. C01 — UI ↔ AppStateViewModel

## Статус

```text
INTERNAL
STABLE
```

## Производитель

```text
Jetpack Compose UI
```

## Потребитель

```text
AppStateViewModel
```

## Команды UI

Основные команды:

```text
selectSession
createSession
deleteSession
selectOrder
attachSessionToOrder

connectCamera
disconnectCamera
refreshCameraStatus
capturePoint

startVideoScan
stopVideoScan
startPhoneVideoScan
stopPhoneVideoScan

downloadVideoScan
enqueueUpload
processQueuedUploadsOnWifi
retry/reset/delete upload item
```

## Ответ ViewModel

UI получает:

```text
StateFlow<AppUiState>
```

## Обязательные поля `AppUiState`

```text
sessions
selectedSessionId
selectedSessionName
selectedSessionPointsCount
selectedSessionRooms
selectedSessionConnections
selectedSessionScanVideos
selectedOrder
cameraStatus
isCapturing
isRecordingScanVideo
videoScanUiState
uploadQueue
uploadError
```

## Инварианты

* UI не должен напрямую обращаться к DAO.
* UI не должен разбирать OSC JSON.
* UI не должен самостоятельно изменять Room entity.
* UI не должен самостоятельно формировать server storage path.
* Повторное действие должно блокироваться через state, если операция уже выполняется.
* UI не должен показывать успех до получения подтверждённого результата.

## Ошибки

Ошибки передаются как:

* структурированное состояние;
* понятное пользователю сообщение;
* подробный log для разработчика.

## Проверки

* recomposition не запускает повторную операцию;
* поворот экрана не создаёт duplicate capture;
* ошибка сохраняет управляемый UI state;
* active operation имеет явный progress/state.

---

# 5. C02 — `CameraProvider`

## Статус

```text
INTERNAL
STABLE
CRITICAL
```

## Производитель интерфейса

```text
Domain layer
```

## Реализации

```text
MockCameraProvider
Insta360OscProvider
```

Phone CameraX использует отдельный provider-контур и не обязан полностью реализовывать этот интерфейс.

## Базовый интерфейс

```kotlin
interface CameraProvider {
    suspend fun connect(): CameraStatus
    suspend fun disconnect(): CameraStatus
    suspend fun getStatus(): CameraStatus
    suspend fun capture(pointName: String): CapturePoint
    suspend fun startVideoScan(scanName: String): ScanVideo
    suspend fun stopVideoScan(): ScanVideo
    suspend fun listFiles(): List<CameraFile>
    suspend fun getPreview(fileId: String): PreviewResult
}
```

Фактическую сигнатуру необходимо сверять с текущим кодом.

## Инварианты

* provider возвращает domain object;
* необработанный OSC JSON не выходит в UI;
* transport exception преобразуется в контролируемую ошибку;
* успешный photo capture содержит file reference;
* успешный stop video содержит video file reference;
* повторный start во время recording не выполняется.

## `CameraStatus`

Минимально ожидаемые данные:

```text
connected
camera model
capture mode
recording state
battery, если доступна
storage, если доступно
error, если есть
```

## Ошибки

```text
camera unavailable
network unavailable
unsupported camera
mode switch failed
capture timeout
malformed response
file reference missing
```

---

# 6. C03 — Insta360 OSC transport

## Статус

```text
CROSS-SYSTEM
STABLE
CRITICAL
```

## Producer

```text
Insta360OscProvider / OscHttpClient
```

## Consumer

```text
Insta360 X4 OSC API
```

## Base URL

```text
http://192.168.42.1
```

## Execute endpoint

```text
POST /osc/commands/execute
```

## Status endpoint

```text
POST /osc/commands/status
```

## Обязательные headers

```http
Content-Type: application/json;charset=utf-8
Accept: application/json
X-XSRF-Protected: 1
```

## Проверка режима

Request:

```json
{
  "name": "camera.getOptions",
  "parameters": {
    "optionNames": [
      "captureMode",
      "_videoType",
      "_videoTypeSupport"
    ]
  }
}
```

## Video mode

Request:

```json
{
  "name": "camera.setOptions",
  "parameters": {
    "options": {
      "captureMode": "video",
      "_videoType": "normal"
    }
  }
}
```

После команды обязательно повторить `camera.getOptions`.

Успешное подтверждение:

```text
captureMode == video
_videoType == normal
```

## Photo mode

Request:

```json
{
  "name": "camera.setOptions",
  "parameters": {
    "options": {
      "captureMode": "image"
    }
  }
}
```

Успешное подтверждение:

```text
captureMode == image
```

## Photo capture

```json
{
  "name": "camera.takePicture"
}
```

Если возвращён статус:

```text
inProgress
```

необходимо выполнять polling по command ID.

## Video recording

Start:

```json
{
  "name": "camera.startCapture"
}
```

Stop:

```json
{
  "name": "camera.stopCapture"
}
```

Успешный stop должен содержать `.mp4` reference в:

```text
fileUrls
или
_localFileUrls
```

## Инварианты

* `/osc/state` не является единственным источником истины;
* stale response не подтверждает режим;
* state `done` не гарантирует, что неправильные option names реально изменили режим;
* transport success не равен capture success;
* camera file URL должен быть проверен отдельно.

---

# 7. C04 — `CapturePoint`

## Статус

```text
INTERNAL
MVP
```

## Producer

```text
CameraProvider.capture()
```

## Consumers

```text
AppStateViewModel
SessionRepository
Room
MobileUploadApi
Backend
```

## Обязательные поля

```text
id
sessionId или привязка при добавлении
name
sequenceNumber
status
createdAt
```

## Media fields

```text
cameraFileUrl
cameraLocalPath
localPreviewPath
localOriginalPath
```

## Server fields

```text
serverUploadState
serverMediaId
```

## Успешный capture

Минимальные условия:

```text
status != Failed
cameraFileUrl не пустой
id не пустой
```

## Инварианты

* failed capture не добавляется как успешный point;
* preview download может выполняться позже;
* отсутствие preview не делает сам capture неуспешным;
* original и preview не должны путаться;
* point принадлежит только одной session;
* server confirmation относится к тому же `app_point_uuid`.

---

# 8. C05 — `ScanVideo`

## Статус

```text
INTERNAL
STABLE
CRITICAL
```

## Producers

```text
Insta360OscProvider
PhoneCameraScanProvider
AppStateViewModel
```

## Consumers

```text
Room
UploadQueue
MobileUploadApi
Backend
SfM processing
```

## Identity

```text
id
sessionId
sequenceNumber
name
```

## Source

```text
INSTA360
PHONE_CAMERA
другие актуальные enum значения
```

## Role

```text
BACKBONE
DETAIL
```

## Capture state

```text
RECORDING
CAPTURED
FAILED
```

## UI state

```text
IDLE
SWITCHING_MODE
RECORDING
STOPPING
CAPTURED
FAILED
```

## Download state

```text
LOCAL_ONLY
DOWNLOADING
DOWNLOADED
DOWNLOAD_ERROR
```

Фактические enum names необходимо сверять с кодом.

## Upload state

```text
LOCAL_ONLY
QUEUED
UPLOADING
UPLOADED
CONFIRMED
UPLOAD_ERROR
```

## Media fields

```text
cameraFileUrl
cameraLocalFileUrl
localVideoPath
durationSec
fileSizeBytes
```

## Processing fields

```text
serverProcessingState
markerExpected
markerDetected
```

## Инварианты

* `CAPTURED` требует подтверждённого media reference;
* `PHONE_CAMERA + CAPTURED` требует существующего local MP4;
* `UPLOADED/CONFIRMED` нельзя выставлять до server success;
* один активный recording на session;
* stop должен завершить state machine;
* failed stop не оставляет `isRecording=true`.

---

# 9. C06 — Phone camera scan directory

## Статус

```text
INTERNAL
MVP
```

## Producer

```text
PhoneCameraScanProvider
PhoneCameraVideoRecorder
metadata writers
```

## Consumer

```text
MobileUploadApi
server upload
SfM pipeline
```

## Directory

```text
sessions/<sessionId>/phone_scans/<scanId>/
```

## Основной файл

```text
video.mp4
```

## Optional metadata

```text
camera_info.json
manifest.json
imu.jsonl
```

## Правила

* `video.mp4` обязателен для успешного phone scan;
* metadata optional;
* metadata должна относиться к тому же `scanId`;
* metadata не должна блокировать upload video при отсутствии;
* повреждённый metadata-файл должен логироваться;
* metadata не должна подменяться данными другой записи.

## `camera_info.json`

Ожидаемые категории:

```text
camera ID
lens
sensor/orientation information
resolution
fps
codec
device information
```

## `manifest.json`

Ожидаемые категории:

```text
session ID
scan ID
start/end timestamp
duration
video filename
camera source
orientation metadata
calibration metadata reference
```

## `imu.jsonl`

Одна строка — одна JSON-запись sensor sample.

Ожидаемые поля:

```text
timestamp_ns
sensor type
x
y
z
accuracy
```

---

# 10. C07 — Raw stereo frame

## Статус

```text
INTERNAL
EXPERIMENTAL
CRITICAL
```

## Producers

```text
cam0 CameraX
cam1 USB UVC native layer
```

## Consumers

```text
ring buffer
ChArUco detector
stereo calibration
synced depth capture
capture bundle
```

## Обязательные свойства

```text
camera identity: cam0 или cam1
timestampNs
width
height
pixel/image format
raw image data или immutable bitmap reference
rotationDegreesApplied = 0
```

## Инварианты

```text
saved frame не вращается
detector input не вращается вслед за UI
calibration input использует raw coordinate system
display preview не изменяет source frame
```

## Владение памятью

Для native UVC frame должно быть явно определено:

* кто владеет buffer;
* до какого момента buffer валиден;
* должен ли consumer копировать данные;
* в каком потоке освобождается память.

Асинхронный consumer не должен хранить pointer на buffer после завершения callback, если native contract этого не гарантирует.

## Ошибки

```text
null frame
zero size
unsupported format
incomplete JPEG/MJPEG
timestamp missing
dimension mismatch
buffer already released
```

---

# 11. C08 — Synced stereo pair

## Статус

```text
INTERNAL
EXPERIMENTAL
CRITICAL
```

## Producer

```text
StereoCaptureExperimentalManager
```

## Consumers

```text
stereo calibration
synced depth recording
manifest writer
capture bundle
```

## Структура

```text
cam0 frame
cam1 frame
cam0 timestampNs
cam1 timestampNs
deltaMs
pair index
pair timestamp
```

## Pair selection

Пара выбирается по ближайшему timestamp.

Текущее ограничение:

```text
stereoMaxDeltaMs = 30
```

## Pair timestamp

Рекомендуемый midpoint:

```text
(cam0.timestampNs + cam1.timestampNs) / 2
```

## Инварианты

* оба кадра raw;
* обе камеры соответствуют ожидаемым `cam0/cam1`;
* pair сохраняется только при допустимом delta;
* порядок файлов и metadata совпадает;
* calibration и depth используют реальные пары;
* независимые video streams не считаются synced pairs.

---

# 12. C09 — ChArUco stereo calibration

## Статус

```text
INTERNAL
EXPERIMENTAL
CRITICAL
```

## Producer

```text
Calibration detector
StereoCalibrationProcessor
```

## Consumers

```text
Capture bundle
Dense processor
Diagnostics
```

## Correspondence contract

```text
commonIds = cam0.ids ∩ cam1.ids
```

Corresponding points должны выбираться по ID.

Запрещено:

```text
cam0.points[index] ↔ cam1.points[index]
```

без проверки соответствующего ChArUco ID.

## Quality gates

Manual capture:

```text
minimum common IDs = 35
```

Auto capture:

```text
minimum common IDs = 38
```

Final calibration:

```text
minimum accepted stereo pairs = 10
```

## Calibration mode

```text
CALIB_FIX_INTRINSIC
```

при наличии рассчитанных intrinsics.

## Outlier filtering

* initial calibration;
* расчёт per-pair epipolar error;
* удаление допустимых outliers;
* повторный refit;
* повторный расчёт ошибок;
* максимум ограниченное число итераций;
* нельзя удалить пары ниже минимального количества.

Рабочий threshold:

```text
6.0 px
```

Фактическое значение необходимо сверять с кодом.

## Result fields

```text
success
initialPairCount
candidatePairCount
usedPairCount
rejectedPairs
commonIdsPerPair
initialRms
finalRms
initialEpipolarErrors
finalEpipolarErrors
outlierIterations
cameraMatrix0
distCoeffs0
cameraMatrix1
distCoeffs1
R
T
E
F
imageSize
```

## Инварианты

* calibration resolution соответствует captured frames;
* `T` имеет документированную единицу;
* result не успешен при недостатке pairs;
* высокая error не скрывается;
* rejected pair содержит причину.

---

# 13. C10 — IMU orientation metadata

## Статус

```text
INTERNAL
EXPERIMENTAL
```

## Producer

```text
DeviceOrientationTracker
ImuRecorder
```

## Consumers

```text
manifest writer
diagnostics
future processing analysis
```

## Required pair fields

```text
pair_orientation_timestamp_ns
physical_orientation
physical_orientation_source
physical_orientation_confidence
imu_orientation_stale
imu_sample_timestamp_ns
imu_sample_delta_ms
imu_gravity_x
imu_gravity_y
imu_gravity_z
config_orientation
display_rotation_degrees
```

## Allowed orientations

```text
portrait_upright
portrait_upside_down
landscape_left
landscape_right
face_up
face_down
unknown
```

## Root summary

```text
physical_orientation_counts
display_rotation_counts
config_orientation_counts
orientation_transition_count
first_pair_physical_orientation
last_pair_physical_orientation
```

## Инварианты

IMU orientation является только metadata.

Запрещено использовать IMU для:

* вращения raw images;
* calibration;
* rectification;
* выбора baseline axis;
* выбора disparity axis;
* изменения saved JPEG.

---

# 14. C11 — Domain ↔ Room

## Статус

```text
INTERNAL
STABLE
CRITICAL
```

## Producers

```text
Repositories
Room mappings
```

## Consumers

```text
AppStateViewModel
Room database
```

## Mapping chain

```text
Domain model
↔ Repository mapping
↔ Room entity
↔ DAO
↔ SQLite
```

## Основные mappings

```text
Session ↔ CaptureSessionEntity
CapturePoint ↔ CapturePointEntity
ScanVideo ↔ ScanVideoEntity
RoomDraft ↔ RoomEntity
TourDraftConnection ↔ TourDraftConnectionEntity
UploadItem ↔ UploadItemEntity
```

## Инварианты

* ID сохраняется без изменения;
* nullable state сохраняется однозначно;
* enum сериализуется стабильно;
* timestamps не теряют единицу/таймзону;
* file paths не меняются при read/write;
* server IDs сохраняются;
* upload state восстанавливается после restart.

## Schema change

Изменение entity требует:

```text
database version increment
migration
clean-install test
upgrade test
mapping test
```

## Interrupted states

После restart недопустимо бесконечное состояние:

```text
UPLOADING
DOWNLOADING
RECORDING
```

Оно должно быть сброшено в retryable/error state согласно логике модуля.

---

# 15. C12 — Safe JSON manifest IO

## Статус

```text
INTERNAL
CRITICAL
```

## Producer/consumer

Все Android и processing-компоненты, изменяющие manifest JSON.

## Read contract

JSON необходимо читать через safe helper.

Пустой, частично записанный или повреждённый файл:

* не должен вызывать uncaught exception;
* считается отсутствующим или invalid;
* логируется;
* восстанавливается только при наличии достаточных данных.

## Write contract

Критические JSON-файлы записываются атомарно:

```text
write <file>.tmp
flush
close
rename <file>.tmp → <file>
```

## Запрещено

* писать непосредственно в основной manifest во время длительной операции;
* читать файл, пока он ещё записывается;
* игнорировать результат rename;
* оставлять успешный state после неуспешной записи.

---

# 16. C13 — Upload queue item

## Статус

```text
INTERNAL
STABLE
CRITICAL
```

## Producer

```text
AppStateViewModel
CaptureBundlePackager
Session/upload use cases
```

## Consumers

```text
UploadQueueRepository
Room
MobileUploadApi
```

## Обязательные поля

```text
id
sessionId
orderId
uploadType
status
createdAt
updatedAt
retryCount
```

## Conditional fields

```text
serverCaptureSessionId
localFilePath
displayName
mimeType
uploadAppSessionUuid
appBundleUuid
captureType
bytesUploaded
bytesTotal
progressPercent
currentFileName
currentStep
error
```

## States

```text
QUEUED
UPLOADING
SUCCESS
ERROR
```

Фактические enum values сверяются с кодом.

## Инварианты

* `SUCCESS` требует server confirmation;
* отсутствующий обязательный файл переводит item в `ERROR`;
* retry увеличивает counter;
* interrupted `UPLOADING` сбрасывается;
* item связан с одной session и order;
* capture bundle item содержит существующий `.tgz`.

---

# 17. C14 — Android ↔ Backend: создание capture session

## Статус

```text
CROSS-SYSTEM
STABLE
CRITICAL
```

## Endpoint

```text
mobile.php?action=create_session
```

## Authentication

```http
Authorization: Bearer <token>
```

## Request fields

```text
order_id
app_session_uuid
```

## Response success

Минимально:

```json
{
  "ok": true,
  "capture_session_id": 123
}
```

Допустим временный fallback:

```json
{
  "ok": true,
  "session_id": 123
}
```

Но целевой canonical field:

```text
capture_session_id
```

## Response error

```json
{
  "ok": false,
  "error": "error_code"
}
```

## Инварианты

* `order_id > 0`;
* `app_session_uuid` стабилен;
* пользователь имеет доступ к order;
* повторный запрос не создаёт бесконтрольные duplicates;
* Android сохраняет returned server ID;
* значение `0` не является успешным ID.

---

# 18. C15 — Android ↔ Backend: photo point upload

## Статус

```text
CROSS-SYSTEM
MVP
CRITICAL
```

## Endpoint

```text
mobile.php?action=upload_photo_point
```

## Multipart fields

```text
order_id
capture_session_id
app_point_uuid
point_name
camera_file_url
camera_local_path
preview
original
```

## Media rules

Должен присутствовать минимум один файл:

```text
preview
или
original
```

## Response

```json
{
  "ok": true,
  "media_id": 123
}
```

Точное имя server ID необходимо сверить с текущим backend.

## Инварианты

* point принадлежит указанной capture session;
* order/session соответствуют друг другу;
* filename очищается;
* file сохраняется внутри storage root;
* DB record создаётся после успешной записи файла;
* Android сохраняет server media ID;
* повторная загрузка того же `app_point_uuid` идемпотентна или явно обрабатывается.

---

# 19. C16 — Android ↔ Backend: обычный video upload

## Статус

```text
CROSS-SYSTEM
STABLE
CRITICAL
```

## Endpoint

```text
mobile.php?action=upload_video_scan
```

## Обязательные multipart fields

```text
order_id
capture_session_id
app_scan_uuid
duration_sec
source
video
```

## Дополнительные fields

```text
local_camera_url
scan_role
role
camera_local_file_url
marker_expected
marker_detected
```

## Optional phone metadata parts

```text
camera_info
manifest
imu
```

## Video part

```text
name: video
media type: video/mp4
file size > 0
```

## Response

```json
{
  "ok": true
}
```

Дополнительные поля могут включать:

```text
video_scan_id
storage_path
upload_complete
```

## Инварианты

* `app_scan_uuid` стабилен;
* source соответствует фактическому источнику;
* `PHONE_CAMERA` использует существующий local MP4;
* metadata относится к тому же scan;
* server сохраняет правильный file size;
* DB state обновляется только после записи файла;
* Android не проверяет успех через простой поиск строки, если доступен корректный JSON parser.

---

# 20. C17 — Chunked video upload

## Статус

```text
CROSS-SYSTEM
MVP
CRITICAL
```

## Threshold

```text
200 MiB
```

## Chunk size

```text
8 MiB
```

## Max client retries

```text
3
```

## Request fields

```text
order_id
capture_session_id
app_scan_uuid
upload_id
chunk_index
total_chunks
chunk_size
total_size
source
video
```

Дополнительные video metadata совпадают с обычным upload.

## Индексация

```text
chunk_index: 0 .. total_chunks - 1
```

## Server temporary state

Сервер должен однозначно связывать chunks по:

```text
authenticated user
order_id
capture_session_id
upload_id
app_scan_uuid
```

## Intermediate response

```json
{
  "ok": true,
  "upload_complete": false
}
```

## Final response

```json
{
  "ok": true,
  "upload_complete": true
}
```

## Инварианты

* chunk size соответствует фактическим bytes;
* повторный chunk не повреждает файл;
* chunks не смешиваются между uploads;
* final assembly имеет `size == total_size`;
* последний chunk не означает success без server assembly;
* metadata может прикладываться на последнем chunk;
* temp files очищаются после success/error timeout.

## Ошибки

```text
bad_chunk_index
bad_total_chunks
size_mismatch
upload_not_found
assembly_failed
storage_error
unauthorized
```

---

# 21. C18 — Capture bundle upload

## Статус

```text
CROSS-SYSTEM
MVP
CRITICAL
```

## Endpoint

```text
mobile.php?action=upload_capture_bundle
```

## Multipart fields

```text
order_id
capture_session_id
upload_type = CAPTURE_BUNDLE
capture_type
app_bundle_uuid
capture_bundle
```

## File

```text
extension: .tgz
mime type: application/gzip
size > 0
```

## Supported capture type

Dense processing:

```text
synced_depth_frames
```

Legacy audit-only:

```text
stereo_video_legacy
```

## Response

```json
{
  "ok": true,
  "capture_bundle_id": 123
}
```

Фактические поля сверяются с backend.

## Инварианты

* bundle UUID уникален;
* archive полностью записан до queue;
* server хранит original archive;
* dense job нельзя создать для unsupported capture type;
* server path находится внутри session storage;
* Android не удаляет bundle до server confirmation.

---

# 22. C19 — Capture bundle internal structure

## Статус

```text
CROSS-SYSTEM
EXPERIMENTAL
CRITICAL
```

## Producer

```text
CaptureBundlePackager
```

## Consumer

```text
Backend audit
GrafikStation dense processor
```

## Минимальная структура

```text
bundle_manifest.json
capture/
    synced_depth_manifest.json
    pairs/
calibration/
    stereo_extrinsics.json
rig/
    active_rig_profile.json
```

## Pair files

Naming contract должен быть стабильным.

Рекомендуемый пример:

```text
capture/pairs/pair_000001_cam0.jpg
capture/pairs/pair_000001_cam1.jpg
capture/pairs/pair_000001.json
```

Фактическое naming необходимо сверять с текущим кодом.

## `bundle_manifest.json`

Минимальные категории:

```text
schema_version
app_bundle_uuid
capture_type
created_at
order/session identity
pair_count
capture manifest path
calibration path
rig profile path
file/checksum summary
```

## `synced_depth_manifest.json`

Минимальные категории:

```text
schema_version
session ID
capture ID
pair count
raw dimensions
rotation_degrees_applied = 0
stereoMaxDeltaMs
pairs[]
orientation summary
```

## `stereo_extrinsics.json`

Минимальные категории:

```text
schema_version
image size
camera matrices
distortion coefficients
R
T
E
F
RMS
used pair count
rejected pairs
```

## Инварианты

* archive relative paths;
* никаких absolute Android paths;
* raw JPG не перекодируются при packaging;
* `rotation_degrees_applied = 0`;
* calibration соответствует размеру кадров;
* bundle audit выполняется до dense processing;
* schema version присутствует или должна быть добавлена как отдельная задача.

---

# 23. C20 — Server storage path

## Статус

```text
CROSS-SYSTEM
STABLE
CRITICAL
```

## Canonical logical layout

```text
storage/orders/<orderId>/sessions/<sessionUuid>/
```

## Возможные подкаталоги

```text
photos/
videos/
capture_bundles/
sfm/
processing/
```

## Правила

* база хранит relative path;
* абсолютный root задаётся configuration/deployment;
* client absolute path не принимается;
* `realpath` должен оставаться внутри storage root;
* session directory очищается от опасных символов;
* worker использует тот же logical session identity;
* UI не строит путь вручную.

## Известный долг

В коде могут встречаться разные root:

```text
/home/makler/web/storage/
/home/storage/
```

До унификации необходимо документировать runtime-конфигурацию.

---

# 24. C21 — Processing job

## Статус

```text
CROSS-SYSTEM
STABLE
CRITICAL
```

## Producer

```text
HTTP API
Web UI
Backend service
```

## Consumer

```text
Local worker
Remote worker
GrafikStation
```

## Identity

```text
job_id
order_id
session_id
job_type
```

## States

```text
NOT_STARTED
QUEUED
PENDING
RUNNING
SUCCESS
FAILED
```

## Required metadata

```text
parameters_json
created_at
updated_at
error_text
warning_text
result reference
```

## Active states

```text
NOT_STARTED
QUEUED
PENDING
RUNNING
```

## Locking

Worker должен атомарно изменить:

```text
QUEUED/PENDING/NOT_STARTED → RUNNING
```

Только один worker получает job.

## Success contract

`SUCCESS` разрешён только когда:

* process exit code успешен;
* обязательные artifacts существуют;
* `result.json` валиден;
* job ID результата совпадает;
* outputs скопированы в server storage.

## Failure contract

При ошибке:

```text
status = FAILED
error_text заполнен
log сохранён
partial outputs не выдаются как final
```

---

# 25. C22 — Video SfM job parameters

## Статус

```text
CROSS-SYSTEM
MVP
```

## Job type

```text
SFM_VIDEO_PIPELINE
```

## Parameters

```json
{
  "video_path": "/resolved/server/path/video.mp4",
  "camera_type": "PHONE_VIDEO",
  "sfm_fps": 3.0,
  "keyframe_fps": 0.33,
  "frame_width": 1920,
  "marker_size_m": 0.16,
  "marker_family": "tag36h11"
}
```

## Supported camera types

```text
PHONE_VIDEO
INSTA360_DUAL_VIDEO
```

## Инварианты

* `video_path` разрешается сервером;
* файл должен находиться внутри storage root;
* source video существует;
* numeric parameters имеют допустимые limits;
* camera type известен;
* approximate camera profile помечается как approximate.

---

# 26. C23 — Remote processing job

## Статус

```text
CROSS-SYSTEM
MVP
CRITICAL
```

## Producer

```text
sfm_remote_worker.php
backend job manager
```

## Consumer

```text
GrafikStation runner
```

## Input package

Минимально:

```text
job ID
job type
parameters JSON
input files/archive
expected output contract
```

## Output directory

```text
output/job_<remote_job_id>/
```

## Required result

```text
result.json
```

## Инварианты

* output job ID совпадает с request;
* station не меняет order/session бизнес-состояние напрямую;
* сервер обновляет DB после получения результата;
* partial transfer не считается success;
* повторный запуск не смешивает старые artifacts;
* input/output directory изолирован по job ID.

---

# 27. C24 — Synced dense job

## Статус

```text
CROSS-SYSTEM
EXPERIMENTAL
CRITICAL
```

## Job type

```text
MAKLERTOUR_SYNCED_DENSE
```

## Parameters

```json
{
  "capture_bundle_id": 123,
  "capture_type": "synced_depth_frames",
  "max_pairs": 40,
  "num_disparities": 128,
  "block_size": 7
}
```

## Input

```text
validated capture bundle
```

## Processing contract

```text
unpack
validate
load calibration
load synced pairs
rectify
detect baseline axis
prepare disparity orientation
compute disparity
compute depth
write debug artifacts
```

## Baseline axis

После `stereoRectify`:

```text
abs(P2[0,3]) >= abs(P2[1,3])
→ horizontal baseline
→ disparity on X
```

Иначе:

```text
vertical baseline
```

## Vertical baseline

OpenCV block matcher ищет по X.

Поэтому обе rectified images:

```text
rotate identically by 90 degrees
```

только для disparity/depth processing.

Raw frames и calibration result не изменяются.

## Depth

Для rotated vertical disparity исходную `Q` нельзя использовать без адаптации.

Разрешён manual depth:

```text
Z = f * B / disparity
```

---

# 28. C25 — Processing `result.json`

## Статус

```text
CROSS-SYSTEM
MVP
CRITICAL
```

## Producer

```text
GrafikStation processing script
local processing worker
```

## Consumers

```text
Remote worker
Backend
Web UI
Diagnostics
```

## Минимальная структура

```json
{
  "ok": true,
  "job_id": 123,
  "job_type": "MAKLERTOUR_SYNCED_DENSE",
  "status": "SUCCESS",
  "created_at": "2026-07-15T12:00:00Z",
  "artifacts": [],
  "warnings": [],
  "errors": []
}
```

## Artifact entry

```json
{
  "type": "dense_depth_preview",
  "path": "dense/contact_dense_depth.jpg",
  "required": true,
  "size_bytes": 123456
}
```

## Failure structure

```json
{
  "ok": false,
  "job_id": 123,
  "job_type": "MAKLERTOUR_SYNCED_DENSE",
  "status": "FAILED",
  "artifacts": [],
  "warnings": [],
  "errors": [
    {
      "code": "CALIBRATION_MISSING",
      "message": "stereo_extrinsics.json not found"
    }
  ]
}
```

## Инварианты

* JSON валиден;
* paths relative;
* required artifact существует;
* job ID совпадает;
* `ok=true` соответствует `status=SUCCESS`;
* stack trace не должен быть единственным error representation;
* absolute secret/server paths не должны попадать в public response.

---

# 29. C26 — Dense artifacts

## Статус

```text
CROSS-SYSTEM
EXPERIMENTAL
```

## Required outputs

```text
dense/contact_dense_depth.jpg
dense/dense_depth_debug.json
dense/dense_depth_summary.csv
result.json
```

## Debug JSON fields

```text
rectified_baseline_axis
disparity_axis
depth_input_rotation
depth_method
q_valid_for_rotated_disparity
baseline_magnitude
focal_for_depth
num_disparities
block_size
min_disparity
valid_depth_ratio
```

## Инварианты

* preview относится к тому же job;
* debug JSON объясняет выбранную processing branch;
* valid depth ratio записывается числом;
* отсутствующий required output делает job failed;
* viewer не должен рассчитывать depth повторно.

---

# 30. C27 — Logging contract

## Статус

```text
INTERNAL
STABLE
```

## Для Android

Лог должен содержать context IDs:

```text
sessionId
scanId
pointId
uploadId
orderId
```

Лог не должен содержать:

```text
password
Bearer token
private secret
полный sensitive response без фильтрации
```

## Для processing step

Минимальный формат:

```text
START <step>
command/context
output
exit_code
elapsed
DONE <step>
```

или:

```text
FAILED <step>
error
exit_code
```

## Инварианты

* успех не логируется заранее;
* exception не подавляется без error state;
* soft failure явно маркируется `WARNING`;
* пользовательское сообщение и developer log разделяются.

---

# 31. C28 — Error response contract

## Статус

```text
CROSS-SYSTEM
STABLE
```

## Backend error

```json
{
  "ok": false,
  "error": "stable_error_code",
  "message": "optional human-readable message"
}
```

## HTTP codes

Рекомендуемые значения:

```text
400 invalid request
401 unauthenticated
403 forbidden
404 object not found
409 conflict/duplicate active state
413 file too large
422 invalid media/contract
500 internal error
503 processing dependency unavailable
```

## Правила

* Android должен проверять HTTP code и JSON;
* `200` с `ok=false` не должен считаться успехом;
* HTML error page не должна разбираться как успешный JSON;
* error code должен быть стабильнее текста сообщения.

---

# 32. C29 — Contract versioning

## Статус

```text
CROSS-SYSTEM
REQUIRED
```

Следующие форматы должны получить явную версию:

```text
capture bundle
synced_depth_manifest.json
stereo_extrinsics.json
result.json
phone scan manifest
mobile API contract
```

Рекомендуемое поле:

```json
{
  "schema_version": 1
}
```

## Правила совместимости

Minor-compatible изменение:

* добавление optional field;
* добавление нового artifact type;
* расширение enum только при safe fallback.

Breaking изменение:

* переименование field;
* изменение типа;
* удаление обязательного поля;
* изменение единицы измерения;
* изменение directory structure;
* изменение meaning существующего enum.

Breaking изменение требует:

```text
новая schema version
consumer fallback или migration
tests на старый формат
обновление документации
```

---

# 33. Порядок изменения контракта

Перед изменением необходимо заполнить:

```text
Contract ID:
Producer:
Consumer:
Current format:
Requested change:
Backward compatible:
Migration required:
Files on producer side:
Files on consumer side:
Tests:
Rollback:
```

## Пример

```text
Contract ID:
C16 — video upload

Requested change:
Добавить поле camera_model

Backward compatible:
Да, optional multipart field

Producer:
MobileUploadApi.kt

Consumer:
mobile.php

Storage:
video_scans.camera_model

Tests:
upload без поля
upload с полем
old APK compatibility
```

---

# 34. Запрещённые изменения

Запрещено:

* менять multipart field только в Android;
* менять Room enum без migration;
* менять единицу timestamp без версии;
* использовать UI-rotated bitmap как raw frame;
* переименовывать capture bundle path без processor update;
* считать job успешным без required artifacts;
* использовать `0` как успешный server ID;
* использовать absolute client path на сервере;
* смешивать chunks разных uploads;
* использовать старую `Q` после rotation disparity без адаптации;
* сохранять corrupted manifest как valid;
* менять storage root в одном worker без остальных consumers.

---

# 35. C30 — Android dual-phone MASTER ↔ SLAVE application ownership

## Статус

```text
INTERNAL
MVP
CRITICAL
```

## Producer

```text
MASTER navigation and DualPhoneApplicationRuntime
```

## Consumer

```text
SLAVE DualPhoneApplicationRuntime and DualPhoneSlaveWorkScreen
```

## Managed sections

После pairing следующие разделы MASTER считаются рабочими:

```text
sessions
orders
camera
draft
queue
```

`WORK_APP` является пассивным управляемым состоянием без LIVE/HYBRID transport.

SLAVE во всех рабочих разделах показывает заблокированный экран
`SLAVE · УПРАВЛЯЕТСЯ MASTER` и не предоставляет обычную нижнюю навигацию.

## Release section

```text
settings
```

Только переход MASTER в Settings отправляет `EXIT_WORK_MODE` и возвращает SLAVE
в локальный экран настроек. Pairing/control channel при этом сохраняется.

## Инварианты

* переход между рабочими разделами не освобождает SLAVE;
* переход между рабочими разделами не останавливает активный LIVE/HYBRID transport;
* кнопка `Выкл. LIVE` возвращает `WORK_LIVE/WORK_HYBRID → WORK_APP`, но не отправляет `EXIT_WORK_MODE`;
* состояние управления приложением не равно состоянию TCP/45831;
* ошибка/блокировка data channel отображается внутри управляемого экрана SLAVE;
* потеря control channel освобождает SLAVE безопасным образом;
* SLAVE сохраняет аварийную локальную кнопку отключения;
* camera-only условие `currentRoute != AppTab.Camera.route` запрещено как regression;
* запуск управляемого состояния должен находиться на уровне root navigation, а не
  внутри Compose-карточки Camera.

## Control messages

```text
ENTER_WORK_MODE
ENTER_WORK_MODE_ACK
EXIT_WORK_MODE
EXIT_WORK_MODE_ACK
```

## Contract checks

```text
web/tests/dual_phone_lm01a_navigation_ownership_test.php
```

---

# 35. Матрица cross-system контрактов

| Контракт                 | Producer              | Consumer         |        Риск |
| ------------------------ | --------------------- | ---------------- | ----------: |
| OSC command              | Android               | Insta360 X4      |     высокий |
| CameraProvider           | Camera implementation | ViewModel        |     высокий |
| Domain ↔ Room            | Repository            | SQLite/ViewModel | критический |
| Photo upload             | Android               | PHP backend      | критический |
| Video upload             | Android               | PHP backend      | критический |
| Chunk upload             | Android               | PHP backend      | критический |
| Capture bundle upload    | Android               | PHP backend      | критический |
| Capture bundle structure | Android               | GrafikStation    | критический |
| Processing job           | Backend               | Worker           | критический |
| Remote job               | Backend               | GrafikStation    | критический |
| `result.json`            | GrafikStation         | Backend/Web      | критический |
| Dense artifacts          | Processor             | Viewer           |     высокий |

---

# 36. Контекст для LLM при изменении контракта

LLM должна получить:

```text
docs/llm/00_PROJECT_OVERVIEW.md
docs/llm/01_REQUIREMENTS.md
docs/llm/02_ARCHITECTURE.md
docs/llm/03_MODULES.md
docs/llm/04_CONTRACTS.md
профильный contract
producer files
consumer files
model/entity/schema files
tests/audits
task file
```

LLM должна сначала ответить:

```text
Какой контракт меняется?
Почему текущий контракт недостаточен?
Изменение совместимо?
Какие стороны нужно изменить?
Какие проверки подтвердят результат?
```

---

# 37. Открытые контрактные вопросы

Требуют отдельной проверки по текущему коду и runtime:

* точные response fields `mobile.php`;
* полная schema `camera_info.json`;
* полная schema phone `manifest.json`;
* naming stereo pair files;
* единицы `T` в calibration;
* version capture bundle;
* version `result.json`;
* checksum policy;
* idempotency photo/video upload;
* chunk resume после restart;
* timeout remote jobs;
* canonical server storage root;
* canonical package namespace;
* окончательный список processing job types;
* обязательные artifacts каждого job type.

До подтверждения эти вопросы не должны закрываться предположениями.

---

# 38. Краткое резюме

```text
CameraProvider
защищает UI от camera implementation

Domain ↔ Room
защищает persistent local state

MobileUploadApi ↔ mobile.php
является главным Android/backend контрактом

Capture bundle
является главным Android/GrafikStation контрактом

Processing job
является главным backend/worker контрактом

result.json + artifacts
является главным processing/web контрактом

Raw stereo coordinates
являются критическим математическим инвариантом
```

## AUTO-B06 standalone dense preview contract

A valid completed standalone Auto Photo sparse component can create an independent `COLMAP_RECONSTRUCTION_PREVIEW` with `pipeline_run_id=NULL`, exact `merged/merged_fused.ply` output, server-resolved Preview settings and both dense-only markers. Chunks/retries inherit its settings. Both markers suppress automatic mesh; neither marker alone changes legacy behavior.

## C31 — Android dual-phone live pairing, rectification and diagnostic depth

### Producer

```text
DualPhoneReducedFrameProducer
DualPhoneReducedFrameTransport
DualPhoneLiveDepthProcessor
```

### Consumer

```text
DualPhoneMasterScanDialog
DualPhoneSlaveScanWorkspace
future LM03 tracking and room-geometry stages
```

### Invariants

* LIVE/HYBRID opens a separate full-screen MASTER workspace;
* the SLAVE preview occupies its managed full-screen surface;
* SLAVE does not receive local LIVE/HYBRID or STOP authority;
* full-screen UI does not create another CameraX producer or network socket;
* only real LM01B frames may enter pairing and depth;
* MASTER generates the authoritative `stream_id` for each LIVE/HYBRID start;
* `ENTER_WORK_MODE` carries that ID and SLAVE adopts it for both producers/transports;
* `stream_id` must match before pairing;
* SLAVE elapsed timestamps are converted to the MASTER clock domain;
* histories are bounded and old frames cannot accumulate;
* LM02.2 uses `StereoSGBM`, not the original raw `StereoBM` preview;
* disparity passes explicit range, texture and spatial morphology gates;
* temporal disparity history is bounded to five maps and reset on stream replacement;
* stable depth requires temporal agreement before publication;
* MASTER exposes separate RAW, FILTERED and CONF confidence views;
* confidence colors have fixed HIGH/MEDIUM/LOW/INVALID semantics;
* raw valid, filtered valid, stable coverage and depth jitter remain diagnostic metrics;
* accepted pair delta is at most 120 ms and `READY` requires at most 35 ms;
* rectification uses the accepted active calibration K/D/R/T/baseline;
* transported raw pixels are not UI-rotated before calibration math;
* vertical baseline rotation is applied only to the rectified disparity input;
* LM02 output is a diagnostic disparity/depth preview, not a completed room scan;
* minimizing the workspace does not stop LIVE/HYBRID;
* explicit STOP returns both phones to passive `WORK_APP`.

### Contract checks

```text
web/tests/dual_phone_lm02_fullscreen_depth_test.php
```

## C34 — Android fast reduced-frame producer and profile recovery

### Invariants

* one accepted reduced frame causes at most one JPEG encode;
* NV21 is reduced before JPEG compression when the CameraX source is oversized;
* no full-size JPEG-to-Bitmap-to-JPEG scaling path is allowed;
* OpenCV/JIT warm-up samples do not participate in adaptive-profile decisions;
* downgrade requires sustained slow p95 windows;
* a long stable window may promote BALANCED/THROTTLED after recovery;
* thermal state remains an immediate profile floor but is not permanently latched;
* final StereoSGBM buffers exactly match the active 480x270 or 320x240 profile;
* focal length is scaled along the final horizontal disparity axis;
* the last valid depth map remains published while the next pair is pending;
* LIVE/HYBRID ownership and future texture-video budget remain independent.

### Contract checks

```text
web/tests/dual_phone_lm02_fullscreen_depth_test.php
```

## C32 — Android dual-phone live display orientation and cadence

### Producer

```text
DualPhoneReducedFrameProducer
DualPhoneLiveDepthProcessor
```

### Consumer

```text
DualPhoneFullScreenScanWorkspace
future LM03 tracking/geometry stage
```

### Invariants

* reduced-frame producer target is 10 FPS with CameraX keep-only-latest;
* depth starts at most once per 250 ms and does not consume every media frame;
* pair and temporal histories remain bounded;
* `processing_rotation_degrees` describes only the disparity processing buffer;
* `display_rotation_degrees` is derived from MASTER frame display metadata;
* RECT, RAW, FILTERED and CONF use display rotation only in Compose drawing;
* raw JPEG pixels and accepted K/D/R/T remain unchanged;
* READY remains at most 35 ms and accepted pairs remain at most 120 ms;
* UI reports actual media/depth FPS, pair-quality ratio and utilization;
* higher cadence must not introduce an unbounded queue or weaken drop accounting.

### Contract checks

```text
web/tests/dual_phone_lm01b_reduced_frame_stream_test.php
web/tests/dual_phone_lm02_fullscreen_depth_test.php
```

## C33 — Android dual-phone motion-aware adaptive live depth

### Producer

```text
DualPhoneFilteredDepthEngine
DualPhoneDepthPerformanceController
DualPhoneLiveDepthProcessor
```

### Consumer

```text
DualPhoneFullScreenScanWorkspace
future LM03 point-cloud/trajectory stages
```

### Invariants

* media stays at 10 FPS while depth is independently budgeted;
* quality depth targets 480x270 at 5 FPS, with bounded fallback profiles;
* future full-resolution texture recording remains a separate owner and must keep
  CPU, camera and storage headroom;
* CLAHE normalizes MASTER/SLAVE grayscale input before matching only;
* quality/balanced profiles apply reverse disparity and left-right consistency;
* STATIC/MOVING/RESET temporal modes prevent stale same-pixel history during motion;
* all disparity, motion, timing and pair histories are finite and resettable;
* thermal protection may reduce or pause depth but may not release LIVE ownership,
  CameraX media, TCP/45831 or TCP/45832;
* one stream may downgrade but does not oscillate back to a higher profile;
* raw JPEG pixels and calibration K/D/R/T are never rewritten;
* UI reports profile, resolution, thermal state, motion, LR acceptance and p50/p95;
* LM02.4 remains diagnostic and does not claim trajectory, walls or a room model.

### Contract checks

```text
web/tests/dual_phone_lm02_fullscreen_depth_test.php
```
