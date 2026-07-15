# insta3D / MaklerTour — зависимости проекта

> Файл: `docs/llm/06_DEPENDENCIES.md`
> Актуализация: 2026-07-15
> Статус: фактический inventory зависимостей
> Назначение: зафиксировать build-time, runtime, внешние, аппаратные и инфраструктурные зависимости проекта.

---

# 1. Назначение документа

Документ описывает зависимости всех исполняемых контуров:

```text
Android
Backend
MySQL
Server processing
GrafikStation
Web UI
C++ tools
Python tools
External devices and services
```

Для каждой зависимости необходимо понимать:

* где она используется;
* является ли она обязательной;
* где задаётся версия;
* кто владеет конфигурацией;
* что произойдёт при недоступности;
* как проверить её наличие;
* как безопасно обновлять;
* влияет ли изменение на контракты.

Этот файл не является автоматическим lock-файлом.

Фактические версии всегда дополнительно проверяются по:

```text
Gradle files
CMake files
system packages
virtual environments
container images
runtime commands
deployment configuration
```

---

# 2. Классификация зависимостей

| Тип            | Значение                                       |
| -------------- | ---------------------------------------------- |
| `BUILD`        | требуется только для сборки                    |
| `RUNTIME`      | требуется при выполнении                       |
| `DEVICE`       | внешнее аппаратное устройство                  |
| `SERVICE`      | внешний или отдельный сетевой сервис           |
| `STORAGE`      | база данных или файловое хранилище             |
| `PROCESSING`   | инструмент обработки изображений, видео или 3D |
| `VENDORED`     | исходники библиотеки находятся в репозитории   |
| `OPTIONAL`     | используется только отдельным сценарием        |
| `CRITICAL`     | отсутствие останавливает основной сценарий     |
| `EXPERIMENTAL` | используется экспериментальной подсистемой     |

---

# 3. Общая карта зависимостей

```text
MaklerTour Android
├── Android SDK
├── Gradle
├── Android Gradle Plugin
├── Kotlin
├── Jetpack Compose
├── Room
├── CameraX
├── OpenCV Android
├── OkHttp
├── Coil
├── Android NDK
├── CMake
├── JNI/native shared library
├── Insta360 X4
├── USB UVC camera
└── MaklerTour backend

MaklerTour backend
├── Web server
├── PHP
├── mysqli
├── MySQL/MariaDB
├── Smarty
├── server filesystem
├── ffmpeg
├── COLMAP
├── sfm_tool
├── SSH/SCP
└── GrafikStation

sfm_tool
├── CMake
├── C++17 compiler
├── pkg-config
├── OpenCV 4
├── AprilTag
└── nlohmann_json

GrafikStation
├── Linux
├── Bash
├── SSH
├── Python 3
├── NumPy
├── OpenCV Python
├── Open3D
├── ffmpeg
├── COLMAP
├── Podman
├── NVIDIA driver
├── CUDA-compatible runtime
└── local disk storage
```

---

# 4. Android build environment

# 4.1 Gradle Wrapper

## Тип

```text
BUILD
CRITICAL
```

## Текущая версия

```text
Gradle 8.13
```

## Источник версии

```text
app/MaklerTour/gradle/wrapper/gradle-wrapper.properties
```

## Запуск

```bash
cd app/MaklerTour
./gradlew --version
```

## Правила

* использовать `./gradlew`, а не системный `gradle`;
* wrapper должен находиться в Git;
* изменение Gradle выполняется отдельной задачей;
* после изменения необходимо проверить Android Gradle Plugin;
* нельзя обновлять Gradle одновременно с крупным refactoring.

## Проверка

```bash
./gradlew --version
./gradlew :app:assembleDebug
```

---

# 4.2 Android Gradle Plugin

## Тип

```text
BUILD
CRITICAL
```

## Текущая версия

```text
8.5.2
```

## Источник

```text
app/MaklerTour/gradle/libs.versions.toml
```

## Plugin ID

```text
com.android.application
```

## Правила обновления

Перед обновлением проверить:

* совместимость с Gradle wrapper;
* совместимость с Android Studio;
* совместимость с Kotlin;
* совместимость с compile SDK;
* native CMake integration;
* generated BuildConfig;
* Room kapt;
* Compose compiler/plugin.

---

# 4.3 Kotlin

## Тип

```text
BUILD
RUNTIME
CRITICAL
```

## Текущая версия

```text
2.0.21
```

## Plugins

```text
org.jetbrains.kotlin.android
org.jetbrains.kotlin.plugin.compose
kotlin-kapt
```

## JVM target

```text
17
```

## Правила

* Kotlin и Compose plugin сейчас используют одну версию;
* изменение Kotlin требует полной сборки;
* необходимо проверять kapt/Room;
* нельзя одновременно менять Kotlin и архитектуру state layer;
* compiler warnings после обновления должны быть проанализированы, а не подавлены массово.

---

# 4.4 Java Development Kit

## Тип

```text
BUILD
CRITICAL
```

## Требуемая версия

```text
Java 17
```

## Настройки

```text
sourceCompatibility = Java 17
targetCompatibility = Java 17
kotlin jvmTarget = 17
```

## Проверка

```bash
java -version
javac -version
```

## Правило

Сборка должна использовать один и тот же major JDK локально и в CI.

---

# 4.5 Android SDK

## Тип

```text
BUILD
RUNTIME
CRITICAL
```

## Настройки

```text
compileSdk = 34
targetSdk = 34
minSdk = 26
```

## Значение

```text
Минимальная версия устройства:
Android 8.0 / API 26

Целевая и compile версия:
Android API 34
```

## Проверка

```bash
sdkmanager --list
./gradlew :app:properties
```

## Риски

* изменение target SDK может менять permission behavior;
* camera, storage и background execution могут вести себя иначе;
* новые SDK restrictions должны проверяться runtime-тестами;
* нельзя считать compile success достаточной проверкой permission flow.

---

# 4.6 Android ABI

## Тип

```text
BUILD
RUNTIME
DEVICE
CRITICAL
```

## Поддерживаемая ABI

```text
arm64-v8a
```

## Следствие

APK с native `cam1_uvc` рассчитан только на ARM64-устройства.

По умолчанию не поддерживаются:

```text
armeabi-v7a
x86
x86_64
```

## Проверка APK

```bash
unzip -l app/build/outputs/apk/debug/app-debug.apk | grep '^.*lib/'
```

Ожидается:

```text
lib/arm64-v8a/libcam1_uvc.so
```

## Риск

Android emulator x86/x86_64 не сможет загрузить ARM64 native library без соответствующей эмуляции.

---

# 5. Android application dependencies

# 5.1 AndroidX Core KTX

## Версия

```text
1.13.1
```

## Назначение

* Kotlin extensions Android framework;
* lifecycle и platform helpers;
* context/file/permission operations.

## Источник

```text
libs.androidx.core.ktx
```

---

# 5.2 Lifecycle Runtime KTX

## Версия

```text
2.8.6
```

## Назначение

* lifecycle-aware coroutines;
* lifecycle state;
* Activity/Compose integration.

## Источник

```text
libs.androidx.lifecycle.runtime.ktx
```

---

# 5.3 Lifecycle ViewModel KTX

## Версия

```text
2.8.7
```

## Назначение

* ViewModel;
* `viewModelScope`;
* coroutine orchestration;
* lifecycle persistence boundary.

## Источник

Версия задана напрямую в:

```text
app/MaklerTour/app/build.gradle.kts
```

## Известная несогласованность

```text
lifecycle-runtime-ktx = 2.8.6
lifecycle-viewmodel-ktx = 2.8.7
```

Это не обязательно является ошибкой, но version alignment не централизован.

Целевое улучшение:

```text
вынести обе версии в version catalog
```

---

# 5.4 Activity Compose

## Версия

```text
1.9.2
```

## Назначение

* `ComponentActivity`;
* `setContent`;
* Compose lifecycle integration.

---

# 5.5 Compose BOM

## Версия

```text
2024.09.00
```

## Назначение

Compose BOM управляет совместимыми версиями:

```text
compose-ui
compose-ui-graphics
compose-ui-tooling
compose-ui-tooling-preview
compose-ui-test
```

## Правило

Для библиотек, управляемых BOM, не следует без причины задавать отдельные версии.

---

# 5.6 Material 3

## Версия

```text
1.3.0
```

## Назначение

* Compose UI controls;
* dialogs;
* navigation components;
* themes;
* buttons and cards.

## Примечание

Версия Material 3 задана отдельно, а не только через Compose BOM.

Перед обновлением проверить совместимость с BOM.

---

# 5.7 Navigation Compose

## Версия

```text
2.8.2
```

## Назначение

* `NavHost`;
* routes;
* back stack;
* bottom navigation.

## Риск изменения

Navigation upgrade может менять:

* state restoration;
* route arguments;
* deep-link behavior;
* lifecycle экранов;
* повторный запуск effects.

---

# 5.8 Google Material Components

## Версия

```text
1.12.0
```

## Назначение

Используется для Android Material components и совместимости с platform UI.

Следует отличать от:

```text
androidx.compose.material3
```

---

# 5.9 Room

## Версия

```text
2.6.1
```

## Компоненты

```text
androidx.room:room-runtime
androidx.room:room-ktx
androidx.room:room-compiler
```

## Annotation processor

```text
kapt
```

## Назначение

* Android SQLite database;
* entities;
* DAO;
* Flow;
* local session persistence;
* upload queue persistence.

## Критические требования

* изменение Room version отдельно от schema refactoring;
* сохранить migration compatibility;
* проверить kapt generation;
* проверить schema export;
* проверить старую локальную базу;
* проверить interrupted upload recovery.

## Проверки

```bash
./gradlew :app:kaptDebugKotlin
./gradlew :app:assembleDebug
```

---

# 5.10 Coil Compose

## Версия

```text
2.7.0
```

## Назначение

* загрузка изображений в Compose;
* preview;
* local/remote images;
* asynchronous image rendering.

## Риски

* загрузка full-resolution original вместо preview;
* cache сохраняет устаревшее изображение;
* memory pressure при больших panorama;
* URI/file path compatibility.

---

# 5.11 OpenCV Android

## Версия

```text
4.9.0
```

## Maven dependency

```text
org.opencv:opencv:4.9.0
```

## Назначение

* ChArUco detection;
* camera calibration;
* stereo calibration;
* matrix operations;
* image processing;
* rectification-related calculations.

## Критичность

```text
CRITICAL
```

для:

```text
stereo calibration
board detection
rig computation
```

## Правила обновления

OpenCV нельзя обновлять одновременно с изменением calibration algorithm.

После обновления проверить:

* native loading;
* ChArUco API;
* matrix types;
* calibration flags;
* stereo calibration;
* serialization;
* Android ABI;
* output RMS и epipolar errors.

---

# 5.12 OkHttp

## Версия

```text
4.12.0
```

## Назначение

* backend API;
* Insta360 OSC transport;
* multipart upload;
* chunked upload;
* file download;
* Bearer authentication.

## Критичность

```text
CRITICAL
```

## Риски

* timeout configuration;
* routing запроса через неправильную network;
* response body consumption;
* connection leaks;
* upload cancellation;
* retry semantics;
* cleartext HTTP;
* большие multipart requests.

## Правила

* всегда закрывать `Response`;
* не читать большой response body без ограничения;
* не выполнять network request на main thread;
* camera HTTP и backend HTTP должны иметь раздельный routing/context;
* автоматический retry не должен дублировать non-idempotent upload.

---

# 5.13 CameraX

## Версия

```text
1.3.4
```

## Компоненты

```text
camera-camera2
camera-lifecycle
camera-video
camera-view
```

## Назначение

* phone camera preview;
* video recording;
* Camera2 backend;
* lifecycle binding;
* `PreviewView`;
* cam0 stereo capture.

## Критичность

```text
CRITICAL
```

для phone video и stereo cam0.

## Риски обновления

* Camera2 device selection;
* physical camera behavior;
* concurrent camera support;
* timestamp behavior;
* image analysis format;
* recording finalization;
* lifecycle unbind;
* rotation metadata;
* preview transformation.

## Обязательные runtime-проверки

* bind/unbind;
* start/stop recording;
* video non-zero size;
* pause/resume;
* lens selection;
* cam0 raw frame;
* stereo timestamp;
* preview orientation;
* saved frame orientation.

---

# 5.14 Test dependencies

## JUnit

```text
4.13.2
```

## AndroidX JUnit

```text
1.2.1
```

## Espresso

```text
3.6.1
```

## Compose UI tests

Управляются Compose BOM.

## Текущее значение

Build-файл содержит test dependencies, но наличие dependency ещё не означает достаточное test coverage.

Необходимо отдельно инвентаризировать:

```text
src/test/
src/androidTest/
```

---

# 6. Android native dependencies

# 6.1 Android NDK

## Тип

```text
BUILD
RUNTIME
CRITICAL
EXPERIMENTAL
```

## Назначение

Сборка native библиотеки:

```text
libcam1_uvc.so
```

## Версия

Версия NDK в текущем build-файле явно не закреплена через:

```text
ndkVersion
```

Фактическая версия зависит от установленного Android SDK/NDK и Gradle environment.

## Риск

Разные машины могут использовать разные NDK.

## Целевое улучшение

После проверки рабочей версии закрепить:

```kotlin
android {
    ndkVersion = "<verified-version>"
}
```

---

# 6.2 Android CMake

## Минимальная версия проекта

```text
3.22.1
```

## Источник

```text
app/MaklerTour/app/src/main/cpp/CMakeLists.txt
```

## Build integration

```text
externalNativeBuild.cmake
```

## Проверка

```bash
cmake --version
./gradlew :app:externalNativeBuildDebug
```

---

# 6.3 Native library `cam1_uvc`

## Target

```text
cam1_uvc
```

## Type

```text
SHARED
```

## Source

```text
cam1_uvc.cpp
```

## Linked Android system libraries

```text
log
android
dl
m
```

## Назначение

* USB UVC cam1 integration;
* native camera operations;
* frame delivery;
* native logging;
* dynamic system interaction.

## Важное ограничение

Текущий CMake-файл напрямую связывает только Android system libraries.

Если `cam1_uvc.cpp` динамически загружает стороннюю UVC-библиотеку или использует platform symbols, это должно быть отдельно отражено после аудита исходника.

## Проверки

```bash
readelf -d libcam1_uvc.so
nm -D libcam1_uvc.so
adb logcat | grep -i cam1
```

---

# 6.4 USB host support

## Тип

```text
DEVICE
RUNTIME
CRITICAL
EXPERIMENTAL
```

## Требования устройства

* Android USB host mode;
* физический USB OTG/host;
* достаточное питание камеры;
* USB permission;
* совместимый UVC device;
* стабильный cable;
* поддерживаемый video format.

## Риски

* недостаточное питание;
* reconnect;
* permission lost;
* USB device path изменился;
* MJPEG frame повреждён;
* camera bandwidth;
* buffer lifetime;
* unsupported descriptor.

---

# 7. Android external device dependencies

# 7.1 Insta360 X4

## Тип

```text
DEVICE
SERVICE
RUNTIME
CRITICAL
```

## Transport

```text
Wi-Fi
HTTP OSC
```

## Base URL

```text
http://192.168.42.1
```

## Required endpoints

```text
/osc/commands/execute
/osc/commands/status
/osc/state
```

## Важное условие

Android-устройство должно иметь сетевой маршрут к camera Wi-Fi.

## Риски

* backend internet недоступен при подключении к camera Wi-Fi;
* Android выбирает неправильную network;
* camera firmware меняет OSC behavior;
* stale state;
* unsupported option names;
* camera storage full;
* low battery.

## Version policy

Firmware Insta360 должна фиксироваться в runtime inventory.

Рекомендуемый диагностический файл:

```text
docs/runtime/INSTA360_DEVICE_INVENTORY.md
```

---

# 7.2 USB UVC cam1

## Тип

```text
DEVICE
RUNTIME
CRITICAL
EXPERIMENTAL
```

## Требования

* UVC-compatible device;
* стабильное разрешение;
* фиксированный или известный format;
* временные метки;
* USB permission;
* достаточная bandwidth;
* известная camera orientation;
* calibration profile.

## Запрещено предполагать

* что любой UVC device имеет одинаковый timestamp;
* что preview orientation равна raw orientation;
* что resolution автоматически соответствует calibration;
* что reconnect возвращает ту же camera identity.

---

# 7.3 Android sensors

## Тип

```text
DEVICE
RUNTIME
OPTIONAL
```

## Используются для

* IMU recording;
* gravity vector;
* physical orientation;
* diagnostics;
* metadata.

## Не используются как источник

* raw frame rotation;
* stereo calibration;
* baseline axis;
* disparity axis.

---

# 8. Android application configuration

# 8.1 Package identity

```text
namespace = com.maklertour
applicationId = com.maklertour
```

## Известный долг

В исходниках также встречается:

```text
com.example.maklertour
```

Это source namespace debt, а не отдельное application ID.

---

# 8.2 Backend URL

## Текущее значение

```text
http://makler.cargocells.com/
```

## Задаётся в

```text
BuildConfig.API_BASE_URL
```

## Build types

Одинаковый HTTP URL сейчас задан для:

```text
defaultConfig
debug
release
```

## Риск

Backend работает через cleartext HTTP.

Риски:

* перехват token;
* подмена API response;
* изменение upload;
* утечка customer data;
* невозможность безопасного public deployment.

## Целевое состояние

```text
HTTPS
valid certificate
separate debug/release endpoint
secrets outside source code
```

---

# 8.3 Camera provider build configuration

## Debug

```text
CAMERA_PROVIDER = "osc"
```

## Release

```text
CAMERA_PROVIDER = "mock"
```

## Риск

Текущий release APK по build-конфигурации использует mock camera provider.

Это может быть намеренным временным ограничением, но production release с реальной камерой потребует отдельного изменения и проверки.

Нельзя менять это значение скрыто внутри другой задачи.

---

# 8.4 Mock upload configuration

```text
USE_MOCK_UPLOAD_API = false
```

для debug и release.

---

# 8.5 Insta360 URL

```text
INSTA360_OSC_BASE_URL = "http://192.168.42.1"
```

задан в build configuration.

## Целевое улучшение

Camera URL может оставаться compile-time default, но runtime override должен быть контролируемым и диагностируемым.

---

# 9. Backend runtime dependencies

# 9.1 Web server

## Тип

```text
RUNTIME
CRITICAL
```

## Требования

* PHP execution;
* public document root;
* `.htaccess` или equivalent routing/config;
* upload size configuration;
* request timeout;
* file permissions;
* secure TLS termination;
* access logs;
* error logs.

## Фактический продукт

Точный production web server необходимо записать отдельно:

```text
Apache
Nginx + PHP-FPM
другая конфигурация
```

Не следует предполагать конкретный server только по наличию `.htaccess`.

---

# 9.2 PHP

## Тип

```text
RUNTIME
BUILD
CRITICAL
```

## Использование

* web pages;
* mobile API;
* authentication;
* upload;
* MySQL;
* workers;
* processing coordination;
* CLI tools;
* remote worker.

## Версия

Текущая минимальная и production PHP version не закреплена в dependency manifest.

Фактическую версию проверить:

```bash
php -v
php --ini
```

## Требуемые возможности

По текущему коду необходимы как минимум:

```text
strict types
mysqli
JSON
filesystem
CLI
process execution
multipart upload
session/auth helpers
```

Дополнительные extensions необходимо определить через runtime audit:

```bash
php -m
```

## Рекомендуемый inventory

```text
PHP version
loaded extensions
memory_limit
upload_max_filesize
post_max_size
max_execution_time
max_input_time
```

---

# 9.3 PHP mysqli

## Тип

```text
RUNTIME
STORAGE
CRITICAL
```

## Назначение

* MySQL connection;
* prepared statements;
* transactions;
* job state;
* user/order/session state.

## Проверка

```bash
php -r 'var_dump(extension_loaded("mysqli"));'
```

## Правила

* пользовательские данные только через prepared statements;
* connection charset должен быть `utf8mb4`;
* DB errors не должны отправлять sensitive details пользователю;
* reconnect не должен повторять неидемпотентную операцию автоматически.

---

# 9.4 Smarty

## Тип

```text
VENDORED
RUNTIME
```

## Текущая версия

```text
5.3.1
```

## Расположение

```text
web/libs/Smarty.class.php
web/src/
```

## Loading model

Smarty загружается через vendored PSR-4 loader без обязательного Composer.

## Назначение

* PHP templates;
* server-rendered UI;
* compiled templates;
* web order pages.

## Риски

* vendored library сложно обновлять;
* generated templates могут попасть в Git;
* security fixes не устанавливаются автоматически;
* source library смешивается с application repository.

## Правила обновления

* обновлять отдельным commit;
* проверить лицензию;
* очистить compiled template cache;
* проверить все active templates;
* не редактировать vendor source для application behavior;
* application customization хранить вне Smarty source.

---

# 9.5 PHP dependency management

В текущем inventory dependency manager PHP не является очевидным источником истины.

Smarty находится непосредственно в репозитории.

Целевое улучшение:

* определить все сторонние PHP-библиотеки;
* создать dependency inventory;
* решить, использовать ли Composer;
* не переводить production на Composer в рамках несвязанного refactoring;
* сохранить воспроизводимый deployment.

---

# 10. MySQL/MariaDB

# 10.1 Database engine

## Тип

```text
STORAGE
RUNTIME
CRITICAL
```

## Использование

* users;
* tokens;
* orders;
* capture sessions;
* media metadata;
* processing jobs;
* viewer settings;
* audit logs.

## Точная версия

Production MySQL/MariaDB version должна проверяться на сервере:

```bash
mysql --version
```

и запросом:

```sql
SELECT VERSION();
```

## Schema source

```text
web/MySqlDump/maklertour_schema_<timestamp>.sql.gz
```

## Важное ограничение

Текущая схема не содержит объявленных foreign keys.

Referential integrity зависит от application code.

---

# 10.2 Character set

Целевой charset:

```text
utf8mb4
```

Необходимо проверить:

```sql
SHOW VARIABLES LIKE 'character_set%';
SHOW VARIABLES LIKE 'collation%';
```

---

# 10.3 Database backup tools

## Dependencies

```text
mysqldump
gzip
mysql client
filesystem backup destination
```

## Schema-only dump

Допустим для Git:

```text
mysqldump --no-data
```

## Full dump

Не должен храниться в Git при наличии реальных данных.

---

# 10.4 Database migration dependency

В проекте используются:

* schema dumps;
* отдельные `ensure_*_schema.php`;
* install scripts;
* ручные schema changes.

Единый migration framework пока не зафиксирован.

## Риск

Разные серверы могут иметь разные версии schema.

## Целевое состояние

```text
ordered migrations
schema version table
repeatable deployment
rollback/forward recovery
CI schema validation
```

---

# 11. Server filesystem dependencies

# 11.1 Storage root

## Тип

```text
STORAGE
RUNTIME
CRITICAL
```

## Logical path

```text
storage/orders/<orderId>/sessions/<sessionUuid>/
```

## В runtime-коде встречаются roots

```text
/home/makler/web/storage/
/home/storage/
```

## Требования

* достаточно свободного места;
* PHP write access;
* worker read/write access;
* web download access только через controlled endpoints;
* GrafikStation transfer access;
* backup;
* cleanup policy.

## Проверки

```bash
df -h
df -i
findmnt
namei -l /home/makler/web/storage
```

---

# 11.2 Temporary storage

Требуется для:

* multipart uploads;
* chunk uploads;
* archive assembly;
* extraction;
* COLMAP databases;
* frame extraction;
* remote transfer.

## Риски

* temp directory на маленьком partition;
* orphan chunks;
* concurrent job collision;
* stale work directory;
* permissions;
* filesystem full.

---

# 12. Server processing dependencies

# 12.1 PHP CLI

## Тип

```text
PROCESSING
RUNTIME
CRITICAL
```

## Используется для

* workers;
* job coordination;
* finalize scripts;
* artifact export;
* audit;
* cleanup.

## Проверка

```bash
php -v
php web/tools/process_sfm_video_jobs.php --help
```

Если скрипт не поддерживает `--help`, его следует запускать только с безопасными диагностическими аргументами.

---

# 12.2 ffmpeg

## Тип

```text
PROCESSING
RUNTIME
CRITICAL
```

## Используется для

* video frame extraction;
* keyframes;
* stream selection;
* scaling;
* video inspection;
* preview preparation.

## Проверка

```bash
ffmpeg -version
ffprobe -version
```

## Требования

* MP4/H.264/H.265 decoding согласно исходным video;
* фильтр `fps`;
* фильтр `scale`;
* stream mapping;
* JPEG output.

## Риски

* different build codecs;
* unsupported source codec;
* wrong video stream;
* hardware acceleration differences;
* variable frame rate;
* timestamps lost;
* command shell escaping.

## Version policy

Версию ffmpeg необходимо фиксировать в deployment inventory или container image.

---

# 12.3 COLMAP

## Тип

```text
PROCESSING
RUNTIME
CRITICAL
```

## Используется для

* feature extraction;
* matching;
* sparse mapping;
* model conversion;
* dense reconstruction;
* GPU processing.

## Local worker path

В legacy server worker используется:

```text
/usr/local/bin/colmap
```

## Remote runner configuration

```text
COLMAP_MODE
COLMAP_BIN
COLMAP_IMAGE
COLMAP_MATCHER
COLMAP_SEQUENTIAL_OVERLAP
COLMAP_LOOP_DETECTION
```

## Modes

```text
native
container-based
```

согласно station configuration и runner scripts.

## Проверка

```bash
colmap -h
colmap feature_extractor -h
colmap sequential_matcher -h
colmap mapper -h
```

## Риски

* version changes CLI options;
* CUDA support absent;
* GPU architecture incompatibility;
* VRAM exhaustion;
* different camera model defaults;
* old database reused;
* loop detection dependency;
* native/container results differ.

## Rule

COLMAP version и execution mode должны записываться в `result.json` или processing diagnostics.

---

# 12.4 Local C++ `sfm_tool`

## Тип

```text
PROCESSING
BUILD
RUNTIME
CRITICAL
```

## Build system

```text
CMake >= 3.16
C++17
```

## Binary

```text
sfm_tool
```

## Expected runtime path

```text
/home/makler/web/tools/sfm_cpp/build/bin/sfm_tool
```

## Commands used by pipeline

```text
detect-apriltag-frames
parse-colmap-images
rough-scale
```

## Build dependencies

```text
pkg-config
OpenCV 4
AprilTag
nlohmann_json
C++ compiler
CMake
```

---

# 12.5 OpenCV 4 development libraries

## Discovery

```text
pkg-config opencv4
```

## Проверка

```bash
pkg-config --modversion opencv4
pkg-config --cflags --libs opencv4
```

## Назначение

* image loading;
* calibration profile;
* marker processing;
* geometry;
* image operations.

## Важное различие

Server OpenCV и Android OpenCV являются разными runtime builds.

Их версии не обязаны совпадать, но форматы JSON и математические контракты должны быть совместимы.

---

# 12.6 AprilTag library

## Discovery

```text
pkg-config apriltag
```

## Проверка

```bash
pkg-config --modversion apriltag
pkg-config --cflags --libs apriltag
```

## Назначение

* marker detection;
* AprilTag family;
* metric scale support.

## Контракт

Marker family должна совпадать между:

```text
capture/setup
processing parameters
marker_kit_layout
detector
```

---

# 12.7 nlohmann_json

## Discovery

```text
find_package(nlohmann_json REQUIRED)
```

## Назначение

* C++ JSON parsing;
* JSON output;
* camera profiles;
* marker observations;
* trajectory results.

## Риск

Input JSON schema errors должны возвращать non-zero exit code и понятную ошибку.

---

# 13. Remote processing infrastructure

# 13.1 SSH

## Тип

```text
SERVICE
RUNTIME
CRITICAL
```

## Используется для

* deployment scripts;
* запуск remote job;
* status;
* directory preparation;
* command execution.

## Configuration

```text
STATION_HOST
STATION_USER
STATION_SSH_KEY
```

## Security

* private key не хранится в Git;
* key file должен иметь permissions `0600`;
* station user должен иметь минимальные права;
* known host должен проверяться;
* current scripts используют `StrictHostKeyChecking=accept-new`;
* изменение host key после первого подключения должно рассматриваться как security event.

---

# 13.2 SCP

## Тип

```text
SERVICE
RUNTIME
CRITICAL
```

## Используется для

* deployment scripts;
* input transfer;
* result transfer;
* artifact retrieval.

## Риски

* partial file;
* no resume;
* large bundle transfer;
* slow link;
* insufficient disk;
* transfer completed, но checksum не проверен.

## Целевое улучшение

Для критичных artifacts:

```text
size verification
SHA-256 verification
atomic destination rename
```

---

# 13.3 `stations.conf`

## Тип

```text
CONFIGURATION
CRITICAL
```

## Обязательные параметры

```text
STATION_NAME
STATION_HOST
STATION_USER
STATION_SSH_KEY
STATION_BASE
```

## Дополнительные параметры

```text
INSTALL_STATION_DEPENDENCIES
INSTALL_OPEN3D_DEPENDENCIES

COLMAP_MODE
COLMAP_BIN
COLMAP_IMAGE
COLMAP_MATCHER
COLMAP_SEQUENTIAL_OVERLAP
COLMAP_LOOP_DETECTION
COLMAP_CAMERA_MODEL_AUTO_FROM_METADATA
```

## Правила

* production config не хранит private key content;
* example config может находиться в Git;
* реальные hostnames и paths документируются отдельно;
* default values должны быть безопасными;
* worker должен валидировать обязательные variables до начала job.

---

# 14. GrafikStation operating system

## Тип

```text
RUNTIME
CRITICAL
```

## Фактическая среда

Текущая station использует Fedora/Linux.

Deployment script поддерживает:

```text
dnf
apt-get
```

для части Python dependencies.

Open3D auto-install в текущем скрипте ориентирован на:

```text
Fedora/dnf
Python 3.12
```

## Риски

* package names отличаются между distributions;
* system Python отличается;
* SELinux;
* firewall;
* Podman permissions;
* system updates меняют driver/runtime.

---

# 15. Python dependencies GrafikStation

# 15.1 Base Python

## Тип

```text
PROCESSING
RUNTIME
CRITICAL
```

## Команда

```text
python3
```

## Используется для

* planning;
* chunk merging;
* dense depth;
* JSON processing;
* image processing;
* mesh generation helpers.

## Проверка

```bash
python3 --version
```

---

# 15.2 Main virtual environment

## Path

```text
$STATION_BASE/venv
```

## Установка

```bash
python3 -m venv "$STATION_BASE/venv"
"$STATION_BASE/venv/bin/pip" install numpy opencv-python-headless
```

## Dependencies

```text
numpy
opencv-python-headless
```

## Версии

В текущем deployment script версии не закреплены.

Это делает deployment невоспроизводимым во времени.

## Целевое улучшение

Создать:

```text
web/remote_station/requirements.txt
```

с проверенными версиями и hashes либо lock-файл.

До этого необходимо зафиксировать рабочие runtime versions:

```bash
$STATION_BASE/venv/bin/pip freeze
```

---

# 15.3 NumPy

## Используется для

* matrices;
* disparity/depth;
* point cloud data;
* statistics;
* array operations;
* geometry.

## Проверка

```bash
$STATION_BASE/venv/bin/python -c \
'import numpy; print(numpy.__version__)'
```

---

# 15.4 OpenCV Python

## Package

```text
opencv-python-headless
```

## Используется для

* stereo rectification;
* StereoSGBM;
* image loading;
* image rotation;
* maps;
* previews;
* depth output.

## Проверка

```bash
$STATION_BASE/venv/bin/python -c \
'import cv2; print(cv2.__version__)'
```

## Критическое API

```text
stereoRectify
initUndistortRectifyMap
remap
StereoSGBM_create
rotate
imread
imwrite
```

## Риск

OpenCV version change может менять:

* numerical result;
* disparity behavior;
* accepted argument types;
* codec availability;
* output image format.

---

# 15.5 Dense depth script dependencies

Файл:

```text
web/remote_station/scripts/dense_depth_from_synced_capture.py
```

Использует:

```text
Python standard library
cv2
numpy
```

## Inputs

```text
stereo_extrinsics.json
synced depth capture directory
```

## Outputs

```text
rectified images
disparity arrays
depth arrays
preview images
CSV
debug JSON
```

---

# 15.6 Open3D environment

## Path

```text
$STATION_BASE/open3d-venv
```

## Python

```text
Python 3.12
```

## Open3D version

```text
0.19.0
```

## Installation

```bash
python3.12 -m venv "$STATION_BASE/open3d-venv"
pip install open3d==0.19.0
```

## Additional dependency

```text
numpy
```

устанавливается как dependency Open3D либо должна быть доступна в environment.

## Назначение

* point cloud reading;
* outlier removal;
* normals;
* Poisson mesh;
* mesh cleanup;
* decimation;
* PLY output.

## Runtime script

```text
process_open3d_mesh.py
```

## Проверка

```bash
$STATION_BASE/open3d-venv/bin/python -c \
'import open3d; print(open3d.__version__)'
```

---

# 15.7 Python dependency isolation

Используются два environment:

```text
$STATION_BASE/venv
$STATION_BASE/open3d-venv
```

## Причина

Open3D может иметь отдельные требования к Python и binary wheels.

## Правило

Скрипт должен запускаться правильным interpreter.

Нельзя полагаться на:

```text
/usr/bin/python
python из shell PATH
случайно активированный venv
```

---

# 16. Podman

## Тип

```text
PROCESSING
RUNTIME
OPTIONAL
CRITICAL для container mode
```

## Назначение

* COLMAP container;
* GPU-enabled processing;
* воспроизводимый processing environment;
* isolation.

## Проверка

```bash
podman version
podman info
podman images
```

## GPU requirements

* NVIDIA driver;
* NVIDIA Container Toolkit/CDI или equivalent Podman integration;
* GPU device visibility;
* compatible container image.

## Проверка GPU container

Команда зависит от station configuration и image.

Результат должен подтверждать доступ к NVIDIA GPU из container.

## Риски

* rootless mount permissions;
* SELinux labels;
* container image tag изменился;
* CUDA mismatch;
* GPU недоступна;
* output owned by wrong UID;
* volume path mismatch.

---

# 17. NVIDIA GPU dependencies

# 17.1 Hardware

## Фактическая GPU

```text
NVIDIA GeForce RTX 3080
VRAM 10 GB
```

## Использование

* COLMAP GPU feature extraction/matching;
* dense reconstruction;
* containerized CUDA processing;
* потенциальные future ML operations.

---

# 17.2 NVIDIA driver

## Тип

```text
RUNTIME
CRITICAL
```

## Проверка

```bash
nvidia-smi
```

## Необходимо фиксировать

```text
driver version
reported CUDA compatibility
GPU model
VRAM
temperature
power limit
```

## Риск

Driver update может нарушить:

* Podman GPU access;
* COLMAP CUDA;
* container compatibility;
* long-running jobs.

---

# 17.3 CUDA runtime

CUDA toolkit на host не всегда обязателен, если processing выполняется в container.

Следует различать:

```text
driver CUDA compatibility
host CUDA toolkit
container CUDA runtime
COLMAP build CUDA support
```

Фраза `CUDA Version` в `nvidia-smi` не подтверждает установленный host toolkit.

---

# 18. Shell and Unix utilities

Remote и server scripts зависят от:

```text
bash
ssh
scp
nohup
mkdir
rm
cp
mv
test
find
grep
sed
awk
tar
gzip
sha256sum
coreutils
```

Фактический набор необходимо подтвердить audit скриптов.

## Bash requirement

Скрипты используют:

```text
set -euo pipefail
arrays
mapfile
[[ ... ]]
process substitution
```

Поэтому `/bin/sh` не является гарантированно совместимой заменой Bash.

---

# 19. Archive dependencies

# 19.1 Android bundle packaging

Используется создание:

```text
.tgz
```

## Требования

* TAR;
* GZIP;
* сохранение relative paths;
* binary-safe copying;
* отсутствие перекодирования JPG;
* atomic finalization.

Конкретная Android implementation может использовать Java/Kotlin archive library или собственный writer.

Её необходимо отразить после отдельного аудита `CaptureBundlePackager.kt`.

---

# 19.2 Server/GrafikStation extraction

Необходимы:

```text
tar
gzip support
safe extraction validation
```

## Security

Перед extraction проверить:

* отсутствие absolute paths;
* отсутствие `../`;
* отсутствие symlink escape;
* maximum unpacked size;
* expected file count;
* allowed file types.

---

# 20. Web frontend dependencies

# 20.1 Smarty templates

Описаны в разделе backend.

# 20.2 JavaScript assets

В репозитории находятся vendored frontend assets, включая TinyMCE и viewer-related JavaScript.

Полный frontend dependency inventory ещё не выполнен.

## Необходимо определить

```text
library
version
source
license
active usage
duplicate copies
security status
```

## Известный риск

В репозитории существуют дублированные asset paths вида:

```text
web/www/assets/vendor/
web/www/assets/assets/vendor/
```

Это может означать:

* duplicate copy;
* старый deployment artifact;
* ошибочную вложенность;
* active legacy path.

Нельзя удалять дубликат без проверки web references.

---

# 21. Network dependencies

# 21.1 Android → Insta360

```text
HTTP
192.168.42.1
camera Wi-Fi
```

## Security model

Локальный cleartext transport в изолированной camera network.

## Availability

Camera network может не иметь internet access.

---

# 21.2 Android → backend

```text
HTTP в текущей конфигурации
```

## Требование

Для production должен использоваться HTTPS.

## Dependencies

* DNS;
* internet/mobile route;
* TLS после migration;
* backend availability;
* upload timeout;
* network switching.

---

# 21.3 Backend → GrafikStation

```text
SSH
SCP
```

## Dependencies

* route;
* firewall;
* DNS/IP;
* SSH service;
* key;
* station user;
* storage.

---

# 21.4 Backend → MySQL

```text
mysqli connection
```

## Dependencies

* DB host;
* port;
* credentials;
* charset;
* database schema;
* connection timeout.

---

# 22. Dependency configuration ownership

| Конфигурация             | Владелец               |
| ------------------------ | ---------------------- |
| Android library versions | Gradle files           |
| Android SDK/JVM          | `app/build.gradle.kts` |
| Gradle version           | wrapper properties     |
| Android backend URL      | BuildConfig            |
| Insta360 URL             | BuildConfig            |
| Android ABI              | Gradle defaultConfig   |
| Native library links     | Android CMake          |
| `sfm_tool` dependencies  | server CMake           |
| PHP runtime              | server deployment      |
| MySQL connection         | server config          |
| Server storage root      | server config/code     |
| Station host/key/base    | `stations.conf`        |
| COLMAP mode/image        | `stations.conf`        |
| Python packages          | station venv           |
| Open3D version           | deployment script      |
| NVIDIA driver            | GrafikStation OS       |
| Container image          | station configuration  |

---

# 23. Hardcoded dependency paths

В коде и scripts встречаются абсолютные пути:

```text
/usr/local/bin/colmap
/home/makler/web/tools/sfm_cpp/build/bin/sfm_tool
/home/makler/web/storage/orders
/home/makler/web/configs/connectDB.php
```

## Риск

* перенос deployment ломает worker;
* test environment требует тех же paths;
* разные серверы расходятся;
* LLM может изменить один path, забыв остальные.

## Целевое состояние

```text
central configuration
environment validation
startup diagnostics
no duplicated hardcoded roots
```

## Migration rule

Сначала добавить config fallback, затем перевести consumers, после этого удалить старые hardcoded defaults.

---

# 24. Dependency version matrix

## Android

| Dependency            |    Version |
| --------------------- | ---------: |
| Gradle Wrapper        |       8.13 |
| Android Gradle Plugin |      8.5.2 |
| Kotlin                |     2.0.21 |
| Java target           |         17 |
| compile SDK           |         34 |
| target SDK            |         34 |
| min SDK               |         26 |
| Core KTX              |     1.13.1 |
| Lifecycle Runtime     |      2.8.6 |
| Lifecycle ViewModel   |      2.8.7 |
| Activity Compose      |      1.9.2 |
| Compose BOM           | 2024.09.00 |
| Material 3            |      1.3.0 |
| Navigation Compose    |      2.8.2 |
| Google Material       |     1.12.0 |
| Room                  |      2.6.1 |
| Coil Compose          |      2.7.0 |
| OpenCV Android        |      4.9.0 |
| OkHttp                |     4.12.0 |
| CameraX               |      1.3.4 |
| JUnit                 |     4.13.2 |
| AndroidX JUnit        |      1.2.1 |
| Espresso              |      3.6.1 |
| Native ABI            |  arm64-v8a |
| Android CMake minimum |     3.22.1 |

## Backend and processing

| Dependency           | Version/status                            |
| -------------------- | ----------------------------------------- |
| PHP                  | runtime version not pinned                |
| MySQL/MariaDB        | runtime version not pinned                |
| Smarty               | 5.3.1 vendored                            |
| ffmpeg               | runtime version not pinned                |
| COLMAP               | runtime/container version not pinned      |
| server CMake minimum | 3.16                                      |
| C++ standard         | 17                                        |
| OpenCV server        | `opencv4`, version not pinned             |
| AprilTag             | version not pinned                        |
| nlohmann_json        | version not pinned                        |
| Open3D               | 0.19.0                                    |
| Python station       | system `python3`; Open3D uses Python 3.12 |
| NumPy                | version not pinned                        |
| OpenCV Python        | version not pinned                        |
| Podman               | runtime version not pinned                |
| NVIDIA driver        | runtime inventory                         |
| container image      | station config, must be inventoried       |

---

# 25. Known version-management problems

## DEP-DEBT-001

Часть Android versions находится в version catalog, часть hardcoded в `app/build.gradle.kts`.

## DEP-DEBT-002

Lifecycle dependencies используют разные patch versions.

## DEP-DEBT-003

NDK version не закреплена.

## DEP-DEBT-004

Server PHP version не закреплена.

## DEP-DEBT-005

MySQL/MariaDB version не закреплена в deployment manifest.

## DEP-DEBT-006

ffmpeg и COLMAP versions не закреплены.

## DEP-DEBT-007

Main Python venv устанавливает latest compatible:

```text
numpy
opencv-python-headless
```

без lock.

## DEP-DEBT-008

Open3D закреплён, но связанный Python dependency graph не сохранён lock-файлом.

## DEP-DEBT-009

Container image может задаваться конфигурацией без immutable digest.

## DEP-DEBT-010

Vendored frontend libraries не имеют единого inventory.

## DEP-DEBT-011

PHP third-party dependencies не управляются единым manifest.

## DEP-DEBT-012

Абсолютные server paths дублируются в коде.

## DEP-DEBT-013

Release Android build использует mock camera provider.

## DEP-DEBT-014

Backend URL использует HTTP.

---

# 26. Security-sensitive dependencies

Следующие зависимости требуют отдельного security контроля:

```text
OkHttp/backend transport
Bearer token storage
PHP runtime
Smarty
MySQL/MariaDB
web server
SSH
SCP
Podman
container images
OpenCV image parsers
ffmpeg media parsers
archive extraction
public viewer JavaScript
TinyMCE
```

## Правила

* security update не смешивается с feature refactoring;
* перед update создаётся baseline;
* проверяется upstream advisory;
* выполняется build;
* выполняется runtime smoke test;
* rollback должен быть подготовлен;
* public-facing components имеют повышенный приоритет.

---

# 27. License dependencies

Необходимо вести license inventory для:

```text
AndroidX
Kotlin
Compose
Room
CameraX
OpenCV
OkHttp
Coil
Smarty
COLMAP
AprilTag
nlohmann_json
Open3D
NumPy
ffmpeg
TinyMCE
container images
```

## Требования

Для каждой зависимости:

```text
name
version
license
source
usage
distribution impact
required notices
```

Особенно важно проверить лицензии при распространении APK, server software или коммерческого продукта.

---

# 28. Dependency failure matrix

| Dependency          | Симптом отказа              | Затронутый flow              |
| ------------------- | --------------------------- | ---------------------------- |
| Insta360 Wi-Fi      | камера offline              | photo/video Insta360         |
| CameraX             | preview/recording fail      | phone video, cam0            |
| USB UVC             | cam1 отсутствует            | stereo                       |
| Room                | local state unavailable     | все Android sessions/uploads |
| OkHttp              | network error               | camera/backend/upload        |
| Backend             | API unavailable             | auth/orders/upload           |
| MySQL               | DB error                    | весь backend                 |
| Server storage      | write error                 | uploads/artifacts            |
| ffmpeg              | no frames                   | SfM                          |
| COLMAP              | no sparse model             | SfM                          |
| AprilTag            | no marker detection         | metric scale                 |
| sfm_tool            | missing specialized outputs | scale/trajectory             |
| SSH                 | station unreachable         | remote processing            |
| SCP                 | input/result transfer fail  | remote processing            |
| Podman              | container job fail          | container COLMAP             |
| NVIDIA driver       | GPU unavailable             | GPU processing               |
| NumPy/OpenCV Python | dense script fail           | synced dense                 |
| Open3D              | mesh fail                   | mesh stage                   |
| disk space          | partial/corrupt outputs     | capture/upload/processing    |

---

# 29. Startup and preflight checks

# 29.1 Android build preflight

```bash
cd app/MaklerTour

java -version
./gradlew --version
./gradlew :app:assembleDebug
```

# 29.2 Native Android preflight

```bash
./gradlew :app:externalNativeBuildDebug
```

Проверить наличие:

```text
libcam1_uvc.so
```

# 29.3 Backend preflight

```bash
php -v
php -m
mysql --version
df -h
```

# 29.4 `sfm_tool` preflight

```bash
cmake --version
pkg-config --modversion opencv4
pkg-config --modversion apriltag

cmake -S web/tools/sfm_cpp -B /tmp/insta3d-sfm-build
cmake --build /tmp/insta3d-sfm-build
/tmp/insta3d-sfm-build/bin/sfm_tool --help
```

# 29.5 GrafikStation preflight

```bash
nvidia-smi
podman version
python3 --version
ffmpeg -version
colmap -h
```

# 29.6 Python environments

```bash
"$STATION_BASE/venv/bin/python" -c \
'import cv2, numpy; print(cv2.__version__, numpy.__version__)'

"$STATION_BASE/open3d-venv/bin/python" -c \
'import open3d; print(open3d.__version__)'
```

---

# 30. Dependency upgrade procedure

Перед обновлением любой критической зависимости:

## Шаг 1 — определить потребителей

```text
dependency
→ direct imports
→ indirect consumers
→ runtime flows
→ contracts
```

## Шаг 2 — записать baseline

```text
current version
build result
test result
runtime result
sample artifacts
performance metrics
```

## Шаг 3 — проверить release notes

Не полагаться только на номер версии.

Проверить:

* breaking changes;
* removed APIs;
* migration;
* security;
* platform requirements.

## Шаг 4 — обновить одну группу

Не обновлять одновременно:

```text
Kotlin + Room + CameraX
OpenCV + calibration logic
COLMAP + processing parameters
PHP + MySQL + Smarty
NVIDIA driver + container image + COLMAP
```

## Шаг 5 — выполнить проверки

```text
build
unit tests
integration tests
runtime smoke
artifact comparison
performance
```

## Шаг 6 — записать результат

Обновить:

```text
06_DEPENDENCIES.md
07_BUILD_AND_TEST.md
decision record
runtime inventory
```

---

# 31. Dependency pinning strategy

## Android

Использовать:

```text
Gradle wrapper
version catalog
explicit plugin versions
explicit Maven versions
Compose BOM
```

Цель:

```text
все versions в libs.versions.toml,
кроме обоснованных BOM-managed dependencies
```

## Python

Использовать:

```text
requirements.txt
constraints.txt
или lock tool
```

Сохранять отдельно для:

```text
main station venv
Open3D venv
```

## Container

Использовать:

```text
immutable image digest
```

вместо плавающего `latest`.

## PHP

Зафиксировать:

```text
PHP version
extensions
Smarty version
other vendored libraries
```

## System tools

Зафиксировать inventory:

```text
ffmpeg
COLMAP
OpenCV development
AprilTag
CMake
compiler
MySQL/MariaDB
NVIDIA driver
Podman
```

---

# 32. Dependency boundaries for LLM

LLM не должна:

* придумывать установленную version;
* считать наличие source import доказательством runtime availability;
* обновлять dependency без запроса;
* менять version в одном файле, не проверив остальные sources;
* заменять library собственной реализацией без архитектурного решения;
* удалять vendored dependency как «лишнюю» без usage audit;
* считать system CUDA установленной только по `nvidia-smi`;
* считать package установленным только потому, что deployment script умеет его устанавливать.

LLM должна разделять:

```text
declared dependency
installed dependency
loaded dependency
working dependency
```

---

# 33. Контекст для dependency-задачи

Для Android dependency:

```text
app/build.gradle.kts
libs.versions.toml
settings.gradle.kts
gradle-wrapper.properties
affected imports
build logs
tests
```

Для native dependency:

```text
CMakeLists.txt
source includes
ABI
NDK version
linker output
runtime logcat
```

Для server dependency:

```text
worker source
deployment config
system version
service logs
sample job
```

Для Python dependency:

```text
script imports
venv path
pip freeze
requirements
sample input/output
```

Для COLMAP/container dependency:

```text
station config
runner
container image
driver
COLMAP version
job log
result.json
```

---

# 34. Required runtime inventory

Рекомендуемый отдельный machine-readable файл, не содержащий secrets:

```text
docs/runtime/runtime_inventory.json
```

Пример структуры:

```json
{
  "generated_at": "2026-07-15T00:00:00Z",
  "android_build": {
    "gradle": "8.13",
    "agp": "8.5.2",
    "kotlin": "2.0.21",
    "compile_sdk": 34,
    "ndk": null,
    "cmake": null
  },
  "web_server": {
    "php": null,
    "mysql": null,
    "smarty": "5.3.1",
    "ffmpeg": null,
    "colmap": null
  },
  "grafikstation": {
    "os": null,
    "python": null,
    "numpy": null,
    "opencv_python": null,
    "open3d": "0.19.0",
    "podman": null,
    "nvidia_driver": null,
    "gpu": "NVIDIA GeForce RTX 3080"
  }
}
```

`null` означает, что версия должна быть получена фактической командой.

---

# 35. Known dependency questions

Требуют дальнейшей проверки:

* production PHP version;
* active PHP extensions;
* MySQL или MariaDB exact version;
* production web server;
* exact NDK version;
* exact Android CMake package version;
* actual USB UVC native dependency model;
* OpenCV server version;
* AprilTag version;
* ffmpeg build and codecs;
* COLMAP version;
* COLMAP native/container mode в production;
* container image and digest;
* Python main venv versions;
* NumPy version;
* OpenCV Python version;
* Podman version;
* NVIDIA container integration;
* full frontend vendor inventory;
* TinyMCE version and active usage;
* generated/template cache policy;
* license notices;
* production TLS;
* CI dependency environment;
* automated vulnerability scanning.

---

# 36. Critical dependency invariants

1. Android build использует Java 17.
2. Native APK рассчитан на `arm64-v8a`.
3. Calibration зависит от OpenCV.
4. Phone camera и cam0 зависят от CameraX.
5. Insta360 flow зависит от camera Wi-Fi и OSC.
6. Stereo flow зависит от USB host и cam1 UVC.
7. Local Android state зависит от Room.
8. Upload зависит от OkHttp, backend и server storage.
9. Backend business state зависит от MySQL/MariaDB.
10. Video frame extraction зависит от ffmpeg.
11. Sparse reconstruction зависит от COLMAP.
12. Marker processing зависит от AprilTag.
13. `sfm_tool` зависит от OpenCV 4, AprilTag и nlohmann_json.
14. Remote processing зависит от SSH/SCP.
15. GrafikStation GPU processing зависит от NVIDIA driver.
16. Dense depth зависит от NumPy и OpenCV Python.
17. Open3D mesh зависит от Open3D 0.19.0 environment.
18. Viewer зависит от подготовленных artifacts, а не raw processing tools.
19. Dependency update не должна менять contract незаметно.
20. Runtime version должна подтверждаться командой, а не предположением.

---

# 37. Краткое резюме

```text
Android dependencies
задаются Gradle и Maven.

Native Android dependency
собирается NDK/CMake как libcam1_uvc.so.

Backend dependencies
находятся в PHP runtime, MySQL и vendored Smarty.

Server SfM
зависит от ffmpeg, COLMAP и sfm_tool.

sfm_tool
зависит от OpenCV 4, AprilTag и nlohmann_json.

GrafikStation
зависит от SSH, Python environments, Podman и NVIDIA GPU.

Critical versions
должны быть закреплены и проверяемы.

Declared dependency
не равна working runtime dependency.
```
