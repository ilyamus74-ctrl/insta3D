Дата актуализации: 2026-05-09  
Рабочее название: `insta3D / MaklerTour`

---

## 1. Краткая концепция

Проект предназначен для съемки объектов недвижимости с помощью камеры Insta360 и Android-приложения оператора.

Главная идея:

- Android-приложение используется на объекте как полевой инструмент съемки;
- приложение подключается к Insta360 по Wi-Fi;
- оператор может снять один или несколько video scan-проходов по объекту;
- оператор снимает 360 photo points для финального тура;
- оператор может использовать AprilTag / ArUco markers известного размера для восстановления масштаба на backend;
- комнаты и названия комнат являются optional metadata;
- приложение локально сохраняет metadata, preview и ссылки на файлы камеры;
- оператор видит простой preview и понимает, что video scan и photo points сняты;
- тяжелые original/video файлы не выгружаются через мобильную сеть по умолчанию;
- при появлении нормального Wi-Fi приложение выгружает данные на backend;
- backend выполняет финальную обработку, реконструкцию, сборку тура, построение карты/схемы и расчет/уточнение размеров.
Android-приложение не является production-level 3D-редактором и не выполняет тяжелую финальную обработку тура на телефоне.

---

## 2. Цель проекта

Разработать Android-приложение на Kotlin + Jetpack Compose для операторов/маклеров, которое позволяет:

- авторизоваться оператору;
- получить список объектов/заявок;
- открыть карточку объекта;
- подключиться к Insta360 по Wi-Fi;
- снимать 360-точки (photo points);
- снимать video scan объекта;
- снимать несколько scan segments в рамках одной сессии;
- получать preview/thumbnail после каждой точки;
- сохранять список video scan файлов;
- показывать video scans в черновике;
- хранить marker config для AprilTag / ArUco;
- готовить данные для backend-реконструкции;
- сохранять сессию, комнаты, точки и preview локально;
- формировать черновик тура;
- проверять полноту съемки;
- подготовить очередь выгрузки heavy/original-файлов;
- выгрузить данные на backend только при нормальном Wi-Fi;
- получать статус серверной обработки.

Финальная сборка тура и работа с тяжелыми данными выполняются на сервере.

---

## 3. Основное разделение ответственности

### 3.1 Android-приложение

Android-приложение отвечает за:

- работу оператора на объекте;
- авторизацию;
- получение объектов/заявок;
- подключение к Insta360;
- съемку photo points;
- съемку video scan;
- управление режимами съемки: `Photo Point / Video Scan`;
- получение и показ preview;
- локальное хранение metadata;
- хранение scan video metadata;
- optional привязку точек к комнатам;
- optional ручное описание комнат;
- optional ручные связи между точками;
- стартовую точку тура;
- хранение marker config;
- локальную проверку наличия video scan/photo points;
- очередь выгрузки;
- проверку типа сети;
- фоновые загрузки;
- диагностику и экспорт логов.

Android-приложение не должно выполнять:

- тяжелый stitching/export full-quality;
- финальную сборку production-тура;
- SfM/MVS-реконструкцию на телефоне;
- marker detection как обязательный этап на телефоне;
- финальную карту/floorplan на телефоне;
- production-level viewer.

### 3.2 Backend

Backend отвечает за:

- авторизацию;
- хранение объектов/заявок;
- прием metadata;
- прием preview;
- прием original-файлов;
- chunked/resumable upload;
- проверку checksum;
- хранение originals;
- обработку video scan;
- frame extraction;
- marker detection;
- metric scale recovery по AprilTag / ArUco;
- SfM/MVS reconstruction;
- построение карты/схемы/навигационного графа;
- автоматическую или полуавтоматическую привязку photo points к реконструкции;
- возможное восстановление комнат/зон на сервере;
- финальную сборку тура;
- расчет или уточнение размеров помещений;
- хранение final tour;
- публикацию результата;
- выдачу статусов обработки.

---

## 4. Границы MVP

### 4.1 Входит в MVP

- авторизация оператора;
- список объектов/заявок;
- экран объекта;
- создание локальной съемочной сессии;
- подключение к Insta360 по Wi-Fi;
- получение статуса камеры;
- режимы съемки `Photo Point / Video Scan`;
- съемка 360-точек;
- создание scan video в сессии;
- старт/стоп video scan;
- сохранение scan video metadata локально;
- отображение scan videos в Draft screen;
- marker config как metadata, без обязательного распознавания на телефоне;
- получение preview после съемки;
- локальное хранение через Room;
- экран комнат/точек;
- черновик тура:
  - список точек;
  - reorder;
  - стартовая точка;
  - ручные связи между точками;
- optional привязка точек к комнатам;
- простая проверка полноты съемки;
- очередь загрузки originals через WorkManager;
- upload только по нормальному Wi-Fi;
- backend API через Retrofit;
- диагностический JSON;
- переключаемый CameraProvider: mock / Insta360 SDK / OSC.

### 4.2 Не входит в MVP

- финальная сборка production-тура на телефоне;
- тяжелая обработка original-файлов на телефоне;
- полноценный production-level 3D-viewer;
- SfM/MVS на телефоне;
- marker detection на телефоне как обязательная функция;
- финальная карта помещения на телефоне;
- автоматическое построение floorplan на телефоне;
- точная метрическая реконструкция на телефоне;
- автоматическое распознавание всех комнат;
- полностью автоматический расчет размеров помещений без дополнительных данных;
- биллинг;
- кабинет маклера;
- CRM;
- сложная аналитика.

---

## 5. Основной сценарий работы оператора

1. Оператор открывает приложение.
2. Авторизуется.
3. Открывает объект/сессию.
4. Подключается к Insta360 по Wi-Fi.
5. При необходимости размещает AprilTag / ArUco markers на объекте.
6. Выбирает режим `Видео-скан`.
7. Запускает video scan.
8. Делает один или несколько проходов по объекту с Insta360 в руке/на моноподе.
9. Останавливает video scan.
10. Приложение сохраняет scan video metadata.
11. Оператор переключается в режим `Фото-точка`.
12. Делает 360 photo points в нужных местах для финального тура.
13. Приложение сохраняет preview и карточки photo points.
14. Оператор открывает Draft screen и проверяет:
    - есть video scan;
    - есть photo points;
    - preview отображаются;
    - файлы привязаны к текущей сессии.
15. Комнаты, названия и ручные связи оператор может добавить, но это не обязательно.
16. При нормальном Wi-Fi приложение выгружает данные на backend.
17. Backend строит тур, карту/схему/граф и выполняет обработку размеров.
18. Приложение показывает статус обработки.

---

## 6. Работа с размерами помещений

### 6.1 Общий принцип

Размеры не являются обязательными для съемки в MVP. Ручные размеры также не обязательны.

Android-приложение должно собирать данные, которые помогут backend восстановить масштаб и геометрию:

- video scan;
- photo points;
- preview;
- original-файлы;
- marker config (если используются маркеры);
- optional metadata по комнатам/зонам;
- заметки оператора.

### 6.2 Источники размеров

Поддерживаемые источники размеров:

- `marker_scaled` — масштаб восстановлен по AprilTag / ArUco marker;
- `video_reconstruction` — размеры получены из серверной реконструкции video scan;
- `manual` — оператор ввел размеры вручную;
- `arcore` — отдельный режим измерения телефоном, если будет добавлен позже;
- `server_estimated` — backend рассчитал или уточнил размеры;
- `unknown` — размеры пока не заданы.

### 6.3 MVP-подход

Для MVP и MVP+ приоритетный сценарий:

- съемка возможна без ввода размеров;
- маркеры известного физического размера — основной способ scale recovery;
- Android хранит marker config;
- backend выполняет marker detection и scale recovery;
- если маркеров нет, backend может собрать `tour_only` или `tour_with_graph` без точной метрической карты;
- если маркеры есть, backend может пытаться построить `metric reconstruction / floorplan`.

## 6.4 Video Scan + Photo Points + Markers

### 6.4.1 Типы съемочных данных

**A. Video Scan**
- используется для реконструкции;
- может быть один на весь объект;
- может быть несколько scan segments;
- не требует комнат;
- хранится как отдельная сущность.

**B. Photo Point**
- используется для финального web-tour;
- имеет preview;
- может иметь `roomId`, но `roomId` optional;
- может быть связан с другими точками вручную или сервером.

**C. Marker**
- AprilTag / ArUco известного размера;
- нужен для восстановления масштаба;
- detection выполняется backend на первом этапе.

### 6.4.2 Комнаты как optional metadata

- комнаты не обязательны;
- отсутствие комнат не блокирует съемку;
- отсутствие комнат не блокирует upload;
- backend может восстановить зоны/комнаты позже;
- оператор может назвать комнаты вручную, если хочет.

### 6.4.3 Режимы результата backend

- `tour_only`;
- `tour_with_graph`;
- `tour_with_metric_reconstruction`;
- `tour_with_floorplan`.

---

## 7. Архитектура решения

### 7.1 Android App

Рекомендуемые модули приложения:

- `Auth`
- `Objects / Jobs`
- `Object Details`
- `Rooms`
- `Camera Control`
- `Capture Session`
- `Video Scan`
- `Draft Tour`
- `Marker Config`
- `Upload Queue`
- `Sync`
- `Settings`
- `Logs / Diagnostics`

### 7.2 Backend

Рекомендуемые backend-модули:

- Auth API;
- Objects API;
- Sessions API;
- Upload API;
- Processing Status API;
- task queue;
- frame extraction service;
- marker detection service;
- reconstruction service;
- floorplan/map service;
- panorama processing service;
- tour assembly service;
- storage originals;
- storage previews;
- storage final tours.

### 7.3 Camera Integration Layer

Интеграция с камерой должна быть спрятана за интерфейсом `CameraProvider`.

Нужны реализации:

- `MockCameraProvider` — для разработки без камеры;
- `Insta360OscProvider` — управление через OSC/HTTP;
- `Insta360SdkProvider` — управление через официальный Insta360 SDK, если доступ получен.

Базовый интерфейс:

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

---

## 8. Функциональные требования

### 8.1 Авторизация

Приложение должно поддерживать:

- логин/пароль;
- access token;
- refresh token;
- восстановление сессии;
- offline-сессию на ограниченный срок;
- выход из аккаунта.

Токены должны храниться безопасно.

### 8.2 Работа с объектами

Приложение должно:

- получать список назначенных объектов;
- показывать карточки объектов;
- показывать статус объекта;
- открывать детали объекта;
- создавать локальную съемочную сессию;
- работать с объектом offline после первичной загрузки данных.

Карточка объекта должна содержать:

- `object_id`;
- адрес;
- название;
- имя маклера/клиента;
- статус;
- дату/время заявки;
- количество комнат;
- статус съемки;
- статус выгрузки.

### 8.3 Работа с комнатами

Приложение должно позволять:

- создать комнату;
- переименовать комнату;
- выбрать тип комнаты;
- указать этаж;
- добавить заметку;
- вручную указать размеры;
- привязать точки съемки к комнате.

Типы комнат:

- прихожая;
- коридор;
- кухня;
- гостиная;
- спальня;
- санузел;
- ванная;
- балкон;
- кладовая;
- вход;
- другое.

### 8.4 Подключение к Insta360

Приложение должно:

- показывать статус подключения;
- инициировать подключение;
- определять модель камеры;
- показывать заряд;
- показывать свободное место;
- показывать режим съемки, если доступен;
- показывать ошибки подключения;
- проверять совместимость модели;
- учитывать сценарий, когда телефон подключен к Wi-Fi камеры без интернета.

### 8.5 Управление съемкой

Приложение должно поддерживать два режима съемки:

- `Photo Point`;
- `Video Scan`.

Для режима `Photo Point` используется текущая логика 360-фото.

Для режима `Video Scan` приложение должно поддерживать:

- старт записи;
- остановку записи;
- получение результата;
- сохранение scan video metadata;
- отображение статуса записи;
- защиту от повторного старта во время записи.

Общие требования:

- запускать съемку 360-точки;
- получать подтверждение завершения съемки;
- отображать прогресс;
- блокировать повторную команду до завершения предыдущей;
- получать список файлов камеры;
- определять новый файл после съемки;
- получать preview/thumbnail;
- сохранять preview локально;
- создавать локальную карточку точки.

### 8.5.1 Video Scan

Требования:

- создать video scan;
- начать запись;
- остановить запись;
- сохранить `cameraFileUrl/cameraLocalFileUrl`;
- хранить статус `recording/captured/failed`;
- отображать scan videos в Draft screen;
- не скачивать тяжелое video автоматически через mobile data;
- разрешить загрузку video только при нормальном Wi-Fi или вручную.

### 8.6 Карточка точки

После каждой съемки приложение сохраняет:

- `point_id`;
- `object_id`;
- `session_id`;
- `room_id`;
- `sequence_number`;
- `title`;
- `local_timestamp`;
- `camera_file_id`;
- `original_ref`;
- `preview_path`;
- `yaw`;
- `pitch`;
- `roll`;
- `notes`;
- `capture_status`;
- `upload_state`.

### 8.7 Черновик тура

Черновик тура — это не production-тур, а съемочная схема.

Приложение должно поддерживать:

- optional список комнат;
- список photo points;
- группировку точек по комнатам, если roomId задан;
- отдельный список video scans;
- preview точек;
- изменение порядка точек;
- ручные связи между точками;
- выбор стартовой точки;
- финализацию черновика;
- проверку полноты съемки.

Связь между точками:

- `from_point_id`;
- `to_point_id`;
- `connection_type`;
- `notes`.

### 8.8 Очередь загрузки

Приложение должно:

- формировать очередь upload-задач;
- отдельно загружать preview;
- отдельно загружать originals;
- поддерживать chunked upload;
- поддерживать resume upload;
- делать retry;
- считать checksum;
- показывать прогресс;
- показывать ошибки;
- продолжать загрузку после перезапуска приложения;
- разрешать удаление local originals только после подтвержденной успешной загрузки.

## File lifecycle / политика хранения и удаления файлов

Файлы проходят жизненный цикл:

1. `cameraFileUrl` — файл создан на Insta360.
2. `localPreviewPath` — preview скачан на телефон.
3. `localOriginalPath` — original скачан на телефон.
4. `serverUploadState = CONFIRMED` — сервер подтвердил прием файла.
5. После server-confirmed разрешено удаление local original с телефона.
6. Удаление файлов с камеры выполняется только вручную по кнопке для текущего проекта/сессии.

Для `scan video`:

1. Файл создается на Insta360.
2. Metadata сохраняется на телефоне.
3. Local video скачивается на телефон только по кнопке или перед upload.
4. Video не выгружается через mobile data по умолчанию.
5. Local video можно удалить только после `server-confirmed`.
6. Файл на камере удаляется только вручную.

Правила безопасности:

- файл на телефоне нельзя удалять до подтверждения сервера;
- файл на камере нельзя удалять автоматически сразу после скачивания preview;
- удалить файл с камеры до server-confirmed можно только вручную, если original уже скачан на телефон;
- удаление с камеры не влияет на статус server sync;
- preview можно хранить дольше, чтобы черновик оставался доступен offline;
- original на телефоне удаляется после успешной серверной синхронизации.

### 8.9 Диагностика

Приложение должно иметь экран диагностики и экспорт JSON.

Diagnostic JSON должен включать:

- версию приложения;
- пользователя;
- object_id;
- session_id;
- состояние камеры;
- состояние сети;
- список комнат;
- список точек;
- upload queue;
- ошибки камеры;
- ошибки backend;
- последние события.
- список scan videos;
- marker config;
- marker detections, если backend уже вернул;
- reconstruction status;
---

## 9. Нефункциональные требования

### 9.1 Offline-first

Приложение должно:

- сохранять данные локально;
- не терять сессию при закрытии;
- восстанавливать незавершенную съемку;
- восстанавливать очередь выгрузки;
- работать без интернета во время съемки.

### 9.2 Производительность

Приложение должно:

- выдерживать минимум 50 точек в одной сессии;
- открывать список точек менее чем за 2 секунды;
- кешировать preview локально;
- не выполнять тяжелые операции в UI-потоке;
- выполнять upload в фоне.

### 9.3 Надежность

Приложение должно:

- не терять сессию при убийстве процесса;
- не терять очередь загрузки;
- логировать ошибки;
- логировать действия оператора;
- корректно обрабатывать разрывы Wi-Fi;
- корректно обрабатывать отсутствие интернета.

### 9.4 Безопасность

Приложение должно:

- хранить токены в защищенном хранилище;
- использовать HTTPS для backend;
- не отправлять originals через мобильную сеть по умолчанию;
- поддерживать очистку originals после успешной загрузки;
- не хранить лишние чувствительные данные без необходимости.

---

## 10. Экранная модель

### 10.1 LoginScreen

Назначение:

- вход оператора;
- восстановление сессии;
- отображение ошибок авторизации.

### 10.2 ObjectsScreen

Назначение:

- список объектов/заявок;
- фильтры;
- статусы объектов;
- переход к объекту.

Фильтры:

- новые;
- в работе;
- снятые;
- ожидают выгрузки;
- выгружены;
- ошибка.

### 10.3 ObjectDetailsScreen

Назначение:

- данные объекта;
- список комнат;
- статус съемки;
- статус выгрузки;
- кнопка `Начать съемку`;
- кнопка `Черновик тура`;
- кнопка `Синхронизация`.

### 10.4 CameraConnectScreen

Назначение:

- подключение к Insta360;
- статус камеры;
- модель;
- заряд;
- storage;
- режим;
- ошибка подключения.

### 10.5 CaptureScreen

Назначение:

- режимы: `Photo Point` и `Video Scan`.

В режиме `Photo Point`:
- имя точки;
- кнопка `Снять точку`;
- preview.

В режиме `Video Scan`:
- имя scan video;
- кнопка `Начать видео-скан`;
- кнопка `Остановить видео-скан`;
- статус записи;
- список последних scan videos.

### 10.6 DraftTourScreen

Назначение:

- список комнат (optional структура);
- список точек;
- блок `Видео-сканы`;
- статусы scan videos;
- preview;
- reorder;
- ручные связи;
- выбор стартовой точки;
- финализация черновика.

### 10.7 UploadQueueScreen

Назначение:

- очередь выгрузки;
- статус сети;
- progress;
- retry;
- cancel;
- ручной запуск;
- запрет полной выгрузки без нормального Wi-Fi.

### 10.8 DiagnosticsScreen

Назначение:

- версия приложения;
- состояние сети;
- состояние камеры;
- состояние очереди;
- список ошибок;
- экспорт diagnostic JSON.

---

## 11. Данные и локальные модели

### 11.1 Object

```text
id
remote_id
title
address
broker_name
client_name
status
created_at
updated_at
```

### 11.2 Room

```text
id
object_id
session_id
title
room_type
floor
sequence_number
length_m
width_m
height_m
area_m2
measurement_source
notes
created_at
updated_at
```

`measurement_source`:

```text
marker_scaled
video_reconstruction
manual
arcore
server_estimated
unknown
```

### 11.3 CaptureSession

```text
id
object_id
started_at
completed_at
camera_model
camera_serial
total_points
draft_state
upload_state
created_at
updated_at
```

### 11.4 CapturePoint

```text
id
object_id
session_id
room_id
sequence_number
title
local_timestamp
camera_file_id
original_ref
preview_path
yaw
pitch
roll
notes
capture_status
upload_state
created_at
updated_at
```

`capture_status`:

```text
draft
capturing
captured
preview_ready
failed
```

`upload_state`:

```text
local_only
preview_queued
preview_uploaded
original_queued
original_uploading
original_uploaded
upload_error
```

### 11.5 ScanVideo

```text
id
object_id
session_id
name
sequence_number
camera_file_url
camera_local_file_url
local_preview_path
local_video_path
duration_sec
file_size_bytes
marker_expected
marker_detected
capture_status
download_state
upload_state
server_processing_state
created_at
updated_at
notes
```

`capture_status`:

```text
draft
recording
captured
failed
```

`download_state`:

```text
camera_only
downloading
downloaded
download_error
```

`upload_state`:

```text
local_only
queued
uploading
uploaded
confirmed
upload_error
```

`server_processing_state`:

```text
not_started
queued
processing
processed
failed
```

### 11.6 MarkerConfig

```text
id
session_id
marker_type
marker_dictionary
marker_size_m
description
created_at
updated_at
```

### 11.7 MarkerDetection

```text
id
session_id
scan_video_id
point_id
marker_type
marker_dictionary
marker_id
marker_size_m
frame_timestamp_ms
corners_json
confidence
created_at
```

Примечание: `MarkerDetection` на Android в MVP не обязателен; backend может вернуть `MarkerDetection` после обработки.

### 11.8 TourDraftConnection
```text
id
session_id
from_point_id
to_point_id
connection_type
notes
created_at
updated_at
```
### 11.9 UploadItem
```text
id
object_id
session_id
point_id
file_type
local_path
remote_upload_id
status
progress
retry_count
checksum
created_at
updated_at
last_error
```

`file_type`:

```text
preview
original
scan_video
metadata
marker_metadata
```

`status`:

```text
queued
waiting_for_wifi
uploading
success
error
cancelled
```

### 11.10 DiagnosticLog


```text
id
level
category
message
payload_json
created_at
```

---

## 12. Backend API

### 12.1 Auth

```http
POST /auth/login
POST /auth/refresh
POST /auth/logout
```

### 12.2 Objects

```http
GET /objects
GET /objects/{id}
```

### 12.3 Sessions

```http
POST /sessions
GET /sessions/{id}
POST /sessions/{id}/complete
```

### 12.4 Rooms

```http
POST /sessions/{session_id}/rooms
PUT /rooms/{id}
```

### 12.5 Points

```http
POST /sessions/{session_id}/points
PUT /points/{id}
```

### 12.6 Drafts

```http
POST /drafts
PUT /drafts/{id}
POST /drafts/{id}/finalize
```

### 12.7 Upload

```http
POST /upload/init
PUT /upload/chunk
POST /upload/complete
GET /upload/{id}/status
```

### 12.8 Scan Videos

```http
POST /sessions/{session_id}/scan-videos
PUT /scan-videos/{id}
```

### 12.9 Markers

```http
POST /sessions/{session_id}/markers
GET /sessions/{session_id}/markers
```

### 12.10 Processing
```http
POST /processing/{object_id}/start
GET /processing/{object_id}/status
POST /processing/{object_id}/start-reconstruction
GET /processing/{object_id}/reconstruction-status
```

### 12.11pload requirements

Backend upload должен поддерживать:

- chunked upload;
- resume upload;
- checksum;
- подтверждение успешной записи;
- отдельную загрузку previews, originals и scan video;
- повторную отправку только недостающих частей;
- статус обработки после загрузки.

---

## 13. Логика работы с сетью

### 13.1 Состояния сети

```text
CAMERA_WIFI_NO_INTERNET
CAMERA_WIFI_WITH_PROXY
NORMAL_WIFI
MOBILE_DATA
OFFLINE
```

### 13.2 Правила

Если `CAMERA_WIFI_NO_INTERNET`:

- съемка разрешена;
- работа с камерой разрешена;
- upload на backend запрещен;
- приложение показывает предупреждение, что интернет недоступен.

Если `NORMAL_WIFI`:

- разрешена полная выгрузка;
- разрешен auto-upload;
- разрешена синхронизация с backend.

Если `MOBILE_DATA`:

- originals не выгружаются по умолчанию;
- preview можно выгружать только если это разрешено настройками;
- ручная полная выгрузка возможна только после явного подтверждения.

Если `OFFLINE`:

- доступна только локальная работа;
- все upload-задачи остаются в очереди.

---

## 14. Технический стек Android

Рекомендуемый стек:

- Kotlin;
- Jetpack Compose;
- MVVM;
- Kotlin Coroutines;
- StateFlow;
- Navigation Compose;
- Hilt;
- Room;
- WorkManager;
- OkHttp;
- Retrofit;
- Kotlin Serialization или Moshi;
- Coil;
- DataStore;
- EncryptedSharedPreferences / Android Keystore;
- Timber или аналог логирования.

### 14.1 Почему этот стек

- `Room` нужен для локального offline-first хранилища.
- `WorkManager` нужен для надежной фоновой выгрузки.
- `Retrofit/OkHttp` нужны для backend API.
- `Hilt` нужен для нормальной замены mock/real-реализаций.
- `Compose` ускоряет MVP UI.
- `CameraProvider` изолирует UI от конкретного способа работы с Insta360.

---

## 15. Текущий статус проекта

На текущем этапе уже есть:

- Android-проект на Kotlin + Jetpack Compose;
- базовая навигация;
- основные MVP-экраны;
- доменные модели;
- `CameraProvider`;
- `MockCameraProvider`;
- заглушка `Insta360Provider`;
- mock upload API;
- upload queue;
- diagnostic JSON;
- временное локальное хранение через SharedPreferences/JSON.

Текущие ограничения:

- Room еще не внедрен;
- WorkManager еще не внедрен;
- Hilt еще не внедрен;
- Retrofit еще не подключен как реальный API-клиент;
- реальная Insta360 OSC-интеграция частично реализована;
- photo capture реализован частично/полностью по текущему статусу кода;
- Video Scan еще не реализован;
- marker workflow еще не реализован;
- backend reconstruction еще не реализован;
- Room/RoomMeasurement модель еще не добавлена;
- upload пока не является production-grade background pipeline.

---


---

## 16. Прогресс реализации

Этот раздел фиксирует, что уже реализовано в текущем Android-проекте, что реализовано временно, а что еще предстоит сделать.

Обозначения:

- `[x]` — реализовано;
- `[~]` — частично реализовано или временная реализация;
- `[ ]` — не реализовано.

### 16.1 Базовый Android-каркас

- [x] Создан Android-проект на Kotlin.
- [x] Используется Jetpack Compose.
- [x] Используется Material 3.
- [x] Настроена базовая навигация.
- [x] Созданы основные MVP-вкладки/экраны:
  - Sessions;
  - Camera;
  - Draft;
  - Queue.
- [x] Добавлен централизованный `AppStateViewModel`.
- [x] Используется `StateFlow` для состояния UI.

Комментарий: текущие экраны являются MVP-заглушками и рабочим каркасом, но позже их нужно привести к целевой экранной модели: `LoginScreen`, `ObjectsScreen`, `ObjectDetailsScreen`, `CameraConnectScreen`, `CaptureScreen`, `DraftTourScreen`, `UploadQueueScreen`, `DiagnosticsScreen`.

### 16.2 Доменные модели

- [x] Создана модель `Session`.
- [x] Создана модель `CapturePoint`.
- [x] Создана модель `CameraStatus`.
- [x] Создана модель `UploadItem`.
- [x] Созданы enum/status-модели для capture/upload.
- [~] Создана полноценная модель `Object`.
- [~] Создана полноценная модель `Room`.
- [~] Создана модель `TourDraftConnection`.
- [~] Создана модель `DiagnosticLog`.


Комментарий: текущие модели покрывают первый mock-MVP, но для реального сценария недвижимости нужно добавить структуру `Object → Room → CapturePoint`.

### 16.3 Локальное хранение

- [x] Реализованы repository-интерфейсы.
- [x] Реализован in-memory repository для разработки.
- [x] SharedPreferences/JSON больше не является основным хранилищем; основное хранилище — Room.
- [X] Добавлен Room.
- [X] Созданы Room entities.
- [X] Созданы DAO.
- [ ] Реализованы миграции Room.
- [X] Очередь upload хранится в Room.
- [X] Черновик тура хранится в Room.
- [~] Diagnostic logs хранятся в Room.

Комментарий: `SharedPreferences + JSON` считается временным решением. Целевое хранилище — Room.

### 16.4 Интеграция с камерой

- [x] Создан интерфейс `CameraProvider`.
- [x] Реализован `MockCameraProvider`.
- [x] Создана заглушка `Insta360Provider`.
- [X] Реализован `Insta360OscProvider`.
- [ ] Реализован `Insta360SdkProvider`.
- [X] Проверено подключение к реальной Insta360.
- [X] Получен реальный статус камеры.
- [X] Выполнена реальная съемка из приложения.
- [ ] Получен список файлов с камеры.
- [~] Получен preview/thumbnail с камеры.
- [x] Получен fileUrl снятого файла с камеры.
- [x] Добавлены OSC profiles под разные модели.

Комментарий: текущий проект готов архитектурно к подключению камеры, но реальная Insta360-интеграция еще не выполнена.

### 16.5 Съемочная сессия и точки

- [x] Можно создать локальную сессию.
- [x] Можно выбрать сессию.
- [x] Можно добавить mock-точку съемки.
- [x] Можно переименовать точку.
- [x] Можно удалить точку.
- [~] Можно менять порядок точек кнопками вверх/вниз.
- [x] Активная сессия отображается в UI.
- [x] Если сессии нет, приложение предлагает создать новую.
- [x] Можно добавить реальную точку съемки через Insta360.
- [x] Можно переименовать точку.
- [x] Можно удалить точку.
- [x] Точки сохраняются в Room.
- [x] Статус точки сохраняется в Room.
- [x] previewUri/fileUrl сохраняется в Room.
- [x] Реализована авто-нумерация названий точек.
- [~] Черновик тура реализован в упрощенном виде.
- [ ] Есть полноценная привязка точек к комнатам.
- [ ] Есть стартовая точка тура.
- [ ] Есть ручные связи между точками.
- [ ] Есть проверка полноты съемки по комнатам.
- [ ] Есть ввод размеров комнаты.

Комментарий: текущий черновик покрывает только базовый список точек и порядок. Для реального тура нужна структура комнат и связей.

### 16.6 Preview и originals

- [x] После съемки сохраняется camera fileUrl.
- [x] Preview отображается в черновике через remote fileUrl.
- [x] В черновике виден статус точки.
- [~] Mock preview создается как `mock://preview/...`.
- [~] Получение реального preview с Insta360.
- [ ] Локальное кеширование preview-файлов.
- [ ] Хранение ссылок на original-файлы.
- [ ] Подсчет размера локальной съемки.
- [ ] Очистка originals после успешной загрузки.

Комментарий: preview пока имитируется. Реальная работа с файлами камеры будет частью Insta360 PoC.

### 16.7 Upload и backend

- [x] Создан `UploadApi` interface.
- [x] Реализован `MockUploadApi`.
- [x] Добавлен mock polling статуса обработки.
- [x] Добавлены retry-попытки.
- [x] Добавлен `retryCount`.
- [x] Добавлен черновой REST/OpenAPI-контракт.
- [x] Вынесены `baseUrl` и feature flags в `BuildConfig`.
- [x] Очередь upload сохраняется в Room.
- [~] Есть заглушка `BackendUploadApi`.
- [ ] Подключен Retrofit как реальный backend-клиент.
- [ ] Реализован auth API.
- [ ] Реализован objects API.
- [ ] Реализован sessions API.
- [ ] Реализован rooms/points API.
- [ ] Реализован chunked upload.
- [ ] Реализован resume upload.
- [ ] Реализован checksum.
- [ ] Реализован processing status с backend.

Комментарий: backend сейчас смоделирован. Реальный обмен с сервером еще не реализован.

### 16.8 Очередь загрузки и сеть

- [x] Есть upload queue в UI.
- [x] Есть статусы upload:
  - queued;
  - uploading;
  - success;
  - error.
- [x] Добавлена проверка активного Wi-Fi перед постановкой в очередь.
- [x] Добавлена валидация: минимум 5 точек перед enqueue.
- [x] Показываются toast-уведомления по результату постановки в очередь.
- [~] Очередь сохраняется врем через `SharedPreferences + JSON`.
- [x] Очередь сохраняется через Room.
- [ ] Очередь работает через Room.
- [ ] Upload выполняется через WorkManager.
- [ ] Upload продолжается после перезапуска приложения.
- [ ] Есть полноценное определение:
  - `CAMERA_WIFI_NO_INTERNET`;
  - `NORMAL_WIFI`;
  - `MOBILE_DATA`;
  - `OFFLINE`.
- [ ] Полная выгрузка автоматически стартует только при `NORMAL_WIFI`.

Комментарий: логика Wi-Fi уже частично есть, но production-ready upload pipeline еще не реализован.

### 16.9 Диагностика

- [x] Реализован экспорт diagnostic JSON.
- [x] Diagnostic JSON содержит:
  - selected session;
  - camera status;
  - sessions;
  - points;
  - upload queue.
- [ ] Есть отдельный `DiagnosticsScreen`.
- [ ] Есть persist-лог ошибок.
- [ ] Есть категории логов:
  - camera;
  - network;
  - upload;
  - backend;
  - storage.
- [ ] Есть экспорт логов файлом.

Комментарий: базовый diagnostic JSON уже есть, но полноценный журнал диагностики еще нужно добавить.

### 16.10 Dependency Injection и архитектура

- [x] Есть разделение на domain/data/state.
- [x] Есть repository-интерфейсы.
- [x] Есть возможность заменить mock camera provider.
- [x] UI отделен от конкретной реализации камеры через CameraProvider.
- [~] DI сейчас выполняется вручную через создание объектов в `MainActivity`.
- [ ] Добавлен Hilt.
- [ ] Настроены Hilt modules.
- [ ] Провайдеры camera/repository/api выбираются через DI.
- [ ] UI полностью отделен от конкретных data-реализаций.

Комментарий: архитектурная основа есть, но Hilt еще не внедрен.

### 16.11 Итоговый статус на текущий момент

Текущий проект закрывает первый mock-MVP:

- базовый Android UI;
- создание сессий;
- mock-подключение камеры;
- mock-съемка точек;
- редактирование точек;
- упрощенный черновик;
- mock upload;
- retry;
- Wi-Fi check;
- diagnostic JSON.

Следующие приоритеты:

1. Добавить Room и нормальную структуру `Object → Room → CaptureSession → CapturePoint`.
2. Добавить Hilt.
3. Добавить WorkManager для upload.
4. Сделать Insta360 OSC/SDK PoC.
5. Подключить Retrofit к backend API.


## 17. Этапы реализации

### 17.1 Phase 1 — базовый Android-каркас

Цель: подготовить структуру приложения без реальной камеры и backend, но с правильной архитектурой под дальнейшее развитие.

Состав работ:

- проектная структура;
- модели данных;
- navigation graph;
- fake repository;
- базовый state management;
- `CameraProvider`;
- `MockCameraProvider`;
- базовые экраны:
  - `LoginScreen`;
  - `ObjectsScreen`;
  - `ObjectDetailsScreen`;
  - `CameraConnectScreen`;
  - `CaptureScreen`;
  - `DraftTourScreen`;
  - `UploadQueueScreen`.

Критерии готовности:

- приложение запускается;
- можно пройти основной сценарий на fake/mock-данных;
- можно открыть объект;
- можно создать локальную сессию;
- можно добавить mock-точки;
- точки отображаются в черновике;
- можно менять порядок точек;
- можно назначить стартовую точку;
- upload queue отображает mock-задачи;
- UI не зависит напрямую от конкретной реализации камеры.

### 17.2 Phase 2 — локальное хранилище

Цель: заменить временное хранилище на полноценную Room DB.

Состав работ:

- добавить Room;
- создать entities;
- создать DAO;
- создать migrations;
- заменить SharedPreferences/JSON repositories на Room repositories;
- добавить Room-модель комнат;
- добавить Room-модель связей черновика;
- добавить Room-модель upload queue;
- добавить diagnostic logs.

Критерии готовности:

- объект сохраняется локально;
- комнаты сохраняются локально;
- сессия сохраняется локально;
- точки сохраняются локально;
- черновик восстанавливается после перезапуска;
- очередь upload восстанавливается после перезапуска;
- diagnostic JSON содержит объект, комнаты, точки, очередь и ошибки.

### 17.3 Phase 3 — Insta360 Proof of Concept

Цель: подтвердить управление реальной камерой.

Возможные пути:

- Insta360 SDK, если есть доступ;
- Insta360 OSC/HTTP, если SDK пока недоступен.

Минимальный PoC:

- телефон подключен к Wi-Fi камеры;
- приложение получает статус камеры;
- приложение определяет модель;
- приложение получает заряд;
- приложение получает свободное место;
- приложение запускает съемку;
- приложение ожидает завершения команды;
- приложение получает список файлов;
- приложение получает preview/thumbnail;
- приложение создает локальную карточку точки.

Критерии готовности:

- приложение видит реальную камеру;
- оператор может сделать снимок из приложения;
- после съемки появляется карточка точки;
- preview сохраняется локально;
- приложение не отправляет следующую команду до завершения предыдущей.

### 17.4 Phase 4 — надежная очередь выгрузки

Цель: реализовать production-ready upload pipeline.

Состав работ:

- добавить WorkManager;
- создать UploadWorker;
- добавить network constraints;
- реализовать preview upload;
- реализовать original upload;
- реализовать chunked upload;
- реализовать resume upload;
- реализовать retry;
- реализовать checksum;
- реализовать отображение progress;
- реализовать очистку local originals после успешной загрузки.

Критерии готовности:

- upload продолжается после перезапуска приложения;
- upload не стартует при `CAMERA_WIFI_NO_INTERNET`;
- upload стартует при `NORMAL_WIFI`;
- ошибки сохраняются в diagnostic logs;
- после успешной выгрузки originals можно очистить локально.

### 17.5 Phase 5 — Backend Sync

Цель: связать Android-приложение с backend.

Состав работ:

- Retrofit API client;
- авторизация;
- refresh token;
- получение объектов;
- создание сессии на backend;
- отправка комнат;
- отправка точек;
- отправка draft-структуры;
- upload originals;
- запуск обработки;
- получение статусов обработки.

Критерии готовности:

- backend получает полный пакет данных;
- объект структурирован по комнатам и точкам;
- upload подтвержден backend;
- приложение показывает статус обработки;
- ошибки backend отображаются и логируются.

---

## 18. Критерии приемки MVP

MVP считается готовым, если:

- оператор может авторизоваться;
- оператор видит список объектов;
- оператор может открыть объект;
- оператор может создать съемочную сессию;
- приложение подключается к Insta360;
- приложение показывает статус камеры;
- можно снять минимум 20 точек подряд без потери сессии;
- после каждой точки появляется preview;
- точки сохраняются локально;
- комнаты сохраняются локально;
- можно собрать черновой маршрут;
- можно выбрать стартовую точку;
- можно задать ручные связи между точками;
- приложение восстанавливает незавершенную сессию после перезапуска;
- originals не выгружаются по мобильной сети по умолчанию;
- по нормальному Wi-Fi идет полная выгрузка;
- upload возобновляется после разрыва;
- backend получает metadata, previews и originals;
- приложение показывает статус обработки;
- после успешной выгрузки local originals можно очистить;
- diagnostic JSON можно экспортировать.

---

## 19. Основные риски

### 19.1 Доступ к Insta360 SDK

Доступ к официальному SDK может требовать регистрации и одобрения. Поэтому архитектура должна поддерживать альтернативный путь через OSC/HTTP.

### 19.2 Совместимость моделей

Нужно зафиксировать список поддерживаемых моделей для MVP и не пытаться сразу поддержать все камеры.

### 19.3 Wi-Fi камеры без интернета

Когда телефон подключен к Wi-Fi камеры, интернет может быть недоступен. Это нормальный сценарий, который должен быть явно учтен в UX и логике сети.

### 19.4 Размеры помещений

Одна 360-панорама сама по себе не гарантирует надежный расчет размеров. Для MVP нужно поддержать ручной ввод размеров и сбор данных для серверной обработки.

### 19.5 Ресурсы телефона

Нельзя закладываться на тяжелую обработку на телефоне. Телефон должен собирать данные, показывать preview и выгружать материалы позже.

---

## 20. Ближайший практический план

### Шаг 1

Привести проект к целевой структуре:

- screens;
- viewmodels;
- repositories;
- data/local;
- data/remote;
- data/camera;
- domain/models;
- domain/usecases;
- workers.

### Шаг 2

Добавить Room и модели:

- Object;
- Room;
- CaptureSession;
- CapturePoint;
- TourDraftConnection;
- UploadItem;
- DiagnosticLog.

### Шаг 3

Добавить Hilt и dependency injection:

- mock camera provider;
- future OSC camera provider;
- repositories;
- upload services.

### Шаг 4

Сделать Insta360 PoC:

- status;
- capture;
- file list;
- preview.
### 4. Обновить backlog

## Ближайший backlog

### P0 — стабилизация текущего MVP

- [x] Подключение к Insta360 X4 по Wi-Fi.
- [x] Фото-точки через OSC.
- [x] Background preview download.
- [x] Video Scan start/stop через OSC.
- [x] Переключение `image -> video`.
- [x] Переключение `video -> image`.
- [x] Сохранение scan video metadata.
- [ ] Проверить отображение video scans в Draft screen.
- [ ] Проверить, что scan video привязан к текущей sessionId.
- [ ] Проверить, что после stop video app не теряет camera status.
- [ ] Добавить нормальный экран/блок статуса записи.
- [ ] Добавить защиту от повторного start video.
- [ ] Добавить кнопку retry для failed scan.
- [ ] Привести Room migration/version в порядок.
- [ ] Сократить шумные debug logs после стабилизации.

### P1 — локальные файлы

- [ ] Единая структура хранения:
  - `sessions/<sessionId>/previews/`
  - `sessions/<sessionId>/originals/`
  - `sessions/<sessionId>/videos/`
- [ ] Скачивание video original только вручную или перед upload.
- [ ] Статусы local file:
  - camera only;
  - downloading;
  - downloaded;
  - download failed.
- [ ] Не удалять файлы с камеры автоматически.

### P2 — upload/backend

- [ ] Очередь upload.
- [ ] Upload только по нормальному Wi-Fi.
- [ ] Preview upload.
- [ ] Original/photo upload.
- [ ] Video upload.
- [ ] Server processing status.
### Шаг 5

Перевести upload на WorkManager.

---

## 21. Краткая формула проекта

Телефон:

```text
снять → проверить preview → подписать комнаты/точки → сохранить локально → выгрузить позже
```

Сервер:

```text
принять originals → структурировать → обработать → собрать тур → рассчитать/уточнить размеры
```



## 18. Итоговый продуктовый принцип

- оператор снимает быстро;
- комнаты и названия не обязательны;
- video scan + markers дают backend материал для карты и масштаба;
- photo points дают качественный финальный тур;
- Android показывает только локальный контроль/preview;
- backend делает тяжелую обработку.

### 16.12 Video Scan

- [ ] Domain model `ScanVideo`.
- [ ] Room entity `ScanVideoEntity`.
- [ ] `ScanVideoDao`.
- [ ] `CameraProvider.startVideoScan()`.
- [ ] `CameraProvider.stopVideoScan()`.
- [ ] Insta360 OSC video mode.
- [ ] CameraScreen mode switch `Photo Point / Video Scan`.
- [ ] DraftScreen video scans block.
- [ ] Download scan video to phone.
- [ ] Upload scan video to backend.

### 16.13 Markers

- [ ] `MarkerConfig` model.
- [ ] Marker config UI.
- [ ] Marker metadata upload.
- [ ] Backend marker detection.
- [ ] Backend scale recovery.

### 16.14 Backend reconstruction

- [ ] frame extraction.
- [ ] marker detection.
- [ ] SfM/MVS reconstruction.
- [ ] metric scale recovery.
- [ ] map/floorplan generation.
- [ ] photo point matching.
- [ ] final tour assembly.

