# insta3D / MaklerTour — потоки данных

> Файл: `docs/llm/05_DATA_FLOWS.md`
> Актуализация: 2026-07-15
> Статус: описание фактических и контрактных потоков
> Источник схемы: `web/MySqlDump/maklertour_schema_20260715_163926.sql.gz`
> Назначение: зафиксировать движение данных между Android, камерами, Room, backend, MySQL, server storage, workers, GrafikStation и web viewer.

---

# 1. Назначение документа

Документ описывает:

* источник данных;
* потребителя;
* порядок обработки;
* владельца состояния;
* идентификаторы;
* запись в Room;
* запись в MySQL;
* работу с файлами;
* state transitions;
* ошибки;
* точки диагностики;
* обязательные проверки.

Основная системная цепочка:

```text
Оператор
→ Android UI
→ ViewModel
→ Camera / local files
→ Room
→ Upload queue
→ HTTP API
→ MySQL + server storage
→ Processing job
→ Local worker или GrafikStation
→ Artifacts
→ Web viewer
```

---

# 2. Общие правила движения данных

## DF-RULE-001 — сначала identity, затем media

До передачи media должны быть известны:

```text
order_id
app_session_uuid
capture_session_id
entity UUID
```

Media не должно сохраняться как анонимный файл без связи с бизнес-объектом.

## DF-RULE-002 — база и filesystem выполняют разные роли

```text
Database:
    identity
    state
    metadata
    paths
    timestamps
    ownership

Filesystem:
    JPEG
    MP4
    TGZ
    JSON
    JSONL
    PLY
    processing artifacts
    logs
```

## DF-RULE-003 — Android и сервер имеют разные ID

Android создаёт:

```text
app_session_uuid
app_point_uuid
app_scan_uuid
app_bundle_uuid
```

Backend создаёт:

```text
capture_sessions.id
photo_points.id
video_scans.id
capture_bundles.id
processing job IDs
pipeline run IDs
```

Связь должна сохранять оба идентификатора.

## DF-RULE-004 — путь не является идентификатором

Нельзя использовать filename или storage path как единственный ID объекта.

Путь может измениться при:

* переносе storage;
* cleanup;
* миграции;
* повторной обработке;
* изменении deployment root.

## DF-RULE-005 — success только после сохранения

Порядок серверной записи:

```text
validate request
→ authorize
→ resolve business object
→ write file
→ verify file
→ write/update DB record
→ return success
```

Если DB update не завершился, API не должен возвращать окончательный успех.

## DF-RULE-006 — raw data неизменяемы

Исходные media после capture не должны изменяться.

Производные данные сохраняются отдельно:

```text
raw/
preview/
rectified/
disparity/
depth/
viewer/
```

## DF-RULE-007 — state transition должен иметь владельца

Каждый статус изменяет один определённый слой.

Пример:

```text
Android UploadItem.status
    владелец: Android upload queue

video_scans.upload_state
    владелец: backend upload flow

sfm_pipeline_runs.status
    владелец: pipeline coordinator

sfm_remote_jobs.status
    владелец: remote worker
```

---

# 3. Фактическая структура MySQL

Свежий schema dump содержит 32 таблицы.

## 3.1 Пользователи и доступ

```text
users
mobile_tokens
form_tokens
role_permissions
role_menu
menu_groups
menu_items
audit_logs
```

## 3.2 Заявки и capture

```text
tour_orders
capture_sessions
photo_points
capture_points
video_scans
capture_bundles
uploads
```

## 3.3 Tour graph и viewer

```text
tour_point_positions
tour_point_links
photo_cleanup_masks
public_tour_links
sfm_viewer_settings
sfm_session_settings
sfm_user_settings
sfm_debug_public_links
```

## 3.4 Markers и reconstruction

```text
marker_kit_layout
marker_detections
processing_jobs
video_sfm_runs
sfm_pipeline_runs
sfm_remote_jobs
sfm_remote_cleanup_runs
sfm_keyframe_points
sfm_generated_model_merges
```

---

# 4. Логические связи MySQL

В schema dump отсутствуют объявленные `FOREIGN KEY`.

Связи обеспечиваются:

* кодом PHP;
* индексами;
* проверками API;
* соглашениями об ID.

Это означает, что MySQL самостоятельно не предотвращает orphan records.

## 4.1 Основная иерархия

```text
users
  ├── tour_orders.broker_id
  ├── tour_orders.operator_id
  ├── capture_sessions.operator_id
  ├── mobile_tokens.user_id
  ├── uploads.user_id
  └── settings / audit / created_by fields

tour_orders
  └── capture_sessions
        ├── photo_points
        ├── video_scans
        ├── capture_bundles
        ├── uploads
        ├── processing_jobs
        ├── video_sfm_runs
        ├── sfm_pipeline_runs
        ├── sfm_remote_jobs
        ├── marker_detections
        ├── tour_point_positions
        ├── tour_point_links
        └── viewer/public settings
```

## 4.2 Pipeline hierarchy

```text
sfm_pipeline_runs
  ├── sfm_remote_jobs
  ├── sfm_remote_cleanup_runs
  ├── sfm_viewer_settings
  └── source_pipeline_run_id → previous sfm_pipeline_runs.id

video_sfm_runs
  └── sfm_keyframe_points.video_sfm_run_id
```

## 4.3 Photo graph

```text
photo_points
  ├── tour_point_positions.photo_point_id
  ├── tour_point_links.from_photo_point_id
  ├── tour_point_links.to_photo_point_id
  └── photo_cleanup_masks.photo_point_id
```

## 4.4 Риск отсутствия foreign keys

Перед удалением parent record код обязан проверить или обработать child records.

Особенно критичны:

```text
tour_orders
capture_sessions
photo_points
video_scans
sfm_pipeline_runs
```

---

# 5. Основные идентификаторы

| Объект          | Android ID         | Server ID                              | Уникальность в MySQL                         |
| --------------- | ------------------ | -------------------------------------- | -------------------------------------------- |
| User            | отсутствует        | `users.id`                             | primary key                                  |
| Order           | `order_id` сервера | `tour_orders.id`                       | primary key                                  |
| Capture session | `app_session_uuid` | `capture_sessions.id`                  | UUID уникален глобально                      |
| Photo point     | `app_point_uuid`   | `photo_points.id`                      | уникален в пределах session                  |
| Video scan      | `app_scan_uuid`    | `video_scans.id`                       | UUID уникален глобально                      |
| Capture bundle  | `app_bundle_uuid`  | `capture_bundles.id`                   | индекс есть, unique constraint отсутствует   |
| Pipeline run    | отсутствует        | `sfm_pipeline_runs.id`                 | primary key                                  |
| Remote job      | отсутствует        | `sfm_remote_jobs.id` и `remote_job_id` | indexes, не unique                           |
| Legacy SfM run  | отсутствует        | `video_sfm_runs.id`                    | unique по order/session directory/video path |

## 5.1 Риск `capture_bundles`

Для:

```text
capture_bundles.app_bundle_uuid
```

есть обычный index:

```text
(capture_session_id, app_bundle_uuid)
```

но нет `UNIQUE`.

Идемпотентность bundle upload должна обеспечиваться кодом либо отдельным unique constraint после проверки существующих данных.

---

# 6. DF01 — Авторизация Android

## 6.1 Источник

```text
Оператор
→ login/password
```

## 6.2 Android-компоненты

```text
Login UI
→ MobileAuthApi
→ AuthStorage
```

## 6.3 Backend

```text
mobile auth endpoint
→ users
→ mobile_tokens
```

## 6.4 Таблица `users`

Ключевые поля:

```text
id
username
email
password_hash
full_name
phone
is_active
role
last_login_at
login_count
last_login_ip
last_user_agent
```

Роли:

```text
ADMIN
BROKER
OPERATOR
CLIENT
```

## 6.5 Таблица `mobile_tokens`

```text
id
user_id
token_hash
device_name
device_fingerprint
expires_at
last_used_at
created_at
```

Сервер хранит hash токена:

```text
token_hash CHAR(64)
```

Raw token не должен сохраняться в MySQL или логах.

## 6.6 Поток

```text
Android login form
→ HTTPS/HTTP request
→ lookup users
→ password verification
→ user active check
→ create random token
→ hash token
→ INSERT mobile_tokens
→ return raw token Android
→ save in AuthStorage
```

## 6.7 Последующие запросы

```text
Authorization: Bearer <raw-token>
→ hash received token
→ lookup mobile_tokens.token_hash
→ check expires_at
→ load users
→ check is_active
→ update last_used_at
```

## 6.8 Ошибки

```text
invalid credentials
inactive user
expired token
unknown token
malformed Authorization header
database unavailable
```

## 6.9 Диагностика

Разрешено логировать:

```text
user_id
device_fingerprint
HTTP status
error code
```

Запрещено логировать:

```text
password
raw token
password_hash
full Authorization header
```

---

# 7. DF02 — Получение и выбор заявки

## 7.1 Источник истины

```text
tour_orders
```

## 7.2 Ключевые поля

```text
id
broker_id
operator_id
title
address
area_m2
customer_name
customer_phone
customer_email
status
is_published
public_token
created_at
updated_at
closed_at
operator_closed_at
broker_closed_at
```

## 7.3 Статусы заявки

```text
NEW
ASSIGNED
IN_PROGRESS
CAPTURED
UPLOADING
UPLOADED
PROCESSING
READY
COMPLETED
CLOSED
CANCELLED
```

## 7.4 Поток

```text
Android Orders screen
→ MobileOrdersApi
→ Bearer authentication
→ determine current user and role
→ SELECT allowed tour_orders
→ JSON response
→ OrdersRepository
→ Compose state
→ operator selects order
```

## 7.5 Правила доступа

Оператор видит:

* назначенную ему заявку;
* опубликованную свободную заявку, если такой сценарий разрешён;
* только допустимые статусы.

Broker видит свои заявки.

Admin может видеть все заявки.

## 7.6 Локальное состояние

Выбранная заявка находится в:

```text
AppStateViewModel.selectedOrder
```

После привязки session её server identity должна сохраняться в Room, а не только в Compose state.

## 7.7 Ошибки

```text
order not found
forbidden
order already closed
operator mismatch
stale Android order state
```

---

# 8. DF03 — Создание capture session

## 8.1 Android

Android сначала создаёт локальную session:

```text
Session.id / app_session_uuid
name
address
comment
serverOrderId
```

Локальная session сохраняется в Room.

## 8.2 Server request

```text
order_id
app_session_uuid
```

## 8.3 Backend table

```text
capture_sessions
```

Ключевые поля:

```text
id
order_id
operator_id
app_session_uuid
camera_model
status
started_at
completed_at
deleted_at
deleted_by
delete_reason
```

## 8.4 Server status

```text
LOCAL_ONLY
CAPTURED
UPLOADING
UPLOADED
PROCESSING
READY
FAILED
```

## 8.5 Поток

```text
Android local session
→ enqueue/upload preparation
→ create_session API
→ authenticate operator
→ validate tour_orders.id
→ verify operator access
→ lookup app_session_uuid
→ INSERT or return existing capture_sessions
→ return capture_session_id
→ save server ID in Android Room
```

## 8.6 Идемпотентность

`capture_sessions.app_session_uuid` имеет global unique constraint.

Повторный `create_session` должен:

```text
найти существующую session
→ проверить тот же order/operator
→ вернуть тот же capture_session_id
```

Нельзя создавать новый UUID при обычном retry.

## 8.7 Ошибки

```text
order not found
forbidden
order closed
UUID belongs to another order
operator mismatch
DB conflict
```

---

# 9. DF04 — Insta360 photo point

## 9.1 Capture flow

```text
Operator
→ Camera screen
→ AppStateViewModel.capturePoint()
→ CameraProvider.capture()
→ Insta360OscProvider
→ camera mode verification
→ camera.takePicture
→ command polling
→ camera file URL
→ CapturePoint
```

## 9.2 Локальная запись

```text
CapturePoint
→ SessionRepository
→ Room CapturePointEntity
```

Локально сохраняются:

```text
app point UUID
session ID
name
sequence
camera URL
capture status
local preview path
local original path
server upload state
```

## 9.3 Preview flow

```text
camera file URL
→ OscFileDownloader
→ preview file
→ local filesystem
→ update Room path
```

Preview download выполняется отдельно от capture.

Ошибка preview не должна отменять успешно снятый point.

## 9.4 Backend table

Основная активная таблица по текущему upload contract:

```text
photo_points
```

Поля:

```text
id
session_id
app_point_uuid
name
room_name
sequence_number
camera_file_url
camera_local_path
preview_storage_path
original_storage_path
preview_size_bytes
original_size_bytes
upload_state
initial_yaw_deg
initial_pitch_deg
initial_hfov
deleted_at
```

## 9.5 Upload flow

```text
Android point
→ UploadItem
→ MobileUploadApi.uploadPhotoPoint()
→ multipart request
→ mobile.php
→ validate order/session
→ save preview/original
→ INSERT or UPDATE photo_points
→ return success/server ID
→ Android marks point CONFIRMED
```

## 9.6 Идемпотентность

Unique key:

```text
(session_id, app_point_uuid)
```

Retry должен обновлять или возвращать существующий point, а не создавать duplicate.

## 9.7 Storage

```text
order
└── session
    └── photo point media
        ├── preview
        └── original
```

## 9.8 Ошибки

```text
no media file
zero-byte file
wrong session
duplicate UUID conflict
storage error
DB insert error
partial preview/original write
```

---

# 10. DF05 — `capture_points` и `photo_points`

В schema одновременно существуют:

```text
capture_points
photo_points
```

Обе таблицы содержат сходные данные.

## 10.1 `capture_points`

```text
session_id
app_point_uuid
title
room_name
sequence_number
preview_path
original_path
upload_state
```

## 10.2 `photo_points`

```text
session_id
app_point_uuid
name
room_name
sequence_number
camera_file_url
camera_local_path
preview_storage_path
original_storage_path
sizes
upload_state
viewer orientation
soft delete
```

## 10.3 Текущий вывод

`photo_points` выглядит более полной актуальной моделью.

`capture_points` может быть:

* legacy table;
* таблицей старого upload flow;
* промежуточной MVP-реализацией.

До удаления или объединения необходимо выполнить code usage audit:

```bash
grep -R "capture_points" web app
grep -R "photo_points" web app
```

## 10.4 Запрет

Нельзя:

* записывать одну точку в обе таблицы без явного контракта;
* удалять `capture_points` только потому, что существует `photo_points`;
* считать обе таблицы взаимозаменяемыми.

---

# 11. DF06 — Insta360 video scan

## 11.1 Start flow

```text
Operator presses Start
→ AppStateViewModel.startVideoScan()
→ create local ScanVideo(RECORDING)
→ CameraProvider.startVideoScan()
→ verify video mode
→ camera.startCapture
→ UI state RECORDING
```

## 11.2 Stop flow

```text
Operator presses Stop
→ UI state STOPPING
→ CameraProvider.stopVideoScan()
→ camera.stopCapture
→ obtain file URL
→ update ScanVideo(CAPTURED)
→ save in Room
```

## 11.3 Optional local download

```text
camera file URL
→ OscFileDownloader
→ sessions/<session>/videos/<file>.mp4
→ Room localVideoPath
→ downloadState=DOWNLOADED
```

## 11.4 Backend table

```text
video_scans
```

Ключевые поля:

```text
id
session_id
app_scan_uuid
filename
local_camera_url
storage_path
size_bytes
duration_sec
upload_state
processing_state
label
deleted_at
```

## 11.5 Upload states

```text
LOCAL_ONLY
QUEUED
UPLOADING
UPLOADED
FAILED
```

## 11.6 Processing states

```text
NOT_STARTED
PROCESSING
DONE
FAILED
```

## 11.7 Upload flow

```text
local ScanVideo
→ UploadItem
→ upload_video_scan
→ server temp/final file
→ verify size
→ INSERT/UPDATE video_scans
→ upload_state=UPLOADED
→ Android upload success
```

## 11.8 Идемпотентность

```text
video_scans.app_scan_uuid
```

имеет global unique constraint.

Retry должен находить существующую запись.

---

# 12. DF07 — Phone camera video

## 12.1 Capture

```text
CameraX
→ PhoneCameraScanProvider
→ PhoneCameraVideoRecorder
→ video.mp4
```

## 12.2 Metadata

Параллельно создаются:

```text
camera_info.json
manifest.json
imu.jsonl
```

## 12.3 Local layout

```text
sessions/<sessionId>/phone_scans/<scanId>/
├── video.mp4
├── camera_info.json
├── manifest.json
└── imu.jsonl
```

## 12.4 Room

После остановки:

```text
ScanVideo.source = PHONE_CAMERA
ScanVideo.captureStatus = CAPTURED
ScanVideo.localVideoPath = .../video.mp4
ScanVideo.downloadState = DOWNLOADED
```

## 12.5 Upload

```text
video.mp4
+ optional metadata
→ MobileUploadApi
→ normal multipart или chunked upload
→ mobile.php
→ video_scans
→ server storage
```

## 12.6 Processing

```text
video_scans.id
→ sfm_pipeline_runs.video_scan_id
или legacy processing_jobs/video_sfm_runs flow
→ frame extraction
→ sparse reconstruction
```

## 12.7 Ошибки

```text
CameraX bind failed
recording start failed
zero-byte MP4
metadata write failed
upload interrupted
source stream unsupported
```

Optional metadata failure не удаляет валидный MP4.

---

# 13. DF08 — Chunked video upload

## 13.1 Client decision

```text
file size <= 200 MiB
→ ordinary multipart upload

file size > 200 MiB
→ chunked upload
```

## 13.2 Chunk parameters

```text
chunk size = 8 MiB
max retries = 3
chunk_index = 0..N-1
```

## 13.3 Flow

```text
open source MP4
→ read range
→ send chunk metadata + bytes
→ server validates upload identity
→ write temporary chunk/file range
→ acknowledge chunk
→ next chunk
```

## 13.4 Finalization

```text
last chunk
→ verify all chunks
→ assemble/finalize
→ compare final size with total_size
→ move into final storage
→ create/update video_scans
→ return upload_complete=true
```

## 13.5 Server identity

Chunks должны группироваться минимум по:

```text
authenticated user
order_id
capture_session_id
upload_id
app_scan_uuid
```

## 13.6 Metadata

Phone metadata может передаваться с последним chunk:

```text
camera_info
manifest
imu
```

## 13.7 Upload tracking table

Схема содержит общую таблицу:

```text
uploads
```

Поля:

```text
user_id
order_id
session_id
entity_type
entity_id
original_filename
storage_path
mime_type
size_bytes
sha256
state
created_at
completed_at
```

Состояния:

```text
INIT
UPLOADING
COMPLETED
FAILED
```

Поддерживаемые `entity_type`:

```text
POINT_PREVIEW
POINT_ORIGINAL
VIDEO_SCAN
```

Capture bundle в enum отсутствует и хранится отдельно в `capture_bundles`.

## 13.8 Ошибки

```text
missing chunk
duplicate conflicting chunk
wrong total_size
wrong chunk_index
expired temp upload
server restart
assembly failure
checksum mismatch
```

---

# 14. DF09 — Synced stereo capture

## 14.1 Источники кадров

```text
cam0 = CameraX phone camera
cam1 = USB UVC camera
```

## 14.2 Capture threads

```text
cam0 callback/thread
→ raw frame + timestamp

cam1 native callback/thread
→ raw frame + timestamp
```

## 14.3 Pairing

```text
cam0 ring buffer
+
cam1 ring buffer
→ nearest timestamp selection
→ deltaMs calculation
→ compare with stereoMaxDeltaMs
```

Рабочий предел:

```text
30 ms
```

## 14.4 Successful pair

```text
cam0 raw frame
cam1 raw frame
cam0 timestamp
cam1 timestamp
midpoint timestamp
delta
orientation metadata
pair index
```

## 14.5 Ownership

До асинхронного сохранения native frame должен быть скопирован в память, которой владеет Android processing layer.

Нельзя сохранять pointer на UVC callback buffer после завершения callback без явной гарантии native API.

## 14.6 Raw coordinate rule

```text
rotation applied to saved frame = 0
```

Display preview может быть повёрнут отдельно.

## 14.7 Ошибки

```text
cam0 unavailable
cam1 unavailable
USB disconnected
buffer released
unsupported MJPEG
delta too large
pair queue overflow
filesystem full
```

---

# 15. DF10 — Stereo calibration

## 15.1 Input

```text
synced raw cam0/cam1 pairs
→ ChArUco detection
```

## 15.2 Correspondence

```text
commonIds = cam0 IDs ∩ cam1 IDs
```

Points связываются по marker ID, а не по позиции в массиве.

## 15.3 Quality gates

```text
manual minimum common IDs = 35
auto minimum common IDs = 38
minimum final pairs = 10
```

## 15.4 Calibration

```text
cam0 intrinsics
cam1 intrinsics
synced object/image points
→ stereoCalibrate(CALIB_FIX_INTRINSIC)
→ R, T, E, F
```

## 15.5 Filtering

```text
initial fit
→ per-pair epipolar error
→ reject outliers
→ refit
→ final errors
```

## 15.6 Output

```text
stereo_extrinsics.json
active_rig_profile.json
calibration sidecar/debug JSON
```

## 15.7 Downstream consumers

```text
CaptureBundlePackager
GrafikStation dense pipeline
diagnostics
```

## 15.8 Ошибки

```text
insufficient common IDs
insufficient pairs
resolution mismatch
high RMS
invalid matrix
wrong coordinate rotation
T unit unknown
```

---

# 16. DF11 — Capture bundle

## 16.1 Producer

```text
CaptureBundlePackager
```

## 16.2 Input

```text
synced raw pairs
synced_depth_manifest.json
stereo_extrinsics.json
active_rig_profile.json
session/order metadata
```

## 16.3 Temporary assembly

Рекомендуемый порядок:

```text
create temporary bundle directory
→ copy/write manifests
→ add raw pairs
→ verify required files
→ create .tgz.tmp
→ close archive
→ rename to .tgz
```

## 16.4 Package

```text
bundle_manifest.json
capture/synced_depth_manifest.json
capture/pairs/
calibration/stereo_extrinsics.json
rig/active_rig_profile.json
```

## 16.5 Queue

После успешного packaging:

```text
UploadItem.uploadType = CAPTURE_BUNDLE
UploadItem.localFilePath = final .tgz
UploadItem.status = QUEUED
```

## 16.6 Upload

```text
.tgz
→ upload_capture_bundle
→ server storage
→ capture_bundles
```

## 16.7 Таблица `capture_bundles`

```text
id
order_id
capture_session_id
app_bundle_uuid
capture_type
filename
storage_path
size_bytes
status
created_at
updated_at
```

## 16.8 Server state

Default:

```text
status = UPLOADED
```

В дальнейшем рекомендуется ограниченный enum:

```text
UPLOADING
UPLOADED
VALIDATED
QUEUED
PROCESSING
READY
FAILED
```

## 16.9 Dense eligibility

```text
capture_type = synced_depth_frames
→ dense processing allowed

capture_type = stereo_video_legacy
→ audit/download only
```

---

# 17. DF12 — Generic processing job

## 17.1 Таблица

```text
processing_jobs
```

## 17.2 Поля

```text
session_id
order_id
job_type
status
metric_status
marker_expected
marker_kit_id
marker_dictionary
marker_size_m
markers_detected_count
warning_text
error_text
```

## 17.3 Unique constraint

```text
UNIQUE(session_id, job_type)
```

Это означает: в таблице может существовать только одна запись конкретного `job_type` для session.

## 17.4 Важное ограничение

Flow, который пытается создавать новую историческую запись после каждого повторного запуска, конфликтует с текущей схемой.

Допустимы два подхода:

### Подход A — reuse

```text
найти существующий processing_jobs
→ reset fields
→ update status to QUEUED
→ повторно использовать row
```

### Подход B — history

Изменить constraint и добавить отдельный run identity.

До отдельного решения нельзя предполагать, что `processing_jobs` хранит историю запусков.

## 17.5 Поток

```text
API request
→ validate order/session
→ check active/existing job
→ INSERT or UPDATE processing_jobs
→ worker atomically changes status
→ execute
→ update status/error/metrics
```

## 17.6 `warning_text` как parameters storage

Текущий legacy flow может хранить JSON parameters в `warning_text`.

Это технический долг.

Целевое поле:

```text
parameters_json
```

как в новых pipeline tables.

---

# 18. DF13 — Legacy video SfM flow

## 18.1 Tables

```text
processing_jobs
video_sfm_runs
sfm_keyframe_points
marker_detections
```

## 18.2 Job type

```text
SFM_VIDEO_PIPELINE
```

## 18.3 Input

```text
video_scans.storage_path
или server-resolved video path
```

## 18.4 Worker flow

```text
processing_jobs QUEUED
→ atomically set RUNNING
→ resolve video
→ create session SfM directory
→ extract frames
→ extract keyframes
→ write camera profile
→ detect AprilTags
→ COLMAP feature extraction
→ sequential matching
→ mapper
→ model conversion
→ parse poses
→ rough scale
→ finalize run
→ export sparse artifacts
```

## 18.5 Таблица `video_sfm_runs`

Содержит:

```text
order_id
session_id
session_dir
video_path
sfm_base_path
status
metric_status
frames_count
keyframes_count
marker_count
poses_count
scale_ok
scale_factor
scale_samples
artifact paths
warning_text
error_text
```

## 18.6 Status

```text
NOT_STARTED
RUNNING
PROCESSED
FAILED
```

Metric status:

```text
NOT_READY
METRIC_READY
FAILED
```

## 18.7 Keyframe materialization

```text
video_sfm_runs
→ keyframes and poses
→ sfm_keyframe_points
```

Fields include:

```text
keyframe_index
keyframe_name
keyframe_path
nearest_frame
x_scaled
y_scaled
z_scaled
distance_from_prev_m
segment_break
```

## 18.8 Output

```text
sfm_result_summary.json
viewer_keyframes/
markers/
colmap/
trajectory/
3d/
logs/
```

---

# 19. DF14 — New SFM pipeline run

## 19.1 Main table

```text
sfm_pipeline_runs
```

## 19.2 Input identity

```text
order_id
capture_session_id
video_scan_id
pipeline_mode
max_image_size
```

## 19.3 Pipeline modes

```text
preview
standard
fullhd
```

## 19.4 Status

```text
QUEUED
RUNNING
DONE
ERROR
CANCELLED
CANCELLING
RESTARTING
```

## 19.5 Stage

```text
QUEUED
EXTRACT_FRAMES
SPARSE
SPARSE_COMPLETE
DENSE_PLAN
DENSE
MERGE
MESH
FETCH_RESULT
DONE
ERROR
CANCELLED
CANCELLING
```

## 19.6 Flow

```text
user starts pipeline
→ INSERT sfm_pipeline_runs
→ status QUEUED
→ create root remote job
→ root_remote_job_id saved
→ worker updates stage/progress
→ remote jobs perform work
→ fetch result
→ validate artifacts
→ status DONE
```

## 19.7 Metrics

```text
extracted_frames
registered_images
registration_ratio
sparse_models_count
selected_model_id
selected_model_points
sparse_points
dense_points
mesh_vertices
mesh_faces
```

## 19.8 Diagnostics

```text
sparse_diagnostics_json
sparse_reprojection_p95
sparse_position_jumps
sparse_pose_clusters
camera_trajectory_path
world_alignment_path
```

## 19.9 Restart and derivation

```text
source_pipeline_run_id
run_scope
completed_stage
```

Новый run может ссылаться на предыдущий, но не должен изменять его artifacts.

---

# 20. DF15 — Remote job

## 20.1 Table

```text
sfm_remote_jobs
```

## 20.2 Identity

```text
id
pipeline_run_id
job_type
remote_job_id
parent_remote_job_id
```

## 20.3 Status

```text
QUEUED
RUNNING
DONE
ERROR
ERROR_EMPTY
ERROR_OOM
RUNNING_CHUNKS
PLANNING
MERGING
CANCELLING
CANCELLED
```

## 20.4 Flow

```text
sfm_pipeline_runs
→ create sfm_remote_jobs row
→ prepare input_path
→ transfer/start GrafikStation job
→ status RUNNING
→ poll/read progress
→ receive result.json
→ copy outputs to output_path
→ validate
→ status DONE
→ update parent pipeline
```

## 20.5 Chunked processing

Fields:

```text
reconstruction_mode
chunk_index
chunk_count
parent_remote_job_id
```

Flow:

```text
planning job
→ child chunk jobs
→ individual results
→ merge job
→ parent pipeline result
```

## 20.6 Retry

```text
retry_count
```

Retry должен либо очищать старый output, либо использовать новый isolated execution directory.

## 20.7 Cancellation

```text
cancel_requested_at
cancelled_at
```

Переход:

```text
RUNNING
→ CANCELLING
→ CANCELLED
```

Cancellation не должна маркировать partial artifacts как final.

---

# 21. DF16 — GrafikStation execution

## 21.1 Input

```text
remote job identity
parameters_json
input_path
source media/bundle
```

## 21.2 Station path

```text
input/job_<remote_job_id>/
output/job_<remote_job_id>/
```

Фактические deployment paths задаются configuration.

## 21.3 Runtime

```text
job runner
→ processing script
→ Podman/container или host tools
→ NVIDIA GPU
→ output artifacts
→ result.json
```

## 21.4 Ownership

GrafikStation владеет временными:

```text
input copy
working directory
container state
temporary output
```

Backend владеет:

```text
business job state
order/session identity
final artifact registration
```

## 21.5 Failure modes

```text
station unavailable
input transfer incomplete
container start failed
CUDA/driver mismatch
GPU OOM
disk full
worker timeout
result.json missing
output transfer incomplete
```

---

# 22. DF17 — Synced dense processing

## 22.1 Job source

```text
capture_bundles.id
capture_type = synced_depth_frames
```

## 22.2 Remote job parameters

```text
capture_bundle_id
capture_type
max_pairs
num_disparities
block_size
```

## 22.3 Flow

```text
download/copy bundle
→ unpack
→ validate directory structure
→ parse bundle manifest
→ parse synced depth manifest
→ load stereo calibration
→ select pairs
→ rectify cam0/cam1
→ inspect rectified projection matrices
→ determine baseline axis
→ prepare matcher input
→ disparity
→ depth
→ summary/debug
→ result.json
```

## 22.4 Horizontal baseline

```text
abs(P2[0,3]) >= abs(P2[1,3])
→ disparity already on X
→ Q may be used when otherwise valid
```

## 22.5 Vertical baseline

```text
abs(P2[1,3]) > abs(P2[0,3])
→ rotate both rectified images identically
→ disparity search becomes X
→ do not blindly reuse original Q
→ calculate Z using f * B / disparity
```

## 22.6 Artifacts

```text
dense/contact_dense_depth.jpg
dense/dense_depth_debug.json
dense/dense_depth_summary.csv
result.json
```

## 22.7 Validation

```text
valid_depth_ratio
baseline_magnitude
focal_for_depth
disparity range
pair count
calibration resolution
```

---

# 23. DF18 — Marker detection and metric scale

## 23.1 Marker layout

Table:

```text
marker_kit_layout
```

Identity:

```text
marker_kit_id
marker_dictionary
marker_id
```

Geometry:

```text
x_m
y_m
z_m
yaw_deg
pitch_deg
roll_deg
marker_size_m
surface_type
```

## 23.2 Detections

Table:

```text
marker_detections
```

Fields:

```text
session_id
source_type
source_id
source_path
frame_index
timestamp_ms
marker_kit_id
marker_dictionary
marker_id
marker_size_m
corners_json
center_x
center_y
confidence
```

## 23.3 Flow

```text
source frame
→ AprilTag detector
→ marker ID and corners
→ marker_detections
→ match marker_kit_layout
→ estimate scale/alignment
→ update reconstruction metrics
```

## 23.4 Processing job summary

```text
processing_jobs.marker_expected
processing_jobs.marker_kit_id
processing_jobs.marker_dictionary
processing_jobs.marker_size_m
processing_jobs.markers_detected_count
```

## 23.5 Errors

```text
unknown marker ID
wrong dictionary
wrong physical size
duplicate detection
invalid corners JSON
marker layout mismatch
```

---

# 24. DF19 — Photo point placement and links

## 24.1 Position table

```text
tour_point_positions
```

Fields:

```text
session_id
photo_point_id
x_m
y_m
z_m
yaw_deg
source
```

Unique:

```text
photo_point_id
```

Одна photo point имеет одну актуальную position row.

## 24.2 Link table

```text
tour_point_links
```

Fields:

```text
session_id
from_photo_point_id
to_photo_point_id
yaw_deg
pitch_deg
target_yaw_deg
target_pitch_deg
target_hfov
label
source
shared_markers_json
confidence
```

## 24.3 Manual flow

```text
user selects source point
→ selects target point
→ chooses hotspot orientation
→ INSERT/UPDATE tour_point_links
→ viewer renders transition
```

## 24.4 Automatic flow

```text
SfM positions
+ marker relations
+ keyframe matching
→ propose point position/link
→ source = automatic type
→ confidence saved
```

## 24.5 Errors

```text
point belongs to another session
self-link
link references deleted point
duplicate direction
missing target view orientation
```

Отсутствие foreign keys требует проверять эти условия кодом.

---

# 25. DF20 — Viewer

## 25.1 Sources

```text
photo_points
tour_point_positions
tour_point_links
sfm_keyframe_points
sfm_pipeline_runs
video_sfm_runs
processing artifacts
viewer settings
```

## 25.2 Settings

```text
sfm_viewer_settings
sfm_session_settings
sfm_user_settings
```

`settings_json` хранит пользовательские или session-specific настройки.

## 25.3 Flow

```text
user opens order/session
→ authorize
→ load active capture session
→ load photo/video data
→ load latest successful pipeline run
→ verify artifacts
→ load settings
→ render viewer
```

## 25.4 Viewer rules

Viewer:

* не изменяет raw media;
* не запускает тяжёлую processing внутри page request;
* не строит absolute path из client input;
* не показывает artifacts failed job как final;
* учитывает soft-deleted points/scans.

---

# 26. DF21 — Public tour links

## 26.1 Table

```text
public_tour_links
```

Fields:

```text
order_id
session_id
token
is_active
expires_at
created_by
```

## 26.2 Flow

```text
authorized user
→ create random public token
→ INSERT public_tour_links
→ return public URL
→ anonymous request
→ lookup token
→ check active/expiry
→ resolve order/session
→ render restricted viewer
```

## 26.3 Security rules

* token должен иметь достаточную entropy;
* token нельзя использовать как sequential ID;
* inactive/expired link запрещён;
* public viewer не должен открывать server filesystem;
* private debug artifacts не должны становиться public автоматически.

---

# 27. DF22 — Public SfM debug link

## 27.1 Table

```text
sfm_debug_public_links
```

Fields:

```text
token_hash
order_id
capture_session_id
created_by
expires_at
revoked_at
last_accessed_at
access_count
options_json
```

## 27.2 Отличие от tour link

```text
public_tour_links:
    пользовательский просмотр тура

sfm_debug_public_links:
    технический доступ к SfM diagnostics
```

## 27.3 Security

В базе хранится:

```text
token_hash
```

Raw token передаётся только пользователю.

Debug link должен иметь:

* expiry;
* revoke;
* access counter;
* ограниченный scope;
* фильтрацию private paths и secrets.

---

# 28. DF23 — Cleanup

## 28.1 Soft delete

Таблицы с soft delete:

```text
capture_sessions
photo_points
video_scans
```

Поля:

```text
deleted_at
deleted_by
delete_reason
```

## 28.2 Rule

Soft-deleted row:

* не отображается как active;
* не выбирается для нового processing;
* не удаляет файл немедленно;
* остаётся доступной для audit/recovery согласно policy.

## 28.3 Remote cleanup

Table:

```text
sfm_remote_cleanup_runs
```

Fields:

```text
pipeline_run_id
remote_job_id
remote_cleanup_status
remote_cleanup_started_at
remote_cleanup_finished_at
remote_cleanup_freed_bytes
remote_cleanup_result_json
remote_cleanup_last_error
remote_cleanup_attempts
next_attempt_at
```

## 28.4 Flow

```text
pipeline result safely fetched
→ verify server artifacts
→ enqueue remote cleanup
→ delete station working data
→ record freed bytes/result
```

Нельзя удалять remote artifacts до подтверждения server copy.

## 28.5 Local pipeline artifact cleanup

`sfm_pipeline_runs` содержит:

```text
artifacts_deleted_at
artifacts_deleted_json
```

Cleanup должен сохранять список удалённых artifacts.

---

# 29. DF24 — Audit logging

## 29.1 Table

```text
audit_logs
```

Fields:

```text
event_time
user_id
event_type
entity_type
entity_id
ip_address
user_agent
description
extra_data
```

## 29.2 Events

Рекомендуется логировать:

```text
login
logout
order assignment
order close
capture session create/delete
public link create/revoke
processing start/cancel/retry
artifact delete
role/permission change
```

## 29.3 Data policy

`extra_data` не должна содержать:

* password;
* raw token;
* customer data без необходимости;
* private key;
* полный dump request body с secrets.

---

# 30. State transition map

## 30.1 Order

```text
NEW
→ ASSIGNED
→ IN_PROGRESS
→ CAPTURED
→ UPLOADING
→ UPLOADED
→ PROCESSING
→ READY
→ COMPLETED
→ CLOSED
```

Альтернативный terminal state:

```text
CANCELLED
```

Некоторые стадии могут пропускаться текущим MVP, но переход должен быть контролируемым.

## 30.2 Capture session

```text
LOCAL_ONLY
→ CAPTURED
→ UPLOADING
→ UPLOADED
→ PROCESSING
→ READY
```

Ошибка:

```text
FAILED
```

## 30.3 Video upload

```text
LOCAL_ONLY
→ QUEUED
→ UPLOADING
→ UPLOADED
```

Ошибка:

```text
FAILED
```

## 30.4 Upload tracker

```text
INIT
→ UPLOADING
→ COMPLETED
```

Ошибка:

```text
FAILED
```

## 30.5 New pipeline

```text
QUEUED
→ RUNNING
→ DONE
```

Ошибки/управление:

```text
ERROR
CANCELLING
CANCELLED
RESTARTING
```

## 30.6 Remote job

```text
QUEUED
→ RUNNING
→ DONE
```

Расширенные ветки:

```text
PLANNING
RUNNING_CHUNKS
MERGING
CANCELLING
CANCELLED
ERROR
ERROR_EMPTY
ERROR_OOM
```

---

# 31. Transaction boundaries

## 31.1 Create capture session

Одна DB transaction должна охватывать:

```text
lookup order
check access
lookup existing UUID
insert session
```

## 31.2 Media upload

Filesystem и MySQL не образуют общей транзакции.

Необходим compensating flow:

```text
write temporary file
→ verify
→ DB transaction
→ rename/finalize
```

или:

```text
finalize file
→ DB transaction
→ при DB failure удалить/карантинировать orphan file
```

## 31.3 Processing completion

```text
verify result.json
verify required artifacts
→ DB transaction:
    update remote job
    update pipeline run
    update source processing state
```

## 31.4 Delete

```text
mark DB row deleted
→ hide from active queries
→ asynchronous cleanup according retention policy
```

---

# 32. Concurrency boundaries

## 32.1 Android capture

Одновременно разрешено не более одной active capture/recording operation соответствующего provider.

## 32.2 Upload

Один upload item не должен обрабатываться двумя coroutines/workers одновременно.

## 32.3 Generic processing job

Atomic state change должен предотвращать захват двумя workers.

## 32.4 Pipeline run

Один pipeline coordinator владеет изменением stage.

Child remote jobs могут выполняться параллельно, но parent state обновляется централизованно.

## 32.5 File writers

Manifest и `result.json` имеют одного writer на конкретный job/capture.

Reader получает только atomic finalized file.

---

# 33. Диагностические ID

Каждая подсистема должна включать в логи доступные IDs.

## Android

```text
orderId
localSessionId
serverCaptureSessionId
pointId
scanId
bundleUuid
uploadId
```

## Backend

```text
userId
orderId
captureSessionId
photoPointId
videoScanId
captureBundleId
```

## Processing

```text
processingJobId
pipelineRunId
remoteJobId
videoSfmRunId
```

## Pair processing

```text
captureBundleId
pairIndex
cam0TimestampNs
cam1TimestampNs
deltaMs
```

---

# 34. Критические точки потери данных

## 34.1 Capture завершён, Room не записан

Результат:

```text
файл существует, объект отсутствует в UI
```

Необходимо recovery scan локального session directory.

## 34.2 Room записан, файл отсутствует

Результат:

```text
metadata показывает media, upload невозможен
```

Необходимо file existence validation перед queue.

## 34.3 Server file сохранён, DB insert упал

Результат:

```text
orphan server file
```

Необходим quarantine/cleanup.

## 34.4 DB сообщает upload success, файл повреждён

Необходимы:

```text
size
checksum
media validation
```

## 34.5 Remote result получен частично

Pipeline не должен переходить в `DONE`.

## 34.6 Raw stereo pair перекодирован или повёрнут

Calibration/depth результат становится математически недостоверным.

Это критическая необратимая ошибка capture data.

---

# 35. Известные несогласованности схемы

## 35.1 Нет foreign keys

Связи не защищены MySQL.

## 35.2 Две point-таблицы

```text
capture_points
photo_points
```

Нужно определить legacy boundary.

## 35.3 Несколько processing моделей

```text
processing_jobs + video_sfm_runs
sfm_pipeline_runs + sfm_remote_jobs
```

Legacy и новый pipeline существуют одновременно.

## 35.4 Разные status conventions

Используются:

```text
SUCCESS / FAILED
DONE / ERROR
PROCESSED / FAILED
COMPLETED / FAILED
UPLOADED / FAILED
```

Нельзя автоматически преобразовывать их по одинаковым строкам.

## 35.5 Generic job unique constraint

```text
UNIQUE(session_id, job_type)
```

не допускает историю повторных generic jobs.

## 35.6 Bundle UUID не уникален

`app_bundle_uuid` индексирован, но не защищён unique constraint.

## 35.7 Разные integer signedness

Некоторые ID:

```text
BIGINT UNSIGNED
```

другие:

```text
BIGINT
```

Перед добавлением foreign keys типы потребуется унифицировать.

## 35.8 Несколько storage roots

В runtime-коде могут использоваться разные абсолютные storage paths.

Logical path должен быть отделён от deployment root.

---

# 36. Правила работы LLM с data flow

Перед изменением LLM должна указать:

```text
Source:
Destination:
Identity fields:
State owner:
Database tables:
Filesystem paths:
Thread/process:
Failure point:
Retry behavior:
Required tests:
```

## Пример

```text
Task:
Добавить camera_model к phone scan.

Source:
Android camera metadata.

Destination:
video_scans или отдельная metadata запись.

Identity:
app_scan_uuid + session_id.

Producer files:
PhoneCameraInfoCollector.kt
MobileUploadApi.kt

Consumer files:
mobile.php
MySQL migration
SfM camera profile builder

Compatibility:
Поле optional для старого APK.
```

---

# 37. Минимальный контекст для flow-задач

## Upload task

```text
05_DATA_FLOWS.md
04_CONTRACTS.md
MobileUploadApi.kt
AppStateViewModel upload section
mobile.php relevant action
relevant MySQL table
storage helper
```

## Database task

```text
latest schema dump
all code references to table
API endpoint
worker
viewer consumer
migration scripts
```

## Processing task

```text
job creation API
pipeline table schema
worker
remote runner
result contract
viewer/status endpoint
```

## Stereo task

```text
stereo contract
capture manager
native UVC boundary
manifest writer
calibration processor
bundle packager
dense processor
```

---

# 38. Работа с MySQL dump

Для LLM и документации допускается хранить:

```text
schema-only dump
synthetic fixtures
anonymized test data
```

Не следует хранить в Git:

```text
production full dump
real password hashes
mobile token hashes
customer phone/email
public access tokens
private user settings
real audit logs
```

Рекомендуемая структура:

```text
web/MySqlDump/
├── README.md
├── maklertour_schema_<date>.sql.gz
└── fixtures/
    └── synthetic_minimal.sql
```

`README.md` должен указывать:

* версию MariaDB;
* дату schema;
* команду создания;
* содержит ли файл данные;
* правила восстановления;
* запрет production secrets.

---

# 39. Проверки целостности

Рекомендуемые периодические проверки:

```sql
-- Sessions without orders
SELECT cs.id
FROM capture_sessions cs
LEFT JOIN tour_orders o ON o.id = cs.order_id
WHERE o.id IS NULL;

-- Photo points without sessions
SELECT p.id
FROM photo_points p
LEFT JOIN capture_sessions cs ON cs.id = p.session_id
WHERE cs.id IS NULL;

-- Video scans without sessions
SELECT v.id
FROM video_scans v
LEFT JOIN capture_sessions cs ON cs.id = v.session_id
WHERE cs.id IS NULL;

-- Pipeline runs without sessions
SELECT r.id
FROM sfm_pipeline_runs r
LEFT JOIN capture_sessions cs ON cs.id = r.capture_session_id
WHERE cs.id IS NULL;

-- Remote jobs without pipeline run
SELECT j.id
FROM sfm_remote_jobs j
LEFT JOIN sfm_pipeline_runs r ON r.id = j.pipeline_run_id
WHERE j.pipeline_run_id IS NOT NULL
  AND r.id IS NULL;

-- Tour positions without point
SELECT p.id
FROM tour_point_positions p
LEFT JOIN photo_points pp ON pp.id = p.photo_point_id
WHERE pp.id IS NULL;

-- Links with missing points
SELECT l.id
FROM tour_point_links l
LEFT JOIN photo_points p1 ON p1.id = l.from_photo_point_id
LEFT JOIN photo_points p2 ON p2.id = l.to_photo_point_id
WHERE p1.id IS NULL OR p2.id IS NULL;
```

Эти проверки особенно важны до добавления foreign keys.

---

# 40. Краткое резюме

```text
Android Room
хранит локальное capture и upload state.

MySQL
хранит server business, media и processing state.

Server filesystem
хранит загруженные media и final artifacts.

GrafikStation
выполняет тяжёлую обработку, но не владеет заявкой.

app_* UUID
связывают Android objects с server rows.

Server numeric IDs
связывают backend tables и processing.

Raw capture
не изменяется.

Processing output
создаётся как отдельный artifact.

Success
разрешён только после фактической записи и проверки.
```
