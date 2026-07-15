# insta3D / MaklerTour — обзор проекта

> Актуализация: 2026-07-15
> Назначение файла: краткая точка входа для разработчиков, ChatGPT, Aider и локальных LLM.

## 1. Назначение проекта

`insta3D / MaklerTour` — система полевой съёмки объектов недвижимости и серверной обработки материалов для:

* виртуальных туров;
* 360° photo points;
* video scan;
* SfM-реконструкции;
* sparse 3D;
* stereo calibration;
* synced stereo depth;
* последующего построения карты, схемы или floorplan.

Android-приложение используется оператором непосредственно на объекте.

Приложение управляет:

* камерой Insta360 X4 через OSC/HTTP;
* встроенной камерой телефона через CameraX;
* экспериментальной стереопарой:

  * `cam0` — камера телефона;
  * `cam1` — внешняя USB UVC-камера.

Приложение сохраняет данные локально, формирует очередь загрузки и передаёт материалы на backend.

Тяжёлая обработка выполняется сервером и GrafikStation. Android-приложение не должно выполнять полноценную SfM, dense reconstruction или production-рендеринг на телефоне.

---

## 2. Основные пользователи

### 2.1 Оператор

Оператор:

* авторизуется в приложении;
* получает список заявок;
* выбирает объект;
* создаёт или открывает съёмочную сессию;
* подключается к Insta360;
* снимает 360° photo points;
* записывает video scan;
* записывает phone camera scan;
* выполняет stereo calibration;
* записывает synced stereo frames;
* проверяет локальные материалы;
* запускает загрузку;
* контролирует статус upload и обработки.

### 2.2 Маклер или администратор

Маклер или администратор:

* создаёт и назначает заявки;
* контролирует статус съёмки;
* просматривает загруженные файлы;
* запускает серверную обработку;
* просматривает результаты SfM, dense depth и виртуального тура.

### 2.3 Разработчик или инженер

Разработчик обслуживает:

* Android-приложение;
* camera integration;
* Room persistence;
* upload API;
* PHP backend;
* MySQL;
* серверное хранилище;
* SfM workers;
* COLMAP;
* C++ `sfm_tool`;
* remote processing на GrafikStation;
* viewer и диагностические инструменты.

---

## 3. Структура репозитория

```text
insta3D/
├── app/
│   └── MaklerTour/
│       ├── app/
│       │   └── src/main/
│       │       ├── java/
│       │       ├── cpp/
│       │       ├── res/
│       │       └── AndroidManifest.xml
│       ├── docs/
│       ├── tools/
│       ├── gradle/
│       └── build.gradle.kts
│
├── web/
│   ├── www/
│   │   ├── api/
│   │   ├── js/
│   │   └── web pages
│   ├── tools/
│   │   ├── PHP workers
│   │   ├── sfm_cpp/
│   │   └── processing scripts
│   ├── remote_station/
│   │   ├── scripts/
│   │   ├── workers
│   │   └── deployment files
│   ├── libs/
│   ├── templates/
│   └── DOCS/
│
├── DOC/
├── docs/
│   └── llm/
├── CAMERA_OSC_X4.md
├── TESTING.md
└── TZ.md
```

### 3.1 Основные каталоги

| Каталог                             | Назначение                                      |
| ----------------------------------- | ----------------------------------------------- |
| `app/MaklerTour/`                   | Android-приложение оператора                    |
| `app/MaklerTour/app/src/main/java/` | Kotlin-код приложения                           |
| `app/MaklerTour/app/src/main/cpp/`  | native C++/JNI и USB UVC                        |
| `app/MaklerTour/docs/`              | Android, camera, calibration и stereo-контракты |
| `web/www/`                          | web-интерфейс и HTTP API                        |
| `web/tools/`                        | серверные workers, CLI-скрипты и `sfm_tool`     |
| `web/remote_station/`               | обработка на GrafikStation                      |
| `web/libs/`                         | общие PHP-библиотеки                            |
| `web/templates/`                    | серверные UI-шаблоны                            |
| `web/DOCS/`                         | server-side contracts                           |
| `DOC/`                              | документы состояния отдельных подсистем         |
| `docs/llm/`                         | нормализованная документация для LLM            |

---

## 4. Неактуальные и служебные файлы

В репозитории присутствуют резервные, временные и сгенерированные файлы:

```text
*.before_*
*.bak_*
*.bkp
*.old
build/
tmp/
generated/
```

По умолчанию они не считаются текущей реализацией.

LLM и разработчик должны использовать основной файл без backup-суффикса.

Backup-файлы разрешено анализировать только в следующих случаях:

* восстановление потерянного кода;
* поиск регрессии;
* сравнение старой и новой реализации;
* анализ истории конкретной ошибки.

Сгенерированные build-файлы не должны использоваться как источник архитектуры или бизнес-логики.

---

## 5. Основные технологии

### 5.1 Android

* Android SDK 34;
* Kotlin;
* Jetpack Compose;
* Java 17;
* coroutines;
* StateFlow;
* Room;
* OkHttp;
* CameraX;
* OpenCV;
* WorkManager или фоновые coroutine-задачи;
* native C++ через CMake/JNI;
* USB UVC.

### 5.2 Backend

* PHP;
* MySQL;
* HTML;
* JavaScript;
* JSON HTTP API;
* multipart upload;
* chunked upload;
* серверное файловое хранилище;
* CLI workers.

### 5.3 Processing

* ffmpeg;
* COLMAP;
* C++;
* OpenCV;
* AprilTag;
* sparse reconstruction;
* stereo calibration;
* StereoBM/StereoSGBM;
* Podman;
* NVIDIA GPU;
* GrafikStation Linux.

---

## 6. Главные точки входа

### 6.1 Android lifecycle и UI

```text
app/MaklerTour/app/src/main/java/com/example/maklertour/MainActivity.kt
```

Ответственность:

* Android lifecycle;
* запуск Compose;
* навигация;
* создание runtime-зависимостей;
* создание repositories;
* создание camera providers;
* инициализация ViewModel;
* основные экраны;
* stereo/calibration UI;
* часть capture orchestration.

### 6.2 Состояние приложения и orchestration

```text
app/MaklerTour/app/src/main/java/com/example/maklertour/state/AppStateViewModel.kt
```

Ответственность:

* выбранная сессия;
* состояние камеры;
* photo capture;
* video scan;
* phone camera scan;
* управление Room repositories;
* upload queue;
* запуск загрузки;
* серверные статусы;
* диагностический JSON.

### 6.3 Persistence

```text
app/MaklerTour/app/src/main/java/com/example/maklertour/data/repository/Repositories.kt
app/MaklerTour/app/src/main/java/com/maklertour/data/local/
```

Ответственность:

* интерфейсы repositories;
* Room implementations;
* in-memory implementations;
* sessions;
* rooms;
* capture points;
* scan videos;
* connections;
* upload items;
* преобразование domain-моделей в Room entities.

### 6.4 Insta360 OSC

```text
app/MaklerTour/app/src/main/java/com/example/maklertour/data/camera/Insta360OscProvider.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/data/camera/osc/OscHttpClient.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/data/camera/osc/OscFileDownloader.kt
app/MaklerTour/app/src/main/java/com/example/maklertour/data/camera/osc/profile/
```

Ответственность:

* подключение к Insta360;
* определение профиля камеры;
* переключение photo/video mode;
* запуск и остановка capture;
* polling статуса;
* получение file URLs;
* скачивание preview и original-файлов.

### 6.5 Phone camera и stereo

```text
app/MaklerTour/app/src/main/java/com/example/maklertour/data/phonecamera/
```

Ответственность:

* CameraX preview;
* запись phone camera video;
* выбор линзы;
* camera metadata;
* IMU;
* physical orientation;
* stereo capture;
* cam0/cam1 synchronization;
* calibration frame buffers;
* manifests;
* synced depth capture.

### 6.6 Mobile upload

```text
app/MaklerTour/app/src/main/java/com/example/maklertour/auth/MobileUploadApi.kt
web/www/api/mobile.php
```

Ответственность:

* создание server capture session;
* загрузка photo points;
* загрузка video scan;
* chunked upload;
* загрузка phone camera metadata;
* загрузка capture bundle;
* progress;
* retry;
* серверная регистрация файлов.

### 6.7 Video SfM

```text
web/www/api/sfm_video_pipeline.php
web/tools/process_sfm_video_jobs.php
```

Ответственность:

* создание processing job;
* извлечение кадров через ffmpeg;
* построение keyframes;
* AprilTag detection;
* COLMAP feature extraction;
* sequential matching;
* mapper;
* export sparse model;
* camera trajectory;
* rough metric scale;
* materialization результатов.

### 6.8 Remote processing

```text
web/tools/sfm_remote_worker.php
web/remote_station/
```

Ответственность:

* передача задания на GrafikStation;
* запуск GPU processing;
* synced dense processing;
* sparse processing;
* получение результатов;
* копирование artifacts обратно на web server;
* обновление статуса job.

---

## 7. Общая архитектурная цепочка

```text
Оператор
    ↓
Android UI / Compose
    ↓
MainActivity
    ↓
AppStateViewModel
    ↓
CameraProvider / PhoneCameraScanProvider / StereoCapture
    ↓
Room repositories + local filesystem
    ↓
UploadQueueRepository
    ↓
MobileUploadApi
    ↓
web/www/api/mobile.php
    ↓
MySQL + server storage
    ↓
processing_jobs или sfm_remote_jobs
    ↓
local server worker или GrafikStation
    ↓
ffmpeg / COLMAP / OpenCV / sfm_tool
    ↓
artifacts / JSON / PLY / previews / depth results
    ↓
web viewer и processing status
```

---

## 8. Основные data flow

### 8.1 Insta360 photo point

```text
Operator action
→ AppStateViewModel.capturePoint()
→ CameraProvider.capture()
→ Insta360OscProvider
→ OSC camera.takePicture
→ command status polling
→ camera file URL
→ CapturePoint
→ Room
→ preview/original download
→ upload queue
→ MobileUploadApi
→ mobile.php
→ server storage
```

### 8.2 Insta360 video scan

```text
Operator starts recording
→ AppStateViewModel.startVideoScan()
→ CameraProvider.startVideoScan()
→ OSC mode switch
→ camera.startCapture
→ recording state

Operator stops recording
→ AppStateViewModel.stopVideoScan()
→ camera.stopCapture
→ fileUrls/_localFileUrls
→ ScanVideo
→ optional local download
→ upload queue
→ backend storage
→ SfM processing
```

### 8.3 Phone camera video scan

```text
CameraX
→ PhoneCameraScanProvider
→ PhoneCameraVideoRecorder
→ video.mp4
→ camera_info.json
→ manifest.json
→ imu.jsonl
→ ScanVideo
→ upload queue
→ MobileUploadApi
→ backend
→ ffmpeg
→ COLMAP sparse reconstruction
```

### 8.4 Synced stereo depth

```text
cam0 CameraX frame
+
cam1 USB UVC frame
→ timestamp matching
→ raw synced pair
→ synced_depth_manifest.json
→ stereo calibration data
→ CaptureBundlePackager
→ .tgz bundle
→ upload_capture_bundle
→ server capture_bundles
→ MAKLERTOUR_SYNCED_DENSE job
→ GrafikStation
→ rectification
→ disparity
→ dense depth artifacts
```

---

## 9. Текущее состояние проекта

Работают или частично работают:

* Android login;
* список заявок;
* локальные capture sessions;
* Room persistence;
* Insta360 OSC photo capture;
* Insta360 OSC video scan;
* phone camera recording;
* upload queue;
* загрузка photo/video;
* chunked video upload;
* server storage;
* phone video SfM smoke pipeline;
* ffmpeg frame extraction;
* COLMAP sparse reconstruction;
* stereo capture;
* ChArUco calibration;
* synced frame bundle;
* remote dense job;
* базовые web viewers и status API.

Проект находится в активной разработке.

Некоторые ветки реализованы как MVP или experimental и ещё не являются production-ready.

---

## 10. Критичные ограничения

### 10.1 Stereo coordinate contract

Raw stereo frames нельзя вращать для удобства UI.

Должно сохраняться:

```text
cam0 raw frame → без rotation
cam1 raw frame → без rotation
detector input → без display rotation
calibration math → raw coordinates
saved JPEG → raw coordinates
```

Разрешено вращать только операторский preview и overlay, если оба преобразуются одинаково.

### 10.2 Insta360 X4 mode contract

После `camera.setOptions` необходимо повторно вызвать `camera.getOptions`.

Для video mode требуется подтверждение:

```text
captureMode == video
_videoType == normal
```

Ответ `/osc/state` может быть устаревшим и не должен быть единственным источником истины.

### 10.3 Processing boundary

Android-приложение не выполняет:

* SfM;
* MVS;
* dense reconstruction;
* production stitching;
* production mesh generation;
* полный floorplan pipeline.

Телефон отвечает за capture, metadata, packaging и upload.

### 10.4 Upload contract

Изменения в Android upload должны синхронно проверяться с:

```text
MobileUploadApi.kt
mobile.php
MySQL schema
server storage layout
processing worker
```

Нельзя менять имя multipart-поля или JSON-параметра только с одной стороны.

### 10.5 Persistence contract

Изменения domain-моделей требуют проверки:

```text
domain model
Room entity
DAO
repository mapping
database version
migration
JSON serialization
upload
```

### 10.6 Refactoring boundary

Следующие файлы являются крупными связанными узлами:

```text
MainActivity.kt
AppStateViewModel.kt
Repositories.kt
web/www/api/mobile.php
```

Их нельзя рефакторить одним большим изменением.

Рефакторинг должен выполняться поэтапно с сохранением текущих контрактов.

---

## 11. Правила работы LLM

LLM должна:

1. Сначала прочитать этот файл.
2. Определить затрагиваемую подсистему.
3. Прочитать профильный контракт.
4. Не использовать backup-файлы как текущий код.
5. Не менять Android и backend contract только с одной стороны.
6. Не вращать raw stereo data из-за ориентации UI.
7. Не выдумывать результаты сборки и тестов.
8. Делать минимальные проверяемые изменения.
9. Не переписывать большой файл целиком без отдельного плана.
10. После изменения показывать diff.
11. Запускать указанные build, audit и test-команды.
12. При недостатке контекста перечислить необходимые файлы.
13. Отделять подтверждённые факты от предположений.

---

## 12. Основные источники истины

| Документ                                            | Назначение                             |
| --------------------------------------------------- | -------------------------------------- |
| `TZ.md`                                             | общее техническое задание              |
| `CAMERA_OSC_X4.md`                                  | OSC-контракт Insta360 X4               |
| `TESTING.md`                                        | сборка и ручная диагностика            |
| `app/MaklerTour/docs/APP_CAMERA_STEREO_CONTRACT.md` | stereo, calibration и depth invariants |
| `web/DOCS/CAPTURE_BUNDLE_DENSE_CONTRACT.md`         | synced dense server contract           |
| `DOC/PHONE_SCAN_MVP_STATUS.md`                      | текущее состояние phone scan/SfM MVP   |
| `docs/llm/03_MODULES.md`                            | карта модулей и связей                 |
| `docs/llm/04_CONTRACTS.md`                          | сводный список контрактов              |
| `docs/llm/09_REFACTORING_ROADMAP.md`                | этапы рефакторинга                     |

---

## 13. Краткая модель проекта

```text
MaklerTour Android = capture client
Web server = API, storage, jobs, UI
GrafikStation = heavy processing
Room = локальное состояние Android
MySQL = серверное состояние
CameraProvider = граница camera integration
MobileUploadApi/mobile.php = граница Android/backend
capture bundle = граница stereo capture/dense processing
```
