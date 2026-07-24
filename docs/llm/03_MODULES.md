# insta3D / MaklerTour — карта модулей

> Файл: `docs/llm/03_MODULES.md`
> Актуализация: 2026-07-15
> Статус: рабочая карта фактических модулей
> Назначение: помочь разработчикам, ChatGPT, Codex, Aider и локальным LLM быстро определить нужную подсистему, связанные файлы, зависимости, контракты и проверки.

---

# 1. Назначение документа

Документ делит проект `insta3D / MaklerTour` на логические модули.

Для каждого модуля указываются:

* ответственность;
* основные файлы;
* входные данные;
* выходные данные;
* зависимости;
* связанные контракты;
* архитектурные риски;
* обязательные проверки.

Этот документ используется до начала изменения кода.

LLM должна сначала определить затронутые модули, а затем добавлять в контекст только относящиеся к задаче файлы.

---

# 2. Классификация модулей

Используются следующие статусы.

| Статус         | Значение                                                |
| -------------- | ------------------------------------------------------- |
| `CORE`         | основная рабочая часть системы                          |
| `MVP`          | рабочая реализация с известными ограничениями           |
| `EXPERIMENTAL` | экспериментальная функция, контракт ещё стабилизируется |
| `SUPPORT`      | вспомогательные инструменты, аудит, диагностика         |
| `LEGACY`       | старый сценарий, который пока нельзя удалить            |
| `DEBT`         | зона технического долга                                 |
| `GENERATED`    | сгенерированные файлы, не являющиеся исходным кодом     |

Статус модуля не определяет его качество автоматически.

Например, `CORE` может иметь высокий технический долг, а `EXPERIMENTAL` может содержать важные данные, которые нельзя потерять.

---

# 3. Общая карта модулей

```text
insta3D / MaklerTour
│
├── Android
│   ├── A01 Bootstrap and UI
│   ├── A02 Authentication and Orders
│   ├── A03 Application State
│   ├── A04 Domain Models and Interfaces
│   ├── A05 Room Persistence
│   ├── A06 Insta360 OSC
│   ├── A07 Phone Camera
│   ├── A08 Stereo Capture and USB UVC
│   ├── A09 Calibration and Rig
│   ├── A10 Local Media and Sync
│   ├── A11 Upload Queue and Mobile Upload
│   ├── A12 Capture Bundle
│   └── A13 Diagnostics, Settings and i18n
│
├── Web Backend
│   ├── B01 Bootstrap, Auth and Access Control
│   ├── B02 Orders and Capture Sessions
│   ├── B03 Mobile Upload API
│   ├── B04 Server Storage
│   ├── B05 Web UI and Viewers
│   └── B06 Processing Job Management
│
├── Processing
│   ├── P01 Local Video SfM Pipeline
│   ├── P02 C++ sfm_tool
│   ├── P03 Remote Job Coordinator
│   ├── P04 GrafikStation Runtime
│   ├── P05 Synced Dense Pipeline
│   └── P06 Processing Artifacts
│
└── Project Support
    ├── S01 Contracts and Documentation
    ├── S02 Audits and Validation
    └── S03 Build, Deployment and Diagnostics
```

---

# 4. Android-модули

# A01 — Android Bootstrap and UI

## Статус

```text
CORE
DEBT
```

## Ответственность

Модуль отвечает за:

* Android lifecycle;
* запуск Jetpack Compose;
* корневую навигацию;
* создание runtime-зависимостей;
* инициализацию ViewModel;
* экраны приложения;
* отображение состояний;
* обработку пользовательских действий;
* camera preview;
* stereo/calibration UI;
* регистрацию части lifecycle callbacks.

## Основные файлы

```text
app/MaklerTour/app/src/main/java/com/example/maklertour/MainActivity.kt
app/MaklerTour/app/src/main/AndroidManifest.xml
app/MaklerTour/app/src/main/res/
app/MaklerTour/app/src/main/java/com/example/maklertour/ui/
app/MaklerTour/app/src/main/java/com/maklertour/ui/
```

Из-за смешанных namespace необходимо проверять оба пути:

```text
com.example.maklertour
com.maklertour
```

## Входы

* Android lifecycle events;
* пользовательские действия;
* `AppUiState`;
* camera preview frames;
* calibration state;
* network state;
* authentication state.

## Выходы

* вызовы ViewModel;
* navigation events;
* camera binding;
* отображение progress;
* отображение ошибок;
* запуск диалогов и capture controls.

## Зависимости

```text
A01 → A02 Authentication
A01 → A03 Application State
A01 → A05 Room Persistence
A01 → A06 Insta360 OSC
A01 → A07 Phone Camera
A01 → A08 Stereo Capture
A01 → A09 Calibration
A01 → A13 Settings and i18n
```

## Связанные контракты

```text
docs/llm/01_REQUIREMENTS.md
docs/llm/02_ARCHITECTURE.md
app/MaklerTour/docs/APP_CAMERA_STEREO_CONTRACT.md
```

## Риски

* очень большой `MainActivity.kt`;
* UI смешан с composition root;
* UI смешан с camera lifecycle;
* UI содержит calibration orchestration;
* высокая вероятность случайной регрессии preview;
* изменение одного экрана может затронуть runtime dependency creation;
* backup-копии `MainActivity.kt.before_*` мешают поиску LLM.

## Обязательные проверки

```bash
cd app/MaklerTour
./gradlew :app:assembleDebug
python3 tools/stereo_contract_audit.py
```

Дополнительно:

```bash
adb install -r app/build/outputs/apk/debug/app-debug.apk
adb shell monkey -p com.maklertour 1
```

---

# A02 — Authentication and Orders

## Статус

```text
CORE
MVP
```

## Ответственность

Модуль отвечает за:

* хранение authentication token;
* login;
* проверку активной сессии;
* logout;
* получение списка заявок;
* выбор заявки;
* привязку локальной capture session к server order;
* проверку доступности заявки оператору.

## Основные файлы

```text
app/MaklerTour/app/src/main/java/com/example/maklertour/auth/AuthStorage.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/auth/MobileAuthApi.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/auth/MobileOrdersApi.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/data/repository/OrdersRepository.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/network/ApiConfig.kt
```

Backend-side зависимости:

```text
web/www/api/mobile.php
web bootstrap/auth helpers
tour_orders
capture_sessions
```

## Входы

* login/password;
* Bearer token;
* server API response;
* order selection;
* local session ID.

## Выходы

* сохранённый token;
* список `MobileOrder`;
* selected order;
* server order ID;
* ошибка authentication;
* результат привязки session к order.

## Зависимости

```text
A02 → HTTP API
A02 → A03 Application State
A02 → A05 Room Persistence
A02 ↔ B01 Backend Auth
A02 ↔ B02 Orders
```

## Контрактные поля

```text
Authorization: Bearer <token>
order_id
operator_id
status
capture_session_id
app_session_uuid
```

## Риски

* token может попасть в лог;
* локальная session может быть привязана к неправильной заявке;
* закрытая заявка может принять новый upload;
* namespace `com.example.maklertour` отличается от основного namespace;
* server order state и local selected order могут расходиться.

## Обязательные проверки

* успешный login;
* неправильный пароль;
* истёкший token;
* logout;
* загрузка списка заявок;
* привязка session;
* перезапуск приложения после привязки;
* отказ при доступе к чужой заявке.

---

# A03 — Application State

## Статус

```text
CORE
DEBT
```

## Ответственность

Центральный модуль orchestration Android-приложения.

Он отвечает за:

* объединение StateFlow;
* selected session;
* selected order;
* camera status;
* photo capture;
* video scan;
* phone camera scan;
* Room state;
* draft state;
* upload queue;
* upload processing;
* server upload state;
* diagnostics.

## Основные файлы

```text
app/MaklerTour/app/src/main/java/com/example/maklertour/state/AppStateViewModel.kt
```

Связанные модели:

```text
AppUiState
EnqueueUploadResult
VideoScanUiState
```

## Входы

* действия UI;
* repository flows;
* camera provider results;
* network results;
* filesystem state;
* server API results.

## Выходы

* `StateFlow<AppUiState>`;
* изменения Room;
* camera commands;
* upload commands;
* diagnostic JSON;
* error states.

## Зависимости

```text
A03 → A04 Domain
A03 → A05 Persistence
A03 → A06 Insta360
A03 → A07 Phone Camera
A03 → A10 Local Media
A03 → A11 Upload
A03 → A12 Capture Bundle
```

## Основные группы функций

```text
session management
camera connection
photo capture
video scan
phone video scan
draft management
upload queue
upload execution
diagnostics
```

## Риски

* один класс содержит слишком много use cases;
* state transitions могут расходиться;
* filesystem logic находится рядом с UI state;
* upload implementation встроена в ViewModel;
* высокая стоимость контекста для LLM;
* сложно тестировать отдельные сценарии;
* большое изменение может нарушить несколько независимых flows.

## Правило изменения

Нельзя одновременно:

* разделять ViewModel;
* менять domain models;
* менять Room schema;
* менять upload contract;
* добавлять новую функцию.

Сначала фиксируется текущий use case, затем выполняется отдельное выделение.

## Обязательные проверки

* compile;
* создание и выбор session;
* photo capture;
* start/stop video;
* upload queue;
* retry;
* перезапуск приложения;
* восстановление state.

---

# A04 — Domain Models and Interfaces

## Статус

```text
CORE
```

## Ответственность

Модуль определяет бизнес-модели и интерфейсы между слоями.

## Основные каталоги

```text
app/MaklerTour/app/src/main/java/com/example/maklertour/domain/
app/MaklerTour/app/src/main/java/com/maklertour/domain/
```

## Основные модели

```text
Session
CapturePoint
ScanVideo
RoomDraft
TourDraftConnection
UploadItem
CameraStatus
CameraFile
PreviewResult
```

## Основные enum/state модели

```text
CaptureStatus
UploadStatus
ServerUploadState
ScanVideoCaptureStatus
ScanVideoDownloadState
ScanVideoUploadState
ScanVideoProcessingState
VideoScanUiState
ScanSource
ScanVideoRole
```

## Основные интерфейсы

```text
CameraProvider
SessionRepository
UploadQueueRepository
```

## Входы

Domain-модуль не должен принимать Android UI types, Room entities или необработанный HTTP JSON.

## Выходы

* domain objects;
* interface contracts;
* enum states;
* результаты операций.

## Зависимости

Желаемое направление:

```text
A04 не зависит от UI
A04 не зависит от Room
A04 не зависит от PHP API
A04 не зависит от OSC JSON
```

Остальные Android-модули могут зависеть от A04.

## Риски

* добавление поля требует изменений в Room, mappings, JSON и backend;
* разные enum могут описывать похожие состояния;
* nullable-поля могут скрывать незавершённый state;
* namespace split может приводить к дублированию моделей.

## Обязательные проверки

При изменении модели проверить:

```text
domain class
Room entity
DAO
repository mapping
JSON
multipart fields
backend
database schema
viewer
```

---

# A05 — Room Persistence

## Статус

```text
CORE
DEBT
```

## Ответственность

Модуль отвечает за постоянное локальное состояние Android.

## Основные файлы и каталоги

```text
app/MaklerTour/app/src/main/java/com/maklertour/data/local/AppDatabase.kt
app/MaklerTour/app/src/main/java/com/maklertour/data/local/
app/MaklerTour/app/src/main/java/com/maklertour/data/local/entity/
app/MaklerTour/app/src/main/java/com/maklertour/data/local/dao/
app/MaklerTour/app/src/main/java/com/example/maklertour/data/repository/Repositories.kt
```

## Основные entities

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

## Основные DAO

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

## Repository implementations

```text
RoomSessionRepository
RoomUploadQueueRepository
InMemorySessionRepository
InMemoryUploadQueueRepository
SharedPrefsSessionRepository
```

## Входы

* domain objects;
* create/update/delete commands;
* Room queries;
* local database version.

## Выходы

* `StateFlow`;
* восстановленные domain models;
* persistent state;
* IDs и paths.

## Зависимости

```text
A03 → repository interfaces
repository implementation → DAO
DAO → Room entities
Room → SQLite
```

## Риски

* `Repositories.kt` слишком крупный;
* в одном файле смешаны interfaces и несколько implementations;
* часть in-memory implementation содержит no-op методы;
* Room migration может отсутствовать;
* filesystem path может указывать на уже удалённый файл;
* state базы и состояние файла могут расходиться.

## Обязательные проверки

* чистая установка;
* запуск со старой базой;
* migration;
* создание session;
* запись point/video;
* перезапуск;
* удаление;
* interrupted upload reset;
* Room schema validation.

---

# A06 — Insta360 OSC

## Статус

```text
CORE
MVP
```

## Ответственность

Модуль управляет Insta360 X4 по OSC/HTTP.

## Основные файлы

```text
app/MaklerTour/app/src/main/java/com/example/maklertour/data/camera/Insta360Provider.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/data/camera/Insta360OscProvider.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/data/camera/MockCameraProvider.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/data/camera/osc/OscHttpClient.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/data/camera/osc/OscFileDownloader.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/data/camera/osc/profile/Insta360CameraProfile.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/data/camera/osc/profile/Insta360X4OscProfile.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/data/camera/osc/profile/Insta360CameraProfileResolver.kt
```

## Входы

* connect/disconnect;
* capture photo;
* start video;
* stop video;
* OSC command JSON;
* camera network.

## Выходы

* `CameraStatus`;
* `CapturePoint`;
* `ScanVideo`;
* camera file URL;
* command status;
* transport error.

## Внешняя зависимость

```text
Insta360 X4
http://192.168.42.1
OSC commands/execute
OSC commands/status
```

## Основные связи

```text
AppStateViewModel
→ CameraProvider
→ Insta360OscProvider
→ CameraProfile
→ OscHttpClient
→ Insta360 X4
```

## Критические контракты

* обязательные OSC headers;
* подтверждение mode через `camera.getOptions`;
* video mode требует `captureMode=video` и `_videoType=normal`;
* photo mode требует `captureMode=image`;
* `inProgress` требует polling;
* `stopCapture` должен вернуть file URL;
* stale `/osc/state` не является достаточным подтверждением.

## Риски

* phone может быть одновременно подключён к camera Wi-Fi и backend network;
* stale camera response;
* mode может фактически не измениться;
* timeout;
* malformed JSON;
* файл может отсутствовать после формально успешной команды;
* network binding может отправить OSC-запрос не через camera Wi-Fi.

## Обязательные проверки

См.:

```text
CAMERA_OSC_X4.md
TESTING.md
```

Проверить:

* getOptions;
* switch photo;
* takePicture;
* status polling;
* switch video;
* startCapture;
* stopCapture;
* file URL;
* camera offline;
* повторный start.

---

# A07 — Phone Camera

## Статус

```text
MVP
CORE-CONTRACT
```

## Ответственность

Модуль отвечает за съёмку встроенной камерой Android-устройства:

- phone video scan;
- Auto Photo capture;
- CameraX preview, analysis and image capture;
- lens and zoom selection;
- IMU/orientation metadata;
- Auto Photo quality and movement diagnostics;
- compatible Auto Photo bundle inputs.

## Основные файлы

```text
app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/PhoneCameraScanProvider.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/PhoneCameraVideoRecorder.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/AutoPhotoCaptureManager.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/AutoPhotoMovementTracker.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/PhoneCameraLens.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/PhoneCameraLensRepository.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/PhoneCameraInfoCollector.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/PhoneDualCameraProbe.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/PhoneScanManifestWriter.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/PhoneScanCalibrationMetadata.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/ImuRecorder.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/DeviceOrientationTracker.kt
app/MaklerTour/docs/AUTO_PHOTO_MOVEMENT_CONTRACT.md
```

`AutoPhotoMovementTracker.kt` is introduced by `APP-AUTO-M01`; before that task
it may not exist.

## Входы

* CameraX lifecycle;
* preview and `ImageAnalysis` frames;
* selected lens and zoom ratio;
* start/pause/resume/finish/cancel;
* sensor events;
* session and order IDs;
* Auto Photo settings.

## Выходы

Phone video:

```text
video.mp4
camera_info.json
manifest.json
imu.jsonl
ScanVideo
```

Auto Photo:

```text
manifest.json
camera_info.json
photos_metadata.jsonl
imu.jsonl
quality.jsonl
events.jsonl
photos/frame_000001.jpg
...
```

## Основные storage paths

```text
sessions/<sessionId>/phone_scans/<scanId>/
sessions/<sessionId>/auto_photo_sessions/<captureUuid>/
```

## Зависимости

```text
CameraX
Android sensors
Android filesystem
OpenCV for bounded movement metrics
A03 AppStateViewModel
A05 Room
A11 Upload
A12 Capture Bundle
```

## Критические правила Auto Photo

- bundle remains compatible with `capture_type=auto_photo_session`;
- movement is relative to the last successfully saved photo;
- failed image save must not advance the movement reference;
- M01 records metrics but does not change capture decisions;
- do not integrate accelerometer samples into translation;
- no unbounded frame history;
- Auto Photo changes must not modify the stereo contract.

## Риски

* video or JPEG may be empty;
* CameraX lifecycle may be lost;
* selected lens may differ from the bound camera;
* IMU and camera timestamps may differ;
* metadata may not match a saved file;
* state may remain active after an error;
* timer-only Auto Photo may create repeated viewpoints;
* movement reference may advance before `onImageSaved`;
* tracking may retain too much memory or block the analyzer;
* guessed thresholds may reduce overlap.

## Обязательные проверки

```text
./gradlew :app:testDebugUnitTest
./gradlew :app:assembleDebug
python3 tools/stereo_contract_audit.py
```

Runtime: phone video regression, Auto Photo lifecycle, non-empty files,
movement diagnostics, bundle upload, Prepare/Sparse/Dense smoke test, and
unchanged stereo contract.

---

# A08 — Stereo Capture and USB UVC

## Статус

```text
EXPERIMENTAL
CORE-CONTRACT
```

`CORE-CONTRACT` означает, что реализация экспериментальная, но сохранённые данные должны строго соблюдать контракт.

## Ответственность

Модуль отвечает за:

* работу `cam0`;
* работу `cam1`;
* USB UVC;
* native preview;
* получение кадров;
* timestamp matching;
* ring buffer;
* synced stereo pair;
* запись raw frame pairs;
* stereo capture lifecycle.

## Основные файлы

```text
app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/StereoCaptureExperimental.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/UsbUvcStatus.kt
app/MaklerTour/app/src/main/cpp/cam1_uvc.cpp
app/MaklerTour/app/src/main/cpp/CMakeLists.txt
```

В коде также могут присутствовать:

```text
StereoCaptureExperimental.kt.bkp
*.before_*
```

Они не являются текущей реализацией.

## Камеры

```text
cam0 = CameraX phone camera
cam1 = USB UVC camera
```

## Входы

* cam0 frames;
* cam1 frames;
* timestamps;
* USB permission;
* TextureView lifecycle;
* capture command;
* maximum time delta.

## Выходы

* raw cam0 frame;
* raw cam1 frame;
* matched pair;
* pair timestamps;
* calibration frame pair;
* synced depth pair;
* UVC status.

## Зависимости

```text
CameraX
JNI
Android NDK
USB host
TextureView
A09 Calibration
A12 Capture Bundle
```

## Критические инварианты

* saved frames не вращаются;
* detector input не использует UI-rotated image;
* cam0 и cam1 сохраняют raw coordinate systems;
* pair выбирается по timestamp;
* display rotation не меняет capture data;
* legacy stereo video не заменяет synced pairs.

## Риски

* race conditions;
* потеря кадров;
* buffer lifetime;
* JNI memory leaks;
* неправильное владение native buffer;
* timestamp domains;
* orientation regression;
* preview rotation может случайно попасть в saved bitmap;
* UVC device reconnect.

## Обязательные проверки

```bash
python3 tools/stereo_contract_audit.py
./gradlew :app:assembleDebug
```

Runtime:

* подключение USB;
* cam0 preview;
* cam1 preview;
* raw frame dimensions;
* timestamp delta;
* capture 30+ pairs;
* memory growth;
* reconnect;
* activity pause/resume.

---

# A09 — Calibration and Rig

## Статус

```text
EXPERIMENTAL
CORE-CONTRACT
```

## Ответственность

Модуль отвечает за:

* calibration board detection;
* ChArUco;
* cam0 intrinsics;
* cam1 intrinsics;
* stereo extrinsics;
* common-ID matching;
* outlier filtering;
* rig profile;
* calibration settings;
* calibration result serialization.

## Основные файлы

```text
app/MaklerTour/app/src/main/java/com/example/maklertour/data/calibration/CalibrationBoardDetector.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/data/calibration/OpenCvCalibrationBoardDetector.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/data/calibration/StereoCalibrationProcessor.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/data/rig/
app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/PhoneScanCalibrationMetadata.kt
app/MaklerTour/docs/APP_CAMERA_STEREO_CONTRACT.md
```

## Входы

* raw calibration frames;
* ChArUco board parameters;
* detected corners;
* detected IDs;
* image dimensions;
* camera intrinsics;
* stereo frame pairs.

## Выходы

```text
cam0 intrinsics
cam1 intrinsics
stereo extrinsics
R
T
E
F
RMS
epipolar errors
rejected pairs
stereo_extrinsics.json
active_rig_profile.json
```

## Зависимости

```text
OpenCV
A08 Stereo Capture
A12 Capture Bundle
P05 Dense Processing
```

## Критические правила

```text
commonIds = cam0.ids ∩ cam1.ids
CALIB_FIX_INTRINSIC
manual stereo minimum common IDs = 35
auto stereo minimum common IDs = 38
minimum accepted pairs = 10
iterative outlier filtering
raw frame rotation = 0
```

## Риски

* correspondence по порядку массива вместо ID;
* calibration по несинхронным кадрам;
* слишком мало pairs;
* высокий RMS принят как успешный;
* outlier filtering меняет модель и требует повторного расчёта;
* UI orientation влияет на math;
* calibration JSON не соответствует captured frames.

## Обязательные проверки

* detector на cam0;
* detector на cam1;
* common IDs;
* manual quality gate;
* auto quality gate;
* минимум 10 pairs;
* rejection log;
* final RMS;
* epipolar errors;
* JSON completeness;
* stereo contract audit.

---

# A10 — Local Media and Sync

## Статус

```text
CORE
MVP
```

## Ответственность

Модуль отвечает за:

* скачивание preview;
* скачивание originals;
* локальные пути;
* локальное файловое хранение;
* удаление подтверждённых файлов;
* синхронизацию metadata;
* безопасную работу с local media.

## Основные файлы

```text
app/MaklerTour/app/src/main/java/com/example/maklertour/data/sync/LocalOriginalManager.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/data/sync/SyncRepository.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/data/sync/
app/MaklerTour/app/src/main/java/com/example/maklertour/data/camera/osc/OscFileDownloader.kt
```

## Входы

* camera file URL;
* point ID;
* session ID;
* download command;
* server confirmation.

## Выходы

* local preview path;
* local original path;
* updated Room state;
* download error;
* cleanup result.

## Зависимости

```text
A06 Insta360
A05 Room
Android filesystem
network routing
```

## Риски

* partial file;
* неправильное расширение;
* удаление незагруженного original;
* path traversal;
* storage exhaustion;
* Room сообщает о файле, которого нет;
* файл существует, но повреждён.

## Обязательные проверки

* successful preview;
* network error;
* retry;
* interrupted download;
* zero-byte file;
* local path persists after restart;
* cleanup only after server confirmation.

---

# A11 — Upload Queue and Mobile Upload

## Статус

```text
CORE
DEBT
```

## Ответственность

Модуль отвечает за:

* создание upload item;
* сохранение очереди;
* progress;
* retry;
* interrupted upload reset;
* photo upload;
* video upload;
* chunked upload;
* capture bundle upload;
* server capture session creation;
* server upload state.

## Основные файлы

```text
app/MaklerTour/app/src/main/java/com/example/maklertour/auth/MobileUploadApi.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/state/AppStateViewModel.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/data/repository/Repositories.kt
app/MaklerTour/app/src/main/java/com/maklertour/data/local/entity/UploadItemEntity.kt
app/MaklerTour/app/src/main/java/com/maklertour/data/local/dao/UploadItemDao.kt
```

Backend:

```text
web/www/api/mobile.php
```

## Входы

* `CapturePoint`;
* `ScanVideo`;
* capture bundle;
* order ID;
* capture session ID;
* Bearer token;
* local file.

## Выходы

* upload progress;
* server media ID;
* success/error;
* updated Room state;
* server storage file;
* database record.

## Chunked upload параметры

```text
threshold = 200 MiB
chunk size = 8 MiB
max retries = 3
```

## Зависимости

```text
A05 Persistence
A10 Local Media
A12 Capture Bundle
B03 Mobile API
B04 Server Storage
```

## Риски

* ViewModel содержит upload implementation;
* Android и backend поля могут разойтись;
* последний chunk может быть принят без полной сборки;
* повторный upload;
* progress может считаться неправильно;
* upload success при отсутствующем файле;
* interrupted state;
* metadata прикладывается только к последнему chunk;
* сервер может принять неправильный order/session.

## Обязательные проверки

* small photo;
* small video;
* video > 200 MiB;
* chunk retry;
* interruption;
* app restart;
* duplicate chunk;
* final file size;
* server DB record;
* capture session binding;
* upload bundle.

---

# A12 — Capture Bundle

## Статус

```text
MVP
CORE-CONTRACT
```

## Ответственность

Модуль формирует архив для передачи synced stereo capture на сервер.

## Основные файлы

```text
app/MaklerTour/app/src/main/java/com/example/maklertour/data/capture/CaptureBundlePackager.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/PhoneScanManifestWriter.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/StereoCaptureExperimental.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/auth/MobileUploadApi.kt
```

Backend contract:

```text
web/DOCS/CAPTURE_BUNDLE_DENSE_CONTRACT.md
web/www/api/mobile.php
web/www/api/create_capture_bundle_dense_job.php
```

## Минимальная структура

```text
bundle_manifest.json
capture/synced_depth_manifest.json
capture/pairs/
calibration/stereo_extrinsics.json
rig/active_rig_profile.json
```

## Входы

* captured pairs;
* pair metadata;
* calibration files;
* rig profile;
* session/order information.

## Выходы

```text
.tgz archive
CAPTURE_BUNDLE upload item
bundle UUID
capture_type
```

## Зависимости

```text
A08 Stereo Capture
A09 Calibration
A11 Upload
B03 Mobile API
P05 Dense Processing
```

## Критические правила

* упаковка выполняется асинхронно;
* UI быстро возвращается в ready state;
* raw JPG не вращаются;
* raw JPG не перекодируются;
* bundle должен быть проверяемым;
* dense job разрешён только для `synced_depth_frames`.

## Риски

* partial archive;
* missing calibration;
* corrupt manifest;
* неверный relative path;
* bundle загружен до завершения записи;
* несколько bundle получают одинаковый UUID;
* UI блокируется tar/gzip.

## Обязательные проверки

* bundle создаётся;
* archive открывается;
* обязательные файлы присутствуют;
* raw file checksum не меняется;
* corrupt manifest обрабатывается;
* upload;
* server unpack;
* dense audit.

---

# A13 — Diagnostics, Settings and i18n

## Статус

```text
SUPPORT
CORE
```

## Ответственность

Модуль отвечает за:

* язык UI;
* debug mode;
* settings;
* diagnostic export;
* storage status;
* camera diagnostics;
* операторские сообщения;
* log filtering.

## Основные файлы

```text
app/MaklerTour/app/src/main/java/com/maklertour/i18n/
app/MaklerTour/app/src/main/java/com/maklertour/ui/components/
app/MaklerTour/app/src/main/java/com/example/maklertour/state/AppStateViewModel.kt
app/MaklerTour/app/src/main/res/values/
```

## Входы

* preferences;
* locale;
* debug toggle;
* current application state.

## Выходы

* localized UI;
* diagnostic JSON;
* storage information;
* debug details.

## Риски

* secrets попадают в diagnostics;
* UI strings остаются hardcoded;
* диагностический JSON становится невалидным;
* debug mode влияет на production logic.

## Обязательные проверки

* смена языка;
* перезапуск;
* diagnostic JSON parse;
* отсутствие token/password;
* production mode без debug-only behavior.

---

# 5. Backend-модули

# B01 — Bootstrap, Auth and Access Control

## Статус

```text
CORE
```

## Ответственность

Модуль отвечает за:

* bootstrap PHP;
* database connection;
* session или Bearer authentication;
* получение текущего пользователя;
* проверку role;
* проверку доступа к order;
* общие JSON responses.

## Основные файлы

```text
web/www/bootstrap.php
web/configs/
web/libs/auth-related files
web/www/api/mobile.php
web/www/api/sfm_video_pipeline.php
```

## Входы

* HTTP request;
* session cookie;
* Bearer token;
* order/session IDs.

## Выходы

* authenticated user;
* role;
* allow/deny;
* JSON error;
* database connection.

## Риски

* разные endpoints используют разные проверки auth;
* role logic дублируется;
* API может доверять client ID;
* SQL injection при ручной сборке SQL;
* HTML response вместо JSON.

## Обязательные проверки

* unauthenticated;
* broker;
* operator;
* admin;
* чужой order;
* invalid ID;
* expired token.

---

# B02 — Orders and Capture Sessions

## Статус

```text
CORE
```

## Ответственность

Модуль отвечает за:

* `tour_orders`;
* назначение оператора;
* capture sessions;
* связь Android session с server order;
* status;
* просмотр материалов заявки.

## Основные компоненты

```text
tour_orders
capture_sessions
web order pages
mobile API actions
```

## Входы

* order ID;
* user ID;
* app session UUID;
* operator action.

## Выходы

* order data;
* capture session ID;
* status;
* permission result.

## Зависимости

```text
B01 Auth
B03 Upload
B04 Storage
B05 Web UI
B06 Processing Jobs
```

## Риски

* duplicate capture session;
* локальный UUID не уникален;
* session привязана к неправильному order;
* закрытая заявка принимает upload;
* удалённая session остаётся доступной.

## Обязательные проверки

* create session;
* повторный create с тем же UUID;
* order access;
* deleted session;
* closed order;
* operator assignment.

---

# B03 — Mobile Upload API

## Статус

```text
CORE
DEBT
```

## Ответственность

Центральный endpoint Android/backend.

## Основной файл

```text
web/www/api/mobile.php
```

## Основные actions

Фактический список нужно сверять с текущим кодом. Ключевые действия:

```text
create_session
upload_photo_point
upload_video_scan
upload_capture_bundle
chunk upload actions
status-related actions
```

## Входы

* Bearer token;
* multipart form;
* JSON/form parameters;
* media files;
* chunk metadata.

## Выходы

* JSON result;
* server IDs;
* upload completion;
* storage path;
* database record.

## Зависимости

```text
B01 Auth
B02 Orders
B04 Storage
MySQL
A11 MobileUploadApi
```

## Риски

* слишком большой endpoint;
* action dispatch смешан с implementation;
* file validation;
* duplicate upload;
* chunk assembly;
* inconsistent response schema;
* absolute paths;
* temporary file cleanup;
* Android/backend field mismatch.

## Правило рефакторинга

Сначала необходимо:

1. зафиксировать список actions;
2. зафиксировать request/response contract;
3. добавить integration smoke tests;
4. выделять action handlers по одному;
5. сохранять старый endpoint URL.

---

# B04 — Server Storage

## Статус

```text
CORE
```

## Ответственность

Модуль отвечает за физическое хранение:

* photos;
* previews;
* originals;
* videos;
* metadata;
* capture bundles;
* SfM inputs;
* processing outputs.

## Основная структура

```text
storage/orders/<orderId>/sessions/<sessionUuid>/
```

В production могут использоваться разные абсолютные корни:

```text
/home/makler/web/storage/
/home/storage/
```

Конкретный runtime root необходимо проверять по deployment.

## Входы

* accepted upload;
* order/session IDs;
* sanitized filename;
* worker artifacts.

## Выходы

* физический файл;
* relative storage path;
* checksum;
* file size.

## Зависимости

```text
filesystem
PHP upload
MySQL records
processing workers
```

## Риски

* несколько storage roots;
* hardcoded paths;
* path traversal;
* partial upload;
* DB record без файла;
* файл без DB record;
* permission mismatch;
* disk exhaustion.

## Обязательные проверки

* path inside root;
* file size;
* checksum;
* permissions;
* duplicate upload;
* cleanup;
* worker read access;
* web download access.

---

# B05 — Web UI and Viewers

## Статус

```text
MVP
DEBT
```

## Ответственность

Модуль отвечает за:

* страницы order;
* отображение uploaded media;
* processing status;
* запуск jobs;
* SfM viewer;
* 3D viewer;
* manual alignment;
* отображение sparse/dense artifacts.

## Основные файлы

```text
web/www/order.php
web/www/order_simple.php
web/www/sfm_viewer.php
web/www/sfm_3d_viewer.php
web/www/sfm_manual_align.php
web/www/js/
web/templates/sfm_job_card.tpl
```

## Входы

* MySQL data;
* processing status API;
* JSON summaries;
* images;
* PLY/sparse artifacts.

## Выходы

* HTML;
* JavaScript state;
* user job commands;
* artifact download/view.

## Риски

* backup viewer files;
* viewer зависит от нестабильного artifact format;
* processing logic попадает в UI;
* разные viewers используют разные paths;
* large files загружаются в browser полностью.

## Обязательные проверки

* order page;
* uploaded video list;
* job status;
* missing artifact;
* failed job;
* sparse viewer;
* dense preview;
* access control.

---

# B06 — Processing Job Management

## Статус

```text
CORE
```

## Ответственность

Модуль отвечает за:

* создание jobs;
* предотвращение duplicate active job;
* status transitions;
* parameters;
* error state;
* local/remote routing;
* job result registration.

## Основные таблицы

```text
processing_jobs
sfm_remote_jobs
video_sfm_runs
```

## Основные файлы

```text
web/www/api/sfm_video_pipeline.php
web/www/api/create_capture_bundle_dense_job.php
web/www/api/sfm_remote_job_status.php
web/tools/process_sfm_video_jobs.php
web/tools/sfm_remote_worker.php
```

## Входы

* order ID;
* session ID;
* job type;
* parameters JSON;
* source media.

## Выходы

* job ID;
* status;
* error;
* result reference;
* artifacts flags.

## Состояния

```text
NOT_STARTED
QUEUED
PENDING
RUNNING
SUCCESS
FAILED
```

## Риски

* race при захвате job;
* duplicate RUNNING;
* warning field используется для parameters JSON;
* SUCCESS без artifacts;
* worker crash оставляет RUNNING;
* local и remote job state расходятся.

## Обязательные проверки

* create;
* duplicate request;
* atomic lock;
* success;
* failure;
* missing input;
* worker restart;
* timeout/stale RUNNING;
* required artifact validation.

---

# 6. Processing-модули

# P01 — Local Video SfM Pipeline

## Статус

```text
MVP
```

## Ответственность

Локальный серверный pipeline обработки phone video или Insta360 video.

## Основные файлы

```text
web/tools/process_sfm_video_jobs.php
web/tools/sfm_finalize_run.php
web/tools/sfm_materialize_keyframes.php
web/tools/sfm_export_sparse_3d.php
web/tools/sfm_build_viewer_keyframes.php
```

## Входы

* source video;
* camera type;
* FPS;
* frame width;
* marker family;
* marker size.

## Выходы

```text
frames/
keyframes/
viewer_keyframes/
camera_profile.json
marker observations
COLMAP model
camera poses
scaled trajectory
summary JSON
sparse artifacts
logs
```

## Основной pipeline

```text
prepare video
→ extract SfM frames
→ extract keyframes
→ camera profile
→ AprilTag detection
→ COLMAP feature extraction
→ sequential matcher
→ mapper
→ model converter
→ pose parsing
→ rough scale
→ finalize
→ export sparse 3D
```

## Зависимости

```text
ffmpeg
COLMAP
P02 sfm_tool
MySQL
server storage
```

## Риски

* hardcoded binaries;
* hardcoded storage;
* shell escaping;
* partial old COLMAP database;
* approximate camera intrinsics;
* soft step скрывает обязательную ошибку;
* stale artifacts от предыдущего run.

## Обязательные проверки

* clean run directory;
* valid source;
* ffmpeg exit code;
* frame count;
* COLMAP database;
* sparse model;
* pose count;
* summary JSON;
* log;
* failed input.

---

# P02 — C++ sfm_tool

## Статус

```text
MVP
SUPPORT
```

## Ответственность

C++ CLI-инструмент выполняет специализированные SfM-операции.

## Основные файлы

```text
web/tools/sfm_cpp/CMakeLists.txt
web/tools/sfm_cpp/src/main.cpp
web/tools/sfm_cpp/src/camera_profile.cpp
web/tools/sfm_cpp/src/camera_profile.hpp
web/tools/sfm_cpp/src/apriltag_frames.cpp
web/tools/sfm_cpp/src/apriltag_frames.hpp
```

Build output:

```text
web/tools/sfm_cpp/build/bin/sfm_tool
```

Каталог `build/` является сгенерированным и не должен редактироваться вручную.

## Основные команды

Фактический список необходимо получать через:

```bash
sfm_tool --help
```

Из текущего pipeline используются:

```text
detect-apriltag-frames
parse-colmap-images
rough-scale
```

## Входы

* image directory;
* camera profile;
* COLMAP text model;
* marker observations;
* JSON parameters.

## Выходы

* marker observations JSON;
* camera poses JSON;
* scaled trajectory JSON;
* exit code;
* stderr/stdout.

## Риски

* input validation;
* JSON schema;
* path handling;
* OpenCV/AprilTag compatibility;
* silent partial result;
* build artifacts закоммичены в Git.

## Обязательные проверки

```bash
cmake -S web/tools/sfm_cpp -B /tmp/insta3d-sfm-build
cmake --build /tmp/insta3d-sfm-build
/tmp/insta3d-sfm-build/bin/sfm_tool --help
```

Плюс тестовые fixture для каждой команды.

---

# P03 — Remote Job Coordinator

## Статус

```text
CORE
MVP
```

## Ответственность

Модуль соединяет web backend и GrafikStation.

## Основные файлы

```text
web/tools/sfm_remote_worker.php
web/www/api/sfm_remote_job_status.php
web/www/api/create_capture_bundle_dense_job.php
web/remote_station/deploy_station.sh
```

## Входы

* `sfm_remote_jobs`;
* job parameters;
* input files;
* station configuration.

## Выходы

* переданный job;
* remote execution status;
* полученные artifacts;
* updated DB state.

## Зависимости

```text
SSH/transfer mechanism
GrafikStation filesystem
P04 station runtime
B06 job state
```

## Риски

* job скопирован частично;
* station недоступна;
* повторный запуск одного job;
* result.json не соответствует job;
* server и station clock различаются;
* output получен не полностью;
* stale lock.

## Обязательные проверки

* station reachable;
* input copied;
* job runner starts;
* output copied;
* result ID matches;
* failure propagation;
* retry;
* cleanup.

---

# P04 — GrafikStation Runtime

## Статус

```text
CORE
MVP
```

## Ответственность

Модуль предоставляет runtime для тяжёлой обработки.

## Основные файлы

```text
web/remote_station/deploy_station.sh
web/remote_station/run_colmap_sparse_job.sh
web/remote_station/run_maklertour_synced_dense_job.sh
web/remote_station/scripts/
web/remote_station/sfm_cleanup.php
```

## Runtime зависимости

```text
Linux
NVIDIA driver
Podman
CUDA-compatible container
COLMAP
ffmpeg
Python
OpenCV
storage space
```

## Входы

* remote job directory;
* input archive/video;
* parameters JSON;
* job ID.

## Выходы

```text
output/job_<id>/
result.json
logs
sparse artifacts
dense artifacts
```

## Риски

* container version drift;
* driver/container CUDA mismatch;
* insufficient VRAM;
* job consumes disk;
* wrong mount paths;
* permission mismatch;
* process survives worker timeout;
* artifacts remain from old run.

## Обязательные проверки

```bash
nvidia-smi
podman info
ffmpeg -version
colmap -h
```

Плюс smoke job и проверка `result.json`.

---

# P05 — Synced Dense Pipeline

## Статус

```text
EXPERIMENTAL
CORE-CONTRACT
```

## Ответственность

Модуль строит dense depth по synced stereo pairs.

## Основные файлы

```text
web/remote_station/run_maklertour_synced_dense_job.sh
web/remote_station/scripts/process_maklertour_synced_dense.sh
web/DOCS/CAPTURE_BUNDLE_DENSE_CONTRACT.md
web/tools/capture_bundle_dense_audit.php
```

## Входы

```text
bundle_manifest.json
synced_depth_manifest.json
pairs/
stereo_extrinsics.json
processing parameters
```

## Выходы

```text
dense/contact_dense_depth.jpg
dense/dense_depth_debug.json
dense/dense_depth_summary.csv
result.json
```

## Основные этапы

```text
unpack
→ audit
→ load calibration
→ load pairs
→ rectify
→ determine baseline axis
→ optional identical rotation
→ disparity
→ depth
→ debug summary
```

## Критические контракты

* только synced raw pairs;
* baseline axis определяется по `P2`;
* StereoBM/SGBM ищет disparity по X;
* vertical baseline требует одинакового поворота rectified images;
* исходная `Q` после поворота не используется без адаптации;
* raw files не изменяются.

## Риски

* неверный baseline axis;
* использование UI orientation;
* неправильный `Q`;
* invalid disparity;
* слишком низкий valid depth ratio;
* calibration не соответствует pair resolution;
* обработка legacy stereo video как synced depth.

## Обязательные проверки

```bash
php web/tools/capture_bundle_dense_audit.php ...
```

Дополнительно проверить:

* horizontal baseline;
* vertical baseline;
* missing calibration;
* malformed manifest;
* disparity range;
* valid depth ratio;
* output artifacts.

---

# P06 — Processing Artifacts

## Статус

```text
CORE-CONTRACT
```

## Ответственность

Модуль определяет результат processing jobs и данные для viewer.

## Основные artifacts

```text
result.json
sfm_result_summary.json
viewer_keyframes_summary.json
camera_profile.json
marker_observations.json
camera_poses.json
trajectory_scaled.json
sfm_3d_summary.json
dense_depth_debug.json
dense_depth_summary.csv
PLY
preview images
logs
```

## Входы

* outputs processing tools;
* job metadata;
* source IDs.

## Выходы

* web-visible result;
* status summary;
* downloadable artifacts;
* diagnostic information.

## Основные требования

Каждый result должен связываться с:

```text
job ID
order ID
session ID
processing type
input reference
created time
success/failure
artifact list
warnings
errors
```

## Риски

* viewer ожидает старую schema;
* SUCCESS без обязательного файла;
* result относится к предыдущему job;
* absolute path попадает в public JSON;
* partial artifacts доступны пользователю.

## Обязательные проверки

* JSON parse;
* required fields;
* artifact existence;
* artifact size;
* job ID;
* viewer rendering;
* failed result.

---

# 7. Support-модули

# S01 — Contracts and Documentation

## Статус

```text
SUPPORT
CORE-CONTRACT
```

## Основные файлы

```text
docs/llm/00_PROJECT_OVERVIEW.md
docs/llm/01_REQUIREMENTS.md
docs/llm/02_ARCHITECTURE.md
docs/llm/03_MODULES.md
docs/llm/04_CONTRACTS.md
docs/llm/05_DATA_FLOWS.md
docs/llm/06_DEPENDENCIES.md
docs/llm/07_BUILD_AND_TEST.md
docs/llm/08_KNOWN_PROBLEMS.md
docs/llm/09_REFACTORING_ROADMAP.md
docs/llm/10_LLM_WORK_RULES.md
CAMERA_OSC_X4.md
TESTING.md
TZ.md
app/MaklerTour/docs/APP_CAMERA_STEREO_CONTRACT.md
web/DOCS/CAPTURE_BUNDLE_DENSE_CONTRACT.md
DOC/PHONE_SCAN_MVP_STATUS.md
```

## Ответственность

* описание системы;
* требования;
* архитектура;
* module boundaries;
* contracts;
* known problems;
* roadmap;
* LLM instructions.

## Риски

* документ устарел;
* код изменился без документации;
* два документа противоречат друг другу;
* LLM использует старый contract.

## Проверки

При изменении публичного поведения обновить соответствующий документ в том же PR/commit series.

---

# S02 — Audits and Validation

## Статус

```text
SUPPORT
```

## Основные инструменты

```text
app/MaklerTour/tools/stereo_contract_audit.py
web/tools/capture_bundle_dense_audit.php
build commands
runtime smoke scripts
```

## Ответственность

* проверка запрещённых паттернов;
* проверка bundle;
* проверка contracts;
* раннее обнаружение регрессии.

## Риски

* audit проверяет текст, но не runtime;
* audit может устареть;
* false positive;
* false negative.

Audit дополняет тесты, но не заменяет их.

---

# S03 — Build, Deployment and Diagnostics

## Статус

```text
SUPPORT
```

## Основные источники

```text
TESTING.md
Gradle files
CMake files
deploy_station.sh
worker CLI
adb commands
logcat filters
```

## Ответственность

* воспроизводимая сборка;
* установка;
* запуск;
* deployment;
* сбор логов;
* smoke validation.

---

# 8. Связи модулей по основным сценариям

# 8.1 Login и загрузка заявок

```text
A01 UI
→ A02 Auth and Orders
→ B01 Auth
→ B02 Orders
→ A02 OrdersRepository
→ A01 UI
```

# 8.2 Insta360 photo point

```text
A01 UI
→ A03 App State
→ A06 Insta360 OSC
→ A04 CapturePoint
→ A05 Room
→ A10 Local Media
→ A11 Upload
→ B03 Mobile API
→ B04 Storage
```

# 8.3 Insta360 video scan

```text
A01 UI
→ A03 App State
→ A06 Insta360 OSC
→ A04 ScanVideo
→ A05 Room
→ A10 Local download
→ A11 Upload
→ B03 Mobile API
→ B04 Storage
→ B06 Job
→ P01 Video SfM
→ P06 Artifacts
→ B05 Viewer
```

# 8.4 Phone video scan

```text
A01 UI
→ A03 App State
→ A07 Phone Camera
→ local MP4 + metadata
→ A05 Room
→ A11 Upload
→ B03 Mobile API
→ B04 Storage
→ B06 Processing Job
→ P01 SfM
→ P06 Artifacts
```

# 8.5 Stereo calibration

```text
A01 Calibration UI
→ A08 Stereo Capture
→ A09 Calibration
→ calibration JSON
→ local filesystem
→ A12 Capture Bundle
```

# 8.6 Synced dense depth

```text
A08 Synced pairs
→ A09 Calibration
→ A12 Capture Bundle
→ A11 Upload
→ B03 Mobile API
→ B04 Storage
→ B06 Remote Job
→ P03 Remote Coordinator
→ P04 GrafikStation
→ P05 Dense Pipeline
→ P06 Artifacts
→ B05 Viewer
```

---

# 9. Dependency rules

## 9.1 Разрешённые зависимости

```text
A01 UI → A03 ViewModel
A03 ViewModel → A04 interfaces
A05 repositories → Room DAO
A06 camera implementation → A04 CameraProvider
A11 upload client → backend HTTP API
B03 API → B01 auth + B04 storage
B06 jobs → processing workers
P01/P05 → processing tools
B05 viewer → prepared artifacts
```

## 9.2 Нежелательные зависимости

```text
UI → DAO
UI → raw OSC JSON
DAO → Compose
domain → Android Activity
camera transport → Room entity
GrafikStation → Android local state
viewer → mutation of raw capture
worker → unaudited client absolute path
```

## 9.3 Запрещённые скрытые связи

Нельзя использовать:

* backup-файл как runtime dependency;
* current working directory как неявный storage root;
* UI orientation как processing parameter;
* filename как единственный object ID;
* log text как единственный machine-readable result;
* database warning field как бессрочный универсальный storage для JSON без документированного контракта.

---

# 10. Матрица риска модулей

| Модуль                |             Связанность | Риск изменения | Приоритет тестов |
| --------------------- | ----------------------: | -------------: | ---------------: |
| A01 MainActivity/UI   |                 высокая |    критический |          высокий |
| A02 Auth/Orders       |                 средняя |        высокий |          высокий |
| A03 AppStateViewModel |           очень высокая |    критический |      критический |
| A04 Domain            | высокая по последствиям |    критический |      критический |
| A05 Room              |                 высокая |    критический |      критический |
| A06 Insta360 OSC      |                 средняя |        высокий |          высокий |
| A07 Phone Camera      |                 средняя |        высокий |          высокий |
| A08 Stereo/UVC        |           очень высокая |    критический |      критический |
| A09 Calibration       |           очень высокая |    критический |      критический |
| A10 Local Media       |                 средняя |        высокий |          высокий |
| A11 Upload            |           очень высокая |    критический |      критический |
| A12 Capture Bundle    |                 высокая |    критический |      критический |
| B01 Auth              | высокая по безопасности |    критический |      критический |
| B02 Orders/Sessions   |                 высокая |        высокий |          высокий |
| B03 mobile.php        |           очень высокая |    критический |      критический |
| B04 Storage           |           очень высокая |    критический |      критический |
| B05 Viewers           |                 средняя |        средний |          средний |
| B06 Jobs              |                 высокая |    критический |      критический |
| P01 SfM Pipeline      |                 высокая |        высокий |          высокий |
| P02 sfm_tool          |                 средняя |        высокий |          высокий |
| P03 Remote Worker     |                 высокая |    критический |      критический |
| P04 GrafikStation     |                 высокая |        высокий |          высокий |
| P05 Dense             |           очень высокая |    критический |      критический |
| P06 Artifacts         |                 высокая |        высокий |          высокий |

---

# 11. Контекстные пакеты для LLM

# 11.1 Изменение Insta360 OSC

Добавлять:

```text
docs/llm/00_PROJECT_OVERVIEW.md
docs/llm/01_REQUIREMENTS.md
docs/llm/02_ARCHITECTURE.md
docs/llm/03_MODULES.md
CAMERA_OSC_X4.md
CameraProvider domain file
Insta360OscProvider.kt
OscHttpClient.kt
camera profile files
AppStateViewModel.kt — только связанные функции
TESTING.md
```

# 11.2 Изменение Room

Добавлять:

```text
relevant domain model
relevant entity
relevant DAO
repository interface
Room repository implementation
AppDatabase.kt
existing migration
task file
```

Не добавлять весь `Repositories.kt`, если можно выделить только нужную область.

# 11.3 Изменение upload

Добавлять обе стороны:

```text
MobileUploadApi.kt
AppStateViewModel upload functions
UploadItem domain/entity/DAO
mobile.php relevant action
database table definition
storage layout
test request
```

# 11.4 Изменение stereo/calibration

Добавлять:

```text
APP_CAMERA_STEREO_CONTRACT.md
StereoCaptureExperimental.kt
relevant calibration classes
rig profile classes
cam1 native file, если затронут
capture manifest writer
stereo_contract_audit.py
```

# 11.5 Изменение dense pipeline

Добавлять:

```text
CAPTURE_BUNDLE_DENSE_CONTRACT.md
capture bundle audit
job creation endpoint
remote worker
GrafikStation runner
dense processing script
example manifest
example result.json
```

---

# 12. Правила изменения модуля

Перед изменением необходимо записать:

```text
Target module:
Related modules:
Contract boundary:
Files to read:
Files allowed to change:
Files forbidden to change:
Required tests:
Rollback:
```

Пример:

```text
Target module:
A06 Insta360 OSC

Related modules:
A03 Application State
A10 Local Media

Contract boundary:
CameraProvider

Files allowed to change:
Insta360OscProvider.kt
Insta360X4OscProfile.kt

Files forbidden to change:
Room schema
mobile.php
stereo pipeline

Required tests:
assembleDebug
camera.getOptions
photo capture
video start/stop
```

---

# 13. Правила для Codex, Aider и локальной LLM

Модель должна:

1. Определить target module.
2. Определить соседние модули.
3. Найти contract boundary.
4. Прочитать профильный contract.
5. Не изменять соседний модуль без необходимости.
6. Не использовать backup-файлы.
7. Не редактировать `build/`.
8. Не смешивать refactoring и изменение поведения.
9. Не заявлять об успехе без выполнения проверок.
10. Обновить module map, если появилась новая постоянная подсистема.

---

# 14. Не являющиеся модулями файлы

По умолчанию не считаются отдельными модулями:

```text
*.before_*
*.bak_*
*.bkp
build/
tmp/
cache/
generated/
compiled binaries
downloaded artifacts
runtime output
```

Они могут использоваться только как:

* история;
* fixture;
* диагностический output;
* результат сборки;
* материал для сравнения.

---

# 15. Известные пробелы карты

Требуют отдельной последующей инвентаризации:

* полный список actions в `mobile.php`;
* полная схема MySQL;
* все processing job types;
* окончательный список viewer artifacts;
* текущая WorkManager architecture;
* точное разделение phone scan и stereo capture classes;
* все Android navigation screens;
* все server storage roots;
* все active remote station scripts;
* production deployment topology;
* namespace migration plan;
* список закоммиченных build и backup-файлов;
* фактические unit/integration tests.

Эти пробелы должны уточняться по коду и runtime, а не заполняться предположениями.

---

# 16. Краткая карта ответственности

```text
A01 UI:
показывает состояние и принимает команды

A03 ViewModel:
координирует Android use cases

A04 Domain:
определяет модели и интерфейсы

A05 Room:
хранит локальное persistent state

A06/A07/A08:
получают данные с камер

A09:
рассчитывает calibration

A10:
управляет локальными media

A11:
загружает данные

A12:
формирует stereo capture package

B01/B02:
проверяют пользователя и бизнес-объекты

B03:
принимает Android upload

B04:
хранит server files

B06:
создаёт и отслеживает processing jobs

P01/P02:
выполняют local sparse processing

P03/P04:
управляют remote processing

P05:
строит synced dense depth

P06:
определяет результат processing

B05:
показывает готовые результаты
```

## AUTO-B06 standalone Auto Photo dense preview

`web/libs/auto_photo_sparse_web_lib.php`, the Photo 3D DTO/rendering modules, and `sfm_remote_worker.php` own the isolated dense-only job. It reuses existing COLMAP chunk orchestration and does not create an `sfm_pipeline_runs` row.
