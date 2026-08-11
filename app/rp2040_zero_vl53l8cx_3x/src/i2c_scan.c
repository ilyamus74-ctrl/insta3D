#include <stdio.h>
#include <stdbool.h>
#include <stdint.h>

#include "pico/stdlib.h"
#include "hardware/i2c.h"

#define I2C_PORT i2c0
#define SDA_PIN 4
#define SCL_PIN 5

#define LP0_PIN 2
#define INT0_PIN 3
#define LP1_PIN 6
#define LP2_PIN 8

static bool reserved_addr(uint8_t addr) {
    return ((addr & 0x78u) == 0u) || ((addr & 0x78u) == 0x78u);
}

static void scan_bus(void) {
    printf("\nI2C scan: SDA=%d SCL=%d LP0=%d INT0=%d\n",
           gpio_get(SDA_PIN),
           gpio_get(SCL_PIN),
           gpio_get(LP0_PIN),
           gpio_get(INT0_PIN));

    printf("    0  1  2  3  4  5  6  7  8  9  A  B  C  D  E  F\n");

    int found = 0;
    for (int addr = 0; addr < 128; ++addr) {
        if ((addr & 0x0f) == 0) {
            printf("%02X ", addr);
        }

        int ret = PICO_ERROR_GENERIC;
        uint8_t rx = 0;

        if (!reserved_addr((uint8_t)addr)) {
            ret = i2c_read_timeout_us(
                I2C_PORT,
                (uint8_t)addr,
                &rx,
                1,
                false,
                3000
            );
        }

        if (ret >= 0) {
            printf("@  ");
            printf("\nACK address: 0x%02X (%d)\n", addr, addr);
            found++;
        } else {
            printf(".  ");
        }

        if ((addr & 0x0f) == 0x0f) {
            printf("\n");
        }
    }

    if (found == 0) {
        printf("RESULT: NO I2C DEVICES FOUND\n");
    } else {
        printf("RESULT: %d I2C device(s) found\n", found);
    }
}

int main(void) {
    stdio_init_all();
    sleep_ms(1800);

    printf("\nRP2040-Zero VL53L8CX RAW I2C SCANNER\n");
    printf("I2C0 SDA=GP4 SCL=GP5 @100kHz\n");

    gpio_init(LP0_PIN);
    gpio_set_dir(LP0_PIN, GPIO_OUT);
    gpio_put(LP0_PIN, 1);

    gpio_init(LP1_PIN);
    gpio_set_dir(LP1_PIN, GPIO_OUT);
    gpio_put(LP1_PIN, 0);

    gpio_init(LP2_PIN);
    gpio_set_dir(LP2_PIN, GPIO_OUT);
    gpio_put(LP2_PIN, 0);

    gpio_init(INT0_PIN);
    gpio_set_dir(INT0_PIN, GPIO_IN);

    i2c_init(I2C_PORT, 100000);
    gpio_set_function(SDA_PIN, GPIO_FUNC_I2C);
