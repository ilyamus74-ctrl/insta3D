# insta3D / MaklerTour — дорожная карта рефакторинга

> Файл: `docs/llm/09_REFACTORING_ROADMAP.md`
> Актуализация: 2026-07-15
> Статус: рабочая дорожная карта
> Назначение: определить безопасную последовательность стабилизации, тестирования, рефакторинга и развития проекта.

---

# 1. Назначение документа

Этот документ определяет порядок технических изменений в проекте `insta3D / MaklerTour`.

Дорожная карта нужна для того, чтобы:

* не переписывать проект целиком;
* не смешивать несколько критических изменений;
* сохранять рабочие сценарии;
* уменьшать связанность постепенно;
* сначала создавать доказательства и тесты;
* отделять исправление ошибки от архитектурного рефакторинга;
* синхронно изменять Android, backend и processing contracts;
* обеспечивать понятный контекст для ChatGPT, Codex, Aider и локальной LLM.

Основной принцип:

```text
Сначала понять и зафиксировать текущее поведение.
Затем добавить проверки.
После этого выполнять минимальные изменения.
```

---

# 2. Текущий workflow разработки

Основной рабочий процесс:

```text
Локальный ноутбук разработчика
→ изменение кода и документации
→ локальная проверка
→ Git repository
→ синхронизация с web server
→ runtime-проверка backend
```

GrafikStation используется для тяжёлого processing, но её deployment пока не является обязательной частью каждого локального изменения.

Проверка GrafikStation требуется при изменении:

```text
web/remote_station/
web/tools/sfm_remote_worker.php
COLMAP pipeline
dense depth
Open3D mesh
remote job contracts
```

---

# 3. Основные правила дорожной карты

## ROADMAP-RULE-001

Нельзя начинать крупный рефакторинг без baseline.

Baseline включает:

```text
рабочий commit;
build result;
runtime result;
логи;
sample input;
sample output;
известные ограничения.
```

## ROADMAP-RULE-002

Рефакторинг и изменение поведения должны разделяться.

Неправильно:

```text
разделить AppStateViewModel
+
изменить upload retry
+
изменить Room schema
```

Правильно:

```text
1. Зафиксировать upload behavior.
2. Добавить tests.
3. Выделить UploadCoordinator без изменения behavior.
4. Отдельной задачей изменить retry.
```

## ROADMAP-RULE-003

Cross-system contract меняется с обеих сторон.

Пример:

```text
MobileUploadApi.kt
↔
mobile.php
↔
MySQL schema
```

## ROADMAP-RULE-004

Каждый этап должен иметь критерий завершения.

Фраза:

```text
код выглядит лучше
```

не является критерием завершения.

## ROADMAP-RULE-005

Высокорисковые модули изменяются маленькими шагами.

К ним относятся:

```text
MainActivity.kt
AppStateViewModel.kt
Repositories.kt
mobile.php
StereoCaptureExperimental.kt
process_sfm_video_jobs.php
sfm_remote_worker.php
dense processing scripts
```

## ROADMAP-RULE-006

Backup и generated files не используются для текущего runtime.

## ROADMAP-RULE-007

Проект не должен полностью останавливаться на время рефакторинга.

Каждый промежуточный commit должен:

```text
собираться;
сохранять контракт;
иметь понятный rollback;
не блокировать основные сценарии.
```

---

# 4. Приоритеты дорожной карты

## P0 — безопасность и сохранность данных

В первую очередь:

```text
HTTPS backend;
удаление production/full dumps из Git;
целостность raw stereo;
DB/filesystem consistency;
retention raw media;
защита токенов и customer data.
```

## P1 — стабильность рабочих сценариев

```text
Room migrations;
Android/backend upload contract;
chunked upload;
capture bundle versioning;
bundle UUID idempotency;
calibration fixtures;
stereo timestamps;
processing status;
stale jobs;
result schema;
integration tests.
```

## P2 — архитектура и поддерживаемость

```text
MainActivity split;
AppStateViewModel split;
Repositories split;
mobile.php split;
namespace cleanup;
dependency pinning;
hardcoded paths;
runtime inventory;
repository cleanup.
```

---

# 5. Этапы дорожной карты

```text
R0 — Документационный baseline
R1 — Repository safety
R2 — Build and test baseline
R3 — Security baseline
R4 — Database and storage integrity
R5 — Android persistence stability
R6 — Upload contract stabilization
R7 — Camera and OSC stabilization
R8 — USB UVC and stereo stabilization
R9 — Calibration and capture bundle versioning
R10 — Processing contract stabilization
R11 — Android modular refactoring
R12 — Backend modular refactoring
R13 — Processing modular refactoring
R14 — Dependency and deployment reproducibility
R15 — CI and automated quality gates
R16 — Production hardening
```

Этапы перечислены в рекомендуемом порядке.

Некоторые задачи могут выполняться параллельно, если они не пересекают одни и те же contracts.

---

# 6. R0 — Документационный baseline

## Цель

Создать устойчивый набор контекста для человека и LLM.

## Файлы

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
```

## Работы

1. Заполнить все файлы.
2. Сравнить документы между собой.
3. Сверить ключевые statements с кодом.
4. Сверить table names со schema dump.
5. Проверить status names.
6. Проверить file paths.
7. Удалить очевидные противоречия.
8. Создать root `AGENTS.md`.
9. Создать task templates.

## Критерии завершения

* все документы существуют;
* каждый документ имеет назначение;
* основные модули описаны;
* критические contracts описаны;
* известные problems имеют ID;
* roadmap связан с problem IDs;
* Codex/Aider получают порядок чтения;
* нет явных противоречий в status names и paths.

## Риски

Документация может описывать желаемую архитектуру как фактическую.

Поэтому каждое спорное утверждение должно быть помечено:

```text
CONFIRMED
ASSUMED
TARGET
OPEN QUESTION
```

---

# 7. R1 — Repository safety

## Связанные проблемы

```text
KP-007
KP-008
```

## Цель

Убрать из Git данные и файлы, которые мешают работе или создают риск утечки.

## Задачи

### R1.1 — Inventory backup-файлов

Найти:

```text
*.before_*
*.bak
*.bak_*
*.bkp
*.old
```

Для каждого определить:

```text
активный;
исторический;
уникальный код;
безопасно удалить;
нужен только через Git history.
```

### R1.2 — Inventory generated files

Найти:

```text
build/
templates_c/
tmp/
cache/
compiled binaries
runtime output
```

### R1.3 — MySQL dumps

Разделить:

```text
schema-only dumps
full/data dumps
synthetic fixtures
```

### R1.4 — `.gitignore`

Добавить правила для:

```text
full database dumps
build outputs
temporary archives
runtime logs
generated artifacts
local station config
private keys
IDE local files
```

### R1.5 — Secrets scan

Проверить:

```text
passwords
tokens
private keys
database credentials
real customer data
public access tokens
```

## Критерии завершения

* production/full dumps отсутствуют в current tree;
* при необходимости они удалены из Git history;
* schema-only dump сохранён;
* synthetic fixture создана;
* `.gitignore` покрывает known generated files;
* backup-файлы не мешают code search;
* secrets scan не показывает active secrets.

## Запрет

Не выполнять repository cleanup вместе с функциональным refactoring.

---

# 8. R2 — Build and test baseline

## Связанные проблемы

```text
KP-018
KP-019
KP-020
KP-024
KP-037
```

## Цель

Создать минимальные воспроизводимые проверки до изменения архитектуры.

## Задачи

### R2.1 — Android baseline

Обязательные команды:

```text
stereo contract audit
unit tests
lint
assembleDebug
native build
```

### R2.2 — PHP syntax baseline

Проверять все активные PHP-файлы.

### R2.3 — Shell baseline

```text
bash -n
```

для active scripts.

### R2.4 — Python baseline

```text
py_compile
```

для processing scripts.

### R2.5 — C++ baseline

```text
CMake configure
CMake build
sfm_tool --help
```

### R2.6 — Database schema restore

Восстанавливать schema-only dump в disposable database.

### R2.7 — Smoke fixtures

Создать минимальные synthetic fixtures:

```text
small phone video;
small photo;
small chunked video;
stereo pair sample;
calibration pair set;
capture bundle;
COLMAP parser input;
result.json samples.
```

## Критерии завершения

* существует один документированный локальный test sequence;
* Android build воспроизводим;
* PHP syntax checks воспроизводимы;
* schema restore работает;
* `sfm_tool` собирается;
* есть минимум одна fixture для каждого критичного pipeline;
* test evidence можно сохранить в `docs/llm/tasks/`.

---

# 9. R3 — Security baseline

## Связанные проблемы

```text
KP-006
KP-008
```

## Цель

Устранить наиболее критичные риски передачи и хранения данных.

## Задачи

### R3.1 — HTTPS backend

Порядок:

```text
1. Настроить HTTPS на web server.
2. Проверить certificate chain.
3. Добавить HTTPS endpoint в debug build.
4. Проверить auth.
5. Проверить small upload.
6. Проверить chunked upload.
7. Проверить release build.
8. Перенаправить HTTP на HTTPS.
9. Удалить cleartext external backend dependency.
```

### R3.2 — Token logging

Проверить:

```text
Android logcat;
PHP logs;
audit_logs;
diagnostic export;
HTTP proxy logs.
```

### R3.3 — Public links

Проверить:

```text
token entropy;
expiry;
revoke;
hash storage;
access scope.
```

### R3.4 — File upload validation

Проверить:

```text
path traversal;
MIME validation;
extension validation;
size limits;
archive extraction;
symlink escape.
```

## Критерии завершения

* Android backend transport использует HTTPS;
* raw tokens не логируются;
* credentials отсутствуют в Git;
* public links имеют expiry/revoke;
* archive extraction безопасна;
* upload paths остаются внутри storage root.

---

# 10. R4 — Database and storage integrity

## Связанные проблемы

```text
KP-009
KP-010
KP-012
KP-013
KP-029
KP-035
KP-039
```

## Цель

Стабилизировать server identity, schema и filesystem consistency.

## Задачи

### R4.1 — Schema versioning

Создать таблицу:

```text
schema_migrations
```

Минимальные поля:

```text
version
name
applied_at
checksum
```

### R4.2 — Ordered migrations

Каждое schema change оформлять отдельным SQL-файлом.

Пример:

```text
migrations/
001_initial_baseline.sql
002_capture_bundle_unique.sql
003_add_parameters_json.sql
```

### R4.3 — Usage audit point tables

Проверить использование:

```text
capture_points
photo_points
```

Результат должен определить:

```text
active;
legacy read-only;
legacy writable;
candidate for migration;
candidate for removal.
```

### R4.4 — Bundle UUID idempotency

Проверить duplicates.

После очистки добавить:

```text
UNIQUE(capture_session_id, app_bundle_uuid)
```

или другой подтверждённый canonical constraint.

### R4.5 — Filesystem reconciliation

Создать diagnostic utility:

```text
DB row without file;
file without DB row;
wrong size;
wrong checksum;
path outside storage;
orphan temporary file.
```

### R4.6 — Foreign key preparation

До добавления foreign keys:

```text
orphan audit;
signedness alignment;
delete policy;
soft-delete rules;
legacy table decision.
```

### R4.7 — Retention policy

Зафиксировать:

```text
Android raw retention;
server raw retention;
bundle retention;
remote job retention;
failed artifact retention;
closed order retention;
backup requirements.
```

## Критерии завершения

* schema имеет version;
* migration sequence воспроизводим;
* point table ownership определён;
* bundle upload идемпотентен;
* DB/filesystem reconciliation доступна;
* retention policy документирована;
* добавление foreign keys возможно безопасно.

---

# 11. R5 — Android persistence stability

## Связанные проблемы

```text
KP-003
KP-019
```

## Цель

Гарантировать сохранение local state при обновлении APK и перезапуске.

## Задачи

### R5.1 — Room schema export

Включить экспорт Room schema.

### R5.2 — Migration fixtures

Сохранять test database от предыдущих versions.

### R5.3 — Mapping tests

Проверить:

```text
Session;
CapturePoint;
ScanVideo;
Room;
Connection;
UploadItem.
```

### R5.4 — Interrupted states

При startup нормализовать:

```text
UPLOADING;
DOWNLOADING;
RECORDING;
STOPPING;
SWITCHING_MODE.
```

### R5.5 — File validation

Перед отображением/загрузкой проверять:

```text
path;
exists;
size;
type.
```

## Критерии завершения

* старая DB открывается новой APK;
* локальные sessions сохраняются;
* upload queue сохраняется;
* file paths сохраняются;
* stale active states восстанавливаются;
* destructive migration не используется без отдельного решения.

---

# 12. R6 — Upload contract stabilization

## Связанные проблемы

```text
KP-016
KP-017
KP-018
KP-028
KP-029
```

## Цель

Сделать upload проверяемым, идемпотентным и независимым от UI.

## Порядок

### R6.1 — Зафиксировать current contract

Для каждого action:

```text
endpoint;
method;
auth;
request fields;
file parts;
response;
error codes;
idempotency;
DB table;
storage path.
```

### R6.2 — Integration fixtures

Проверить:

```text
create session;
photo preview;
photo original;
small video;
chunked video;
phone metadata;
capture bundle;
retry;
duplicate UUID.
```

### R6.3 — Выделить Android UploadExecutor

Без изменения поведения вынести:

```text
HTTP call;
multipart building;
chunk loop;
progress;
response parsing.
```

### R6.4 — Выделить UploadCoordinator

Ответственность:

```text
queue item;
state transition;
retry;
session creation;
server ID persistence.
```

### R6.5 — Разделить backend handlers

Сохранять URL:

```text
mobile.php?action=...
```

Но выделить внутренние handlers.

### R6.6 — Chunk resume

Определить contract:

```text
upload_id;
already uploaded chunks;
resume query;
temp retention;
final checksum;
retry after restart.
```

## Критерии завершения

* ViewModel не формирует multipart;
* upload можно тестировать отдельно;
* duplicate UUID не создаёт duplicate rows;
* chunk resume подтверждён;
* server final size/checksum проверяется;
* error codes стабильны;
* старый Android client остаётся совместимым или явно блокируется version check.

---

# 13. R7 — Camera и OSC stabilization

## Связанные проблемы

```text
KP-005
```

## Цель

Стабилизировать CameraProvider и mode state machine.

## Задачи

### R7.1 — OSC response fixtures

Сохранить sanitized JSON:

```text
getOptions;
setOptions success;
inProgress;
done;
error;
startCapture;
stopCapture;
takePicture.
```

### R7.2 — Parser tests

Проверить:

```text
missing fields;
wrong type;
stale state;
unknown camera;
missing file URL.
```

### R7.3 — State machine

Зафиксировать:

```text
DISCONNECTED
CONNECTING
READY
SWITCHING_MODE
CAPTURING
RECORDING
STOPPING
ERROR
```

### R7.4 — Network routing

Отдельно проверить:

```text
camera Wi-Fi route;
backend mobile/Wi-Fi route;
network switch;
camera download.
```

### R7.5 — Release provider

Принять решение о:

```text
release CAMERA_PROVIDER
```

## Критерии завершения

* parser покрыт fixtures;
* mode всегда подтверждается `getOptions`;
* повторный start блокируется;
* stop возвращает проверенный file reference;
* camera network не ломает backend routing;
* release provider определён явно.

---

# 14. R8 — USB UVC и stereo stabilization

## Связанные проблемы

```text
KP-020
KP-021
KP-022
KP-023
```

## Цель

Доказать стабильность native capture, timestamps и raw coordinate contract.

## Задачи

### R8.1 — UVC diagnostic mode

Добавить метрики:

```text
selected format;
width;
height;
FPS;
frame bytes;
decode errors;
dropped frames;
queue size;
open count;
close count.
```

### R8.2 — Resource lifecycle

Проверить:

```text
native buffer;
decoder;
USB handle;
threads;
file descriptors;
bitmaps;
callbacks.
```

### R8.3 — Timestamp validation

Определить:

```text
cam0 clock source;
cam1 clock source;
unit;
offset;
drift;
callback latency.
```

### R8.4 — Pairing tests

Создать pure tests для nearest-pair algorithm.

### R8.5 — Rotation fixture

Использовать асимметричный test image для автоматического определения поворота.

### R8.6 — Long-running test

Минимум:

```text
30 минут
```

Целевой:

```text
2 часа
```

## Критерии завершения

* memory выходит на plateau;
* thread count стабилен;
* descriptors стабилен;
* reconnect работает;
* timestamp domains подтверждены;
* pair delta измеряется корректно;
* raw saved images не вращаются;
* rotation regression определяется автоматически.

---

# 15. R9 — Calibration и capture bundle versioning

## Связанные проблемы

```text
KP-024
KP-025
KP-026
```

## Цель

Сделать calibration и bundle воспроизводимыми и версионированными.

## Задачи

### R9.1 — Calibration fixture

Сохранить synthetic или consented dataset:

```text
cam0 images;
cam1 images;
pair timestamps;
detected IDs;
expected quality ranges.
```

### R9.2 — Unit contract

Зафиксировать:

```text
board units;
translation unit;
depth unit;
baseline unit.
```

### R9.3 — Calibration schema

Добавить:

```text
schema_version;
translation_unit;
image_size;
algorithm_version;
OpenCV version;
quality metrics.
```

### R9.4 — Bundle schema

Добавить:

```text
schema_version;
producer_app_version;
capture_type;
file checksums;
calibration version;
manifest version.
```

### R9.5 — Bundle validator

Создать runtime validator, который проверяет реальный archive, а не только source fragments.

## Критерии завершения

* fixture воспроизводит calibration;
* ошибка rotation обнаруживается;
* единицы указаны явно;
* unsupported schema отклоняется понятной ошибкой;
* bundle содержит checksums;
* validator проверяет archive structure и JSON.

---

# 16. R10 — Processing contract stabilization

## Связанные проблемы

```text
KP-011
KP-012
KP-027
KP-030
KP-033
KP-034
KP-036
KP-041
```

## Цель

Унифицировать envelope processing results без уничтожения legacy flows.

## Задачи

### R10.1 — State inventory

Зафиксировать отдельно:

```text
processing_jobs;
video_sfm_runs;
sfm_pipeline_runs;
sfm_remote_jobs.
```

Не заменять statuses автоматически.

### R10.2 — Result envelope

Создать common schema:

```text
schema_version;
ok;
job_id;
job_type;
status;
artifacts;
metrics;
warnings;
errors;
environment.
```

### R10.3 — Status mapping

Создать документированный mapping:

```text
legacy local success = PROCESSED
pipeline success = DONE
remote job success = DONE
conceptual success = success category, не DB value
```

### R10.4 — Required artifacts

Для каждого job type определить:

```text
required;
optional;
debug;
viewer-facing.
```

### R10.5 — Stale reconciliation

При worker startup:

```text
найти RUNNING;
проверить heartbeat/process/status;
перевести stale job;
сохранить reason;
разрешить safe retry.
```

### R10.6 — Environment metadata

Записывать:

```text
COLMAP version;
ffmpeg version;
OpenCV version;
Python version;
container image;
image digest;
GPU;
driver.
```

### R10.7 — Viewer compatibility

Определить:

```text
active viewer;
legacy viewer;
artifact versions;
fallback policy.
```

### R10.8 — Устранение расхождения dense script copies

Проверяемые файлы:

```text
app/MaklerTour/tools/dense_depth_from_synced_capture.py
web/remote_station/scripts/dense_depth_from_synced_capture.py
```

Необходимо выбрать один вариант:

```text
A. оставить один canonical script;
B. выполнять controlled copy при deployment;
C. временно проверять обе копии одним audit.
```

Acceptance:

```text
app test copy и production remote script
не могут расходиться незаметно.
```

## Критерии завершения

* каждый job type имеет state machine;
* `result.json` валидируется;
* terminal status не путаются;
* missing required artifact делает job failed;
* stale jobs автоматически reconciled;
* viewer выбирает корректный successful run;
* environment сохранён.

---

# 17. R11 — Android modular refactoring

## Связанные проблемы

```text
KP-001
KP-002
KP-003
KP-004
KP-016
```

## Входные условия

До начала должны быть выполнены:

```text
R2 Build baseline;
R5 Room stability;
R6 Upload stabilization;
R7 Camera stabilization;
R8 Stereo baseline.
```

## Порядок

### R11.1 — Разделение `Repositories.kt`

Сначала физически разделить без изменения интерфейсов:

```text
contracts;
Room implementations;
in-memory implementations;
mappers.
```

### R11.2 — Выделение upload classes

Если ещё не выполнено на R6.

### R11.3 — Выделение use cases

Порядок от наименее связанного:

```text
DraftUseCase
SessionUseCase
UploadUseCase
PhoneScanUseCase
CameraCaptureUseCase
VideoScanUseCase
```

### R11.4 — Разделение экранов

Выносить Compose screens по одному.

### R11.5 — Composition root

Создать отдельный application container/factory.

### R11.6 — Уменьшение `MainActivity`

Оставить:

```text
Activity lifecycle;
setContent;
root app call.
```

### R11.7 — Namespace migration

Только после стабилизации структуры.

## Запрет

Не выполнять namespace migration в том же commit, что и разделение `MainActivity`.

## Критерии завершения

* `MainActivity` минимален;
* ViewModel не выполняет HTTP/filesystem детали;
* repositories разделены;
* use cases тестируемы;
* package structure единообразна;
* все Android flows проходят regression matrix.

---

# 18. R12 — Backend modular refactoring

## Связанные проблемы

```text
KP-017
KP-029
KP-035
```

## Входные условия

```text
upload contract зафиксирован;
integration fixtures существуют;
schema migrations работают;
storage paths определены.
```

## Порядок

### R12.1 — Общие helpers

Выделить:

```text
JSON response;
authentication;
order access;
session access;
storage path resolution;
upload validation.
```

### R12.2 — Action handlers

```text
create session;
photo upload;
video upload;
chunk upload;
capture bundle upload.
```

### R12.3 — Storage service

Ответственность:

```text
temporary file;
validation;
final path;
atomic move;
checksum;
cleanup.
```

### R12.4 — Repository/data access layer

Выделить SQL по domain areas.

### R12.5 — Transaction boundaries

Явно определить transaction и compensating actions.

### R12.6 — Endpoint compatibility

`mobile.php?action=...` остаётся совместимым до controlled API version change.

## Критерии завершения

* endpoint dispatch отделён от handlers;
* handlers тестируются отдельно;
* SQL не дублируется;
* storage root централизован;
* file/DB inconsistency обрабатывается;
* старый Android client проходит integration tests.

---

# 19. R13 — Processing modular refactoring

## Цель

Разделить job coordination и processing execution.

## Порядок

### R13.1 — Local SfM configuration

Убрать hardcoded paths через config с fallback.

### R13.2 — Stage abstraction

Каждый этап имеет:

```text
name;
command;
inputs;
outputs;
required;
timeout;
exit code;
log.
```

### R13.3 — Artifact validator

Отдельный validator проверяет outputs.

### R13.4 — Remote worker separation

Разделить:

```text
DB claim;
station launch;
status polling;
result fetch;
artifact validation;
DB finalization;
cleanup.
```

### R13.5 — Dense processor

Выделить:

```text
bundle validation;
calibration loading;
rectification;
baseline decision;
disparity;
depth;
artifact output.
```

## Критерии завершения

* каждый stage тестируется fixture;
* paths берутся из config;
* `SUCCESS/DONE/PROCESSED` выставляется после validator;
* worker restart безопасен;
* processing script не управляет business access;
* remote execution можно воспроизвести на test job.

---

# 20. R14 — Dependency и deployment reproducibility

## Связанные проблемы

```text
KP-014
KP-015
KP-031
KP-032
KP-033
KP-038
KP-040
```

## Цель

Сделать environments воспроизводимыми.

## Задачи

### R14.1 — Runtime inventory

Зафиксировать:

```text
PHP;
MariaDB/MySQL;
ffmpeg;
COLMAP;
OpenCV;
AprilTag;
CMake;
compiler;
Python;
NumPy;
OpenCV Python;
Open3D;
Podman;
NVIDIA driver.
```

### R14.2 — Python pinning

Создать requirements/constraints.

### R14.3 — Container pinning

Использовать image digest.

### R14.4 — NDK pinning

Закрепить verified `ndkVersion`.

### R14.5 — Storage/path config

Централизовать:

```text
web root;
storage root;
tools root;
station base;
output root.
```

### R14.6 — Deployment documentation

Отдельно описать:

```text
local laptop;
Git;
web server;
database;
storage;
workers;
GrafikStation.
```

## Критерии завершения

* новая environment поднимается по документу;
* versions известны;
* Python packages закреплены;
* container immutable;
* hardcoded paths имеют controlled fallback;
* deployment не зависит от памяти одного разработчика.

---

# 21. R15 — CI и автоматические quality gates

## Связанная проблема

```text
KP-037
```

## Цель

Автоматически проверять безопасную часть проекта при каждом push/PR.

## Минимальный CI

### Android

```text
stereo audit;
unit tests;
lint;
assembleDebug.
```

### PHP

```text
php -l.
```

### Shell

```text
bash -n.
```

### Python

```text
py_compile.
```

### C++

```text
CMake configure/build.
```

### Database

```text
schema-only restore.
```

### Documentation

```text
required files;
broken relative links;
duplicate problem IDs;
duplicate contract IDs;
status vocabulary checks.
```

### Security

```text
secret scan;
full dump detection;
private key detection.
```

## Hardware tests

Не входят в обычный CI:

```text
Insta360;
Android physical camera;
USB UVC;
long stereo test;
GrafikStation GPU.
```

Для них требуется hardware-in-the-loop pipeline или ручной evidence.

## Критерии завершения

* каждый PR получает automatic result;
* critical failure блокирует merge;
* CI не использует production secrets;
* build environment закреплена;
* test artifacts доступны.

---

# 22. R16 — Production hardening

## Цель

Подготовить проект к стабильной эксплуатации.

## Задачи

### R16.1 — Monitoring

```text
API availability;
DB health;
storage free space;
worker health;
stale jobs;
GrafikStation availability;
GPU health;
upload failures;
processing duration.
```

### R16.2 — Backup

```text
MySQL backup;
storage backup;
configuration backup;
restore test;
retention.
```

### R16.3 — Service management

Workers должны запускаться через controlled service manager.

Пример:

```text
systemd
```

### R16.4 — Log rotation

Для:

```text
PHP;
web server;
workers;
processing jobs;
GrafikStation.
```

### R16.5 — Alerting

```text
disk low;
DB unavailable;
worker stopped;
job stale;
GPU unavailable;
upload failure spike.
```

### R16.6 — Disaster recovery

Документировать:

```text
web server loss;
database loss;
storage loss;
GrafikStation loss;
Android device loss;
partial upload recovery.
```

## Критерии завершения

* backup restore проверен;
* workers автоматически запускаются;
* disk monitoring работает;
* stale jobs видимы;
* logs не заполняют disk;
* recovery procedure проверена на test environment.

---

# 23. Зависимости между этапами

```text
R0 Documentation
    ↓
R1 Repository safety
    ↓
R2 Build/test baseline
    ↓
R3 Security baseline
    ↓
R4 DB/storage integrity
    ↓
R5 Room stability
    ↓
R6 Upload stabilization
    ↓
R7 Camera stabilization
    ↓
R8 UVC/stereo stabilization
    ↓
R9 Calibration/bundle versioning
    ↓
R10 Processing contracts
    ↓
R11 Android refactoring
R12 Backend refactoring
R13 Processing refactoring
    ↓
R14 Reproducibility
    ↓
R15 CI
    ↓
R16 Production hardening
```

Некоторые ветви могут выполняться параллельно:

```text
R7 Camera OSC
||
R4 Database integrity
```

если они не затрагивают общие файлы.

---

# 24. Запрещённые сочетания задач

## Не объединять

```text
repository cleanup
+
bug fix
```

```text
namespace migration
+
MainActivity split
```

```text
Room migration
+
repository refactoring
```

```text
HTTPS migration
+
upload architecture rewrite
```

```text
OpenCV update
+
calibration algorithm change
```

```text
COLMAP update
+
new reconstruction parameters
```

```text
mobile.php split
+
API field rename
```

```text
foreign keys
+
legacy table removal
```

```text
bundle schema version
+
dense algorithm rewrite
```

```text
worker stale recovery
+
new remote job type
```

---

# 25. Размер рекомендуемой задачи

Одна задача должна:

* затрагивать один основной модуль;
* менять один контракт или не менять контракты;
* иметь 1–5 основных source files;
* иметь конкретный тест;
* иметь понятный rollback;
* завершаться одним логическим commit.

Допустимое исключение:

Cross-system contract может потребовать больше файлов, но изменение должно оставаться одним логическим действием.

Пример:

```text
Добавить optional camera_model в video upload
```

может включать:

```text
Android model;
MobileUploadApi;
mobile.php;
migration;
test.
```

Это остаётся одной contract-задачей.

---

# 26. Формат roadmap-задачи

```text
Task ID:
Roadmap stage:
Problem IDs:
Priority:

Goal:
Non-goals:

Target module:
Related modules:
Contract:

Confirmed baseline:
Files to inspect:
Files allowed to change:
Files not to change:

Implementation:
Migration:
Backward compatibility:

Required tests:
Runtime environment:
Acceptance criteria:
Rollback:

Documentation updates:
```

---

# 27. Пример безопасной задачи

```text
Task ID:
UPLOAD-001

Roadmap stage:
R6

Problem IDs:
KP-016, KP-018

Goal:
Вынести HTTP-загрузку video из AppStateViewModel
в UploadExecutor без изменения request contract.

Non-goals:
Не менять multipart fields.
Не менять retry.
Не менять Room schema.
Не менять mobile.php.

Files allowed:
AppStateViewModel.kt
UploadExecutor.kt
MobileUploadApi.kt
unit tests

Required tests:
assembleDebug
existing video upload
chunked upload
retry smoke

Acceptance:
Request совпадает с baseline.
Server file checksum совпадает.
ViewModel больше не формирует multipart.
```

---

# 28. Пример задачи, которую нужно разделить

Плохая задача:

```text
Привести Android в порядок,
перенести классы,
исправить upload,
обновить Room,
перейти на HTTPS
и изменить backend.
```

Разделение:

```text
SEC-001 — HTTPS backend
DB-001 — Room migration tests
UPLOAD-001 — выделение UploadExecutor
ANDROID-001 — разделение Repositories.kt
ANDROID-002 — выделение SessionUseCase
ANDROID-003 — разделение screens
```

---

# 29. Критерии допуска к крупному refactoring

Крупный refactoring разрешён только если:

```text
baseline build PASS;
relevant runtime PASS;
контракт описан;
known problems определены;
fixture существует;
rollback существует;
scope ограничен;
generated/backup files исключены.
```

Для camera/stereo дополнительно:

```text
physical device доступно;
logcat baseline сохранён;
raw samples сохранены;
memory baseline сохранён.
```

Для processing:

```text
input fixture;
result.json;
artifact metrics;
environment versions;
runtime baseline.
```

---

# 30. Критерии остановки задачи

Задачу необходимо остановить и разделить, если:

* затрагивается больше трёх основных contracts;
* требуется менять Android, backend, DB и processing одновременно без versioning;
* отсутствует baseline;
* обнаружена production data loss risk;
* тестовая fixture недоступна;
* diff стал значительно больше первоначального scope;
* потребовалось переписать крупный файл целиком;
* возникла новая schema migration;
* новая dependency нужна без отдельного решения;
* root cause не подтверждена.

Остановка не означает отказ от задачи.

Результат исследования должен быть сохранён.

---

# 31. Порядок при обнаружении новой проблемы

```text
1. Не исправлять сразу случайным патчем.
2. Сохранить наблюдение.
3. Добавить known problem ID.
4. Определить критичность.
5. Определить затронутый contract.
6. Собрать минимальное доказательство.
7. Добавить задачу в roadmap stage.
8. Подготовить regression test.
9. После этого исправлять.
```

Для очевидной маленькой ошибки допускается прямое исправление, если:

* scope понятен;
* контракт не меняется;
* тест существует;
* риск низкий.

---

# 32. Ведение статусов roadmap

Для этапов:

| Статус        | Значение                      |
| ------------- | ----------------------------- |
| `NOT_STARTED` | работы не начинались          |
| `IN_PROGRESS` | этап выполняется              |
| `BLOCKED`     | есть внешний блокер           |
| `PARTIAL`     | часть задач выполнена         |
| `DONE`        | критерии завершения выполнены |
| `DEFERRED`    | этап осознанно отложен        |

Рекомендуемая таблица:

| Этап | Статус      | Основной результат           |
| ---- | ----------- | ---------------------------- |
| R0   | IN_PROGRESS | комплект `docs/llm`          |
| R1   | NOT_STARTED | безопасный repository        |
| R2   | NOT_STARTED | baseline tests               |
| R3   | NOT_STARTED | HTTPS и security baseline    |
| R4   | NOT_STARTED | DB/storage integrity         |
| R5   | NOT_STARTED | Room stability               |
| R6   | NOT_STARTED | stable upload                |
| R7   | NOT_STARTED | stable OSC                   |
| R8   | NOT_STARTED | stable stereo/UVC            |
| R9   | NOT_STARTED | versioned calibration/bundle |
| R10  | NOT_STARTED | processing contracts         |
| R11  | NOT_STARTED | Android modules              |
| R12  | NOT_STARTED | backend modules              |
| R13  | NOT_STARTED | processing modules           |
| R14  | DEFERRED    | reproducible deployment      |
| R15  | NOT_STARTED | CI                           |
| R16  | NOT_STARTED | production hardening         |

Статусы должны обновляться по факту, а не автоматически.

---

# 33. Первые рекомендуемые задачи после документации

После завершения `docs/llm` и итогового аудита:

## 1. REPO-001 — Проверка database dumps

```text
Priority: P0
Stage: R1
```

## 2. REPO-002 — Inventory backup/generated files

```text
Priority: P1
Stage: R1
```

## 3. TEST-001 — Локальный baseline script

```text
Priority: P1
Stage: R2
```

## 4. DB-001 — Disposable schema restore

```text
Priority: P1
Stage: R2/R4
```

## 5. DB-002 — Orphan integrity report

```text
Priority: P1
Stage: R4
```

## 6. UPLOAD-001 — Android/backend contract fixture

```text
Priority: P1
Stage: R6
```

## 7. CAMERA-001 — OSC response fixtures

```text
Priority: P1
Stage: R7
```

## 8. STEREO-001 — UVC memory baseline

```text
Priority: P1
Stage: R8
```

## 9. CAL-001 — Calibration regression fixture

```text
Priority: P1
Stage: R9
```

## 10. PROCESS-001 — Result schema inventory

```text
Priority: P1
Stage: R10
```

---

# 34. Итоговый аудит перед началом рефакторинга

После создания всех файлов `docs/llm` выполнить:

## 34.1 Документы между собой

Проверить:

```text
requirements ↔ architecture;
architecture ↔ modules;
modules ↔ contracts;
contracts ↔ data flows;
data flows ↔ schema;
dependencies ↔ build instructions;
known problems ↔ roadmap.
```

## 34.2 Документы против кода

Проверить:

```text
file paths;
class names;
table names;
status values;
endpoint actions;
build versions;
processing job types;
artifact names.
```

## 34.3 Документы против runtime

Проверить:

```text
Android build;
APK package;
camera provider;
backend URL;
PHP version;
database version;
storage root;
worker status;
GrafikStation versions.
```

## 34.4 Создать correction patch

Все найденные документальные несоответствия исправить одним отдельным documentation-only commit.

Не смешивать этот commit с code changes.

---

# 35. Использование roadmap LLM

Перед началом задачи LLM должна определить:

```text
Roadmap stage:
Problem IDs:
Priority:
Target module:
Contract:
Baseline:
Required tests:
```

LLM не должна выбирать refactoring stage только потому, что код выглядит неудобно.

Приоритет определяется:

```text
безопасность;
потеря данных;
стабильность;
контракт;
тестируемость;
поддерживаемость.
```

---

# 36. Правила для Codex и Aider

Codex/Aider должны:

1. Прочитать `AGENTS.md`.
2. Прочитать `00_PROJECT_OVERVIEW.md`.
3. Прочитать roadmap stage задачи.
4. Прочитать related known problem.
5. Прочитать affected contract.
6. Прочитать target module.
7. Не выходить за scope.
8. Не редактировать backup/generated files.
9. Не выполнять массовый rename без отдельной задачи.
10. Показывать diff.
11. Запускать обязательные проверки.
12. Помечать результат `PARTIAL`, если runtime не выполнен.

---

# 37. Что roadmap не разрешает автоматически

Наличие задачи в roadmap не является автоматическим разрешением:

* удалять таблицу;
* менять production database;
* очищать storage;
* отзывать tokens;
* менять deployment;
* обновлять dependency;
* изменять public API;
* удалять legacy viewer;
* запускать destructive migration;
* переписывать camera pipeline.

Для таких действий требуется отдельная задача и review.

---

# 38. Definition of Done этапа

Этап считается `DONE`, когда:

```text
все обязательные задачи выполнены;
критерии этапа подтверждены;
tests проходят;
documentation обновлена;
known problems обновлены;
непроверенные части перечислены;
rollback понятен;
нет скрытого изменения contract.
```

Частичное выполнение:

```text
PARTIAL
```

а не `DONE`.

---

# 39. Краткое резюме

```text
R0–R2
создают понимание и тестовый baseline.

R3–R6
защищают данные, database и upload.

R7–R9
стабилизируют camera, stereo и calibration.

R10
стабилизирует processing contracts.

R11–R13
разделяют Android, backend и processing modules.

R14–R16
обеспечивают воспроизводимость, CI и production эксплуатацию.

Рефакторинг начинается не с переписывания,
а с доказательства текущего поведения.
```
