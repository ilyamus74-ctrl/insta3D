# insta3D / MaklerTour — правила работы LLM

> Файл: `docs/llm/10_LLM_WORK_RULES.md`
> Актуализация: 2026-07-15
> Статус: обязательные правила
> Назначение: определить порядок работы ChatGPT, Codex, Aider, Ollama и других LLM с проектом.

---

# 1. Назначение документа

Этот документ задаёт обязательные правила работы LLM с проектом `insta3D / MaklerTour`.

Он используется для:

* анализа проекта;
* исправления ошибок;
* подготовки patches;
* локального редактирования кода;
* рефакторинга;
* изменения Android;
* изменения backend;
* изменения MySQL;
* изменения processing pipeline;
* code review;
* подготовки тестов;
* обновления документации.

Основной принцип:

```text
LLM не должна сразу изменять код.

Сначала она должна определить:
1. текущий workflow;
2. целевой модуль;
3. затронутый контракт;
4. владельца состояния;
5. минимальный scope;
6. обязательные проверки.
```

---

# 2. Поддерживаемые LLM

Правила применяются к:

```text
ChatGPT
OpenAI Codex
Aider
Ollama
Qwen
другим локальным coding-моделям
```

Разные инструменты имеют разные возможности, но архитектурные и безопасные ограничения одинаковы.

---

# 3. Режимы работы

Перед началом задачи необходимо определить режим.

## 3.1 `ADVISORY`

LLM:

* анализирует;
* объясняет;
* предлагает изменения;
* формирует полный файл;
* формирует patch;
* не изменяет working tree;
* не выполняет Git operations;
* не запускает deployment.

Этот режим используется в текущем диалоге с ChatGPT, если пользователь отдельно не попросил изменить repository.

## 3.2 `LOCAL_EDIT`

LLM может изменять локальные файлы проекта.

Разрешено только после явной задачи:

```text
исправь;
внеси изменения;
сделай patch;
отредактируй локальный проект.
```

В этом режиме LLM:

* изменяет только разрешённые файлы;
* не делает commit;
* не делает push;
* не выполняет deployment без отдельной команды.

## 3.3 `REVIEW`

LLM работает только на чтение:

* проверяет diff;
* ищет ошибки;
* сопоставляет contracts;
* проверяет tests;
* не изменяет файлы.

## 3.4 `PATCH_ONLY`

LLM выдаёт:

```text
unified diff
```

или полный replacement конкретного файла, но не применяет его.

## 3.5 `EXECUTE_AND_VERIFY`

LLM:

* изменяет локальные файлы;
* запускает разрешённые проверки;
* анализирует результат;
* показывает diff;
* не делает commit/push/deploy без разрешения.

## 3.6 `DEPLOY`

Deployment разрешён только отдельной явной командой.

Нельзя считать просьбу:

```text
исправь код
```

разрешением на:

```text
Git push
синхронизацию web server
SSH на GrafikStation
database migration
restart production worker
```

---

# 4. Текущий workflow проекта

Основной процесс разработки:

```text
локальный ноутбук разработчика
→ локальное изменение
→ локальная проверка
→ синхронизация с Git repository
→ синхронизация с web server
→ runtime-проверка
```

GrafikStation используется отдельно для тяжёлого processing.

Типовой путь:

```text
локальный ноутбук
→ Git
→ web server
→ GrafikStation
```

Deployment на GrafikStation требуется только для задач, затрагивающих:

```text
web/remote_station/
web/tools/sfm_remote_worker.php
COLMAP
dense depth
Open3D
remote jobs
```

LLM не должна предполагать, что каждый локальный commit автоматически разворачивается на GrafikStation.

---

# 5. Git и deployment policy

## 5.1 По умолчанию запрещено

Без явного запроса LLM не должна:

```text
git add
git commit
git push
git pull --rebase
git reset
git checkout другого branch
создавать PR
merge
force push
deploy на web server
подключаться по SSH
перезапускать service
изменять production database
```

## 5.2 Разрешённые Git-команды в review/edit mode

Для анализа разрешены read-only команды:

```bash
git status
git diff
git diff --stat
git log
git show
git branch --show-current
git rev-parse HEAD
```

## 5.3 Commit policy

Commit разрешён только по прямому запросу.

Перед commit необходимо показать:

```text
изменённые файлы;
краткое содержание;
выполненные тесты;
непроверенные части.
```

## 5.4 Push policy

Push разрешён только после прямой команды пользователя.

## 5.5 Deployment policy

Deployment является отдельным этапом после:

```text
локального изменения;
проверки diff;
локальных tests;
commit/push, если используются;
подтверждения пользователя.
```

---

# 6. Каталоги, которые по умолчанию не анализируются

LLM должна игнорировать, если задача явно их не касается:

```text
/baseline
build/
.gradle/
.idea/
templates_c/
tmp/
cache/
generated/
runtime output
processing output
```

Также по умолчанию игнорируются:

```text
*.before_*
*.bak
*.bak_*
*.bkp
*.old
```

## Причина

Эти файлы могут:

* быть устаревшими;
* содержать предыдущие версии;
* быть generated output;
* мешать repo map;
* приводить к изменению неправильного файла.

## Исключение

Backup или baseline можно читать только когда задача прямо требует:

```text
сравнить текущую версию со старой;
восстановить потерянный код;
исследовать регрессию.
```

Они всё равно не становятся источником текущего поведения.

---

# 7. Обязательный порядок чтения

Перед серьёзной задачей LLM должна прочитать:

```text
1. AGENTS.md
2. docs/llm/00_PROJECT_OVERVIEW.md
3. профильный task-файл
4. профильный known problem
5. профильный contract
6. описание target module
7. изменяемые source files
8. связанные consumer/producer files
9. build/test instructions
```

## 7.1 Базовый комплект

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

LLM не обязана загружать все файлы целиком для каждой маленькой задачи.

Она должна выбирать минимальный релевантный context pack.

---

# 8. Источники истины

Нужно различать:

```text
требуемое поведение;
фактическое текущее поведение;
целевую архитектуру;
runtime environment.
```

## 8.1 Требуемое поведение

Приоритет:

```text
1. Явная текущая инструкция пользователя.
2. Утверждённый task-файл.
3. Принятое architecture decision.
4. docs/llm/01_REQUIREMENTS.md.
5. профильный contract.
```

## 8.2 Фактическое текущее поведение

Приоритет:

```text
1. Воспроизводимый runtime.
2. Текущий исполняемый код.
3. Текущая database schema.
4. Текущая configuration.
5. Логи.
6. Документация.
```

Документ не доказывает, что runtime уже соответствует описанию.

## 8.3 Целевая архитектура

Источник:

```text
roadmap;
decision records;
утверждённые refactoring tasks.
```

Целевую архитектуру нельзя описывать как уже реализованную.

## 8.4 Runtime versions

Источник:

```text
фактическая команда на машине.
```

Пример:

```bash
php -v
mysql --version
nvidia-smi
podman version
python3 --version
```

Нельзя придумывать runtime version по dependency declaration.

---

# 9. Метки уверенности

При анализе использовать:

```text
CONFIRMED
INFERRED
ASSUMED
UNKNOWN
TARGET
```

## `CONFIRMED`

Подтверждено:

* кодом;
* schema;
* runtime;
* логом;
* тестом;
* artifact.

## `INFERRED`

Логически следует из нескольких подтверждённых фактов.

## `ASSUMED`

Временное предположение для выполнения задачи.

Предположение должно быть явно указано.

## `UNKNOWN`

Данных недостаточно.

## `TARGET`

Желаемое будущее состояние, ещё не реализованное.

---

# 10. Начало задачи

Перед изменением LLM должна сформировать краткий scope.

```text
Task:
Mode:
Target module:
Related modules:
Contract:
Problem IDs:
Roadmap stage:

Goal:
Non-goals:

Files to inspect:
Files allowed to change:
Files not to change:

Required tests:
Runtime required:
```

## Пример

```text
Task:
Исправить повторный start video scan.

Mode:
LOCAL_EDIT

Target module:
A06 Insta360 OSC

Related modules:
A03 Application State

Contract:
C02 CameraProvider
C03 Insta360 OSC transport
C05 ScanVideo

Goal:
Блокировать второй start до завершения первого.

Non-goals:
Не менять Room.
Не менять backend.
Не менять multipart upload.

Files allowed:
Insta360OscProvider.kt
AppStateViewModel.kt
tests

Required tests:
assembleDebug
OSC fixture tests
runtime start/stop
```

---

# 11. Правило минимального scope

LLM должна изменять минимальное количество файлов.

## Правильно

```text
исправить parser
→ parser file
→ parser test
```

## Неправильно

```text
исправить parser
→ переписать provider
→ переименовать package
→ обновить dependency
→ изменить ViewModel
```

## Scope expansion

Если во время задачи требуется дополнительный contract или migration, LLM должна:

1. остановить расширение;
2. описать найденную зависимость;
3. предложить отдельную задачу;
4. продолжить только если без неё невозможно безопасное исправление.

---

# 12. Запрет на скрытые изменения

LLM не должна без явного указания:

* менять API field names;
* менять enum values;
* менять Room schema;
* менять MySQL schema;
* менять storage layout;
* менять package namespace;
* менять dependency versions;
* менять Android SDK;
* менять camera resolution;
* менять calibration thresholds;
* менять COLMAP parameters;
* менять deployment paths;
* менять status semantics;
* удалять legacy table;
* удалять backup-файлы;
* форматировать весь крупный файл.

---

# 13. Работа с крупными файлами

Крупные узлы:

```text
MainActivity.kt
AppStateViewModel.kt
Repositories.kt
mobile.php
process_sfm_video_jobs.php
sfm_remote_worker.php
StereoCaptureExperimental.kt
```

## Правила

LLM должна:

* читать только релевантные sections и соседние functions;
* искать все вызовы изменяемой функции;
* находить interface и model;
* не переписывать файл целиком;
* не выполнять массовое форматирование;
* не менять unrelated imports;
* не перемещать код без отдельной refactoring-задачи.

## Запрещённый подход

```text
Файл большой и неудобный,
поэтому перепишем его полностью.
```

---

# 14. Работа с Android

Перед изменением определить:

```text
UI layer;
ViewModel/use case;
domain model;
repository;
Room;
camera provider;
filesystem;
backend contract.
```

## 14.1 UI

UI должна:

* отображать state;
* передавать user intent;
* не обращаться к DAO;
* не разбирать raw OSC JSON;
* не строить server path;
* не выполнять тяжёлый filesystem/network work.

## 14.2 ViewModel

ViewModel не должна получать новую HTTP/filesystem-ответственность без отдельного решения.

## 14.3 Room

При изменении entity обязательны:

```text
database version;
migration;
mapping;
upgrade test;
clean install test.
```

## 14.4 Camera

Изменение preview не должно менять raw capture.

## 14.5 Android permissions

Изменение Camera, USB, storage или network требует runtime-проверки на физическом устройстве.

---

# 15. Работа с Insta360 OSC

LLM обязана учитывать:

```text
camera.getOptions;
camera.setOptions;
camera.takePicture;
camera.startCapture;
camera.stopCapture;
commands/status;
stale /osc/state.
```

## Обязательные инварианты

* mode подтверждается через `camera.getOptions`;
* video требует `captureMode=video`;
* X4 normal video требует `_videoType=normal`;
* photo требует `captureMode=image`;
* `inProgress` требует polling;
* successful stop требует media URL;
* transport success не равен capture success;
* повторный start блокируется.

## Запрещено

* считать `done` подтверждением неправильных option names;
* использовать только `/osc/state`;
* возвращать fake success;
* скрывать timeout.

---

# 16. Работа со stereo и calibration

Это критическая математическая область.

Перед изменением Android stereo, calibration, IMU metadata, capture bundle или native UVC LLM обязана прочитать:

```text
app/MaklerTour/docs/APP_CAMERA_STEREO_CONTRACT.md
app/MaklerTour/tools/stereo_contract_audit.py
```

После изменения обязательна команда:

```bash
cd app/MaklerTour
python3 tools/stereo_contract_audit.py
```

LLM запрещено изменять audit только для сокрытия нарушения действующего контракта.

## 16.1 Raw coordinates

Запрещено применять к saved/detector/calibration frames:

```text
UI rotation;
display rotation;
operator orientation;
IMU orientation.
```

## 16.2 Pairing

Пары выбираются по timestamp.

LLM не должна заменять synced pairs независимыми video streams.

## 16.3 ChArUco

Correspondence:

```text
commonIds = cam0.ids ∩ cam1.ids
```

Нельзя связывать points только по array index.

## 16.4 Calibration

Нельзя без отдельной задачи менять:

```text
minimum common IDs;
minimum pair count;
CALIB_FIX_INTRINSIC;
outlier threshold;
unit system.
```

## 16.5 Dense depth

При vertical baseline:

```text
rectified images могут одинаково поворачиваться
только для matcher input.
```

Raw frames не изменяются.

Original `Q` нельзя использовать после rotation disparity без математической адаптации.

---

# 17. Работа с backend

## 17.1 Authentication

Каждый protected endpoint должен проверять user.

## 17.2 Authorization

Недостаточно проверить, что object существует.

Нужно проверить:

```text
user имеет доступ к order/session/job.
```

## 17.3 SQL

Пользовательские данные:

```text
только prepared statements.
```

## 17.4 Files

Client path не является trusted.

Путь должен:

* разрешаться сервером;
* находиться внутри storage root;
* исключать traversal;
* использовать safe filename;
* проверяться после `realpath`.

## 17.5 Responses

Backend должен возвращать:

```json
{
  "ok": false,
  "error": "stable_error_code"
}
```

HTML error page не является допустимым API response.

---

# 18. Работа с MySQL

## 18.1 До schema change

LLM должна прочитать:

```text
latest schema dump;
все references table/column;
API producer;
worker consumer;
viewer consumer;
migration mechanism.
```

## 18.2 Schema change

Обязательно:

```text
ordered migration;
backup plan;
disposable DB restore;
forward test;
compatibility;
rollback or corrective migration.
```

## 18.3 Foreign keys

Нельзя добавлять foreign key без:

* orphan audit;
* signedness check;
* delete policy;
* legacy usage audit.

## 18.4 Full dumps

LLM не должна:

* открывать production dump без необходимости;
* копировать реальные строки в ответ;
* добавлять full dump в Git;
* использовать real user data как fixture.

## 18.5 Schema-only dump

Допустим как documentation/reference artifact.

---

# 19. Работа с upload

Изменение upload считается cross-system task.

Проверить:

```text
Android model;
UploadItem;
Room;
MobileUploadApi;
mobile.php;
database;
storage;
processing consumer.
```

## Обязательные сценарии

```text
small file;
large chunked file;
retry;
interruption;
duplicate UUID;
missing file;
wrong order/session;
server file size;
checksum.
```

## Запрещено

* менять multipart field только на одной стороне;
* маркировать success до server confirmation;
* считать последний chunk автоматически successful;
* создавать новый UUID при retry;
* отправлять zero-byte file как success.

---

# 20. Работа с processing

## 20.1 State machines

Нужно различать:

```text
processing_jobs success = PROCESSED
sfm_pipeline_runs success = DONE
sfm_remote_jobs success = DONE
```

Нельзя использовать универсальную строку `SUCCESS` как фактический DB value без проверки таблицы.

## 20.2 Job success

Разрешён только если:

```text
process exit code успешен;
result JSON валиден;
required artifacts существуют;
artifact size > 0;
job ID совпадает;
output скопирован.
```

## 20.3 Remote worker

LLM не должна запускать бесконечный worker во время обычного локального теста без явного разрешения.

## 20.4 GrafikStation

SSH/deployment выполняется только при отдельной задаче.

## 20.5 Environment

Processing result должен по возможности фиксировать:

```text
ffmpeg;
COLMAP;
OpenCV;
Python;
container;
GPU;
driver;
parameters.
```

---

# 21. Работа с dependencies

LLM не должна обновлять dependency как побочный эффект.

Перед update:

```text
определить текущую version;
прочитать release notes;
проверить compatibility;
зафиксировать baseline;
обновить одну dependency group;
запустить regression tests.
```

## Нельзя объединять

```text
OpenCV update
+
calibration algorithm change
```

```text
COLMAP update
+
parameter optimization
```

```text
Kotlin update
+
ViewModel refactoring
```

```text
PHP update
+
mobile.php split
```

---

# 22. Работа с configuration и secrets

## Запрещено включать в ответ или commit

```text
password;
raw Bearer token;
private SSH key;
database credentials;
production full dump;
customer personal data;
private access token;
secret environment file.
```

## Configuration examples

В Git можно хранить:

```text
*.example
synthetic configuration
documented placeholders
```

## Local files

Обычно должны игнорироваться:

```text
stations.conf
.env
local.properties
private key files
production DB config
```

---

# 23. Обязательные проверки

Точный набор берётся из:

```text
docs/llm/07_BUILD_AND_TEST.md
```

## 23.1 Documentation-only

Проверить:

* path names;
* class/table/status names;
* внутренние ссылки;
* отсутствие противоречий;
* Markdown structure.

## 23.2 Android

Минимально:

```bash
cd app/MaklerTour

python3 tools/stereo_contract_audit.py
./gradlew :app:testDebugUnitTest
./gradlew :app:lintDebug
./gradlew :app:assembleDebug
```

Фактический набор зависит от scope.

## 23.3 PHP

```bash
php -l <changed-file.php>
```

## 23.4 Shell

```bash
bash -n <changed-file.sh>
```

## 23.5 Python

```bash
python3 -m py_compile <changed-file.py>
```

## 23.6 C++

```bash
cmake -S web/tools/sfm_cpp -B /tmp/insta3d-sfm-build
cmake --build /tmp/insta3d-sfm-build
```

## 23.7 Database

Restore schema в disposable database.

---

# 24. Запрет на выдуманные результаты

LLM не должна писать:

```text
Build passed.
Tests passed.
Runtime confirmed.
```

если команды не выполнялись.

Разрешённые формулировки:

```text
Команда не запускалась.
Build не проверен.
Static analysis выполнен.
Runtime требует физического устройства.
GrafikStation не проверялась.
```

## Итоговые статусы

```text
PASS
PARTIAL
FAIL
NOT_RUN
```

---

# 25. Работа при отсутствии environment

Если недоступны:

```text
Android device;
Insta360;
USB UVC;
web server;
MySQL;
GrafikStation;
GPU.
```

LLM должна:

1. выполнить доступные static/build tests;
2. подготовить точные runtime-команды;
3. перечислить непроверенные части;
4. установить статус `PARTIAL`;
5. не блокировать безопасную подготовительную работу.

---

# 26. Анализ ошибок

LLM должна разделять:

## Наблюдения

```text
фактический log;
exception;
exit code;
status;
artifact;
metric.
```

## Выводы

Что следует из наблюдений.

## Гипотезы

Возможные причины.

## Пример

```text
Наблюдение:
Native heap растёт после каждого UVC reconnect.

Вывод:
Ресурсы не полностью возвращаются после close.

Гипотезы:
USB handle leak;
decoder leak;
frame buffer leak;
thread leak.
```

Гипотеза не должна подаваться как подтверждённая причина.

---

# 27. Работа с известными проблемами

Перед изменением проверить:

```text
docs/llm/08_KNOWN_PROBLEMS.md
```

Если проблема уже существует:

* использовать её ID;
* не создавать duplicate;
* обновить status после проверки.

Если найдена новая проблема:

1. присвоить новый `KP-*`;
2. указать наблюдение;
3. определить confidence;
4. определить severity;
5. связать с roadmap;
6. не исправлять случайным большим patch.

---

# 28. Работа с roadmap

Перед рефакторингом проверить:

```text
docs/llm/09_REFACTORING_ROADMAP.md
```

LLM должна указать:

```text
Roadmap stage;
Problem IDs;
Entry conditions;
Exit criteria.
```

Нельзя начинать R11–R13 крупный modular refactoring до появления необходимого test baseline.

---

# 29. Decision records

Архитектурные решения хранятся:

```text
docs/llm/decisions/
```

Decision нужен, когда изменяется:

* public contract;
* database ownership;
* storage layout;
* namespace;
* processing state semantics;
* bundle schema;
* deployment architecture;
* dependency strategy;
* legacy removal.

## Decision не нужен

Для:

* исправления опечатки;
* локального bug fix без изменения contract;
* добавления test;
* безопасного внутреннего helper.

---

# 30. Task files

Задачи хранятся:

```text
docs/llm/tasks/
```

Один task-файл должен содержать:

```text
ID;
цель;
non-goals;
target module;
contract;
allowed files;
forbidden files;
tests;
acceptance;
rollback.
```

LLM должна выполнять task-файл буквально.

Если task противоречит текущей явной инструкции пользователя, приоритет имеет текущая инструкция пользователя.

---

# 31. Context packs

# 31.1 OSC

```text
00_PROJECT_OVERVIEW.md
04_CONTRACTS.md
CAMERA_OSC_X4.md
CameraProvider
Insta360OscProvider
OscHttpClient
camera profile
affected ViewModel functions
TESTING.md
```

# 31.2 Room

```text
domain model
entity
DAO
repository contract
Room implementation
AppDatabase
migration
affected use case
```

# 31.3 Upload

```text
UploadItem
Upload DAO/repository
MobileUploadApi
ViewModel upload section
mobile.php action
DB table
storage helper
contract
```

# 31.4 Stereo

```text
APP_CAMERA_STEREO_CONTRACT.md
StereoCaptureExperimental.kt
cam1_uvc.cpp
calibration classes
manifest writer
bundle packager
stereo audit
```

# 31.5 Dense processing

```text
CAPTURE_BUNDLE_DENSE_CONTRACT.md
bundle audit
job creation
remote worker
station runner
dense Python script
result artifact
```

# 31.6 Database

```text
latest schema-only dump
table references
API producer
worker/viewer consumers
migration files
integrity queries
```

---

# 32. Правила для ChatGPT

В `ADVISORY` или `PATCH_ONLY` режиме ChatGPT должна:

* давать полный файл или точный patch;
* не утверждать, что repository изменён;
* не делать GitHub changes без прямого запроса;
* учитывать, что пользователь переносит изменения локально;
* не требовать deployment до завершения documentation/code stage;
* сохранять согласованность с предыдущими файлами.

При формировании полного файла:

* весь файл должен находиться в одном блоке;
* не разделять файл на несколько сообщений без необходимости;
* указывать точный путь;
* не включать пояснения внутрь файла, если они не являются частью документа.

---

# 33. Правила для Codex

Codex должен читать root:

```text
AGENTS.md
```

`AGENTS.md` должен направлять Codex к `docs/llm`.

Codex:

* не должен автоматически commit/push;
* не должен менять файлы вне scope;
* должен проверять `git diff`;
* должен запускать доступные tests;
* должен сообщать `PARTIAL`, если hardware/runtime недоступны;
* должен игнорировать `/baseline` и backup-файлы;
* не должен удалять untracked user files.

---

# 34. Правила для Aider

Aider должен получать ограниченный набор файлов.

Не следует добавлять весь repository без необходимости.

Рекомендуемый порядок:

```text
/read AGENTS.md
/read docs/llm/00_PROJECT_OVERVIEW.md
/read профильный contract
/read профильный module
/add только target files
```

Aider не должен:

* автоматически добавлять backup-файлы;
* изменять unrelated files;
* делать массовое formatting;
* обновлять dependency без scope;
* создавать commit, если пользователь не запросил.

---

# 35. Правила для Ollama и локальной LLM

Локальная модель имеет ограниченный context.

Поэтому:

1. Передавать короткий task-файл.
2. Передавать один target module.
3. Передавать один contract.
4. Передавать только связанные source files.
5. Не передавать весь repository.
6. Требовать сначала analysis plan.
7. Требовать unified diff.
8. Проверять diff более сильной моделью или вручную.
9. Не давать локальной модели доступ к deployment secrets.
10. Не разрешать массовые изменения.

## Рекомендуемый prompt

```text
Прочитай AGENTS.md, task-файл и приложенные source files.

Сначала укажи:
- target module;
- contract;
- root cause;
- минимальный patch;
- tests.

Не изменяй:
- API;
- database schema;
- dependencies;
- package namespace;
- unrelated files.

Верни unified diff.
```

---

# 36. Правила code review

Review должен проверять:

```text
scope;
contract;
state ownership;
data identity;
error handling;
threading;
files;
security;
migration;
tests;
artifacts.
```

## Android review

* main-thread blocking;
* coroutine lifecycle;
* duplicate actions;
* Room mapping;
* state recovery;
* camera lifecycle;
* raw/display coordinates.

## Backend review

* authentication;
* authorization;
* prepared statements;
* path safety;
* temporary files;
* response format;
* idempotency.

## Processing review

* command escaping;
* exit code;
* timeout;
* stale output;
* required artifact;
* job identity;
* units;
* environment metadata.

---

# 37. Формат итогового ответа LLM

```text
## Scope

Mode:
Target module:
Contract:
Problem IDs:
Changed files:

## What changed

- ...

## What was not changed

- ...

## Verification

Command:
Exit code:
Result:

## Runtime

Environment:
Observed result:

## Not verified

- ...

## Risks

- ...

## Conclusion

PASS / PARTIAL / FAIL
```

---

# 38. Формат patch-only ответа

```text
Target file:
Reason:
Contract:
Compatibility:
```

После этого:

```diff
...
```

Затем:

```text
Required tests:
Not verified:
```

Patch не должен включать unrelated formatting.

---

# 39. Формат полного replacement-файла

```text
Файл:
<точный repository path>
```

Затем полное содержимое.

Нельзя выдавать:

* только изменённый fragment, если пользователь попросил полный файл;
* несколько несовместимых вариантов без выбора;
* псевдокод вместо рабочего содержимого.

---

# 40. Definition of Done для LLM-задачи

Задача выполнена, когда:

1. определён mode;
2. target module известен;
3. contract известен;
4. scope ограничен;
5. изменены только разрешённые файлы;
6. hidden contract changes отсутствуют;
7. errors не скрыты;
8. tests запущены или помечены `NOT_RUN`;
9. diff проверен;
10. documentation обновлена при необходимости;
11. secrets отсутствуют;
12. generated/backup files не изменены;
13. непроверенные части перечислены;
14. rollback понятен;
15. Git/deployment не выполнены без разрешения.

---

# 41. Критические запреты

LLM запрещено:

```text
придумывать результаты tests;
выдавать гипотезу за root cause;
сообщать fake success;
подавлять exception без state/error;
изменять raw stereo для исправления preview;
сопоставлять ChArUco points без IDs;
менять multipart только на одной стороне;
менять Room schema без migration;
использовать client absolute path на сервере;
смешивать chunks разных uploads;
завершать job без artifacts;
удалять production data;
публиковать secrets;
commit/push/deploy без команды;
использовать /baseline как current source;
редактировать generated build output;
переписывать крупный файл целиком без задачи;
обновлять dependency как побочный эффект.
```

---

# 42. Что делать при конфликте инструкций

Если обнаружен конфликт:

```text
user instruction
↔ task
↔ contract
↔ code
↔ runtime
```

LLM должна:

1. остановить опасное изменение;
2. явно показать конфликт;
3. определить, относится ли он к desired или current behavior;
4. предложить минимальное решение;
5. не выбирать молча удобную сторону.

При безопасном очевидном конфликте в документации допускается correction patch после завершения общего аудита.

---

# 43. Что делать при недостатке данных

LLM должна сделать best effort:

* изучить связанные файлы;
* найти callers/consumers;
* использовать schema;
* сформировать минимальный вывод;
* перечислить unknowns;
* подготовить команды проверки.

Нельзя заполнять unknown fields убедительно звучащими выдумками.

---

# 44. Итоговый порядок работы

```text
1. Прочитать AGENTS.md.
2. Определить mode.
3. Прочитать task.
4. Определить module.
5. Определить contract.
6. Проверить known problems.
7. Проверить roadmap stage.
8. Прочитать target files и consumers.
9. Зафиксировать baseline.
10. Предложить минимальное изменение.
11. Выполнить изменение в разрешённом режиме.
12. Запустить доступные проверки.
13. Проверить diff.
14. Обновить документацию.
15. Сообщить PASS/PARTIAL/FAIL.
16. Не выполнять Git/deployment без команды.
```

---

# 45. Краткое резюме

```text
LLM должна работать по contract,
а не по одному случайному файлу.

Текущий код,
требования
и целевая архитектура
не являются одним и тем же.

Минимальный patch
лучше полного переписывания.

Static audit
не заменяет runtime.

Build success
не доказывает корректность камеры,
upload или processing.

Git, deployment и production changes
выполняются только по явной команде.

Неизвестное
должно оставаться UNKNOWN,
пока не появится доказательство.
```
