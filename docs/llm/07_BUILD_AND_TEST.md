# insta3D / MaklerTour — сборка и тестирование

> Файл: `docs/llm/07_BUILD_AND_TEST.md`
> Актуализация: 2026-07-15
> Статус: рабочий регламент
> Назначение: зафиксировать воспроизводимые способы сборки, установки, запуска, тестирования и подтверждения изменений.

---

# 1. Назначение документа

Документ определяет обязательные проверки для:

* Android-приложения;
* Android native C++/JNI;
* Room;
* Insta360 OSC;
* phone camera;
* USB UVC;
* stereo capture;
* calibration;
* upload;
* PHP backend;
* MySQL schema;
* `sfm_tool`;
* local SfM worker;
* remote processing;
* GrafikStation;
* dense depth;
* processing artifacts;
* web viewer.

Задача не считается выполненной только потому, что:

* код компилируется;
* модель сообщила «должно работать»;
* отдельная функция выглядит правильно;
* static audit прошёл;
* HTTP endpoint вернул `200`;
* worker завершился без видимого исключения.

Для каждого изменения необходимо подтвердить соответствующий уровень тестирования.

---

# 2. Уровни проверки

Используются следующие уровни.

| Уровень | Назначение                               |
| ------- | ---------------------------------------- |
| `L0`    | анализ diff и затронутых контрактов      |
| `L1`    | static audit и syntax checks             |
| `L2`    | compile/build                            |
| `L3`    | unit tests                               |
| `L4`    | component/integration tests              |
| `L5`    | device/runtime smoke test                |
| `L6`    | end-to-end flow                          |
| `L7`    | performance, stability и resource tests  |
| `L8`    | production verification после deployment |

## 2.1 Минимальный уровень по типу изменения

| Изменение          | Минимум                        |
| ------------------ | ------------------------------ |
| документация       | `L0`                           |
| pure helper        | `L0–L3`                        |
| Android UI         | `L0–L2`, `L5`                  |
| Room model/schema  | `L0–L5`                        |
| CameraProvider/OSC | `L0–L6`                        |
| Phone CameraX      | `L0–L6`                        |
| USB UVC/native     | `L0–L7`                        |
| Stereo/calibration | `L0–L7`                        |
| Upload API         | `L0–L6`                        |
| PHP backend        | `L0–L4`                        |
| MySQL schema       | `L0–L6`                        |
| Local SfM          | `L0–L7`                        |
| Remote worker      | `L0–L8`                        |
| Dense pipeline     | `L0–L7`                        |
| dependency update  | полный relevant regression set |

---

# 3. Общие правила тестирования

## TEST-RULE-001

Все команды выполняются из явно указанного каталога.

## TEST-RULE-002

Перед тестированием необходимо зафиксировать:

```text
Git commit:
Git branch:
Date:
Environment:
Device:
Input fixture:
Expected result:
```

## TEST-RULE-003

Результат команды должен содержать:

```text
command
exit code
stdout
stderr
duration
```

## TEST-RULE-004

Нельзя подменять фактическое выполнение команд их предполагаемым результатом.

## TEST-RULE-005

Тесты с изменением данных выполняются:

* в test database;
* на test order;
* с synthetic user;
* в отдельном storage directory;
* на отдельном processing job.

## TEST-RULE-006

Production full MySQL dump нельзя использовать как тестовую fixture внутри Git.

## TEST-RULE-007

Raw capture files должны сохраняться до завершения теста и проверки outputs.

## TEST-RULE-008

При ошибке необходимо сохранить:

* log;
* входные параметры;
* job ID;
* session/order ID;
* artifact list;
* environment versions.

## TEST-RULE-009

Static audit дополняет runtime testing, но не заменяет его.

## TEST-RULE-010

Перед performance test сначала должен проходить functional test.

---

# 4. Безопасность тестовой среды

## 4.1 Запрещено в production без отдельного плана

* удалять реальные sessions;
* очищать production storage;
* сбрасывать production database;
* повторно запускать тяжёлые jobs без оценки нагрузки;
* отправлять test media в реальную заявку клиента;
* запускать migration без backup;
* использовать production mobile token в опубликованной команде;
* запускать несколько remote workers вручную;
* тестировать destructive cleanup на единственной копии artifacts.

## 4.2 Test identifiers

Рекомендуемый prefix:

```text
TEST_
SMOKE_
LLM_
INTEGRATION_
```

Примеры:

```text
TEST_ORDER_20260715
TEST_SESSION_20260715
TEST_PHONE_SCAN_001
TEST_BUNDLE_001
```

## 4.3 Test storage

Рекомендуется использовать отдельный root:

```text
/home/makler/web/storage_test/
/home/makler_storage_test/
```

или отдельный test order/session внутри production-like storage.

---

# 5. Baseline перед изменением

Перед рефакторингом или оптимизацией необходимо сохранить baseline.

## 5.1 Git

```bash
git status
git rev-parse --abbrev-ref HEAD
git rev-parse HEAD
git diff --stat
```

## 5.2 Android environment

```bash
cd app/MaklerTour

java -version
./gradlew --version
adb version
adb devices
```

## 5.3 Backend environment

```bash
php -v
php -m
mysql --version
ffmpeg -version
ffprobe -version
```

## 5.4 GrafikStation

```bash
nvidia-smi
podman version
python3 --version
ffmpeg -version
```

Для native COLMAP:

```bash
colmap -h
```

Для Podman COLMAP — выполнить проверку configured image.

## 5.5 Baseline artifacts

Для processing-задачи сохранить:

```text
input media checksum
input size
frame count
registered image count
sparse point count
dense point count
mesh vertices/faces
runtime
peak RAM
peak VRAM
result.json
logs
```

---

# 6. Android preflight

Рабочий каталог:

```bash
cd app/MaklerTour
```

## 6.1 Проверка файлов

```bash
test -x ./gradlew
test -f app/build.gradle.kts
test -f app/src/main/AndroidManifest.xml
test -f app/src/main/cpp/CMakeLists.txt
```

## 6.2 Проверка Java

```bash
java -version
javac -version
```

Ожидаемый major:

```text
17
```

## 6.3 Проверка Gradle

```bash
./gradlew --version
```

Ожидаемая wrapper version:

```text
8.13
```

## 6.4 Проверка Android SDK

```bash
./gradlew :app:properties
```

Проверить:

```text
compileSdk = 34
targetSdk = 34
minSdk = 26
```

---

# 7. Android static contract audit

Canonical audit:

```bash
cd app/MaklerTour
python3 tools/stereo_contract_audit.py
```

Допустимый wrapper:

```bash
python3 stereo_contract_audit.py
```

## 7.1 Успешный результат

```text
Result: PASS
exit code = 0
```

## 7.2 Ошибка

```text
Result: FAIL
exit code = 1
```

## 7.3 Что проверяет audit

Audit содержит проверки для:

* cam1 preview layout;
* display-only rotation;
* запрета rotation через неправильные UI transforms;
* calibration preview;
* stereo capture UI;
* nearest timestamp pair;
* `stereoMaxDeltaMs`;
* common ChArUco IDs;
* calibration outlier filtering;
* native UVC contract;
* related manifest/depth rules.

## 7.4 Ограничение audit

Audit в основном анализирует текст исходников.

Он не подтверждает:

* реальную работу USB;
* отсутствие memory leak;
* корректность timestamps;
* качество calibration;
* корректность actual frames;
* реальную depth accuracy;
* CameraX lifecycle;
* работу на конкретном устройстве.

---

# 8. Android build

## 8.1 Основная debug-сборка

```bash
cd app/MaklerTour
./gradlew :app:assembleDebug
```

Успех:

```text
BUILD SUCCESSFUL
exit code = 0
```

APK:

```text
app/build/outputs/apk/debug/app-debug.apk
```

## 8.2 Clean build

Использовать при:

* изменении Gradle;
* изменении namespace;
* изменении Room;
* изменении native C++;
* подозрении на stale generated output.

```bash
./gradlew clean
./gradlew :app:assembleDebug
```

Не использовать `clean` автоматически для каждого маленького изменения: он скрывает проблемы incremental build и увеличивает время проверки.

## 8.3 Native build

```bash
./gradlew :app:externalNativeBuildDebug
```

После сборки проверить APK:

```bash
unzip -l app/build/outputs/apk/debug/app-debug.apk \
  | grep 'lib/arm64-v8a/libcam1_uvc.so'
```

Ожидается наличие:

```text
lib/arm64-v8a/libcam1_uvc.so
```

## 8.4 Lint

```bash
./gradlew :app:lintDebug
```

Результат lint необходимо просмотреть.

Нельзя считать команду успешной, если critical findings были просто подавлены.

## 8.5 Полный локальный Android build set

```bash
python3 tools/stereo_contract_audit.py
./gradlew :app:testDebugUnitTest
./gradlew :app:lintDebug
./gradlew :app:assembleDebug
```

---

# 9. Android unit tests

Команда:

```bash
cd app/MaklerTour
./gradlew :app:testDebugUnitTest
```

## 9.1 Текущее ограничение

Наличие `ExampleUnitTest.kt` не означает реальное покрытие проекта.

До появления настоящих tests успешная команда подтверждает главным образом:

* корректность test configuration;
* возможность запуска test task;
* отсутствие compile errors в test source set.

## 9.2 Приоритетные unit tests

Необходимо добавить tests для:

```text
Room/domain mappings
upload state transitions
chunk calculations
camera response parsing
OSC state parsing
safe JSON read/write
session/order identity
timestamp pair selection
common ChArUco ID matching
outlier filtering helpers
storage path validation
processing result validation
```

---

# 10. Android instrumented tests

Подключить устройство или emulator:

```bash
adb devices
```

Запуск:

```bash
cd app/MaklerTour
./gradlew :app:connectedDebugAndroidTest
```

## Ограничение

Camera, USB UVC и Insta360 tests требуют физического Android-устройства.

Emulator не подтверждает:

* USB host;
* реальную CameraX;
* physical camera selection;
* actual sensor timestamps;
* Insta360 Wi-Fi route.

---

# 11. Установка Android APK

## 11.1 Выбор устройства

```bash
adb devices
```

Для конкретного устройства:

```bash
export ANDROID_SERIAL="<DEVICE_SERIAL>"
```

Пример текущего test device:

```text
192.168.2.217:5555
```

## 11.2 Установка

```bash
cd app/MaklerTour

adb -s "$ANDROID_SERIAL" install -r \
  app/build/outputs/apk/debug/app-debug.apk
```

Успех:

```text
Success
```

## 11.3 Очистка данных

Только когда требуется clean-install test:

```bash
adb -s "$ANDROID_SERIAL" shell pm clear com.maklertour
```

Эта команда удаляет локальную Room DB и файлы приложения.

Не применять перед migration test.

---

# 12. Запуск приложения

```bash
adb -s "$ANDROID_SERIAL" shell monkey \
  -p com.maklertour 1
```

Альтернативно:

```bash
adb -s "$ANDROID_SERIAL" shell am force-stop com.maklertour
adb -s "$ANDROID_SERIAL" shell monkey -p com.maklertour 1
```

## Проверка процесса

```bash
adb -s "$ANDROID_SERIAL" shell pidof com.maklertour
```

---

# 13. `MakeInstall.sh`

Текущий helper:

```bash
cd app/MaklerTour
./MakeInstall.sh
```

Он выполняет:

```text
stereo audit
→ assembleDebug
→ install APK
→ force-stop application
→ clear logcat
```

## Ограничение

В script зафиксировано устройство:

```text
192.168.2.217:5555
```

Перед использованием на другом устройстве изменить script либо выполнять команды вручную.

## Правило

`MakeInstall.sh` не выполняет:

* запуск приложения;
* runtime smoke test;
* unit tests;
* instrumentation tests;
* анализ logcat;
* backend test.

---

# 14. Android crash logs

```bash
adb -s "$ANDROID_SERIAL" logcat -c
adb -s "$ANDROID_SERIAL" shell monkey -p com.maklertour 1

adb -s "$ANDROID_SERIAL" logcat -d \
  | grep -i -A 80 -B 20 \
  "FATAL EXCEPTION\|AndroidRuntime\|Room\|SQLite\|IllegalStateException"
```

## Успешный smoke

* приложение запущено;
* `FATAL EXCEPTION` отсутствует;
* Room не сообщает schema mismatch;
* native library загружена;
* главный экран доступен.

---

# 15. Android application logs

```bash
adb -s "$ANDROID_SERIAL" logcat \
  | grep -i -E \
  "AppStateViewModel|RoomSessionRepository|Insta360OscProvider|OscHttpClient|Cam1NativeUvc|PhoneCamera"
```

## Сохранение полного лога

```bash
adb -s "$ANDROID_SERIAL" logcat -v threadtime \
  > "/tmp/maklertour_$(date +%Y%m%d_%H%M%S).log"
```

Лог следует сохранить вместе с task evidence.

---

# 16. Room tests

# 16.1 Clean install

```text
1. Очистить application data.
2. Запустить приложение.
3. Создать session.
4. Добавить point/video.
5. Закрыть приложение.
6. Запустить снова.
7. Проверить восстановление.
```

## 16.2 Restart persistence

Проверить восстановление:

```text
sessions
selected session binding
points
rooms
connections
scan videos
upload queue
server IDs
file paths
```

## 16.3 Migration test

Не очищать app data.

```text
1. Установить старую APK.
2. Создать реальные локальные test records.
3. Установить новую APK через `adb install -r`.
4. Запустить.
5. Проверить schema migration.
6. Проверить чтение старых records.
7. Создать новые records.
```

## 16.4 Room diagnostics

```bash
adb -s "$ANDROID_SERIAL" logcat -c
adb -s "$ANDROID_SERIAL" shell monkey -p com.maklertour 1

adb -s "$ANDROID_SERIAL" logcat -d \
  | grep -i -A 120 -B 40 \
  "Room\|Schema\|Migration\|SQLite\|IllegalStateException"
```

## 16.5 Interrupted states

Перед принудительным завершением создать состояние:

```text
UPLOADING
DOWNLOADING
RECORDING
```

После restart проверить, что состояние не осталось бесконечно активным.

---

# 17. Authentication and orders test

## 17.1 Login matrix

Проверить:

| Сценарий                  | Ожидание                |
| ------------------------- | ----------------------- |
| корректный login/password | login success           |
| неправильный password     | controlled error        |
| inactive user             | access denied           |
| expired token             | logout/login required   |
| malformed token           | HTTP 401                |
| backend unavailable       | retryable network error |

## 17.2 Token checks

Запрещено:

* выводить raw token в logcat;
* выводить token в diagnostic export;
* сохранять token в task-файл;
* публиковать token в Git.

## 17.3 Orders

Проверить:

* operator видит назначенные заявки;
* broker не видит чужие заявки;
* closed order не принимает обычный capture;
* selected order сохраняется в session;
* неправильный order ID возвращает controlled error.

---

# 18. Insta360 direct OSC tests

Android-устройство или test machine должны иметь маршрут к:

```text
192.168.42.1
```

## 18.1 Check mode

```bash
curl -s -X POST \
  "http://192.168.42.1/osc/commands/execute" \
  -H "Content-Type: application/json;charset=utf-8" \
  -H "Accept: application/json" \
  -H "X-XSRF-Protected: 1" \
  -d '{
    "name": "camera.getOptions",
    "parameters": {
      "optionNames": [
        "captureMode",
        "_videoType",
        "_videoTypeSupport"
      ]
    }
  }'
```

## 18.2 Switch to video

```bash
curl -s -X POST \
  "http://192.168.42.1/osc/commands/execute" \
  -H "Content-Type: application/json;charset=utf-8" \
  -H "Accept: application/json" \
  -H "X-XSRF-Protected: 1" \
  -d '{
    "name": "camera.setOptions",
    "parameters": {
      "options": {
        "captureMode": "video",
        "_videoType": "normal"
      }
    }
  }'
```

После этого повторить `camera.getOptions`.

Ожидается:

```text
captureMode = video
_videoType = normal
```

## 18.3 Record video

```bash
curl -s -X POST \
  "http://192.168.42.1/osc/commands/execute" \
  -H "Content-Type: application/json;charset=utf-8" \
  -H "Accept: application/json" \
  -H "X-XSRF-Protected: 1" \
  -d '{"name":"camera.startCapture"}'

sleep 5

curl -s -X POST \
  "http://192.168.42.1/osc/commands/execute" \
  -H "Content-Type: application/json;charset=utf-8" \
  -H "Accept: application/json" \
  -H "X-XSRF-Protected: 1" \
  -d '{"name":"camera.stopCapture"}'
```

Успешный stop должен вернуть `.mp4` reference.

## 18.4 Switch to photo

```bash
curl -s -X POST \
  "http://192.168.42.1/osc/commands/execute" \
  -H "Content-Type: application/json;charset=utf-8" \
  -H "Accept: application/json" \
  -H "X-XSRF-Protected: 1" \
  -d '{
    "name": "camera.setOptions",
    "parameters": {
      "options": {
        "captureMode": "image"
      }
    }
  }'
```

Повторить `camera.getOptions`.

Ожидается:

```text
captureMode = image
```

## 18.5 Take photo

```bash
curl -s -X POST \
  "http://192.168.42.1/osc/commands/execute" \
  -H "Content-Type: application/json;charset=utf-8" \
  -H "Accept: application/json" \
  -H "X-XSRF-Protected: 1" \
  -d '{"name":"camera.takePicture"}'
```

При `state=inProgress` выполнить status polling с command ID.

## 18.6 Negative tests

* камера выключена;
* Wi-Fi камеры отключён;
* неправильный base URL;
* повторный start;
* stop без start;
* storage камеры заполнено;
* mode verification failed;
* malformed response;
* timeout.

---

# 19. Insta360 application flow test

Прямой OSC test подтверждает камеру, но не Android integration.

Проверить через приложение:

```text
1. Подключиться к camera Wi-Fi.
2. Открыть Camera screen.
3. Connect.
4. Проверить model/status.
5. Снять photo point.
6. Проверить Room record.
7. Проверить preview.
8. Запустить video.
9. Остановить video.
10. Проверить file URL.
11. Перезапустить приложение.
12. Проверить восстановление point/video.
```

## Log filters

```bash
adb -s "$ANDROID_SERIAL" logcat -v time \
  | grep -i -E \
  "Insta360OscProvider|OscHttpClient|camera.getOptions|camera.setOptions|camera.startCapture|camera.stopCapture|camera.takePicture"
```

---

# 20. Phone camera test

## 20.1 Runtime flow

```text
1. Разрешить Camera permission.
2. Открыть phone camera screen.
3. Проверить preview.
4. Выбрать lens.
5. Начать recording.
6. Записать минимум 10 секунд.
7. Остановить.
8. Проверить ScanVideo state.
9. Проверить filesystem.
10. Перезапустить приложение.
11. Проверить восстановление.
```

## 20.2 Required files

```text
video.mp4
```

Optional:

```text
camera_info.json
manifest.json
imu.jsonl
```

## 20.3 File checks

```bash
adb -s "$ANDROID_SERIAL" shell run-as com.maklertour \
  find files -type f
```

Доступность `run-as` зависит от debug build.

## 20.4 Video validation

После копирования test video:

```bash
ffprobe -v error \
  -show_entries format=duration,size \
  -show_streams \
  -of json \
  video.mp4
```

Проверить:

* размер больше нуля;
* video stream существует;
* duration разумная;
* resolution соответствует выбранной камере;
* codec поддерживается server ffmpeg.

## 20.5 Negative tests

* permission denied;
* Activity pause;
* camera disconnect/system interruption;
* start дважды;
* stop без active recording;
* storage full;
* app killed during recording.

---

# 21. USB UVC and cam1 test

## 21.1 Precondition

* Android USB host;
* UVC camera подключена;
* USB permission выдан;
* native library загружена.

## 21.2 Log filter

```bash
adb -s "$ANDROID_SERIAL" logcat -c

adb -s "$ANDROID_SERIAL" logcat -v time \
  | grep -i -E \
  "Cam1NativeUvc|UVC|TurboJPEG|MJPEG|YUYV|selected format|stream start"
```

## 21.3 Проверить

```text
device detected
permission granted
requested format
selected format
resolution
fps
stream start
actual frame bytes
preview rendered
stream stop
```

## 21.4 Expected example

```text
requested format=MJPEG
selected format=MJPEG
real libuvc stream start succeeded
```

Фактические values зависят от камеры.

## 21.5 Negative tests

* USB unplug во время preview;
* reconnect;
* unsupported resolution;
* MJPEG decode error;
* zero-byte frame;
* incomplete frame;
* Activity pause/resume;
* repeated open/close.

---

# 22. Stereo capture test

## 22.1 Preconditions

* cam0 работает;
* cam1 работает;
* обе previews видимы;
* audit PASS;
* свободное storage.

## 22.2 Проверить pair selection

Для каждого pair сохранить:

```text
pair index
cam0 timestamp
cam1 timestamp
delta ms
cam0 file
cam1 file
```

Ожидание:

```text
delta <= stereoMaxDeltaMs
```

Текущее значение:

```text
30 ms
```

## 22.3 Raw frame contract

Для сохранённых изображений проверить:

* разрешение;
* orientation;
* отсутствие display rotation;
* cam0/cam1 identity;
* одинаковый naming;
* manifest reference;
* checksum.

## 22.4 Pair count

Smoke:

```text
не менее 10 valid pairs
```

Stability:

```text
30–100 pairs
```

## 22.5 Negative tests

* delta больше threshold;
* cam1 disconnect;
* queue overflow;
* slow filesystem;
* duplicate pair index;
* missing frame;
* Activity recreation.

---

# 23. Stereo memory and stability test

## 23.1 Продолжительность

Минимальный stability run:

```text
30 минут
```

Расширенный:

```text
2–4 часа
```

## 23.2 Мониторинг Android process

```bash
adb -s "$ANDROID_SERIAL" shell dumpsys meminfo com.maklertour
```

Периодически сохранять:

```text
PSS
native heap
Java heap
graphics
number of files
number of threads
```

## 23.3 Thread count

```bash
PID=$(adb -s "$ANDROID_SERIAL" shell pidof com.maklertour | tr -d '\r')

adb -s "$ANDROID_SERIAL" shell \
  "ls /proc/$PID/task | wc -l"
```

## 23.4 File descriptor count

```bash
adb -s "$ANDROID_SERIAL" shell \
  "ls /proc/$PID/fd | wc -l"
```

## 23.5 Failure criteria

* постоянный неограниченный рост native heap;
* thread count растёт после каждого open/close;
* file descriptors не возвращаются;
* preview постепенно замедляется;
* frame queue постоянно растёт;
* USB reconnect перестаёт работать.

---

# 24. Calibration test

## 24.1 Input quality

Проверить:

```text
board полностью виден
достаточная резкость
разные позиции и углы
оба кадра относятся к одной pair
common IDs отображаются
```

## 24.2 Thresholds

Manual stereo capture:

```text
common IDs >= 35
```

Auto capture:

```text
common IDs >= 38
```

Final calibration:

```text
accepted pairs >= 10
```

## 24.3 Result checks

Проверить:

```text
success
initial pair count
candidate pair count
used pair count
rejected pair indexes
rejected reasons
initial RMS
final RMS
per-pair epipolar errors
outlier iterations
R
T
E
F
image size
```

## 24.4 Contract checks

* points сопоставлены по common ChArUco IDs;
* `CALIB_FIX_INTRINSIC`;
* final errors рассчитаны после refit;
* raw coordinates не повернуты;
* calibration resolution совпадает с frames.

## 24.5 Negative tests

* менее 35 common IDs;
* менее 10 pairs;
* одна и та же pose повторяется;
* blurred image;
* resolution mismatch;
* intentionally bad outlier;
* missing cam frame;
* повреждённый calibration JSON.

---

# 25. Capture bundle test

## 25.1 Создание

После synced capture сформировать `.tgz`.

## 25.2 Archive listing

```bash
tar -tzf bundle.tgz
```

## 25.3 Required paths

```text
bundle_manifest.json
capture/synced_depth_manifest.json
capture/pairs/
calibration/stereo_extrinsics.json
rig/active_rig_profile.json
```

## 25.4 Safe archive checks

Запрещены entries:

```text
absolute paths
../
symlink outside bundle
```

## 25.5 Extract into temporary directory

```bash
TMP_DIR=$(mktemp -d)
tar -xzf bundle.tgz -C "$TMP_DIR"
```

Проверить JSON:

```bash
python3 -m json.tool \
  "$TMP_DIR/bundle_manifest.json" >/dev/null

python3 -m json.tool \
  "$TMP_DIR/capture/synced_depth_manifest.json" >/dev/null

python3 -m json.tool \
  "$TMP_DIR/calibration/stereo_extrinsics.json" >/dev/null
```

## 25.6 Pair validation

Проверить:

* pair count совпадает с manifest;
* каждый `cam0_file` существует;
* каждый `cam1_file` существует;
* files non-zero;
* dimensions совпадают;
* `rotation_degrees_applied = 0`;
* delta не превышает contract;
* calibration соответствует resolution.

---

# 26. Capture bundle static audit

Из корня репозитория:

```bash
php web/tools/capture_bundle_dense_audit.php
```

Успех:

```text
все строки начинаются с OK
exit code = 0
```

## Ограничение

Текущий audit проверяет наличие файлов и ожидаемых code fragments.

Он не проверяет реальный `.tgz`, JSON schema, images, calibration или dense result.

---

# 27. PHP syntax checks

Из корня репозитория:

```bash
find web \
  -type f \
  -name '*.php' \
  -not -path '*/templates_c/*' \
  -print0 \
  | xargs -0 -n1 php -l
```

## Успех

Для каждого файла:

```text
No syntax errors detected
```

## Перед commit минимум проверить изменённые PHP-файлы

```bash
php -l web/www/api/mobile.php
php -l web/tools/process_sfm_video_jobs.php
php -l web/tools/sfm_remote_worker.php
```

Проверять только реально изменённые файлы необязательно ограничивать этим списком.

---

# 28. Backend bootstrap test

Проверить:

```bash
php -v
php -m | grep -E 'mysqli|json'
```

Проверить configuration files без вывода secrets:

```bash
test -f web/configs/connectDB.php
test -f web/configs/app.php
```

Нельзя включать содержимое credential files в evidence.

---

# 29. MySQL schema test

## 29.1 Использовать schema-only dump

```text
web/MySqlDump/maklertour_schema_<date>.sql.gz
```

## 29.2 Disposable database

Пример:

```bash
TEST_DB="maklertour_test_$(date +%Y%m%d_%H%M%S)"

mysql -u "$MYSQL_USER" -p \
  -e "CREATE DATABASE \`$TEST_DB\`
      CHARACTER SET utf8mb4
      COLLATE utf8mb4_unicode_ci;"
```

Restore:

```bash
gzip -dc \
  web/MySqlDump/maklertour_schema_20260715_163926.sql.gz \
  | mysql -u "$MYSQL_USER" -p "$TEST_DB"
```

## 29.3 Проверка таблиц

```bash
mysql -u "$MYSQL_USER" -p "$TEST_DB" \
  -e "SHOW TABLES;"
```

Ожидается актуальный набор таблиц.

## 29.4 Проверка schema

```bash
mysql -u "$MYSQL_USER" -p "$TEST_DB" \
  -e "SHOW CREATE TABLE capture_sessions\G
      SHOW CREATE TABLE photo_points\G
      SHOW CREATE TABLE video_scans\G
      SHOW CREATE TABLE sfm_pipeline_runs\G
      SHOW CREATE TABLE sfm_remote_jobs\G"
```

## 29.5 После теста

Удалять только disposable DB:

```bash
mysql -u "$MYSQL_USER" -p \
  -e "DROP DATABASE \`$TEST_DB\`;"
```

---

# 30. Database integrity checks

Поскольку schema не содержит foreign keys, регулярно выполнять orphan checks.

Минимум:

```sql
SELECT cs.id
FROM capture_sessions cs
LEFT JOIN tour_orders o ON o.id = cs.order_id
WHERE o.id IS NULL;

SELECT p.id
FROM photo_points p
LEFT JOIN capture_sessions cs ON cs.id = p.session_id
WHERE cs.id IS NULL;

SELECT v.id
FROM video_scans v
LEFT JOIN capture_sessions cs ON cs.id = v.session_id
WHERE cs.id IS NULL;

SELECT j.id
FROM sfm_remote_jobs j
LEFT JOIN sfm_pipeline_runs r ON r.id = j.pipeline_run_id
WHERE j.pipeline_run_id IS NOT NULL
  AND r.id IS NULL;
```

Ожидаемый результат:

```text
0 rows
```

---

# 31. Mobile API integration tests

Все тесты выполнять с synthetic user/order/session.

## 31.1 Environment

```bash
export API_BASE_URL="https://<test-host>/"
export MOBILE_TOKEN="<test-token>"
```

Не записывать production token в shell script или Git.

## 31.2 Authentication failure

```bash
curl -i \
  "${API_BASE_URL%/}/api/mobile.php?action=<protected-action>"
```

Ожидается:

```text
HTTP 401
ok=false
```

## 31.3 Invalid token

```bash
curl -i \
  -H "Authorization: Bearer invalid-test-token" \
  "${API_BASE_URL%/}/api/mobile.php?action=<protected-action>"
```

## 31.4 Create session

Проверить:

* корректный order;
* повторный `app_session_uuid`;
* чужой order;
* closed order;
* malformed UUID.

Ожидание retry:

```text
тот же capture_session_id
```

## 31.5 Photo upload

Проверить:

* preview only;
* original only;
* оба файла;
* zero-byte file;
* duplicate `app_point_uuid`;
* wrong session;
* storage failure.

## 31.6 Video upload

Проверить:

* small video;
* phone metadata;
* missing video;
* wrong MIME;
* duplicate `app_scan_uuid`;
* invalid session.

## 31.7 Chunked video

Проверить:

* несколько chunks;
* повторный identical chunk;
* missing chunk;
* wrong total size;
* interrupted upload;
* final assembly;
* final checksum.

## 31.8 Capture bundle

Проверить:

* valid bundle;
* duplicate UUID;
* wrong capture type;
* malformed `.tgz`;
* missing manifest;
* session mismatch.

---

# 32. Server storage validation

После каждого upload проверить:

```bash
test -f "<stored-file>"
test -s "<stored-file>"
stat "<stored-file>"
```

Checksum:

```bash
sha256sum "<source-file>"
sha256sum "<stored-file>"
```

Ожидается одинаковый checksum для raw upload.

## Проверить DB

```text
storage path
size_bytes
upload state
app UUID
session ID
order ID
```

## Проверить path safety

`realpath` должен находиться внутри configured storage root.

---

# 33. `sfm_tool` build

Из корня репозитория:

```bash
cmake \
  -S web/tools/sfm_cpp \
  -B /tmp/insta3d-sfm-build

cmake --build /tmp/insta3d-sfm-build
```

Binary:

```text
/tmp/insta3d-sfm-build/bin/sfm_tool
```

## Help

```bash
/tmp/insta3d-sfm-build/bin/sfm_tool --help
```

## Dependency checks

```bash
pkg-config --modversion opencv4
pkg-config --modversion apriltag
```

## Запрет

Не редактировать и не считать source-of-truth:

```text
web/tools/sfm_cpp/build/
```

---

# 34. `sfm_tool` component tests

Необходимо иметь synthetic fixtures для:

```text
detect-apriltag-frames
parse-colmap-images
rough-scale
```

## Минимум проверять

### AprilTag

* known image with marker;
* image without marker;
* wrong family;
* missing image directory;
* malformed camera profile.

### COLMAP parser

* valid `images.txt`;
* empty file;
* malformed line;
* missing file;
* stable pose count.

### Rough scale

* valid poses and marker observations;
* no markers;
* insufficient markers;
* zero-distance/invalid data;
* output JSON validity.

---

# 35. Local video SfM worker preflight

Worker использует hardcoded paths:

```text
/usr/local/bin/colmap
/home/makler/web/tools/sfm_cpp/build/bin/sfm_tool
/home/makler/web/storage/orders
```

Перед запуском:

```bash
test -x /usr/local/bin/colmap
test -x /home/makler/web/tools/sfm_cpp/build/bin/sfm_tool
test -d /home/makler/web/storage/orders
php -r 'var_dump(extension_loaded("mysqli"));'
```

## PHP syntax

```bash
php -l web/tools/process_sfm_video_jobs.php
```

---

# 36. Local video SfM smoke job

Создать test row в `processing_jobs` через официальный API/UI или test fixture.

Запуск конкретного job:

```bash
php web/tools/process_sfm_video_jobs.php \
  --job-id=<TEST_JOB_ID> \
  --limit=1
```

## Проверить DB state

```text
QUEUED/PENDING/NOT_STARTED
→ RUNNING
→ SUCCESS
```

или фактический успешный terminal status текущего flow.

## Required input

```text
video_path существует
camera_type = PHONE_VIDEO или INSTA360_DUAL_VIDEO
order_id валиден
session_id валиден
```

## Required stages

```text
prepare_video
extract_sfm_frames
extract_project_keyframes
camera profile
AprilTag detection
COLMAP feature extraction
sequential matching
mapper
model converter
pose parsing
rough scale
finalize
materialize keyframes
```

## Required outputs

Проверить:

```text
frames/
keyframes/
camera_profile.json
markers/marker_observations.json
colmap/database.db
colmap/sparse/
trajectory/camera_poses.json
trajectory/trajectory_scaled.json
logs/
```

## Failure tests

* missing COLMAP;
* missing `sfm_tool`;
* missing video;
* unsupported camera type;
* ffmpeg failure;
* no sparse model;
* invalid session path.

---

# 37. GrafikStation preflight

Из web server:

```bash
cd web/remote_station
cp stations.conf.example stations.conf
```

Заполнить local configuration без commit secrets.

## Connectivity

```bash
ssh -i "$STATION_SSH_KEY" \
  "${STATION_USER}@${STATION_HOST}" \
  'hostname && whoami'
```

## Station checks

```bash
ssh -i "$STATION_SSH_KEY" \
  "${STATION_USER}@${STATION_HOST}" \
  'nvidia-smi &&
   python3 --version &&
   ffmpeg -version &&
   podman version'
```

---

# 38. Install and deploy station

Из:

```bash
cd web/remote_station
```

Initial install:

```bash
./install_station.sh ./stations.conf
```

Deploy script updates:

```bash
./deploy_station.sh ./stations.conf
```

После deployment проверить executable permissions на web host и station.

## Station metrics

```bash
./get_station_metrics.sh ./stations.conf
```

Ожидание:

* station reachable;
* GPU visible;
* storage visible;
* scripts executable;
* required Python environments доступны.

---

# 39. Remote extract-frames smoke test

```bash
cd web/remote_station

./run_extract_frames_job.sh \
  ./stations.conf \
  1001 \
  /path/to/test-video.mp4
```

Status:

```bash
watch -n 2 \
  './get_station_status.sh ./stations.conf 1001'
```

Fetch:

```bash
./fetch_job_result.sh \
  ./stations.conf \
  1001 \
  ./output
```

Expected:

```text
output/job_1001/frames/frame_000001.jpg
status/job_1001.json
logs
```

Проверить frame count и non-zero files.

---

# 40. Remote COLMAP sparse smoke test

После extract job:

```bash
cd web/remote_station

./run_colmap_sparse_job.sh \
  ./stations.conf \
  1002 \
  /home/makler_storage/output/job_1001/frames
```

Status:

```bash
watch -n 2 \
  './get_station_status.sh ./stations.conf 1002'
```

Fetch:

```bash
./fetch_job_result.sh \
  ./stations.conf \
  1002 \
  ./output
```

Expected:

```text
output/job_1002/colmap/database.db
output/job_1002/colmap/sparse/
output/job_1002/colmap/logs/
output/job_1002/colmap/result.json
```

## GPU monitoring

На GrafikStation:

```bash
watch -n 1 nvidia-smi
```

## Sparse validation

Проверить:

```text
registered images > 0
sparse points > 0
result.json valid
status = DONE
```

---

# 41. Remote COLMAP dense test

Launcher:

```bash
cd web/remote_station

./run_colmap_dense_job.sh \
  ./stations.conf \
  <DENSE_JOB_ID> \
  <SPARSE_JOB_ID> \
  <MODEL_ID>
```

## Stages

```text
image_undistorter
patch_match_stereo
stereo_fusion
```

## Required result

```text
output/job_<DENSE_JOB_ID>/dense/fused.ply
```

## Logs

```text
dense/logs/image_undistorter.log
dense/logs/patch_match_stereo.log
dense/logs/stereo_fusion.log
```

## Validation

```bash
test -s fused.ply
head -n 30 fused.ply
```

PLY header должен содержать vertex count больше нуля.

---

# 42. Remote worker

Файл:

```text
web/tools/sfm_remote_worker.php
```

## Важное поведение

Worker запускает бесконечный цикл и обрабатывает queued jobs.

Обычный запуск:

```bash
php web/tools/sfm_remote_worker.php
```

Не запускать вторую копию вручную, пока production worker уже работает.

## Cleanup worker

```bash
php web/tools/sfm_remote_worker.php --cleanup-worker
```

Это также бесконечный worker.

## Проверить перед запуском

* существует DB config;
* существует `stations.conf`;
* station reachable;
* отсутствует дублирующий worker;
* используется test DB либо контролируемая queue;
* storage paths корректны.

## Process check

```bash
pgrep -af 'sfm_remote_worker.php'
```

## Logs

Worker должен логировать:

```text
job DB ID
job type
remote job ID
launch command
status command
progress
error
```

---

# 43. Pipeline and remote job DB test

Проверить transitions:

```text
sfm_pipeline_runs:
QUEUED → RUNNING → DONE

sfm_remote_jobs:
QUEUED → RUNNING → DONE
```

Failure:

```text
ERROR
ERROR_EMPTY
ERROR_OOM
ERROR_STALE
CANCELLED
```

## Проверить

* только один worker claim;
* `root_remote_job_id`;
* parent/child relationships;
* pipeline stage;
* progress;
* result paths;
* retry count;
* cancellation;
* cleanup scheduling.

---

# 44. Synced dense processing test

## Input

Valid capture bundle:

```text
capture_type = synced_depth_frames
```

## Static audit

```bash
php web/tools/capture_bundle_dense_audit.php
```

## Runtime flow

```text
upload bundle
→ capture_bundles row
→ create dense job
→ remote worker
→ GrafikStation
→ unpack
→ validate
→ rectify
→ disparity
→ depth
→ fetch artifacts
```

## Required outputs

```text
dense/contact_dense_depth.jpg
dense/dense_depth_debug.json
dense/dense_depth_summary.csv
result.json
```

## Debug checks

Проверить:

```text
rectified_baseline_axis
disparity_axis
depth_input_rotation
depth_method
q_valid_for_rotated_disparity
baseline_magnitude
focal_for_depth
num_disparities
block_size
valid_depth_ratio
```

## Horizontal branch

Ожидание:

```text
baseline axis = horizontal
depth input rotation = none
```

## Vertical branch

Ожидание:

```text
baseline axis = vertical
both rectified images rotated identically
manual Z calculation used
original Q not blindly reused
```

## Failure tests

* missing calibration;
* malformed manifest;
* empty pairs;
* resolution mismatch;
* invalid `T`;
* zero disparity;
* no valid depth;
* corrupt image.

---

# 45. Dense depth direct script test

На GrafikStation или совместимой test environment:

```bash
python3 \
  web/remote_station/scripts/dense_depth_from_synced_capture.py \
  <stereo_extrinsics.json> \
  <synced_depth_capture_dir> \
  <output_dir> \
  --max-pairs 20 \
  --num-disparities 128 \
  --block-size 7
```

## Успех

```text
exit code = 0
dense_depth_debug.json exists
dense_depth_summary.csv exists
contact_dense_depth.jpg exists
```

## JSON validation

```bash
python3 -m json.tool \
  <output_dir>/dense_depth_debug.json >/dev/null
```

---

# 46. Open3D mesh test

Precondition:

```text
valid input PLY
input point count >= 100
```

Запуск выполняется через station script или напрямую соответствующим Python interpreter.

Проверить:

```text
result status = DONE
vertices > 0
faces > 0
output size > 256 bytes
```

Failure tests:

* missing PLY;
* too few points;
* empty filtered cloud;
* invalid output path;
* Open3D import unavailable.

---

# 47. Artifact validation

Каждый successful processing job должен пройти проверку.

## 47.1 JSON

```bash
python3 -m json.tool result.json >/dev/null
```

## 47.2 Required fields

```text
job ID
job type
status
ok
artifacts
warnings
errors
```

## 47.3 Paths

* relative path;
* файл находится внутри job output;
* файл существует;
* файл non-zero.

## 47.4 PLY

Проверить header:

```text
ply
format
element vertex
end_header
```

Для mesh:

```text
element face
```

## 47.5 Images

```bash
file <image>
identify <image>
```

`identify` требует ImageMagick и является optional.

## 47.6 CSV

Проверить header и хотя бы одну data row для successful dense output.

---

# 48. Web UI and viewer test

Проверить роли:

```text
unauthenticated
operator
broker
admin
public link user
debug public link user
```

## Order page

Проверить:

* capture sessions;
* photo points;
* video scans;
* bundles;
* jobs;
* statuses;
* errors;
* buttons согласно role.

## Viewer

Проверить:

* latest successful run;
* missing artifact;
* failed job;
* multiple sparse components;
* point links;
* viewer settings;
* deleted points;
* public token expiry.

## Browser console

Не должно быть:

```text
uncaught exception
404 required artifact
invalid JSON
CORS error
mixed-content error после HTTPS migration
```

---

# 49. Performance test

Performance test выполняется на фиксированной fixture.

## 49.1 Android

Измерять:

```text
app startup
capture latency
photo preview download
video stop latency
bundle packaging duration
upload throughput
peak memory
```

## 49.2 Stereo

Измерять:

```text
cam0 FPS
cam1 FPS
pair acceptance ratio
average delta
p95 delta
dropped frames
queue depth
native heap
```

## 49.3 Processing

Измерять по этапам:

```text
frame extraction
feature extraction
matching
mapper
dense
fusion
mesh
transfer
```

## 49.4 Resource metrics

```text
CPU
RAM
GPU utilization
VRAM
disk read/write
storage usage
network transfer
```

## 49.5 Правило сравнения

До и после оптимизации использовать:

* одинаковый commit baseline input;
* одинаковые parameters;
* одинаковое hardware;
* одинаковую dependency environment;
* минимум три runs;
* median result.

---

# 50. Stability test

## Android

```text
30 минут minimum
2–4 часа extended
```

Проверить:

* repeated photo;
* repeated video;
* open/close camera;
* reconnect USB;
* upload queue;
* memory;
* thread count.

## Remote worker

```text
несколько последовательных jobs
job failure
station temporary unavailability
worker restart
stale RUNNING recovery
```

## GrafikStation

Проверить:

* disk growth;
* orphan processes;
* orphan containers;
* stale status;
* cleanup;
* repeated GPU jobs.

---

# 51. Regression matrix по модулю

| Модуль                 | Обязательные проверки                       |
| ---------------------- | ------------------------------------------- |
| A01 UI                 | audit, build, install, navigation, runtime  |
| A02 Auth/Orders        | build, login matrix, access control         |
| A03 ViewModel          | unit/state tests, build, affected E2E       |
| A04 Domain             | unit tests, mappings, consumers             |
| A05 Room               | build, migration, restart persistence       |
| A06 OSC                | audit, build, direct OSC, app camera flow   |
| A07 Phone Camera       | build, device recording, ffprobe, upload    |
| A08 UVC/Stereo         | native build, audit, USB runtime, stability |
| A09 Calibration        | audit, real calibration, JSON, error cases  |
| A10 Local Media        | download interruption, checksum, restart    |
| A11 Upload             | API integration, retry, chunk, DB/storage   |
| A12 Bundle             | archive audit, upload, dense smoke          |
| B01 Auth               | PHP syntax, role matrix, token tests        |
| B02 Orders             | API/UI tests, access, close states          |
| B03 Mobile API         | PHP syntax, contract integration, storage   |
| B04 Storage            | permissions, path, checksum, disk errors    |
| B05 Viewer             | browser/UI, access, artifacts               |
| B06 Jobs               | DB transitions, duplicate claim, failure    |
| P01 Local SfM          | fixture job, outputs, failure cases         |
| P02 sfm_tool           | CMake build, command fixtures               |
| P03 Remote coordinator | SSH, transfer, status, retry                |
| P04 Station runtime    | preflight, extract/sparse smoke             |
| P05 Dense              | bundle, horizontal/vertical, artifacts      |
| P06 Artifacts          | schema, path, existence, viewer             |

---

# 52. Evidence package

Для каждой завершённой задачи сохранить:

```text
task ID
commit SHA
changed files
commands executed
exit codes
test environment
device/station
input fixture
logs
screenshots, если нужны
result JSON
artifact checks
known warnings
```

Рекомендуемый файл:

```text
docs/llm/tasks/<TASK-ID>-result.md
```

## Пример

```text
Task: CAM-004
Commit: abc123
Device: 192.168.2.217:5555
Build: PASS
Stereo audit: PASS
Runtime UVC: PASS
30-minute memory test: PASS
Known warning: none
```

---

# 53. Требования к LLM

LLM должна:

1. Назвать затронутый модуль.
2. Назвать контракт.
3. Выбрать обязательные уровни тестов.
4. Не придумывать результаты.
5. Не утверждать `PASS`, если команда не запускалась.
6. Отличать static audit от runtime test.
7. Показывать точную команду.
8. Сохранять error output.
9. Не предлагать destructive production test без предупреждения.
10. Не использовать production token или full dump.
11. Не редактировать тест, чтобы скрыть реальную ошибку.
12. Не удалять проверку только потому, что она мешает refactoring.
13. После изменения проверить diff.
14. Указать оставшиеся непроверенные части.

---

# 54. Формат отчёта LLM

```text
## Scope

Target module:
Contract:
Changed files:

## Static checks

Command:
Exit code:
Result:

## Build

Command:
Exit code:
Result:

## Tests

Command:
Exit code:
Result:

## Runtime

Environment:
Steps:
Observed result:

## Artifacts

Files:
Validation:

## Not verified

- ...

## Conclusion

PASS / PARTIAL / FAIL
```

## Статусы

### `PASS`

Все обязательные проверки выполнены.

### `PARTIAL`

Код изменён и часть проверок выполнена, но отсутствует device, station, fixture или доступ.

### `FAIL`

Есть failed command, regression или нарушение контракта.

---

# 55. Definition of Done

Задача считается завершённой, когда:

1. определён scope;
2. определён контракт;
3. diff минимален;
4. static checks пройдены;
5. build пройден;
6. relevant tests пройдены;
7. runtime проверен, если нужен;
8. required artifacts существуют;
9. DB и filesystem согласованы;
10. logs не показывают новую ошибку;
11. documentation обновлена;
12. evidence сохранено;
13. rollback понятен;
14. непроверенные части перечислены;
15. commit не содержит secrets, dumps или generated build files.

---

# 56. Текущие пробелы тестирования

На текущем этапе требуют развития:

* реальные Android unit tests;
* Room migration tests;
* CameraProvider fake tests;
* OSC response fixtures;
* phone camera instrumentation tests;
* USB UVC automated harness;
* stereo timestamp unit tests;
* calibration numerical fixtures;
* capture bundle schema validator;
* Android/backend integration suite;
* chunked upload resume tests;
* PHP test framework;
* database migration framework;
* processing fixtures;
* `sfm_tool` automated tests;
* remote worker one-shot test mode;
* isolated test configuration;
* artifact JSON schemas;
* browser/viewer tests;
* CI workflow;
* dependency vulnerability checks;
* performance baseline automation.

---

# 57. Рекомендуемый обязательный локальный набор

Для обычного Android commit:

```bash
cd app/MaklerTour

python3 tools/stereo_contract_audit.py
./gradlew :app:testDebugUnitTest
./gradlew :app:lintDebug
./gradlew :app:assembleDebug
```

Для PHP commit:

```bash
php -l <changed-file.php>
```

Для `sfm_tool`:

```bash
cmake -S web/tools/sfm_cpp -B /tmp/insta3d-sfm-build
cmake --build /tmp/insta3d-sfm-build
/tmp/insta3d-sfm-build/bin/sfm_tool --help
```

Для remote station scripts:

```bash
bash -n <changed-script.sh>
```

Для Python:

```bash
python3 -m py_compile <changed-script.py>
```

Для JSON artifact:

```bash
python3 -m json.tool <file.json> >/dev/null
```

---

# 58. Краткое резюме

```text
Audit
проверяет статические контракты.

Build
подтверждает компиляцию.

Unit tests
проверяют локальную логику.

Device test
подтверждает Android, CameraX, USB и Insta360.

API integration
подтверждает Android/backend contract.

Database test
подтверждает schema и state.

Processing smoke
подтверждает ffmpeg, COLMAP и sfm_tool.

Remote smoke
подтверждает SSH, GrafikStation и artifacts.

Performance test
подтверждает, что оптимизация действительно улучшила систему.

Ни один отдельный уровень
не заменяет остальные обязательные уровни.
```
