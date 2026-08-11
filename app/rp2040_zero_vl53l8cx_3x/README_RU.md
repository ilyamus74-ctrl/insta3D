# RP2040-Zero + до 3× VL53L8CX — bring-up firmware

Назначение:
- RP2040-Zero (Waveshare)
- общий I2C0 на GP4/GP5
- до 3 VL53L8CX на одном I2C
- отдельный LPn и INT на каждый датчик
- автоматическое обнаружение 1..3 датчиков
- переназначение I2C адресов через LPn
- 8x8 @ 15 Hz по умолчанию
- команды USB CDC для 4x4/8x8 и 1..60 Hz
- вывод матрицы расстояний через USB serial

## Пайка

Общая шина:
RP2040-Zero 3V3 -> VIN всех ToF
RP2040-Zero GND -> GND всех ToF
RP2040-Zero GP4 -> SDA/MOSI всех ToF
RP2040-Zero GP5 -> SCL/MCLK всех ToF

ToF #0:
GP2 -> LPn
GP3 <- INT

ToF #1:
GP6 -> LPn
GP7 <- INT

ToF #2:
GP8 -> LPn
GP9 <- INT

На каждом ToF для I2C:
- MISO: NC
- SPI_I2C_N: GND
- NCS: GND

На первом запуске подключить только один ToF.

## Адреса

ST ULD использует 8-bit notation:
- slot0: 0x54
- slot1: 0x56
- slot2: 0x58

Соответствующие 7-bit I2C адреса RP2040:
- 0x2A
- 0x2B
- 0x2C

Дефолт VL53L8CX при включении:
- ST notation 0x52
- 7-bit 0x29

## Сборка

Нужны:
- cmake
- GCC ARM Embedded
- git
- Raspberry Pi pico-sdk 2.3.0

Самый простой запуск:

    ./build_uf2.sh

Скрипт сам скачает:
- pico-sdk 2.3.0
- stm32duino/VL53L8CX pinned commit

Выход:
    build/tof_rig.uf2

## Загрузка UF2

1. Зажать BOOT на RP2040-Zero.
2. Нажать RESET и отпустить.
3. Отпустить BOOT.
4. Появится USB mass-storage RPI-RP2.
5. Скопировать build/tof_rig.uf2.

## USB console

После запуска появится USB CDC serial.

Команды:

    help
    list
    print 0
    print 1
    print 2
    print off

    start 0
    stop 0
    start all
    stop all

    mode 0 8 15
    mode 0 4 60

`mode SLOT RES HZ`
- RES = 8 или 4
- для 8x8 допустимо 1..15 Hz
- для 4x4 допустимо 1..60 Hz

По умолчанию firmware запускает только первый обнаруженный датчик.
Остальные инициализируются, но остаются остановленными, чтобы первый тест
не ловил взаимные 940-nm помехи.

## Примечание про 3 датчика

VL53L8CX поддерживает multi-sensor I2C через отдельные LPn и смену адреса.
Если три ToF смотрят в одну и ту же область, возможна оптическая интерференция.
На этой красной breakout-плате SYNC наружу не выведен, поэтому финальный режим
для 3 датчиков нужно выбирать после теста физической ориентации:
- разные направления/FoV: можно запускать одновременно;
- сильное перекрытие FoV: лучше временное мультиплексирование.

## Источники

- ST UM3109, VL53L8CX ULD user manual
- ST VL53L8CX ULD API via stm32duino/VL53L8CX
- Raspberry Pi Pico SDK
- Waveshare RP2040-Zero
