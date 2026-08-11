#include <stdio.h>
#include <string.h>
#include <stdlib.h>
#include <stdbool.h>
#include <stdint.h>

#include "pico/stdlib.h"
#include "hardware/i2c.h"

#include "vl53l8cx_api.h"
#include "vl53l8cx_pico_platform.h"

#define SENSOR_MAX 3
#define I2C_SDA_PIN 4
#define I2C_SCL_PIN 5
#define I2C_BAUD_HZ 400000

static const uint LP_PIN[SENSOR_MAX]  = {2, 6, 8};
static const uint INT_PIN[SENSOR_MAX] = {3, 7, 9};

/* ST 8-bit I2C notation. 7-bit values are 0x2A/0x2B/0x2C. */
static const uint16_t TARGET_ADDR8[SENSOR_MAX] = {0x54, 0x56, 0x58};

typedef struct {
    bool present;
    bool initialized;
    bool running;
    uint8_t slot;
    uint8_t resolution; /* 16 or 64 */
    uint8_t hz;
    uint32_t sequence;
    uint64_t last_irq_us;
    uint64_t last_frame_us;
    VL53L8CX_Configuration cfg;
    VL53L8CX_ResultsData results;
} tof_slot_t;

static tof_slot_t sensors[SENSOR_MAX];
static int print_slot = 0;
static uint64_t next_print_us = 0;

static int slot_from_int_pin(uint gpio) {
    for (int i = 0; i < SENSOR_MAX; ++i) {
        if (INT_PIN[i] == gpio) return i;
    }
    return -1;
}

static void gpio_irq(uint gpio, uint32_t events) {
    if (!(events & GPIO_IRQ_EDGE_FALL)) return;
    int slot = slot_from_int_pin(gpio);
    if (slot >= 0) sensors[slot].last_irq_us = time_us_64();
}

static void init_gpio(void) {
    i2c_init(i2c0, I2C_BAUD_HZ);
    gpio_set_function(I2C_SDA_PIN, GPIO_FUNC_I2C);
    gpio_set_function(I2C_SCL_PIN, GPIO_FUNC_I2C);

    /* Weak internal pulls are only backup; breakout should provide proper I2C pulls. */
    gpio_pull_up(I2C_SDA_PIN);
    gpio_pull_up(I2C_SCL_PIN);

    for (int i = 0; i < SENSOR_MAX; ++i) {
        gpio_init(LP_PIN[i]);
        gpio_set_dir(LP_PIN[i], GPIO_OUT);
        gpio_put(LP_PIN[i], 0);

        gpio_init(INT_PIN[i]);
        gpio_set_dir(INT_PIN[i], GPIO_IN);
        gpio_pull_up(INT_PIN[i]);
    }

    gpio_set_irq_enabled_with_callback(
        INT_PIN[0], GPIO_IRQ_EDGE_FALL, true, &gpio_irq);
    gpio_set_irq_enabled(INT_PIN[1], GPIO_IRQ_EDGE_FALL, true);
    gpio_set_irq_enabled(INT_PIN[2], GPIO_IRQ_EDGE_FALL, true);
}

static bool probe_default_and_assign(tof_slot_t *s) {
    pico_vl53_bind(&s->cfg);

    gpio_put(LP_PIN[s->slot], 1);
    sleep_ms(20);

    uint8_t alive = 0;
    uint8_t st = vl53l8cx_is_alive(&s->cfg, &alive);
    if (st != 0 || alive == 0) {
        gpio_put(LP_PIN[s->slot], 0);
        printf("SENSOR %u absent at default 0x29 (st=%u alive=%u)\n",
               s->slot, st, alive);
        return false;
    }

    st = vl53l8cx_set_i2c_address(&s->cfg, TARGET_ADDR8[s->slot]);
    if (st != 0) {
        gpio_put(LP_PIN[s->slot], 0);
        printf("SENSOR %u address change failed st=%u\n", s->slot, st);
        return false;
    }

    uint8_t recheck = 0;
    st = vl53l8cx_is_alive(&s->cfg, &recheck);
    if (st != 0 || recheck == 0) {
        gpio_put(LP_PIN[s->slot], 0);
        printf("SENSOR %u missing after address change st=%u alive=%u\n",
               s->slot, st, recheck);
        return false;
    }

    s->present = true;
    printf("SENSOR %u found -> ST addr 0x%02X / 7-bit 0x%02X\n",
           s->slot, TARGET_ADDR8[s->slot], TARGET_ADDR8[s->slot] >> 1);
    return true;
}

static uint8_t apply_mode(tof_slot_t *s, int res, int hz, bool start_after) {
    if (!s->initialized) return 255;

    if (res != 4 && res != 8) return 127;
    if (hz < 1) return 127;
    if (res == 8 && hz > 15) return 127;
    if (res == 4 && hz > 60) return 127;

    uint8_t status = 0;
    if (s->running) {
        status |= vl53l8cx_stop_ranging(&s->cfg);
        s->running = false;
    }

    const uint8_t resolution =
        (res == 8) ? VL53L8CX_RESOLUTION_8X8 : VL53L8CX_RESOLUTION_4X4;

    status |= vl53l8cx_set_resolution(&s->cfg, resolution);
    status |= vl53l8cx_set_ranging_frequency_hz(&s->cfg, (uint8_t)hz);
    status |= vl53l8cx_set_ranging_mode(
        &s->cfg, VL53L8CX_RANGING_MODE_CONTINUOUS);

    if (status == 0 && start_after) {
        status |= vl53l8cx_start_ranging(&s->cfg);
        if (status == 0) s->running = true;
    }

    if (status == 0) {
        s->resolution = resolution;
        s->hz = (uint8_t)hz;
    }
    return status;
}

static bool init_sensor(tof_slot_t *s) {
    if (!s->present) return false;

    printf("SENSOR %u uploading VL53L8CX firmware...\n", s->slot);
    uint8_t st = vl53l8cx_init(&s->cfg);
    if (st != 0) {
        printf("SENSOR %u init FAILED st=%u\n", s->slot, st);
        return false;
    }

    s->initialized = true;
    st = apply_mode(s, 8, 15, false);
    if (st != 0) {
        printf("SENSOR %u configure FAILED st=%u\n", s->slot, st);
        s->initialized = false;
        return false;
    }

    printf("SENSOR %u READY 8x8@15Hz (not started)\n", s->slot);
    return true;
}

static void start_slot(int i) {
    if (i < 0 || i >= SENSOR_MAX || !sensors[i].initialized) return;
    if (sensors[i].running) return;
    uint8_t st = vl53l8cx_start_ranging(&sensors[i].cfg);
    if (st == 0) {
        sensors[i].running = true;
        printf("SENSOR %d START\n", i);
    } else {
        printf("SENSOR %d start failed st=%u\n", i, st);
    }
}

static void stop_slot(int i) {
    if (i < 0 || i >= SENSOR_MAX || !sensors[i].initialized) return;
    if (!sensors[i].running) return;
    uint8_t st = vl53l8cx_stop_ranging(&sensors[i].cfg);
    sensors[i].running = false;
    printf("SENSOR %d STOP st=%u\n", i, st);
}

static bool status_good(uint8_t st) {
    return st == 5 || st == 6 || st == 9;
}

static void print_matrix(const tof_slot_t *s) {
    const int side = (s->resolution == VL53L8CX_RESOLUTION_8X8) ? 8 : 4;
    printf("\nTOF slot=%u seq=%lu ts_us=%llu mode=%dx%d@%uHz temp=%dC irq_us=%llu\n",
           s->slot,
           (unsigned long)s->sequence,
           (unsigned long long)s->last_frame_us,
           side, side, s->hz,
           (int)s->results.silicon_temp_degc,
           (unsigned long long)s->last_irq_us);

    for (int y = 0; y < side; ++y) {
        for (int x = 0; x < side; ++x) {
            const int z = y * side + x;
            const uint8_t stat = s->results.target_status[z];
            const int16_t mm = s->results.distance_mm[z];
            if (s->results.nb_target_detected[z] == 0 || !status_good(stat) || mm <= 0) {
                printf(" ----");
            } else {
                printf(" %4d", (int)mm);
            }
        }
        printf("\n");
    }
}

static void poll_sensor(tof_slot_t *s) {
    if (!s->running) return;

    uint8_t ready = 0;
    uint8_t st = vl53l8cx_check_data_ready(&s->cfg, &ready);
    if (st != 0 || ready == 0) return;

    st = vl53l8cx_get_ranging_data(&s->cfg, &s->results);
    if (st != 0) {
        printf("SENSOR %u read error st=%u\n", s->slot, st);
        return;
    }

    s->sequence++;
    s->last_frame_us = s->last_irq_us ? s->last_irq_us : time_us_64();

    const uint64_t now = time_us_64();
    if (print_slot == s->slot && now >= next_print_us) {
        print_matrix(s);
        next_print_us = now + 500000ULL;
    }
}

static void print_list(void) {
    printf("slot present init running addr7 mode seq\n");
    for (int i = 0; i < SENSOR_MAX; ++i) {
        tof_slot_t *s = &sensors[i];
        printf("%d    %d      %d    %d       0x%02X %ux%u@%u %lu\n",
               i, s->present, s->initialized, s->running,
               (unsigned)(TARGET_ADDR8[i] >> 1),
               s->resolution == 64 ? 8 : 4,
               s->resolution == 64 ? 8 : 4,
               s->hz,
               (unsigned long)s->sequence);
    }
}

static void help(void) {
    printf(
        "commands:\n"
        "  help\n"
        "  list\n"
        "  print 0|1|2|off\n"
        "  start 0|1|2|all\n"
        "  stop 0|1|2|all\n"
        "  mode SLOT 8 HZ    (8x8 max 15Hz)\n"
        "  mode SLOT 4 HZ    (4x4 max 60Hz)\n"
    );
}

static void handle_line(char *line) {
    while (*line == ' ' || *line == '\t') ++line;
    if (*line == '\0') return;

    if (strcmp(line, "help") == 0) {
        help();
        return;
    }
    if (strcmp(line, "list") == 0) {
        print_list();
        return;
    }
    if (strncmp(line, "print ", 6) == 0) {
        char *arg = line + 6;
        if (strcmp(arg, "off") == 0) {
            print_slot = -1;
        } else {
            int n = atoi(arg);
            if (n >= 0 && n < SENSOR_MAX) print_slot = n;
        }
        return;
    }
    if (strncmp(line, "start ", 6) == 0) {
        char *arg = line + 6;
        if (strcmp(arg, "all") == 0) {
            for (int i = 0; i < SENSOR_MAX; ++i) start_slot(i);
        } else {
            start_slot(atoi(arg));
        }
        return;
    }
    if (strncmp(line, "stop ", 5) == 0) {
        char *arg = line + 5;
        if (strcmp(arg, "all") == 0) {
            for (int i = 0; i < SENSOR_MAX; ++i) stop_slot(i);
        } else {
            stop_slot(atoi(arg));
        }
        return;
    }

    int slot = -1, res = 0, hz = 0;
    if (sscanf(line, "mode %d %d %d", &slot, &res, &hz) == 3) {
        if (slot < 0 || slot >= SENSOR_MAX || !sensors[slot].initialized) {
            printf("bad slot\n");
            return;
        }
        const bool was_running = sensors[slot].running;
        uint8_t st = apply_mode(&sensors[slot], res, hz, was_running);
        printf("mode slot=%d %dx%d@%d st=%u running=%d\n",
               slot, res, res, hz, st, sensors[slot].running);
        return;
    }

    printf("unknown command: %s\n", line);
}

static void usb_console_poll(void) {
    static char line[96];
    static size_t pos = 0;

    int ch;
    while ((ch = getchar_timeout_us(0)) != PICO_ERROR_TIMEOUT) {
        if (ch == '\r') continue;
        if (ch == '\n') {
            line[pos] = '\0';
            handle_line(line);
            pos = 0;
            continue;
        }
        if (pos + 1 < sizeof(line)) line[pos++] = (char)ch;
    }
}

int main(void) {
    stdio_init_all();
    sleep_ms(1500);

    printf("\nRP2040-Zero VL53L8CX 3x bring-up\n");
    printf("I2C0 SDA=GP4 SCL=GP5 @ %u Hz\n", I2C_BAUD_HZ);

    memset(sensors, 0, sizeof(sensors));
    for (int i = 0; i < SENSOR_MAX; ++i) sensors[i].slot = (uint8_t)i;

    init_gpio();
    sleep_ms(30);

    /* All LPn low. Raise exactly one default-address device at a time,
       assign a unique address, and leave already-addressed devices enabled. */
    for (int i = 0; i < SENSOR_MAX; ++i) {
        probe_default_and_assign(&sensors[i]);
    }

    int initialized_count = 0;
    for (int i = 0; i < SENSOR_MAX; ++i) {
        if (init_sensor(&sensors[i])) initialized_count++;
    }

    printf("initialized sensors: %d\n", initialized_count);
    print_list();
    help();

    /* Safe first run: only slot 0 starts automatically. */
    if (sensors[0].initialized) start_slot(0);
    else {
        for (int i = 1; i < SENSOR_MAX; ++i) {
            if (sensors[i].initialized) {
                start_slot(i);
                print_slot = i;
                break;
            }
        }
    }

    while (true) {
        usb_console_poll();
        for (int i = 0; i < SENSOR_MAX; ++i) poll_sensor(&sensors[i]);
        tight_loop_contents();
    }
}
