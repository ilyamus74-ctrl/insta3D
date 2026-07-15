# AGENTS.md — insta3D / MaklerTour

## 1. Назначение

Этот файл определяет обязательный порядок работы coding-агентов с репозиторием `insta3D`.

Правила применяются к OpenAI Codex, Aider, локальным coding-LLM и другим агентам, которые могут читать или изменять working tree.

## 2. Основной порядок чтения

Перед любой нетривиальной задачей прочитать:

1. `docs/llm/00_PROJECT_OVERVIEW.md`
2. профильный task-файл из `docs/llm/tasks/`
3. `docs/llm/04_CONTRACTS.md`
4. `docs/llm/07_BUILD_AND_TEST.md`
5. `docs/llm/08_KNOWN_PROBLEMS.md`
6. `docs/llm/09_REFACTORING_ROADMAP.md`
7. `docs/llm/10_LLM_WORK_RULES.md`

Дополнительные документы выбираются по области задачи.

## 3. Источники истины

Разделять:

- фактическое текущее поведение;
- требуемое поведение;
- целевую архитектуру;
- предположения.

Приоритет фактического поведения:

1. воспроизводимый runtime;
2. текущий исполняемый код;
3. актуальная схема БД;
4. конфигурация;
5. логи и artifacts;
6. документация.

Не описывать `TARGET` как уже реализованное состояние.

## 4. Режим работы

Если task-файл не разрешает иное:

- не выполнять `git commit`;
- не выполнять `git push`;
- не выполнять deployment;
- не изменять production database;
- не перезапускать production services;
- не подключаться по SSH;
- не удалять пользовательские или runtime-файлы.

Перед изменениями показать:

- target module;
- contract;
- problem IDs;
- изменяемые файлы;
- non-goals;
- required tests.

## 5. Минимальный scope

Одна задача должна иметь один основной результат, один основной contract, ограниченный набор файлов, конкретные acceptance criteria и понятный rollback.

Не расширять scope молча.

Если требуются новая migration, новый public API, изменение status semantics, обновление dependency, изменение storage layout или новый deployment flow, остановить расширение и оформить отдельную задачу.

## 6. Каталоги и файлы, которые нужно игнорировать

По умолчанию не использовать как current source:

```text
/baseline
build/
.gradle/
.idea/
templates_c/
tmp/
cache/
generated output
runtime output
*.before_*
*.bak
*.bak_*
*.bkp
*.old
```

Исключение: task прямо требует сравнения или восстановления.

## 7. Android camera/stereo contract

При изменении Android camera, CameraX, USB UVC, stereo, calibration, IMU metadata или capture bundle обязательно прочитать:

```text
app/MaklerTour/docs/APP_CAMERA_STEREO_CONTRACT.md
app/MaklerTour/tools/stereo_contract_audit.py
```

После изменения выполнить:

```bash
cd app/MaklerTour
python3 tools/stereo_contract_audit.py
```

Запрещено ослаблять audit только для получения `PASS`.

Изменение preview не должно неявно менять raw capture.

## 8. Auto Photo / Photo SfM

Canonical Android capture type:

```text
auto_photo_session
```

Фактическое текущее состояние:

- Android снимает JPEG;
- создаёт manifest и metadata;
- создаёт TGZ;
- ставит bundle в существующую upload queue;
- загружает bundle как `auto_photo_session`;
- сервер сохраняет bundle в `capture_bundles`.

Не реализовывать вторую Android auto-photo систему без отдельной задачи.

Основной текущий epic:

```text
docs/llm/tasks/AUTO-PHOTO-EPIC.md
```

Codex должен работать только по одному дочернему task-файлу за запуск.

## 9. Backend и filesystem

Для protected endpoint обязательны:

- authentication;
- authorization;
- проверка доступа к order/session/job;
- CSRF для изменяющих web-действий;
- prepared statements;
- server-resolved paths;
- safe storage root;
- controlled JSON errors.

Client path не является trusted.

Для archive обязательны:

- запрет absolute path;
- запрет `../`;
- запрет symlink и hardlink;
- ограничение file count;
- ограничение unpacked size;
- staging;
- atomic publish;
- lock;
- idempotency.

## 10. MySQL

До изменения schema прочитать актуальный schema-only dump, producers, consumers и migration mechanism.

Нельзя:

- изменять schema без migration;
- добавлять foreign key без orphan audit;
- использовать production full dump как fixture;
- выводить реальные персональные данные в отчёт.

## 11. Processing

Различать фактические terminal statuses:

```text
processing_jobs success = PROCESSED
sfm_pipeline_runs success = DONE
sfm_remote_jobs success = DONE
```

Job нельзя считать успешным только по exit code.

Нужны валидный result JSON, required artifacts, non-zero size, корректный job ID и корректный source identity.

Для `auto_photo_session` готовые JPEG нельзя повторно извлекать из видео.

## 12. Проверки

Использовать `docs/llm/07_BUILD_AND_TEST.md`.

Минимально по типу файла:

```bash
php -l <file.php>
bash -n <file.sh>
python3 -m py_compile <file.py>
```

Android:

```bash
cd app/MaklerTour
python3 tools/stereo_contract_audit.py
./gradlew :app:testDebugUnitTest
./gradlew :app:lintDebug
./gradlew :app:assembleDebug
```

Фактический набор определяется task-файлом.

## 13. Запрет на выдуманные результаты

Не писать `Build passed`, `Tests passed` или `Runtime confirmed`, если команды не выполнялись.

Использовать:

```text
PASS
PARTIAL
FAIL
NOT_RUN
```

Отдельно перечислять непроверенные части.

## 14. Итоговый отчёт

После задачи предоставить:

1. Scope.
2. Подтверждённый current flow.
3. Изменённые файлы.
4. Что не изменялось.
5. Выполненные команды.
6. Exit codes.
7. Runtime evidence.
8. Непроверенные части.
9. Риски.
10. Итоговый статус.

## 15. Git и deployment

Без прямой команды пользователя запрещено:

- commit;
- push;
- merge;
- reset;
- force push;
- deployment на web server;
- deployment на GrafikStation;
- database migration;
- restart worker/service.
