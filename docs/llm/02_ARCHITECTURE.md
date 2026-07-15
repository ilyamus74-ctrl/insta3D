# insta3D / MaklerTour — архитектура системы

> Файл: `docs/llm/02_ARCHITECTURE.md`
> Актуализация: 2026-07-15
> Статус: описание фактической текущей архитектуры
> Назначение: зафиксировать компоненты, границы, зависимости, владельцев состояния и основные потоки выполнения.

---

# 1. Назначение документа

Документ описывает фактическую архитектуру проекта `insta3D / MaklerTour`.

Он отвечает на вопросы:

* из каких подсистем состоит проект;
* где находятся основные точки входа;
* какой компонент владеет состоянием;
* как данные проходят от камеры до серверной обработки;
* где проходят Android/backend/processing-контракты;
* какие зависимости допустимы;
* какие архитектурные узлы требуют будущего рефакторинга;
* какие инварианты нельзя нарушать.

Этот документ не является описанием идеальной целевой архитектуры.

Желаемые изменения должны отдельно фиксироваться в:

```text
docs/llm/09_REFACTORING_ROADMAP.md
docs/llm/decisions/
```

---

# 2. Общая системная схема

Система состоит из трёх исполняемых контуров:

```text
┌─────────────────────────────┐
│ Android / MaklerTour        │
│ Capture, local state, upload│
└──────────────┬──────────────┘
               │ HTTP / multipart / JSON
               ▼
┌─────────────────────────────┐
│ Web backend                 │
│ Auth, orders, storage, jobs │
└──────────────┬──────────────┘
               │ local job или remote job
               ▼
┌─────────────────────────────┐
│ GrafikStation               │
│ SfM, COLMAP, dense, GPU     │
└──────────────┬──────────────┘
               │ artifacts / result.json
               ▼
┌─────────────────────────────┐
│ Web UI / viewers / status   │
└─────────────────────────────┘
```

## 2.1 Android-контур

Android-контур выполняет:

* пользовательский интерфейс;
* авторизацию оператора;
* получение заявок;
* управление съёмочной сессией;
* управление Insta360;
* управление камерой телефона;
* управление USB UVC cam1;
* stereo capture;
* calibration;
* локальное хранение;
* упаковку capture bundle;
* upload;
* отображение статусов.

## 2.2 Backend-контур

Backend выполняет:

* проверку авторизации;
* управление заявками;
* создание server capture sessions;
* приём media и metadata;
* запись MySQL-состояния;
* хранение файлов;
* создание processing jobs;
* отображение результатов;
* передачу удалённых заданий на GrafikStation.

## 2.3 Processing-контур

Processing выполняет:

* извлечение кадров;
* построение keyframes;
* AprilTag detection;
* COLMAP reconstruction;
* экспорт sparse 3D;
* stereo rectification;
* disparity;
* dense depth;
* формирование artifacts;
* запись результата задания.

---

# 3. Deployment architecture

## 3.1 Android-устройство

На Android-устройстве находятся:

```text
MaklerTour APK
Room database
local session files
photo previews
downloaded originals
phone video scans
IMU metadata
stereo calibration data
synced stereo frame pairs
capture bundles
upload queue
```

Устройство может одновременно иметь несколько сетевых маршрутов:

* Wi-Fi камеры Insta360;
* Wi-Fi или mobile network для backend;
* USB host connection с UVC-камерой.

Camera network и backend network не должны считаться одним и тем же соединением.

## 3.2 Web server

На web server находятся:

```text
PHP application
MySQL
HTTP API
server-side storage
processing job tables
local PHP workers
remote-job coordinator
web viewers
processing status endpoints
```

Сервер является владельцем:

* серверных заявок;
* server capture session ID;
* upload registration;
* server storage paths;
* processing job state;
* опубликованных результатов.

## 3.3 GrafikStation

На GrafikStation находятся:

```text
Linux
NVIDIA GPU
Podman
COLMAP
ffmpeg
OpenCV/Python tools
remote_station scripts
temporary job input
processing output
```

GrafikStation не является источником бизнес-состояния заявки.

Она получает processing job, создаёт artifacts и возвращает результат серверу.

---

# 4. Архитектура Android-приложения

Текущую Android-архитектуру можно представить так:

```text
Compose UI
    ↓
MainActivity / composable screens
    ↓
AppStateViewModel
    ↓
domain interfaces
    ├── CameraProvider
    ├── SessionRepository
    ├── UploadQueueRepository
    └── processing/upload services
    ↓
data implementations
    ├── Insta360OscProvider
    ├── PhoneCameraScanProvider
    ├── RoomSessionRepository
    ├── RoomUploadQueueRepository
    ├── MobileUploadApi
    └── local file managers
```

---

# 5. Android composition root

Главная composition root находится в:

```text
app/MaklerTour/app/src/main/java/com/example/maklertour/MainActivity.kt
```

`MainActivity` и корневой `MaklerTourApp()` сейчас выполняют несколько ролей:

* Android lifecycle;
* запуск Compose;
* navigation;
* создание `AuthStorage`;
* создание API-клиентов;
* получение Room database;
* создание repositories;
* выбор camera provider;
* создание `AppStateViewModel`;
* регистрация network callback;
* управление языком;
* управление debug mode;
* camera/stereo/calibration UI;
* часть файловой orchestration.

Фактическая сборка зависимостей выглядит примерно так:

```text
MaklerTourApp
    ├── AuthStorage
    ├── MobileAuthApi
    ├── MobileOrdersApi
    ├── OrdersRepository
    ├── RoomDatabaseProvider
    ├── OscFileDownloader
    ├── Insta360OscProvider или MockCameraProvider
    ├── PhoneCameraScanProvider
    ├── RoomSessionRepository
    ├── RoomUploadQueueRepository
    ├── LocalOriginalManager
    ├── SyncRepository
    ├── MobileUploadApi
    └── AppStateViewModel
```

## 5.1 Архитектурный статус

`MainActivity.kt` является:

* composition root;
* navigation host;
* набором экранов;
* stereo/calibration controller;
* частичным application service.

Это подтверждённая зона высокой связанности.

Рефакторинг должен сначала выделить границы без изменения поведения.

---

# 6. Presentation layer

Presentation layer построен на Jetpack Compose.

Основные направления UI:

```text
Login
Orders
Sessions
Camera
Draft
Upload Queue
Settings
Calibration
Stereo Capture
Diagnostics
```

UI получает состояние через:

```text
AppStateViewModel.uiState: StateFlow<AppUiState>
```

UI должен:

* отображать состояние;
* передавать пользовательские команды;
* показывать progress и ошибки;
* не разбирать OSC response;
* не работать напрямую с DAO;
* не формировать серверные storage paths;
* не выполнять тяжёлые файловые операции.

В текущей реализации часть этих границ ещё находится внутри `MainActivity.kt`.

---

# 7. Application orchestration

Главный orchestration-компонент:

```text
AppStateViewModel
```

Он связывает:

* selected session;
* selected order;
* camera status;
* capture state;
* video recording state;
* sessions;
* rooms;
* points;
* connections;
* scan videos;
* upload queue;
* local files;
* server upload;
* diagnostic export.

## 7.1 Основное состояние

```text
AppUiState
    ├── sessions
    ├── selectedSessionId
    ├── selectedSessionName
    ├── selectedSessionPointsCount
    ├── selectedSessionRooms
    ├── selectedSessionConnections
    ├── selectedSessionScanVideos
    ├── selectedOrder
    ├── cameraStatus
    ├── isCapturing
    ├── isRecordingScanVideo
    ├── videoScanUiState
    ├── uploadQueue
    └── uploadError
```

## 7.2 Источники состояния

`AppUiState` формируется из нескольких `StateFlow`:

```text
SessionRepository.sessions
SessionRepository.rooms
SessionRepository.connections
SessionRepository.scanVideos
UploadQueueRepository.queue
cameraStatus
selectedSessionId
isCapturing
isRecordingScanVideo
videoScanUiState
selectedOrder
uploadError
```

## 7.3 Команды ViewModel

Основные группы команд:

```text
Session:
    createSession
    selectSession
    deleteSession
    attachSessionToOrder

Camera:
    connectCamera
    disconnectCamera
    refreshCameraStatus
    capturePoint

Video:
    startVideoScan
    stopVideoScan
    startPhoneVideoScan
    stopPhoneVideoScan
    downloadVideoScan

Draft:
    renamePoint
    deletePoint
    movePoint
    createRoom
    assignPointToRoom
    createConnection
    setStartPoint

Upload:
    enqueueUpload
    processQueuedUploadsOnWifi
    retry/reset/delete queue item

Diagnostics:
    exportDiagnosticJson
```

## 7.4 Архитектурный статус

`AppStateViewModel` фактически является application façade.

Он содержит слишком много сценариев для одного класса, но сейчас является центральной точкой координации.

Разделение должно идти по use case:

```text
CaptureUseCase
VideoScanUseCase
UploadUseCase
SessionUseCase
DraftUseCase
```

До выделения use cases нельзя одновременно менять его публичные команды и domain contracts.

---

# 8. Domain layer

Domain layer представлен моделями и интерфейсами.

Основные модели:

```text
Session
CapturePoint
ScanVideo
RoomDraft
TourDraftConnection
UploadItem
CameraStatus
```

Основные состояния:

```text
CaptureStatus
ScanVideoCaptureStatus
ScanVideoDownloadState
ScanVideoUploadState
ScanVideoProcessingState
UploadStatus
ServerUploadState
VideoScanUiState
ScanSource
ScanVideoRole
```

Основные интерфейсы:

```text
CameraProvider
SessionRepository
UploadQueueRepository
```

Domain layer должен:

* не зависеть от Compose;
* не зависеть от Room entity;
* не зависеть от необработанного HTTP JSON;
* не зависеть от конкретного camera transport;
* не содержать абсолютные server storage paths.

---

# 9. Camera abstraction

Основная граница камеры:

```text
CameraProvider
```

Предполагаемая зависимость:

```text
AppStateViewModel
    ↓
CameraProvider
    ├── MockCameraProvider
    └── Insta360OscProvider
```

Phone camera использует отдельный контур:

```text
AppStateViewModel
    ↓
PhoneCameraScanProvider
    ↓
CameraX / recorder / metadata
```

Stereo capture использует ещё один специализированный контур:

```text
MainActivity / stereo UI
    ↓
StereoCaptureExperimentalManager
    ├── cam0 CameraX
    ├── cam1 native UVC
    ├── timestamp pairing
    ├── calibration buffers
    └── manifest writer
```

## 9.1 Известная архитектурная особенность

В проекте существуют три camera-направления:

1. Insta360 OSC.
2. Phone CameraX.
3. Stereo cam0 + USB UVC cam1.

Они используют разные lifecycle, file format и state machine.

Их нельзя искусственно объединять в один универсальный класс без предварительного определения общего контракта.

---

# 10. Insta360 OSC architecture

OSC-контур:

```text
AppStateViewModel
    ↓
CameraProvider
    ↓
Insta360OscProvider
    ↓
Insta360CameraProfileResolver
    ↓
OscHttpClient
    ↓
Insta360 X4 HTTP OSC API
```

Дополнительный file-контур:

```text
camera file URL
    ↓
OscFileDownloader
    ↓
LocalOriginalManager
    ↓
Android filesystem
    ↓
Room path/state update
```

## 10.1 Ответственность компонентов

### `Insta360OscProvider`

* реализует camera-level операции;
* управляет режимами capture;
* преобразует OSC JSON в domain result;
* выполняет command polling;
* возвращает `CapturePoint` или `ScanVideo`.

### `OscHttpClient`

* формирует HTTP-запрос;
* выбирает camera network;
* задаёт OSC headers;
* отправляет JSON;
* возвращает transport response.

### Camera profile

* определяет особенности модели;
* задаёт команды и параметры;
* изолирует отличия X4 от других камер.

### `OscFileDownloader`

* скачивает camera media;
* пишет файл локально;
* сообщает local path или ошибку.

---

# 11. Phone camera architecture

Phone camera flow:

```text
Compose preview
    ↓
PreviewView
    ↓
PhoneCameraScanProvider
    ↓
PhoneCameraVideoRecorder
    ↓
CameraX
    ↓
video.mp4
```

Параллельные metadata-компоненты:

```text
PhoneCameraInfoCollector → camera_info.json
PhoneScanManifestWriter  → manifest.json
ImuRecorder              → imu.jsonl
DeviceOrientationTracker → orientation metadata
```

После завершения записи:

```text
local MP4 + metadata
    ↓
ScanVideo(source=PHONE_CAMERA)
    ↓
Room
    ↓
UploadQueue
    ↓
MobileUploadApi
```

---

# 12. Stereo capture architecture

Stereo capture использует:

```text
cam0 = CameraX phone camera
cam1 = native USB UVC camera
```

Путь кадров:

```text
cam0 frame callback ─┐
                     ├→ timestamp matching
cam1 frame callback ─┘
                     ↓
StereoCalibrationFramePair
                     ↓
ring buffer
                     ↓
calibration capture или synced depth capture
```

## 12.1 Coordinate systems

Существуют разные пространства:

```text
raw camera coordinates
detector coordinates
calibration coordinates
rectified coordinates
display coordinates
```

Display rotation не должна изменять raw/calibration data.

## 12.2 Calibration flow

```text
raw cam0 frames
    ↓
ChArUco detection
    ↓
cam0 intrinsics

raw cam1 frames
    ↓
ChArUco detection
    ↓
cam1 intrinsics

synced cam0/cam1 pairs
    ↓
common ChArUco IDs
    ↓
stereo extrinsics
    ↓
outlier filtering
    ↓
stereo_extrinsics.json
```

## 12.3 Synced depth capture

```text
timestamp-matched raw pairs
    ↓
pair files + pair metadata
    ↓
synced_depth_manifest.json
    ↓
CaptureBundlePackager
    ↓
capture bundle .tgz
```

---

# 13. Persistence architecture

Основное локальное хранилище:

```text
Room
```

Основные entities:

```text
CaptureSessionEntity
CapturePointEntity
RoomEntity
TourDraftConnectionEntity
ScanVideoEntity
UploadItemEntity
ObjectEntity
DiagnosticLogEntity
```

DAO layer:

```text
CaptureSessionDao
CapturePointDao
RoomDao
TourDraftConnectionDao
ScanVideoDao
UploadItemDao
ObjectDao
DiagnosticLogDao
```

Repository layer:

```text
RoomSessionRepository
RoomUploadQueueRepository
OrdersRepository
```

## 13.1 Dependency direction

Правильная зависимость:

```text
ViewModel
    ↓
Repository interface
    ↓
Room repository implementation
    ↓
DAO
    ↓
Entity
    ↓
SQLite
```

UI не должен обращаться к DAO напрямую.

## 13.2 Файлы и база

Room хранит metadata и пути.

Большие binary-файлы хранятся в filesystem:

```text
Room:
    ID
    state
    path
    size
    timestamps

Filesystem:
    JPEG
    MP4
    JSON
    JSONL
    TGZ
```

База и filesystem должны обновляться согласованно.

---

# 14. Upload architecture

Upload flow:

```text
Session / ScanVideo / CapturePoint / CaptureBundle
    ↓
UploadQueueRepository
    ↓
UploadItem
    ↓
AppStateViewModel.processUploadInternal()
    ↓
MobileUploadApi
    ↓
mobile.php
```

## 14.1 Типы upload

```text
photo point
video scan
phone video scan
capture bundle
metadata
```

## 14.2 Обычная загрузка video

```text
local video
    ↓
multipart request
    ↓
upload_video_scan
    ↓
server storage
    ↓
video_scans DB record
```

## 14.3 Chunked загрузка

```text
video > threshold
    ↓
split into logical chunks
    ↓
chunk 0..N
    ↓
server temporary upload
    ↓
final assembly
    ↓
upload_complete=true
```

Metadata phone scan добавляется при наличии:

```text
camera_info.json
manifest.json
imu.jsonl
```

## 14.4 Capture bundle upload

```text
bundle.tgz
    ↓
uploadCaptureBundle()
    ↓
mobile.php?action=upload_capture_bundle
    ↓
server capture_bundles storage
    ↓
capture_bundles DB record
```

---

# 15. Backend application architecture

Backend построен как PHP-приложение с:

```text
web pages
HTTP API endpoints
shared libraries
MySQL
filesystem storage
CLI workers
templates
JavaScript UI
```

Основные каталоги:

```text
web/www/api/      HTTP API
web/www/          web entry pages
web/libs/         shared PHP logic
web/templates/    UI templates
web/tools/        CLI workers and processing tools
```

## 15.1 Текущий API-стиль

Некоторые API реализованы отдельными endpoint-файлами:

```text
sfm_video_pipeline.php
sfm_remote_job_status.php
create_capture_bundle_dense_job.php
```

Mobile API концентрирует несколько действий через параметр:

```text
mobile.php?action=<action>
```

Это создаёт центральную границу Android/backend, но также делает `mobile.php` крупным связанным узлом.

---

# 16. Server data architecture

Основные классы серверных данных:

```text
users
tour_orders
capture_sessions
capture_points/media
video_scans
capture_bundles
processing_jobs
sfm_remote_jobs
video_sfm_runs
```

Иерархия бизнес-объектов:

```text
Order
    ↓
Capture Session
    ├── Photo Points
    ├── Video Scans
    ├── Capture Bundles
    └── Processing Jobs
```

Иерархия storage:

```text
storage/
└── orders/
    └── <orderId>/
        └── sessions/
            └── <sessionUuid>/
                ├── photos/
                ├── videos/
                ├── capture_bundles/
                ├── sfm/
                └── processing artifacts/
```

База хранит идентичность, состояние и путь.

Filesystem хранит binary data и artifacts.

---

# 17. Video SfM architecture

Создание задания:

```text
web UI/API
    ↓
sfm_video_pipeline.php
    ↓
processing_jobs
    ↓
status = QUEUED
```

Выполнение:

```text
process_sfm_video_jobs.php
    ↓
lock job
    ↓
status = RUNNING
```

Pipeline:

```text
source video
    ↓
ffmpeg frame extraction
    ↓
project keyframes
    ↓
camera_profile.json
    ↓
AprilTag observations
    ↓
COLMAP feature_extractor
    ↓
COLMAP sequential_matcher
    ↓
COLMAP mapper
    ↓
model_converter
    ↓
camera poses
    ↓
rough metric scale
    ↓
summary and sparse artifacts
```

## 17.1 Processing tools

```text
ffmpeg
COLMAP
sfm_tool
PHP finalize/export scripts
```

## 17.2 Outputs

Типичные outputs:

```text
frames/
keyframes/
viewer_keyframes/
markers/marker_observations.json
colmap/database.db
colmap/sparse/
trajectory/camera_poses.json
trajectory/trajectory_scaled.json
3d/
logs/
sfm_result_summary.json
```

---

# 18. Remote processing architecture

Remote processing используется для тяжёлых задач на GrafikStation.

Серверная сторона:

```text
sfm_remote_jobs
    ↓
sfm_remote_worker.php
    ↓
prepare input
    ↓
transfer job
```

GrafikStation:

```text
remote_station job runner
    ↓
processing script
    ↓
Podman / NVIDIA / COLMAP / OpenCV
    ↓
output/job_<id>/
    ↓
result.json
```

Возврат:

```text
GrafikStation artifacts
    ↓
server remote worker
    ↓
web/remote_station/output/
    ↓
DB status update
    ↓
web viewer
```

## 18.1 Ownership

Backend владеет:

* job ID;
* job status;
* order/session binding;
* окончательным server result state.

GrafikStation владеет только временным execution state и создаваемыми artifacts.

---

# 19. Synced dense architecture

Источник:

```text
Android capture bundle
```

Server flow:

```text
upload_capture_bundle
    ↓
capture_bundles
    ↓
MAKLERTOUR_SYNCED_DENSE remote job
    ↓
GrafikStation
```

GrafikStation flow:

```text
unpack bundle
    ↓
validate structure
    ↓
load raw pairs
    ↓
load stereo calibration
    ↓
rectify
    ↓
determine baseline axis
    ↓
prepare matcher input
    ↓
StereoBM/StereoSGBM
    ↓
depth computation
    ↓
debug artifacts
```

Ожидаемые результаты:

```text
dense/contact_dense_depth.jpg
dense/dense_depth_debug.json
dense/dense_depth_summary.csv
result.json
```

---

# 20. Viewer architecture

Web viewer не должен пересчитывать SfM или dense data.

Viewer получает подготовленные artifacts:

```text
JSON summaries
keyframe images
PLY/sparse models
depth previews
camera trajectory
processing status
```

Viewer отвечает за:

* отображение;
* выбор результата;
* показ статуса;
* диагностические таблицы;
* ссылки на artifacts.

Processing worker отвечает за подготовку данных viewer.

---

# 21. Состояния и владельцы

| Состояние                        | Владелец                              |
| -------------------------------- | ------------------------------------- |
| текущий экран                    | Compose/navigation                    |
| выбранная локальная сессия       | `AppStateViewModel`                   |
| локальные sessions/points/videos | Room repositories                     |
| локальный upload queue           | `UploadQueueRepository`               |
| camera runtime state             | camera provider + ViewModel           |
| raw media                        | Android filesystem или server storage |
| серверная заявка                 | backend/MySQL                         |
| server capture session           | backend/MySQL                         |
| processing job                   | backend/MySQL                         |
| remote execution                 | GrafikStation runner                  |
| final artifacts                  | backend storage                       |
| опубликованный результат         | backend/web layer                     |

Один вид состояния не должен иметь двух независимых источников истины.

---

# 22. Основные контрактные границы

## 22.1 UI → ViewModel

```text
user intent
state rendering
error rendering
```

## 22.2 ViewModel → CameraProvider

```text
connect
disconnect
status
capture
start video
stop video
```

## 22.3 ViewModel → Repository

```text
session operations
point operations
scan operations
queue operations
state flows
```

## 22.4 Android → Backend

```text
Bearer auth
multipart fields
JSON fields
IDs
upload result
error codes
```

## 22.5 Backend → GrafikStation

```text
job type
parameters_json
input package
storage paths
result.json
artifacts list
status
```

## 22.6 Capture bundle → Dense processor

```text
bundle directory structure
manifest fields
raw pair naming
calibration files
capture type
```

Любое изменение границы требует проверки обеих сторон.

---

# 23. Dependency rules

## 23.1 Разрешённые направления

```text
UI → ViewModel
ViewModel → domain interface
data implementation → domain model
repository → DAO
API client → HTTP transport
worker → processing tool
viewer → prepared artifact
```

## 23.2 Запрещённые направления

```text
DAO → Compose UI
domain → Android Activity
CameraProvider → web template
viewer → raw capture mutation
GrafikStation → direct Android state
UI → MySQL schema
Room entity → OSC transport
```

## 23.3 Cross-layer changes

При изменении модели данных необходимо проверить:

```text
domain
Room
JSON
multipart
backend
MySQL
worker
viewer
```

Но эти изменения не обязательно должны выполняться одним большим commit.

---

# 24. Namespace architecture debt

В Android-коде встречаются пространства имён:

```text
com.maklertour
com.example.maklertour
```

Это создаёт дополнительную связанность импортов и усложняет навигацию.

До унификации необходимо:

1. составить список обоих namespace;
2. проверить `namespace` и `applicationId`;
3. проверить Room class names;
4. проверить manifest;
5. проверить instrumentation tests;
6. проверить ProGuard/R8;
7. выполнить переименование отдельным этапом.

Namespace нельзя массово менять внутри другого рефакторинга.

---

# 25. Текущие архитектурные hotspots

## 25.1 `MainActivity.kt`

Причины риска:

* большой объём UI;
* composition root;
* camera lifecycle;
* stereo logic;
* calibration logic;
* navigation;
* dependency creation.

## 25.2 `AppStateViewModel.kt`

Причины риска:

* множество use cases;
* camera orchestration;
* session orchestration;
* upload implementation;
* filesystem decisions;
* server upload state.

## 25.3 `Repositories.kt`

Причины риска:

* interfaces;
* in-memory implementations;
* SharedPreferences implementation;
* Room implementations;
* mappings;
* большой объём кода в одном файле.

## 25.4 `mobile.php`

Причины риска:

* множество actions;
* auth;
* validation;
* upload;
* chunk assembly;
* storage;
* database updates.

## 25.5 Processing scripts

Причины риска:

* shell command composition;
* абсолютные paths;
* DB state transitions;
* external process errors;
* artifacts validation.

---

# 26. Архитектурные инварианты

Следующие правила должны сохраняться при рефакторинге:

1. Raw stereo frames остаются без display rotation.
2. Camera mode подтверждается реальным ответом камеры.
3. Android не выполняет тяжёлую reconstruction.
4. Room является источником локального persistent state.
5. MySQL является источником server business state.
6. Filesystem хранит binary data, база хранит identity и state.
7. Processing job не становится успешным без обязательных artifacts.
8. Android/backend contract изменяется согласованно.
9. Capture bundle version и structure должны быть проверяемыми.
10. GrafikStation не должна самостоятельно менять order/session business state.
11. UI не должен напрямую управлять DAO или MySQL.
12. Backup-файлы не являются частью runtime architecture.

---

# 27. Стратегия безопасного рефакторинга

Рекомендуемый порядок:

```text
1. Зафиксировать baseline build и runtime
2. Добавить audits/tests
3. Выделить contracts
4. Выделить небольшие pure helpers
5. Выделить use cases
6. Разделить repositories
7. Разделить UI-файлы
8. Разделить backend actions
9. Уменьшить абсолютные paths/config debt
10. Оптимизировать processing
```

Нельзя начинать с полного переписывания `MainActivity` или `AppStateViewModel`.

---

# 28. Архитектурная карта файлов

```text
Android entry:
    MainActivity.kt

Application state:
    state/AppStateViewModel.kt

Domain:
    domain/

Camera:
    data/camera/
    data/camera/osc/
    data/camera/osc/profile/

Phone/stereo:
    data/phonecamera/
    data/calibration/
    data/rig/
    src/main/cpp/

Persistence:
    data/local/
    data/repository/Repositories.kt

Upload:
    auth/MobileUploadApi.kt
    data/sync/
    state/AppStateViewModel.kt

Backend:
    web/www/api/mobile.php
    web/www/api/

Local processing:
    web/tools/process_sfm_video_jobs.php
    web/tools/sfm_cpp/

Remote processing:
    web/tools/sfm_remote_worker.php
    web/remote_station/

Web result:
    web/www/
    web/templates/
    web/www/js/
```

---

# 29. Как LLM должна использовать архитектуру

Перед изменением LLM должна определить:

```text
1. Какой runtime-контур затронут?
2. Кто владеет изменяемым состоянием?
3. Какая контрактная граница пересекается?
4. Какие файлы находятся по обе стороны границы?
5. Какие tests/audits подтверждают сохранение поведения?
```

Для задачи необходимо добавлять в контекст:

* этот архитектурный файл;
* профильный контракт;
* task-файл;
* непосредственно изменяемые исходники;
* ближайшие interface/model files;
* build/test instructions.

Не следует добавлять весь репозиторий без необходимости.

---

# 30. Краткое резюме

```text
Android:
    capture + local state + packaging + upload

Backend:
    auth + orders + storage + job control + web UI

GrafikStation:
    heavy processing + artifacts

Главная Android orchestration:
    MainActivity + AppStateViewModel

Главная persistence boundary:
    Repository interfaces + Room

Главная Android/backend boundary:
    MobileUploadApi.kt ↔ mobile.php

Главная stereo/backend boundary:
    capture bundle

Главная processing boundary:
    job parameters ↔ result.json/artifacts
```
