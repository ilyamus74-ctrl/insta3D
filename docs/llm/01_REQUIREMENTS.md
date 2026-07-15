# insta3D / MaklerTour — требования проекта

> Файл: `docs/llm/01_REQUIREMENTS.md`
> Актуализация: 2026-07-15
> Статус: рабочий документ
> Назначение: единый набор функциональных, технических и эксплуатационных требований для разработчиков и LLM.

---

# 1. Назначение документа

Этот документ фиксирует требования к системе `insta3D / MaklerTour`.

Он используется как источник ограничений при:

* добавлении новых функций;
* исправлении ошибок;
* оптимизации;
* рефакторинге;
* изменении Android-приложения;
* изменении backend;
* изменении processing pipeline;
* работе ChatGPT;
* работе Aider;
* работе локальной LLM.

LLM не должна считать текущее поведение правильным только потому, что оно уже реализовано в коде.

При противоречии необходимо разделять:

1. утверждённое требование;
2. фактическое текущее поведение;
3. известное ограничение;
4. предполагаемое улучшение.

---

# 2. Границы системы

Система состоит из трёх основных контуров:

```text
Android capture client
        ↓
Web backend / storage / job management
        ↓
GrafikStation processing
```

## 2.1 Android-приложение

Android-приложение отвечает за:

* работу оператора на объекте;
* авторизацию;
* получение заявок;
* создание съёмочных сессий;
* управление камерами;
* локальное хранение данных;
* формирование upload queue;
* упаковку capture bundles;
* передачу данных на сервер;
* отображение статусов.

## 2.2 Web backend

Backend отвечает за:

* авторизацию;
* заявки;
* серверные capture sessions;
* приём файлов и metadata;
* хранение материалов;
* создание processing jobs;
* контроль прав доступа;
* отображение результатов;
* передачу тяжёлых задач на GrafikStation.

## 2.3 GrafikStation

GrafikStation отвечает за:

* SfM;
* COLMAP;
* dense depth;
* обработку capture bundles;
* GPU-задачи;
* формирование processing artifacts;
* возврат результатов на сервер.

---

# 3. Роли пользователей

## 3.1 Оператор

Оператор должен иметь возможность:

* войти в приложение;
* получить назначенные заявки;
* выбрать заявку;
* создать или открыть съёмочную сессию;
* подключить камеру;
* выполнить съёмку;
* проверить сохранённые данные;
* поставить материалы в очередь загрузки;
* увидеть результат загрузки;
* повторить неуспешную загрузку.

## 3.2 Маклер

Маклер должен иметь возможность:

* создавать или просматривать заявки;
* видеть статус заявки;
* видеть статус съёмки;
* видеть загруженные материалы;
* просматривать результат обработки.

## 3.3 Администратор

Администратор должен иметь возможность:

* просматривать все заявки;
* просматривать processing jobs;
* запускать или повторять обработку;
* просматривать ошибки;
* получать доступ к диагностическим данным.

## 3.4 Разработчик

Разработчик должен иметь возможность:

* собрать Android-приложение;
* воспроизвести capture flow;
* проверить backend API;
* запустить worker вручную;
* получить логи;
* проверить storage layout;
* проверить artifacts;
* воспроизвести ошибку по инструкции.

---

# 4. Функциональные требования Android

# 4.1 Авторизация

## FR-AUTH-001

Приложение должно поддерживать авторизацию оператора через серверный API.

## FR-AUTH-002

После успешной авторизации приложение должно сохранять токен локально.

## FR-AUTH-003

При запуске приложение должно проверять сохранённую сессию.

## FR-AUTH-004

При недействительном токене приложение должно:

* удалить локальный токен;
* вернуть пользователя на экран входа;
* не продолжать запросы от имени старой сессии.

## FR-AUTH-005

Токен не должен записываться в обычные диагностические логи.

---

# 4.2 Заявки

## FR-ORDER-001

Приложение должно получать список доступных оператору заявок.

## FR-ORDER-002

Для заявки должны отображаться как минимум:

* серверный ID;
* название;
* адрес;
* статус;
* назначенный оператор;
* статус съёмки;
* статус обработки.

## FR-ORDER-003

Съёмочная сессия должна быть привязана к конкретной заявке до загрузки материалов.

## FR-ORDER-004

Нельзя загружать материалы в закрытую или завершённую заявку без отдельного разрешённого сценария.

## FR-ORDER-005

Привязка локальной сессии к заявке должна сохраняться после перезапуска приложения.

---

# 4.3 Съёмочные сессии

## FR-SESSION-001

Оператор должен иметь возможность создать локальную съёмочную сессию.

## FR-SESSION-002

Сессия должна иметь:

* локальный UUID;
* название;
* адрес;
* комментарий;
* серверный order ID;
* server capture session ID;
* время создания;
* время обновления;
* статус.

## FR-SESSION-003

В рамках сессии могут храниться:

* photo points;
* Insta360 video scans;
* phone camera video scans;
* rooms;
* point connections;
* calibration sessions;
* synced stereo captures;
* capture bundles;
* upload items.

## FR-SESSION-004

Удаление сессии не должно случайно удалять данные другой сессии.

## FR-SESSION-005

Удаление сессии с незагруженными файлами должно требовать явного действия пользователя или показывать предупреждение.

---

# 4.4 Camera provider abstraction

## FR-CAMERA-001

Работа с камерой должна быть скрыта за интерфейсом `CameraProvider`.

## FR-CAMERA-002

Система должна поддерживать как минимум:

* `MockCameraProvider`;
* `Insta360OscProvider`;
* phone camera provider.

## FR-CAMERA-003

UI и ViewModel не должны зависеть от деталей OSC HTTP-запросов.

## FR-CAMERA-004

Camera provider должен возвращать структурированный результат, а не требовать от UI разбирать необработанный JSON.

## FR-CAMERA-005

Ошибки камеры должны быть преобразованы в понятное состояние приложения.

---

# 4.5 Insta360 X4 OSC

## FR-OSC-001

Базовый OSC endpoint:

```text
http://192.168.42.1/osc/commands/execute
```

## FR-OSC-002

Все OSC-запросы должны включать:

```http
Content-Type: application/json;charset=utf-8
Accept: application/json
X-XSRF-Protected: 1
```

## FR-OSC-003

После переключения режима через `camera.setOptions` приложение должно проверить фактический режим через `camera.getOptions`.

## FR-OSC-004

Для video mode должно подтверждаться:

```text
captureMode == video
_videoType == normal
```

## FR-OSC-005

Для photo mode должно подтверждаться:

```text
captureMode == image
```

## FR-OSC-006

`/osc/state` не должен использоваться как единственный источник состояния камеры.

## FR-OSC-007

Если команда возвращает `inProgress`, приложение должно выполнять polling через:

```text
/osc/commands/status
```

## FR-OSC-008

После `camera.stopCapture` video scan считается успешным только при наличии корректного file URL.

## FR-OSC-009

Повторный старт capture должен блокироваться, пока предыдущая операция не завершена.

## FR-OSC-010

Ошибки сети камеры не должны приводить к аварийному завершению приложения.

---

# 4.6 Photo point

## FR-PHOTO-001

Оператор должен иметь возможность снять photo point.

## FR-PHOTO-002

Photo point должен содержать:

* локальный UUID;
* session ID;
* название;
* sequence number;
* camera file URL;
* camera local path;
* local preview path;
* local original path;
* capture status;
* upload state;
* server media ID;
* время создания.

## FR-PHOTO-003

Неуспешный capture не должен добавляться в сессию как успешная точка.

## FR-PHOTO-004

Preview может загружаться асинхронно после создания точки.

## FR-PHOTO-005

Ошибка загрузки preview не должна удалять успешно снятый photo point.

## FR-PHOTO-006

Original-файл и preview должны быть явно различимы.

---

# 4.7 Video scan Insta360

## FR-VIDEO-001

Оператор должен иметь возможность запустить и остановить video scan.

## FR-VIDEO-002

Состояния video scan должны быть явными:

```text
IDLE
SWITCHING_MODE
RECORDING
STOPPING
CAPTURED
FAILED
```

## FR-VIDEO-003

Во время активной записи нельзя запускать вторую запись.

## FR-VIDEO-004

После остановки должны сохраняться:

* camera file URL;
* camera local file URL;
* duration;
* file size, если доступен;
* capture status;
* source;
* role;
* download state;
* upload state.

## FR-VIDEO-005

Video scan может иметь роль:

* `BACKBONE`;
* `DETAIL`.

## FR-VIDEO-006

Первый успешный scan сессии может назначаться `BACKBONE`, последующие — `DETAIL`.

## FR-VIDEO-007

Тяжёлый Insta360 video не должен автоматически скачиваться по мобильной сети.

---

# 4.8 Phone camera video

## FR-PHONE-001

Приложение должно поддерживать запись video scan встроенной камерой телефона.

## FR-PHONE-002

Файл должен сохраняться примерно по структуре:

```text
sessions/<sessionId>/phone_scans/<scanId>/video.mp4
```

## FR-PHONE-003

Рядом с video могут сохраняться:

```text
camera_info.json
manifest.json
imu.jsonl
```

## FR-PHONE-004

После остановки записи файл должен существовать и иметь ненулевой размер.

## FR-PHONE-005

Phone video должен маркироваться:

```text
source = PHONE_CAMERA
```

## FR-PHONE-006

Metadata должны загружаться вместе с video, если соответствующие файлы существуют.

## FR-PHONE-007

Отсутствие optional metadata не должно блокировать загрузку video.

## FR-PHONE-008

Отсутствие самого video-файла должно переводить upload в состояние ошибки.

---

# 4.9 Stereo capture

## FR-STEREO-001

Стереопара состоит из:

```text
cam0 = встроенная камера телефона
cam1 = USB UVC камера
```

## FR-STEREO-002

Обе камеры должны сохранять raw frames без применения UI rotation.

## FR-STEREO-003

Сохранённые кадры должны иметь стабильную систему координат.

## FR-STEREO-004

UI preview может вращаться только как display-only представление.

## FR-STEREO-005

Detector input, calibration math и depth input не должны использовать случайно повёрнутые UI bitmap.

## FR-STEREO-006

Синхронная пара должна выбираться по минимальной временной разнице.

## FR-STEREO-007

Максимальная допустимая временная разница должна быть записана в manifest.

Текущее рабочее значение:

```text
stereoMaxDeltaMs = 30
```

## FR-STEREO-008

Для calibration и depth должны использоваться реальные синхронные пары, а не независимые кадры из двух video streams.

## FR-STEREO-009

Legacy stereo video не должно считаться полноценным источником synced depth.

---

# 4.10 Stereo calibration

## FR-CAL-001

Calibration должна поддерживать:

* cam0 intrinsics;
* cam1 intrinsics;
* stereo extrinsics.

## FR-CAL-002

Stereo ChArUco соответствия должны строиться по общим ID:

```text
commonIds = cam0.ids ∩ cam1.ids
```

## FR-CAL-003

Нельзя сопоставлять ChArUco точки только по позиции элемента в массиве.

## FR-CAL-004

Stereo calibration должна использовать:

```text
CALIB_FIX_INTRINSIC
```

если intrinsics уже рассчитаны.

## FR-CAL-005

Минимум общих ChArUco ID для ручного stereo capture:

```text
35
```

## FR-CAL-006

Минимум общих ChArUco ID для auto stereo capture:

```text
38
```

## FR-CAL-007

Для финальной stereo calibration требуется минимум:

```text
10
```

валидных stereo pairs.

## FR-CAL-008

Пары с высокой epipolar error должны фильтроваться итеративно.

## FR-CAL-009

Calibration result должен содержать:

* RMS;
* число исходных пар;
* число принятых пар;
* rejected pairs;
* причины rejection;
* common IDs;
* per-pair epipolar error;
* число итераций filtering.

## FR-CAL-010

Неуспешная calibration должна завершаться понятной ошибкой, а не записывать сомнительный результат как успешный.

---

# 4.11 IMU orientation

## FR-IMU-001

IMU orientation используется только как metadata и диагностика.

## FR-IMU-002

IMU orientation не должна:

* вращать raw frames;
* менять calibration math;
* выбирать disparity axis;
* менять rectification;
* менять saved JPEG.

## FR-IMU-003

Для stereo pair должен выбираться ближайший IMU sample по timestamp.

## FR-IMU-004

Manifest должен хранить:

* orientation timestamp;
* physical orientation;
* source;
* confidence;
* stale flag;
* IMU timestamp;
* delta;
* gravity vector;
* display rotation;
* config orientation.

---

# 4.12 Capture bundle

## FR-BUNDLE-001

Synced stereo capture должен упаковываться в `.tgz`.

## FR-BUNDLE-002

Минимальная структура:

```text
bundle_manifest.json
capture/synced_depth_manifest.json
capture/pairs/
calibration/stereo_extrinsics.json
rig/active_rig_profile.json
```

## FR-BUNDLE-003

Упаковка должна выполняться асинхронно.

## FR-BUNDLE-004

Остановка записи не должна ожидать завершения tar/gzip.

## FR-BUNDLE-005

Во время упаковки нельзя вращать или повторно сжимать raw JPG.

## FR-BUNDLE-006

Готовый bundle должен добавляться в upload queue как:

```text
CAPTURE_BUNDLE
```

## FR-BUNDLE-007

Dense processing разрешён только для:

```text
capture_type = synced_depth_frames
```

## FR-BUNDLE-008

`stereo_video_legacy` может храниться и отображаться, но не должен автоматически запускать synced dense processing.

---

# 4.13 Локальное хранение

## FR-STORAGE-001

Состояние сессий должно сохраняться через Room.

## FR-STORAGE-002

После перезапуска должны восстанавливаться:

* sessions;
* points;
* rooms;
* connections;
* scan videos;
* upload items;
* server IDs;
* upload states.

## FR-STORAGE-003

Room entities и domain models должны иметь однозначные mappings.

## FR-STORAGE-004

Изменение схемы Room требует:

* увеличения database version;
* migration;
* проверки старой базы;
* проверки новой установки.

## FR-STORAGE-005

Interrupted uploads после перезапуска не должны оставаться навсегда в состоянии `UPLOADING`.

## FR-STORAGE-006

JSON manifest должен читаться безопасно.

Пустой или повреждённый JSON не должен приводить к uncaught exception.

## FR-STORAGE-007

Запись важных manifest-файлов должна быть атомарной:

```text
write temp file
→ flush/close
→ rename
```

---

# 4.14 Upload queue

## FR-UPLOAD-001

Все uploads должны иметь отдельное состояние.

## FR-UPLOAD-002

Поддерживаемые состояния:

```text
QUEUED
UPLOADING
SUCCESS
ERROR
```

или их актуальные domain equivalents.

## FR-UPLOAD-003

Upload item должен содержать:

* локальный ID;
* session ID;
* order ID;
* server capture session ID;
* local file path;
* upload type;
* progress;
* retry count;
* error information.

## FR-UPLOAD-004

Upload должен поддерживать retry.

## FR-UPLOAD-005

Interrupted upload должен восстанавливаться после перезапуска.

## FR-UPLOAD-006

Пользователь должен видеть:

* текущий файл;
* этап;
* процент;
* загруженные байты;
* общий размер;
* ошибку.

## FR-UPLOAD-007

Нельзя маркировать upload как успешный, если обязательный файл отсутствует.

## FR-UPLOAD-008

Повторный upload подтверждённого файла должен предотвращаться, если нет отдельной команды force/reupload.

---

# 4.15 Chunked upload

## FR-CHUNK-001

Большие video-файлы должны загружаться частями.

Текущий threshold:

```text
200 MiB
```

## FR-CHUNK-002

Текущий размер chunk:

```text
8 MiB
```

## FR-CHUNK-003

Каждый chunk должен содержать:

* upload ID;
* chunk index;
* total chunks;
* chunk size;
* total size.

## FR-CHUNK-004

Chunk должен поддерживать retry.

Текущее число попыток:

```text
3
```

## FR-CHUNK-005

Upload считается завершённым только после подтверждения последнего chunk и серверной сборки файла.

## FR-CHUNK-006

При повторной отправке chunk сервер не должен повреждать уже собранный файл.

---

# 5. Функциональные требования backend

# 5.1 Mobile API

## FR-BACKEND-001

Mobile API должен проверять Bearer token.

## FR-BACKEND-002

Оператор не должен иметь доступ к чужим заявкам.

## FR-BACKEND-003

API должен поддерживать:

* создание capture session;
* upload photo point;
* upload video scan;
* chunked upload;
* upload capture bundle;
* получение статуса.

## FR-BACKEND-004

API должен проверять обязательные ID и параметры.

## FR-BACKEND-005

API не должен доверять абсолютным путям, переданным клиентом.

## FR-BACKEND-006

Имена файлов должны очищаться от опасных символов.

## FR-BACKEND-007

Файлы должны сохраняться только внутри разрешённого storage root.

## FR-BACKEND-008

Ответ API должен быть JSON и содержать как минимум:

```json
{
  "ok": true
}
```

или:

```json
{
  "ok": false,
  "error": "error_code"
}
```

---

# 5.2 Server storage

## FR-SERVER-STORAGE-001

Материалы должны храниться по order/session hierarchy.

Пример:

```text
storage/orders/<orderId>/sessions/<sessionUuid>/
```

## FR-SERVER-STORAGE-002

Видео, photo points, bundles и processing artifacts должны находиться в отдельных каталогах.

## FR-SERVER-STORAGE-003

Storage path должен быть связан с записью в базе данных.

## FR-SERVER-STORAGE-004

Удаление записи в БД не должно немедленно физически удалять файл без отдельной политики cleanup.

## FR-SERVER-STORAGE-005

Worker не должен работать с файлом, который ещё загружается.

---

# 5.3 Processing jobs

## FR-JOB-001

Processing job должен иметь:

* ID;
* order ID;
* session ID;
* job type;
* status;
* parameters;
* created time;
* updated time;
* error text;
* result reference.

## FR-JOB-002

Поддерживаемые состояния должны быть однозначными:

```text
NOT_STARTED
QUEUED
PENDING
RUNNING
SUCCESS
FAILED
```

## FR-JOB-003

Одновременно не должно создаваться несколько одинаковых активных jobs для одной сессии без явного force.

## FR-JOB-004

Worker должен атомарно захватывать job перед запуском.

## FR-JOB-005

При ошибке job должен переходить в `FAILED`.

## FR-JOB-006

Ошибка должна сохраняться в базе и в log-файле.

## FR-JOB-007

Worker не должен объявлять `SUCCESS`, если обязательный artifact отсутствует.

---

# 5.4 Video SfM pipeline

## FR-SFM-001

Pipeline должен поддерживать:

```text
PHONE_VIDEO
INSTA360_DUAL_VIDEO
```

## FR-SFM-002

Pipeline должен проверять существование исходного video.

## FR-SFM-003

Pipeline должен выполнять:

1. проверку video;
2. извлечение кадров;
3. создание keyframes;
4. подготовку camera profile;
5. AprilTag detection;
6. COLMAP feature extraction;
7. matching;
8. mapping;
9. model conversion;
10. pose parsing;
11. rough scale;
12. export artifacts.

## FR-SFM-004

Каждый этап должен логироваться отдельно.

## FR-SFM-005

Команда с ненулевым exit code должна считаться ошибкой этапа.

## FR-SFM-006

Soft-этап может завершиться предупреждением только если он действительно не является обязательным.

## FR-SFM-007

Для phone video должен использоваться правильный video stream.

## FR-SFM-008

Camera profile с приблизительными intrinsics должен явно маркироваться как approximate.

## FR-SFM-009

Sparse reconstruction до появления полноценной calibration не должна позиционироваться как метрически точная модель.

---

# 5.5 Synced dense processing

## FR-DENSE-001

Dense processing должно запускаться на GrafikStation.

## FR-DENSE-002

Android и web server не должны выполнять тяжёлую dense computation.

## FR-DENSE-003

Worker должен распаковывать и проверять bundle перед processing.

## FR-DENSE-004

Для disparity должны использоваться rectified images.

## FR-DENSE-005

Baseline axis должен определяться по stereo rectification output.

## FR-DENSE-006

Если baseline вертикальный, обе rectified images должны быть одинаково повёрнуты для StereoBM/StereoSGBM.

## FR-DENSE-007

Исходная `Q` не должна использоваться без адаптации после поворота disparity image.

## FR-DENSE-008

Для vertical branch допускается ручной расчёт:

```text
Z = f * B / disparity
```

## FR-DENSE-009

Debug JSON должен содержать:

* baseline axis;
* disparity axis;
* input rotation;
* depth method;
* Q validity;
* baseline magnitude;
* focal;
* num disparities;
* block size;
* valid depth ratio.

## FR-DENSE-010

Минимальные artifacts:

```text
dense/contact_dense_depth.jpg
dense/dense_depth_debug.json
dense/dense_depth_summary.csv
result.json
```

---

# 6. Нефункциональные требования

# 6.1 Надёжность

## NFR-REL-001

Ошибка одной операции не должна повреждать всю сессию.

## NFR-REL-002

Приложение не должно завершаться аварийно из-за:

* недоступной камеры;
* ошибки HTTP;
* пустого JSON;
* повреждённого manifest;
* отсутствующего optional-файла.

## NFR-REL-003

Неуспешная фоновая задача должна оставлять диагностируемое состояние.

## NFR-REL-004

Все state transitions должны быть конечными.

Операция не должна навсегда зависать в:

```text
UPLOADING
STOPPING
SWITCHING_MODE
RUNNING
```

---

# 6.2 Целостность данных

## NFR-DATA-001

Raw capture files нельзя изменять после записи без создания отдельной производной копии.

## NFR-DATA-002

Rotation preview не должна менять raw data.

## NFR-DATA-003

ID сессии, заявки, scan и point должны сохраняться при переходе Android → backend → processing.

## NFR-DATA-004

Metadata и media должны относиться к одной и той же сессии.

## NFR-DATA-005

Нельзя подменять отсутствующее значение фиктивным успешным значением.

---

# 6.3 Производительность

## NFR-PERF-001

UI не должен блокироваться во время:

* скачивания;
* упаковки bundle;
* upload;
* checksum;
* обработки manifest;
* работы с большими файлами.

## NFR-PERF-002

Файловые и сетевые операции должны выполняться вне main thread.

## NFR-PERF-003

Preview не должен загружать full-resolution original без необходимости.

## NFR-PERF-004

Большие файлы не должны целиком загружаться в RAM.

## NFR-PERF-005

Processing workers должны записывать длительность каждого этапа.

## NFR-PERF-006

Оптимизация не должна ухудшать точность calibration или целостность raw data.

---

# 6.4 Безопасность

## NFR-SEC-001

Backend должен проверять авторизацию для каждого защищённого API.

## NFR-SEC-002

Нельзя доверять:

* client file path;
* MIME type;
* original filename;
* order ID;
* session ID;
* role, переданной клиентом.

## NFR-SEC-003

Все SQL-запросы с пользовательскими данными должны использовать prepared statements.

## NFR-SEC-004

File path должен проверяться через разрешённый storage root.

## NFR-SEC-005

Токены, пароли и секреты не должны попадать в Git.

## NFR-SEC-006

Diagnostic export должен исключать секретные данные.

## NFR-SEC-007

Cleartext HTTP к Insta360 разрешён как локальное camera connection.

Cleartext HTTP к внешнему backend должно рассматриваться как временное ограничение и отдельный security risk.

---

# 6.5 Совместимость

## NFR-COMP-001

Android minimum SDK:

```text
26
```

## NFR-COMP-002

Target и compile SDK:

```text
34
```

## NFR-COMP-003

Основная native ABI:

```text
arm64-v8a
```

## NFR-COMP-004

Java/Kotlin target:

```text
17
```

## NFR-COMP-005

Изменение версий CameraX, Room, OpenCV или Compose должно выполняться отдельной задачей.

---

# 6.6 Наблюдаемость

## NFR-OBS-001

Критические операции должны иметь структурированные логи.

## NFR-OBS-002

Логи должны содержать ID:

* order;
* session;
* scan;
* point;
* upload;
* processing job.

## NFR-OBS-003

Лог не должен сообщать об успехе до фактического завершения операции.

## NFR-OBS-004

Processing log должен содержать:

```text
START step
command
output
exit code
elapsed time
DONE или FAILED
```

## NFR-OBS-005

Ошибка должна быть доступна пользователю в сокращённой форме и разработчику в подробной форме.

---

# 6.7 Поддерживаемость

## NFR-MAINT-001

Большие файлы должны рефакториться поэтапно.

Критические узлы:

```text
MainActivity.kt
AppStateViewModel.kt
Repositories.kt
web/www/api/mobile.php
```

## NFR-MAINT-002

Один commit должен решать одну связанную задачу.

## NFR-MAINT-003

Рефакторинг не должен одновременно:

* менять архитектуру;
* менять API contract;
* менять Room schema;
* менять storage layout;
* добавлять новую функцию.

Такие изменения должны разделяться.

## NFR-MAINT-004

Новые публичные контракты должны документироваться в `docs/llm`.

## NFR-MAINT-005

Backup-файлы не должны использоваться как постоянная система версий.

История должна храниться в Git.

---

# 7. Требования к тестированию

## TEST-001

Перед Android commit необходимо выполнять:

```bash
cd app/MaklerTour
./gradlew :app:assembleDebug
```

## TEST-002

После изменений stereo/camera UI необходимо выполнять:

```bash
python3 tools/stereo_contract_audit.py
```

если audit доступен в актуальном дереве проекта.

## TEST-003

После изменения Room schema необходимо проверять:

* чистую установку;
* запуск со старой базой;
* migration;
* чтение данных после migration.

## TEST-004

После изменения upload необходимо проверить:

* маленький файл;
* большой chunked файл;
* retry;
* interruption;
* повторный запуск;
* серверную запись;
* физическое наличие файла.

## TEST-005

После изменения camera flow необходимо проверить:

* camera offline;
* неправильный режим;
* успешный capture;
* timeout;
* malformed response;
* повторное нажатие кнопки.

## TEST-006

После изменения SfM worker необходимо проверить:

* успешный job;
* отсутствующий video;
* ошибка ffmpeg;
* ошибка COLMAP;
* отсутствие output artifact;
* повторный запуск.

## TEST-007

После изменения dense pipeline необходимо проверить bundle audit и наличие обязательных artifacts.

---

# 8. Критерии готовности изменения

Задача считается выполненной только когда:

1. определён затронутый контракт;
2. изменены только необходимые файлы;
3. build завершается успешно;
4. обязательные audits проходят;
5. тесты проходят;
6. diff проверен;
7. нет случайного изменения backup-файлов;
8. новые ошибки не скрываются;
9. документация обновлена при изменении контракта;
10. фактические результаты команд сохранены.

Фраза модели «должно работать» не является подтверждением.

---

# 9. Запрещённые упрощения

Запрещено:

* возвращать фиктивный успех вместо ошибки;
* вращать raw frame для исправления preview;
* считать video загруженным без файла;
* считать processing успешным без artifacts;
* использовать stale camera state как подтверждение режима;
* использовать порядок ChArUco arrays вместо common IDs;
* считать legacy stereo video synced depth источником;
* менять multipart field только на Android;
* менять Room entity без migration;
* удалять error handling ради упрощения;
* переписывать крупный модуль целиком без baseline tests;
* смешивать refactoring и изменение поведения без отдельного решения.

---

# 10. Источники требований

Основные документы:

```text
docs/llm/00_PROJECT_OVERVIEW.md
TZ.md
CAMERA_OSC_X4.md
TESTING.md
app/MaklerTour/docs/APP_CAMERA_STEREO_CONTRACT.md
web/DOCS/CAPTURE_BUNDLE_DENSE_CONTRACT.md
DOC/PHONE_SCAN_MVP_STATUS.md
```

При противоречии необходимо:

1. найти более новый подтверждённый контракт;
2. проверить текущий код;
3. проверить фактический runtime;
4. зафиксировать решение в `docs/llm/decisions/`.

---

# 11. Открытые вопросы

Следующие вопросы требуют дальнейшего уточнения:

* окончательный production deployment backend;
* переход внешнего API на HTTPS;
* точный lifecycle WorkManager;
* production Room migrations;
* полноценные unit/integration tests;
* политика удаления local originals;
* retention server storage;
* production calibration profiles;
* критерии качества sparse reconstruction;
* критерии качества dense depth;
* формат финального тура;
* floorplan pipeline;
* versioning capture bundle;
* versioning Android/backend API;
* rollback processing jobs;
* cleanup backup-файлов в репозитории.

Открытый вопрос не должен автоматически считаться разрешением на изменение поведения.
