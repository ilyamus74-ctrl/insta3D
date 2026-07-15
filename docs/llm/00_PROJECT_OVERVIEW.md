# insta3D / MaklerTour — обзор проекта

> Актуализация: 2026-07-15. Источник: код ветки `main`.  
> Назначение файла: краткая точка входа для разработчиков, ChatGPT, Aider и локальных LLM.

## Назначение

`insta3D / MaklerTour` — система полевой съёмки недвижимости и серверной обработки материалов для виртуальных туров, SfM-реконструкции и stereo depth.

Android-приложение используется оператором на объекте. Оно управляет Insta360 X4 по OSC/HTTP, встроенной CameraX-камерой телефона и экспериментальной стереопарой `cam0 + USB UVC cam1`, сохраняет данные локально и загружает их на backend. Тяжёлая обработка выполняется сервером и GrafikStation, а не телефоном.

## Основные пользователи

- **Оператор** — получает заявку, создаёт сессию, снимает фото/видео/stereo frames и контролирует загрузку.
- **Маклер или администратор** — управляет заявками и просматривает результаты через web-интерфейс.
- **Разработчик/инженер** — обслуживает Android, backend, SfM workers и GrafikStation.

## Структура репозитория

```text
app/MaklerTour/       Android-приложение Kotlin/Compose
web/www/              web UI и HTTP API
web/tools/            PHP CLI workers и C++ sfm_tool
web/remote_station/   обработка на GrafikStation
web/libs/, templates/ серверные библиотеки и UI-шаблоны
DOC/, web/DOCS/       документы состояния и server contracts
app/MaklerTour/docs/  Android/stereo contracts
docs/llm/             нормализованная документация для LLM
```

Backup-файлы `*.before_*`, `*.bak_*`, `*.bkp`, каталоги `build/` и временные файлы не являются текущей реализацией, если задача явно не требует анализа истории.

## Технологии

- Android SDK 34, Kotlin, Jetpack Compose, Java 17;
- Room, StateFlow/coroutines, OkHttp, CameraX, OpenCV;
- native C++/CMake/JNI для USB UVC `cam1`;
- PHP, MySQL, HTML/JavaScript;
- ffmpeg, COLMAP, C++ `sfm_tool`, AprilTag;
- GrafikStation: Linux, Podman, NVIDIA GPU.

## Главные точки входа

- Android lifecycle, DI и UI: `app/MaklerTour/app/src/main/java/com/example/maklertour/MainActivity.kt`;
- состояние и orchestration: `.../state/AppStateViewModel.kt`;
- persistence boundaries: `.../data/repository/Repositories.kt` и `.../data/local/`;
- Insta360: `.../data/camera/Insta360OscProvider.kt`;
- phone/stereo capture: `.../data/phonecamera/`;
- mobile upload API: `.../auth/MobileUploadApi.kt` и `web/www/api/mobile.php`;
- video SfM API/worker: `web/www/api/sfm_video_pipeline.php` и `web/tools/process_sfm_video_jobs.php`;
- remote processing: `web/tools/sfm_remote_worker.php` и `web/remote_station/`.

## Основные связи

```text
Android UI
  -> AppStateViewModel
  -> CameraProvider / PhoneCameraScanProvider
  -> Room repositories + local files
  -> UploadQueueRepository
  -> MobileUploadApi
  -> web/www/api/mobile.php
  -> MySQL + server storage
  -> local SfM worker или sfm_remote_jobs
  -> GrafikStation
  -> artifacts, viewers и processing status
```

Три главных data flow:

1. **Insta360 photo/video**: OSC capture → metadata/file URLs → download/upload → server storage.
2. **Phone video**: CameraX MP4 + metadata → upload → ffmpeg/COLMAP → sparse reconstruction.
3. **Synced stereo depth**: raw cam0/cam1 pairs + calibration → `.tgz` capture bundle → GrafikStation dense processing.

## Текущее состояние

Работают базовые сценарии Android capture, Room persistence, upload queue, загрузка phone video и серверный sparse SfM smoke test. Реализованы stereo capture/calibration contracts и pipeline для synced dense capture bundles. Качество реконструкции и production-стабильность отдельных viewer/dense веток ещё требуют системной проверки.

## Критичные ограничения

- raw stereo frames, detector input и calibration math нельзя вращать вслед за UI preview;
- смена режима Insta360 X4 должна подтверждаться через `camera.getOptions`;
- тяжёлая реконструкция не выполняется на телефоне;
- `MainActivity.kt`, `AppStateViewModel.kt`, `Repositories.kt` и `mobile.php` нельзя рефакторить одним большим изменением;
- изменение domain/Room/JSON/upload contract требует проверки Android и backend одновременно;
- модель не должна считать задачу выполненной без фактической сборки, audit и тестов.

## Связанные источники истины

- [`TZ.md`](../../TZ.md) — общее техническое задание;
- [`CAMERA_OSC_X4.md`](../../CAMERA_OSC_X4.md) — OSC-контракт Insta360 X4;
- [`TESTING.md`](../../TESTING.md) — сборка и ручная диагностика;
- [`APP_CAMERA_STEREO_CONTRACT.md`](../../app/MaklerTour/docs/APP_CAMERA_STEREO_CONTRACT.md) — stereo/calibration/depth invariants;
- [`CAPTURE_BUNDLE_DENSE_CONTRACT.md`](../../web/DOCS/CAPTURE_BUNDLE_DENSE_CONTRACT.md) — серверный synced dense contract;
- [`PHONE_SCAN_MVP_STATUS.md`](../../DOC/PHONE_SCAN_MVP_STATUS.md) — статус phone video/SfM MVP.
