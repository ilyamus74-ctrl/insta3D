# insta3D / MaklerTour — известные проблемы и технический долг

> Файл: `docs/llm/08_KNOWN_PROBLEMS.md`
> Актуализация: 2026-07-15
> Статус: рабочий реестр проблем
> Назначение: фиксировать подтверждённые ошибки, архитектурные риски, технический долг, непроверенные гипотезы и необходимые доказательства.

---

# 1. Назначение документа

Этот документ является единым реестром известных проблем проекта `insta3D / MaklerTour`.

Он используется для:

* планирования исправлений;
* декомпозиции задач;
* подготовки refactoring roadmap;
* передачи контекста ChatGPT, Codex, Aider и локальной LLM;
* отделения подтверждённых фактов от предположений;
* определения необходимых тестов;
* предотвращения повторного исследования уже известных проблем.

Документ не должен превращаться в список неподтверждённых догадок.

Для каждой проблемы необходимо указывать:

```text
что наблюдалось;
что подтверждено кодом или runtime;
что пока является гипотезой;
какие доказательства отсутствуют;
какое условие считается исправлением.
```

---

# 2. Статусы проблем

| Статус             | Значение                                |
| ------------------ | --------------------------------------- |
| `OPEN`             | проблема подтверждена и не исправлена   |
| `INVESTIGATING`    | выполняется сбор доказательств          |
| `PARTIALLY_FIXED`  | часть проблемы устранена                |
| `BLOCKED`          | исправление зависит от внешнего условия |
| `DEFERRED`         | исправление сознательно отложено        |
| `FIXED_UNVERIFIED` | код изменён, но runtime не проверен     |
| `FIXED`            | исправление подтверждено тестами        |
| `WONT_FIX`         | принято решение не исправлять           |
| `DUPLICATE`        | проблема объединена с другой записью    |

---

# 3. Уровни критичности

| Уровень    | Значение                                                                      |
| ---------- | ----------------------------------------------------------------------------- |
| `CRITICAL` | потеря данных, нарушение безопасности или полная остановка основного сценария |
| `HIGH`     | основной сценарий работает неправильно или нестабильно                        |
| `MEDIUM`   | функциональность ограничена, но существует обходной путь                      |
| `LOW`      | неудобство, поддерживаемость или некритичный технический долг                 |
| `INFO`     | наблюдение, которое пока не является ошибкой                                  |

---

# 4. Уровни уверенности

| Уровень     | Значение                                                           |
| ----------- | ------------------------------------------------------------------ |
| `CONFIRMED` | подтверждено кодом, схемой, логом или воспроизводимым runtime      |
| `HIGH`      | есть сильные доказательства, но отсутствует полный end-to-end тест |
| `MEDIUM`    | есть косвенные доказательства                                      |
| `LOW`       | рабочая гипотеза                                                   |
| `UNKNOWN`   | данных недостаточно                                                |

---

# 5. Формат записи проблемы

Каждая проблема оформляется следующим образом:

```text
ID:
Название:
Статус:
Критичность:
Уверенность:

Затронутые модули:
Затронутые контракты:

Наблюдение:
Подтверждённые факты:
Предположения:
Влияние:
Временный обход:
Необходимые доказательства:
Предлагаемое направление:
Критерии исправления:
Обязательные проверки:
Связанные файлы:
Связанные задачи:
```

---

# 6. Сводная таблица

| ID     | Проблема                                                                     | Критичность | Статус          | Уверенность |
| ------ | ---------------------------------------------------------------------------- | ----------: | --------------- | ----------- |
| KP-001 | `MainActivity.kt` перегружен ответственностями                               |        HIGH | OPEN            | CONFIRMED   |
| KP-002 | `AppStateViewModel.kt` перегружен use cases                                  |        HIGH | OPEN            | CONFIRMED   |
| KP-003 | `Repositories.kt` содержит несколько уровней и реализаций                    |      MEDIUM | OPEN            | CONFIRMED   |
| KP-004 | Смешанные Android namespace                                                  |      MEDIUM | OPEN            | CONFIRMED   |
| KP-005 | Release build использует mock camera provider                                |        HIGH | OPEN            | CONFIRMED   |
| KP-006 | Backend URL использует HTTP                                                  |    CRITICAL | OPEN            | CONFIRMED   |
| KP-007 | Backup и generated files находятся в Git                                     |      MEDIUM | OPEN            | CONFIRMED   |
| KP-008 | Full MySQL dumps могут содержать реальные данные                             |    CRITICAL | INVESTIGATING   | HIGH        |
| KP-009 | В MySQL нет foreign keys                                                     |        HIGH | OPEN            | CONFIRMED   |
| KP-010 | Одновременно существуют `capture_points` и `photo_points`                    |        HIGH | INVESTIGATING   | CONFIRMED   |
| KP-011 | Несколько несовместимых processing state models                              |      MEDIUM | OPEN            | CONFIRMED   |
| KP-012 | Generic `processing_jobs` не хранит историю запусков                         |      MEDIUM | OPEN            | CONFIRMED   |
| KP-013 | `capture_bundles.app_bundle_uuid` не уникален                                |        HIGH | OPEN            | CONFIRMED   |
| KP-014 | Зависимости runtime частично не закреплены                                   |      MEDIUM | OPEN            | CONFIRMED   |
| KP-015 | Абсолютные server paths дублируются                                          |        HIGH | OPEN            | CONFIRMED   |
| KP-016 | Android upload implementation находится во ViewModel                         |        HIGH | OPEN            | CONFIRMED   |
| KP-017 | `mobile.php` является крупным связанным endpoint                             |        HIGH | OPEN            | CONFIRMED   |
| KP-018 | Нет полной Android/backend integration suite                                 |        HIGH | OPEN            | CONFIRMED   |
| KP-019 | Недостаточное покрытие Room migrations                                       |        HIGH | OPEN            | CONFIRMED   |
| KP-020 | Нет автоматизированного USB UVC test harness                                 |        HIGH | OPEN            | CONFIRMED   |
| KP-021 | Возможен рост памяти или ресурсов в cam1/UVC flow                            |        HIGH | INVESTIGATING   | MEDIUM      |
| KP-022 | Stereo timestamp domains требуют подтверждения                               |        HIGH | INVESTIGATING   | MEDIUM      |
| KP-023 | Raw/display rotation остаётся критическим regression risk                    |    CRITICAL | OPEN            | CONFIRMED   |
| KP-024 | Calibration quality не имеет полного regression fixture                      |        HIGH | OPEN            | CONFIRMED   |
| KP-025 | Единицы `stereo_T` требуют явной фиксации                                    |        HIGH | INVESTIGATING   | MEDIUM      |
| KP-026 | Capture bundle не имеет завершённого versioning                              |        HIGH | OPEN            | CONFIRMED   |
| KP-027 | `result.json` не имеет единой формальной schema                              |        HIGH | OPEN            | CONFIRMED   |
| KP-028 | Chunked upload resume после restart не подтверждён                           |        HIGH | INVESTIGATING   | MEDIUM      |
| KP-029 | DB и filesystem не имеют общей транзакции                                    |        HIGH | OPEN            | CONFIRMED   |
| KP-030 | Remote jobs могут оставаться в stale `RUNNING`                               |        HIGH | PARTIALLY_FIXED | CONFIRMED   |
| KP-031 | GrafikStation deployment не является воспроизводимым полностью               |      MEDIUM | DEFERRED        | CONFIRMED   |
| KP-032 | Python dependencies GrafikStation не закреплены                              |      MEDIUM | OPEN            | CONFIRMED   |
| KP-033 | Container image и COLMAP version не зафиксированы                            |        HIGH | OPEN            | HIGH        |
| KP-034 | Local SfM использует status `PROCESSED`, документы местами ожидали `SUCCESS` |         LOW | OPEN            | CONFIRMED   |
| KP-035 | Нет единого migration framework для MySQL                                    |        HIGH | OPEN            | CONFIRMED   |
| KP-036 | Viewer зависит от нескольких поколений artifacts                             |      MEDIUM | INVESTIGATING   | MEDIUM      |
| KP-037 | Нет единого CI workflow                                                      |        HIGH | OPEN            | CONFIRMED   |
| KP-038 | Нет централизованного runtime inventory                                      |      MEDIUM | OPEN            | CONFIRMED   |
| KP-039 | Политика удаления raw media не формализована                                 |        HIGH | OPEN            | CONFIRMED   |
| KP-040 | Точное production topology не зафиксировано                                  |      MEDIUM | OPEN            | CONFIRMED   |

---

# 7. Android architecture

# KP-001 — `MainActivity.kt` перегружен ответственностями

## Статус

```text
OPEN
```

## Критичность

```text
HIGH
```

## Уверенность

```text
CONFIRMED
```

## Затронутые модули

```text
A01 Android Bootstrap and UI
A07 Phone Camera
A08 Stereo Capture
A09 Calibration
```

## Наблюдение

`MainActivity.kt` одновременно выполняет роли:

* Activity lifecycle;
* Compose root;
* navigation;
* dependency creation;
* camera lifecycle;
* phone camera preview;
* stereo preview;
* calibration orchestration;
* settings;
* часть файловой логики.

## Влияние

* высокий риск регрессий;
* большой context для LLM;
* сложно тестировать отдельные экраны;
* изменение UI может затронуть camera lifecycle;
* сложно определить владельца состояния;
* конфликтующие lifecycle effects.

## Предлагаемое направление

Поэтапно выделить:

```text
application composition root
navigation graph
screen composables
camera screen controller
stereo screen controller
calibration screen controller
```

## Запрет на исправление

Нельзя переписывать файл целиком одной задачей.

## Критерии исправления

* `MainActivity` содержит только lifecycle и root composition;
* зависимости создаются в отдельном composition module;
* крупные экраны находятся в отдельных файлах;
* camera/stereo lifecycle имеет определённого владельца;
* функциональность подтверждена runtime-тестами.

---

# KP-002 — `AppStateViewModel.kt` перегружен use cases

## Статус

```text
OPEN
```

## Критичность

```text
HIGH
```

## Уверенность

```text
CONFIRMED
```

## Наблюдение

ViewModel управляет:

* sessions;
* orders;
* camera;
* photo capture;
* Insta360 video;
* phone video;
* download;
* upload queue;
* upload implementation;
* draft;
* diagnostics.

## Влияние

* высокая связанность;
* сложные state transitions;
* сложно писать unit tests;
* невозможно безопасно изменять upload отдельно;
* coroutine scopes разных сценариев смешаны.

## Предлагаемое направление

Выделять use cases по одному:

```text
SessionUseCase
CameraCaptureUseCase
VideoScanUseCase
PhoneScanUseCase
UploadUseCase
DraftUseCase
```

## Критерии исправления

* ViewModel координирует use cases;
* файловая и HTTP-логика вынесена;
* публичный UI contract сохранён;
* state transitions покрыты tests.

---

# KP-003 — `Repositories.kt` содержит несколько уровней и реализаций

## Статус

```text
OPEN
```

## Критичность

```text
MEDIUM
```

## Уверенность

```text
CONFIRMED
```

## Наблюдение

В одном файле находятся:

* repository interfaces;
* in-memory implementations;
* SharedPreferences implementation;
* Room implementations;
* mappings.

## Влияние

* высокий размер контекста;
* сложная навигация;
* риск изменения неправильной реализации;
* interface и implementation изменяются вместе;
* backup-копии дополнительно мешают поиску.

## Предлагаемое направление

```text
repository/contracts/
repository/room/
repository/memory/
repository/mappers/
```

Разделение должно выполняться без изменения поведения.

---

# KP-004 — Смешанные Android namespace

## Статус

```text
OPEN
```

## Критичность

```text
MEDIUM
```

## Уверенность

```text
CONFIRMED
```

## Наблюдение

Используются:

```text
com.maklertour
com.example.maklertour
```

При этом application identity:

```text
namespace = com.maklertour
applicationId = com.maklertour
```

## Влияние

* сложные imports;
* неочевидная структура каталогов;
* риск дублирования классов;
* сложная навигация для LLM;
* массовое переименование может нарушить Room и manifest.

## Критерии исправления

* выбран canonical namespace;
* составлен полный список классов;
* проверены Room class names;
* проверен manifest;
* проверены tests;
* migration выполняется отдельной задачей;
* APK запускается после переименования.

---

# KP-005 — Release build использует mock camera provider

## Статус

```text
OPEN
```

## Критичность

```text
HIGH
```

## Уверенность

```text
CONFIRMED
```

## Подтверждённый факт

В build configuration:

```text
debug:
    CAMERA_PROVIDER = "osc"

release:
    CAMERA_PROVIDER = "mock"
```

## Влияние

Production release APK не будет использовать реальную Insta360 по текущей конфигурации.

## Открытый вопрос

Это может быть:

* временной защитой;
* намеренным demo behavior;
* незавершённой release configuration.

## Критерии исправления

* принято явное решение о release provider;
* release APK собран;
* release APK проверен с камерой;
* mock provider доступен только через controlled debug/test setting.

---

# KP-006 — Backend URL использует HTTP

## Статус

```text
OPEN
```

## Критичность

```text
CRITICAL
```

## Уверенность

```text
CONFIRMED
```

## Подтверждённый факт

Текущий API URL:

```text
http://makler.cargocells.com/
```

## Влияние

Возможен перехват или изменение:

* Bearer token;
* login data;
* customer data;
* upload media;
* API responses.

## Разграничение

Cleartext HTTP к Insta360 внутри локальной camera network является отдельным случаем.

Cleartext HTTP к внешнему backend не должен считаться приемлемым production-состоянием.

## Критерии исправления

* backend доступен по HTTPS;
* сертификат валиден;
* Android использует HTTPS;
* HTTP перенаправляется или отключён;
* upload протестирован;
* mixed-content и network security config проверены.

---

# 8. Repository hygiene и безопасность данных

# KP-007 — Backup и generated files находятся в Git

## Статус

```text
OPEN
```

## Критичность

```text
MEDIUM
```

## Уверенность

```text
CONFIRMED
```

## Примеры

```text
*.before_*
*.bak_*
*.bkp
build/
tmp/
generated/
```

## Влияние

* ухудшение Git search;
* ухудшение Aider repo map;
* LLM может выбрать старый файл;
* увеличение репозитория;
* сложность code review;
* возможные старые secrets.

## Предлагаемое направление

1. Создать inventory.
2. Определить active/legacy/generated.
3. Восстановить нужные части через Git history.
4. Добавить `.gitignore`.
5. Удалить мусор отдельным commit.
6. Не объединять cleanup с функциональным изменением.

---

# KP-008 — Full MySQL dumps могут содержать реальные данные

## Статус

```text
INVESTIGATING
```

## Критичность

```text
CRITICAL
```

## Уверенность

```text
HIGH
```

## Наблюдение

В `web/MySqlDump` добавлены:

* schema-only dumps;
* full dumps.

Full dump потенциально содержит:

```text
users
password hashes
mobile token hashes
customer names
phone numbers
email
public tokens
audit logs
```

## Влияние

Даже private Git repository не является правильным хранилищем production database dump.

## Необходимые доказательства

* проверить, содержит ли full dump строки данных;
* определить происхождение данных;
* проверить наличие secrets;
* проверить Git history.

## Критерии исправления

* в Git остаётся только schema-only dump;
* fixtures синтетические;
* реальные данные удалены из history;
* credentials и tokens при необходимости отозваны;
* добавлен `web/MySqlDump/README.md`;
* `.gitignore` запрещает full dumps.

---

# 9. MySQL и data model

# KP-009 — В MySQL нет foreign keys

## Статус

```text
OPEN
```

## Критичность

```text
HIGH
```

## Уверенность

```text
CONFIRMED
```

## Влияние

MySQL не предотвращает:

* session без order;
* point без session;
* scan без session;
* remote job без pipeline;
* link на удалённую point;
* settings для несуществующего run.

## Временный обход

Application code и periodic orphan queries.

## Предлагаемое направление

Не добавлять foreign keys сразу.

Сначала:

1. проверить orphan records;
2. унифицировать signed/unsigned ID;
3. определить delete policy;
4. очистить данные;
5. добавить constraints по одному.

---

# KP-010 — Одновременно существуют `capture_points` и `photo_points`

## Статус

```text
INVESTIGATING
```

## Критичность

```text
HIGH
```

## Уверенность

```text
CONFIRMED
```

## Наблюдение

Обе таблицы содержат сходные поля.

`photo_points` выглядит более полной моделью.

## Возможные объяснения

* `capture_points` является legacy;
* используется отдельным старым endpoint;
* используется web UI;
* данные частично дублируются.

## Необходимые доказательства

```text
все PHP references
все SQL references
все import/export scripts
viewer references
runtime row counts
latest write timestamps
```

## Запрет

Нельзя удалять или объединять таблицы до usage audit.

---

# KP-011 — Несколько processing state models

## Статус

```text
OPEN
```

## Критичность

```text
MEDIUM
```

## Уверенность

```text
CONFIRMED
```

## Примеры

```text
processing_jobs:
    NOT_STARTED
    RUNNING
    PROCESSED
    FAILED

sfm_pipeline_runs:
    QUEUED
    RUNNING
    DONE
    ERROR
    CANCELLED

sfm_remote_jobs:
    QUEUED
    RUNNING
    DONE
    ERROR
    ERROR_OOM
    ERROR_EMPTY
```

## Влияние

* нельзя сравнивать statuses напрямую;
* viewer может неверно интерпретировать состояние;
* documentation может использовать неправильный terminal status;
* conversion logic может скрывать ошибку.

## Предлагаемое направление

Создать mapping только после фиксации semantics каждого state machine.

---

# KP-012 — `processing_jobs` не хранит историю запусков

## Статус

```text
OPEN
```

## Критичность

```text
MEDIUM
```

## Уверенность

```text
CONFIRMED
```

## Подтверждённый факт

Существует unique constraint:

```text
UNIQUE(session_id, job_type)
```

## Следствие

Для одной session и job type возможна одна row.

Retry:

* переиспользует существующую row;
* либо конфликтует с constraint.

## Открытый вопрос

Должна ли таблица быть:

* current-state registry;
* историей runs.

## Критерии решения

Принять один из вариантов:

```text
A. Current state:
   одна row, отдельный audit/history log.

B. Run history:
   отдельный run UUID и несколько rows.
```

---

# KP-013 — `capture_bundles.app_bundle_uuid` не уникален

## Статус

```text
OPEN
```

## Критичность

```text
HIGH
```

## Уверенность

```text
CONFIRMED
```

## Наблюдение

Поле индексировано, но unique constraint отсутствует.

## Влияние

Retry upload может создать duplicate bundle rows.

Это может привести к:

* двум dense jobs;
* неправильному выбору bundle;
* повторной обработке;
* некорректному cleanup.

## Перед исправлением

Проверить existing duplicates:

```sql
SELECT capture_session_id, app_bundle_uuid, COUNT(*) AS c
FROM capture_bundles
GROUP BY capture_session_id, app_bundle_uuid
HAVING COUNT(*) > 1;
```

## Критерии исправления

* duplicates обработаны;
* upload идемпотентен;
* добавлен unique constraint;
* retry возвращает существующую row.

---

# KP-035 — Нет единого migration framework для MySQL

## Статус

```text
OPEN
```

## Критичность

```text
HIGH
```

## Уверенность

```text
CONFIRMED
```

## Наблюдение

Schema управляется через сочетание:

* dumps;
* `ensure_*_schema.php`;
* install scripts;
* ручные SQL changes.

## Влияние

Разные environments могут иметь разные schema.

## Критерии исправления

* существует schema version table;
* migrations имеют порядок;
* повторный запуск безопасен;
* migration проверяется на disposable DB;
* deployment знает текущую и target version.

---

# 10. Upload и filesystem

# KP-016 — Upload implementation находится во ViewModel

## Статус

```text
OPEN
```

## Критичность

```text
HIGH
```

## Уверенность

```text
CONFIRMED
```

## Влияние

* ViewModel знает HTTP, files и queue;
* сложно тестировать upload отдельно;
* state UI смешан с retry logic;
* изменение upload может нарушить session state.

## Предлагаемое направление

Выделить:

```text
UploadCoordinator
UploadExecutor
UploadProgress
UploadRecovery
```

Сначала покрыть текущие state transitions tests.

---

# KP-017 — `mobile.php` является крупным связанным endpoint

## Статус

```text
OPEN
```

## Критичность

```text
HIGH
```

## Уверенность

```text
CONFIRMED
```

## Ответственности

* auth;
* action dispatch;
* session creation;
* photo upload;
* video upload;
* chunk assembly;
* bundle upload;
* validation;
* DB;
* storage.

## Риск

Изменение одного action может нарушить другой.

## Предлагаемое направление

Не менять публичный URL.

Внутренне выделять по одному handler:

```text
MobileCreateSessionHandler
MobilePhotoUploadHandler
MobileVideoUploadHandler
MobileChunkUploadHandler
MobileBundleUploadHandler
```

---

# KP-028 — Chunked upload resume после restart не подтверждён

## Статус

```text
INVESTIGATING
```

## Критичность

```text
HIGH
```

## Уверенность

```text
MEDIUM
```

## Подтверждено

* существует chunked upload;
* существует локальная upload queue;
* существует retry chunk.

## Не подтверждено

* сохранение текущего chunk index после Android restart;
* server-side resume;
* обнаружение уже отправленных chunks;
* timeout cleanup;
* checksum final assembly.

## Необходимый тест

```text
1. Начать upload большого файла.
2. Завершить процесс Android после нескольких chunks.
3. Перезапустить приложение.
4. Продолжить upload.
5. Сравнить checksum.
6. Проверить отсутствие duplicate DB rows.
```

---

# KP-029 — DB и filesystem не имеют общей транзакции

## Статус

```text
OPEN
```

## Критичность

```text
HIGH
```

## Уверенность

```text
CONFIRMED
```

## Возможные ошибки

```text
file сохранён, DB insert failed;
DB row создана, file move failed;
partial file доступен worker;
cleanup удалил файл, row осталась.
```

## Предлагаемое направление

Использовать:

```text
temporary file
→ validation
→ DB transaction
→ atomic rename/finalization
```

и periodic orphan reconciliation.

---

# KP-039 — Политика удаления raw media не формализована

## Статус

```text
OPEN
```

## Критичность

```text
HIGH
```

## Уверенность

```text
CONFIRMED
```

## Открытые вопросы

* когда Android удаляет original;
* когда server удаляет source video;
* можно ли удалить bundle после dense;
* сколько хранится remote input;
* можно ли удалить failed job artifacts;
* кто подтверждает backup;
* какой retention у closed order.

## Риск

* потеря единственной копии;
* неограниченный рост storage;
* невозможность повторной обработки.

---

# 11. Native USB UVC и stereo

# KP-020 — Нет автоматизированного USB UVC test harness

## Статус

```text
OPEN
```

## Критичность

```text
HIGH
```

## Уверенность

```text
CONFIRMED
```

## Влияние

Каждое изменение требует ручного:

* подключения камеры;
* просмотра logcat;
* проверки preview;
* проверки reconnect;
* наблюдения memory.

## Предлагаемое направление

Создать debug diagnostic screen или test harness, который собирает:

```text
device info
selected format
resolution
FPS
frame size
decode failures
dropped frames
open/close count
native memory
thread count
```

---

# KP-021 — Возможен рост памяти или ресурсов в cam1/UVC flow

## Статус

```text
INVESTIGATING
```

## Критичность

```text
HIGH
```

## Уверенность

```text
MEDIUM
```

## Наблюдение

Ранее при продолжительной работе camera flow наблюдалось постепенное замедление.

## Возможные причины

Это гипотезы, а не подтверждённые факты:

* native frame buffer не освобождается;
* bitmap copies накапливаются;
* queue растёт;
* decoder создаётся повторно;
* thread создаётся при каждом start;
* file descriptors не закрываются;
* preview consumer обрабатывает кадры медленнее producer.

## Необходимые доказательства

```text
PSS timeline
native heap timeline
Java heap timeline
thread count
file descriptor count
queue depth
cam0/cam1 FPS
decode time
open/close cycles
```

## Критерии исправления

На 2-часовом тесте:

* память выходит на plateau;
* thread count стабилен;
* descriptors стабилен;
* FPS не деградирует;
* reconnect продолжает работать.

---

# KP-022 — Stereo timestamp domains требуют подтверждения

## Статус

```text
INVESTIGATING
```

## Критичность

```text
HIGH
```

## Уверенность

```text
MEDIUM
```

## Наблюдение

cam0 и cam1 имеют timestamps, но необходимо доказать, что:

* они относятся к совместимой временной шкале;
* единицы одинаковы;
* offset стабилен;
* callback delay не используется как capture timestamp.

## Риск

Pair с маленьким вычисленным delta фактически может быть несинхронным.

## Необходимые тесты

* записать timestamps обоих источников;
* сравнить monotonic behavior;
* проверить drift;
* использовать визуальное событие;
* измерить p50/p95/p99 delta.

---

# KP-023 — Raw/display rotation остаётся критическим regression risk

## Статус

```text
OPEN
```

## Критичность

```text
CRITICAL
```

## Уверенность

```text
CONFIRMED
```

## Причина

В системе одновременно существуют:

* raw coordinates;
* detector coordinates;
* calibration coordinates;
* rectified coordinates;
* display coordinates.

## Риск

Исправление визуальной ориентации preview может случайно изменить:

* saved frames;
* detector input;
* calibration;
* disparity;
* manifest.

## Текущая защита

```text
APP_CAMERA_STEREO_CONTRACT.md
stereo_contract_audit.py
```

## Ограничение

Static audit не подтверждает runtime pixels.

## Критерии улучшения

Добавить fixture test с asymmetric marker/image, чтобы rotation можно было определить автоматически.

---

# 12. Calibration и bundle

# KP-024 — Calibration quality не имеет полного regression fixture

## Статус

```text
OPEN
```

## Критичность

```text
HIGH
```

## Уверенность

```text
CONFIRMED
```

## Проблема

Build и static audit не подтверждают численную корректность calibration.

## Необходимо создать fixture

```text
10–20 сохранённых valid stereo pairs;
expected common IDs;
expected approximate intrinsics;
expected R/T;
expected RMS range;
expected rejected outlier.
```

## Критерии исправления

Одинаковая fixture:

* успешно проходит текущий algorithm;
* отклоняет заранее повреждённую pair;
* выдаёт результат в допустимом диапазоне;
* обнаруживает rotation regression.

---

# KP-025 — Единицы `stereo_T` требуют явной фиксации

## Статус

```text
INVESTIGATING
```

## Критичность

```text
HIGH
```

## Уверенность

```text
MEDIUM
```

## Проблема

Dense depth рассчитывается:

```text
Z = f * B / disparity
```

Результат зависит от единицы baseline `B`.

## Необходимо подтвердить

* board square/marker unit;
* unit translation vector;
* unit output depth;
* используются ли миллиметры или метры;
* соответствуют ли `min_depth_mm` и `max_depth_mm`.

## Критерии исправления

В JSON должны быть поля:

```text
translation_unit
depth_unit
baseline_magnitude
```

---

# KP-026 — Capture bundle не имеет завершённого versioning

## Статус

```text
OPEN
```

## Критичность

```text
HIGH
```

## Уверенность

```text
CONFIRMED
```

## Влияние

Новый Android может сформировать bundle, который старый processor интерпретирует неправильно.

## Необходимо

```text
bundle schema version;
capture manifest version;
calibration schema version;
supported-version check;
clear unsupported-version error.
```

---

# 13. Processing и artifacts

# KP-027 — `result.json` не имеет единой формальной schema

## Статус

```text
OPEN
```

## Критичность

```text
HIGH
```

## Уверенность

```text
CONFIRMED
```

## Влияние

Разные jobs могут возвращать разные поля и statuses.

Viewer и worker вынуждены использовать fallback.

## Предлагаемое направление

Создать:

```text
schemas/result.schema.json
```

с общим envelope:

```text
schema_version
ok
job_id
job_type
status
created_at
artifacts
warnings
errors
metrics
```

Job-specific данные помещать в `metrics` или `details`.

---

# KP-030 — Remote jobs могут оставаться в stale `RUNNING`

## Статус

```text
PARTIALLY_FIXED
```

## Критичность

```text
HIGH
```

## Уверенность

```text
CONFIRMED
```

## Подтверждено кодом

Remote worker уже проверяет:

* возраст status;
* отсутствие процесса;
* отсутствие container;
* `SIGABRT` в log;
* stale dense chunk.

## Остаточный риск

* worker сам остановился;
* station недоступна;
* status file не обновляется;
* job type не покрыт stale logic;
* orphan process существует без progress;
* DB остаётся `RUNNING` после restart.

## Критерии полного исправления

* timeout policy для всех job types;
* heartbeat;
* worker restart reconciliation;
* operator-visible stale reason;
* safe retry.

---

# KP-031 — GrafikStation deployment не является полностью воспроизводимым

## Статус

```text
DEFERRED
```

## Критичность

```text
MEDIUM
```

## Уверенность

```text
CONFIRMED
```

## Текущий контекст

Основная разработка выполняется локально на ноутбуке.

После изменений проект синхронизируется:

```text
локальный ноутбук
→ Git repository
→ web server
```

GrafikStation deployment сейчас не является частью ежедневного локального workflow.

## Причина отложенного статуса

* SSH deployment пока не требуется для заполнения `docs/llm`;
* station scripts существуют;
* реальный deployment будет проверяться при работе с processing pipeline;
* credentials и station configuration не должны попадать в documentation.

## Когда вернуть в работу

При изменении:

```text
web/remote_station/
sfm_remote_worker.php
COLMAP pipeline
dense pipeline
Open3D mesh
```

---

# KP-032 — Python dependencies GrafikStation не закреплены

## Статус

```text
OPEN
```

## Критичность

```text
MEDIUM
```

## Уверенность

```text
CONFIRMED
```

## Подтверждено

Main venv устанавливает:

```text
numpy
opencv-python-headless
```

без закреплённых версий.

Open3D закреплён как:

```text
open3d==0.19.0
```

## Риск

Повторный deployment может получить другие numerical results или несовместимые packages.

## Критерии исправления

* сохранить `pip freeze`;
* создать requirements/constraints;
* проверить dense fixture;
* закрепить Python version;
* задокументировать venv paths.

---

# KP-033 — Container image и COLMAP version не зафиксированы

## Статус

```text
OPEN
```

## Критичность

```text
HIGH
```

## Уверенность

```text
HIGH
```

## Риск

Изменение COLMAP может изменить:

* CLI;
* feature extraction;
* matcher;
* mapper;
* sparse selection;
* dense behavior;
* GPU requirements.

## Необходимо

Записывать в processing result:

```text
COLMAP version
execution mode
container image
image digest
GPU
driver
parameters
```

---

# KP-034 — Local SfM использует `PROCESSED`, а не `SUCCESS`

## Статус

```text
OPEN
```

## Критичность

```text
LOW
```

## Уверенность

```text
CONFIRMED
```

## Подтверждённый факт

`process_sfm_video_jobs.php` завершает успешную задачу:

```text
status = PROCESSED
```

## Проблема

Часть общей документации использует универсальный terminal status:

```text
SUCCESS
```

## Влияние

LLM или разработчик может неверно проверить результат SQL.

## Исправление

При итоговом аудите документов:

* для legacy local SfM использовать `PROCESSED`;
* для `sfm_pipeline_runs` использовать `DONE`;
* для generic conceptual flow явно указывать, что фактический status зависит от таблицы.

---

# KP-036 — Viewer зависит от нескольких поколений artifacts

## Статус

```text
INVESTIGATING
```

## Критичность

```text
MEDIUM
```

## Уверенность

```text
MEDIUM
```

## Наблюдение

В проекте существуют:

```text
video_sfm_runs
sfm_pipeline_runs
sfm_remote_jobs
legacy viewers
new viewers
manual alignment
sparse/dense outputs
```

## Необходимо определить

* какой viewer является основным;
* какие artifacts он читает;
* какие fallback поддерживаются;
* какие старые outputs ещё нужны;
* какой run выбирается latest/successful.

---

# 14. Tests, CI и эксплуатация

# KP-018 — Нет полной Android/backend integration suite

## Статус

```text
OPEN
```

## Критичность

```text
HIGH
```

## Уверенность

```text
CONFIRMED
```

## Отсутствуют автоматические проверки

```text
create session;
photo upload;
small video;
chunked video;
bundle upload;
retry;
duplicate UUID;
wrong order/session;
storage checksum.
```

## Влияние

Контракт может сломаться при изменении только одной стороны.

---

# KP-019 — Недостаточное покрытие Room migrations

## Статус

```text
OPEN
```

## Критичность

```text
HIGH
```

## Уверенность

```text
CONFIRMED
```

## Риск

Обновление APK может:

* аварийно завершиться;
* потребовать destructive migration;
* потерять upload queue;
* потерять local file references.

## Необходимо

* сохранить старые DB fixture;
* тестировать upgrade;
* проверять schema export;
* проверять interrupted states.

---

# KP-037 — Нет единого CI workflow

## Статус

```text
OPEN
```

## Критичность

```text
HIGH
```

## Уверенность

```text
CONFIRMED
```

## Минимальный будущий CI

```text
Android:
    stereo audit
    unit tests
    lint
    assembleDebug

PHP:
    php -l

Shell:
    bash -n

Python:
    py_compile

C++:
    CMake configure/build

Docs:
    Markdown links
    required files
    schema dump check
    secrets scan
```

Device, Insta360, USB и GrafikStation tests останутся отдельным hardware-in-the-loop уровнем.

---

# KP-038 — Нет централизованного runtime inventory

## Статус

```text
OPEN
```

## Критичность

```text
MEDIUM
```

## Уверенность

```text
CONFIRMED
```

## Не зафиксированы полностью

```text
PHP version
MariaDB version
ffmpeg version
COLMAP version
NDK version
CMake package version
Python packages
Podman version
NVIDIA driver
container digest
```

## Влияние

Ошибка может зависеть от environment, но не воспроизводиться на другой машине.

---

# KP-040 — Точное production topology не зафиксировано

## Статус

```text
OPEN
```

## Критичность

```text
MEDIUM
```

## Уверенность

```text
CONFIRMED
```

## Известно

```text
локальный ноутбук разработчика;
Git repository;
web server;
MySQL/MariaDB;
GrafikStation;
Android device;
Insta360;
USB UVC.
```

## Не зафиксировано окончательно

* где выполняется PHP;
* где физически находится MySQL;
* storage mounts;
* backup;
* web server type;
* TLS termination;
* production worker services;
* точный sync/deployment workflow;
* systemd units;
* station network path.

---

# 15. Проблемы, которые нельзя смешивать в одной задаче

## Нельзя объединять

```text
MainActivity refactoring
+
namespace migration
```

```text
Room schema migration
+
repository split
```

```text
HTTP → HTTPS
+
upload refactoring
```

```text
OpenCV update
+
calibration algorithm change
```

```text
COLMAP update
+
processing parameter optimization
```

```text
mobile.php split
+
multipart contract change
```

```text
repository cleanup
+
functional bug fix
```

```text
database foreign keys
+
table removal
```

Каждое изменение должно иметь отдельный baseline и rollback.

---

# 16. Приоритеты

# 16.1 Приоритет P0 — безопасность и потеря данных

```text
KP-006 Backend HTTP
KP-008 Full dumps в Git
KP-023 Raw rotation regression
KP-029 DB/filesystem inconsistency
KP-039 Raw media retention
```

# 16.2 Приоритет P1 — стабильность основных flows

```text
KP-005 Release mock provider
KP-009 No foreign keys
KP-010 capture_points/photo_points
KP-013 bundle UUID duplicates
KP-016 upload in ViewModel
KP-017 mobile.php coupling
KP-018 integration tests
KP-019 Room migrations
KP-021 UVC resource growth
KP-022 stereo timestamps
KP-024 calibration fixtures
KP-025 stereo_T units
KP-026 bundle versioning
KP-027 result schema
KP-028 chunk resume
KP-030 stale jobs
KP-033 COLMAP version
KP-035 DB migrations
KP-037 CI
```

# 16.3 Приоритет P2 — поддерживаемость

```text
KP-001 MainActivity
KP-002 AppStateViewModel
KP-003 Repositories
KP-004 Namespace
KP-007 repository hygiene
KP-011 status models
KP-012 job history
KP-014 dependency pinning
KP-015 hardcoded paths
KP-032 Python versions
KP-036 viewer generations
KP-038 runtime inventory
KP-040 topology
```

# 16.4 Deferred

```text
KP-031 GrafikStation deployment reproducibility
```

Статус `DEFERRED` не означает, что проблема решена.

---

# 17. Правила исследования проблемы

LLM или разработчик должны разделять вывод на три части.

## Наблюдения

То, что непосредственно видно:

```text
лог;
код;
SQL schema;
файл;
runtime metric;
HTTP response;
artifact.
```

## Выводы

То, что логически следует из наблюдений.

## Гипотезы

Возможные объяснения, которые ещё требуют проверки.

Пример:

```text
Наблюдение:
native heap растёт после каждого open/close.

Вывод:
часть native resources не возвращается между циклами.

Гипотезы:
frame buffer leak;
decoder leak;
thread lifecycle;
USB handle leak.
```

---

# 18. Формат новой задачи из known problem

```text
Task ID:
Problem ID:
Target module:
Contract:
Goal:

Confirmed evidence:
Hypotheses:
Evidence still needed:

Files to inspect:
Files allowed to change:
Files not to change:

Implementation scope:
Required tests:
Acceptance criteria:
Rollback:
```

---

# 19. Когда проблема считается исправленной

Недостаточно:

* изменить код;
* убрать warning;
* изменить status вручную;
* скрыть ошибку;
* добавить catch;
* написать документацию.

Проблема считается `FIXED`, когда:

```text
root cause определена;
изменение минимально;
контракт сохранён или версионирован;
reproduction больше не воспроизводится;
negative tests проходят;
нет новой регрессии;
evidence сохранено;
```

Если runtime не выполнен:

```text
FIXED_UNVERIFIED
```

---

# 20. Итоговая проверка реестра

После завершения всех файлов `docs/llm` необходимо:

1. Сопоставить problem IDs с roadmap.
2. Проверить, что каждый P0/P1 risk имеет этап.
3. Проверить contracts против known problems.
4. Проверить requirements против текущих ограничений.
5. Проверить schema и status names.
6. Проверить file paths.
7. Проверить outdated assumptions.
8. Объединить duplicates.
9. Отметить неподтверждённые записи.
10. Подготовить первый набор задач.

---

# 21. Краткое резюме

```text
Known problem
не равна предположению.

Каждая запись должна содержать
наблюдение, доказательства и критерий исправления.

Главные риски проекта:
безопасность backend;
целостность raw media;
Android/backend upload contract;
stereo coordinates;
calibration quality;
DB/filesystem consistency;
processing state;
воспроизводимость environment.

Сначала фиксируются baseline и tests.
После этого выполняется поэтапный refactoring.
```
