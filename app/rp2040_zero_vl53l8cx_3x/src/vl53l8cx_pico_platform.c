#include "vl53l8cx_pico_platform.h"

#include <string.h>
#include "pico/stdlib.h"
#include "hardware/i2c.h"

#define I2C_PORT i2c0
#define I2C_CHUNK 240U

static uint8_t addr7_from_cfg(VL53L8CX_Configuration *cfg) {
    return (uint8_t)((cfg->platform.address >> 1) & 0x7fU);
}

void pico_vl53_bind(VL53L8CX_Configuration *cfg) {
    memset(cfg, 0, sizeof(*cfg));
    cfg->platform.address = VL53L8CX_DEFAULT_I2C_ADDRESS;
    cfg->platform.Write = pico_vl53_write;
    cfg->platform.Read = pico_vl53_read;
    cfg->platform.Wait = pico_vl53_wait;
    cfg->platform.handle = cfg;
}

uint8_t pico_vl53_write(void *handle, uint16_t reg, uint8_t *values, uint32_t size) {
    VL53L8CX_Configuration *cfg = (VL53L8CX_Configuration *)handle;
    const uint8_t addr7 = addr7_from_cfg(cfg);
    uint32_t offset = 0;
    uint8_t tx[I2C_CHUNK + 2U];

    while (offset < size) {
        uint32_t chunk = size - offset;
        if (chunk > I2C_CHUNK) chunk = I2C_CHUNK;

        const uint16_t current_reg = (uint16_t)(reg + offset);
        tx[0] = (uint8_t)(current_reg >> 8);
        tx[1] = (uint8_t)(current_reg & 0xffU);
        memcpy(&tx[2], values + offset, chunk);

        const int expected = (int)chunk + 2;
        const int written = i2c_write_blocking(
            I2C_PORT, addr7, tx, (size_t)expected, false);
        if (written != expected) return VL53L8CX_STATUS_ERROR;

        offset += chunk;
    }
    return VL53L8CX_STATUS_OK;
}

uint8_t pico_vl53_read(void *handle, uint16_t reg, uint8_t *values, uint32_t size) {
    VL53L8CX_Configuration *cfg = (VL53L8CX_Configuration *)handle;
    const uint8_t addr7 = addr7_from_cfg(cfg);
    uint32_t offset = 0;

    while (offset < size) {
        uint32_t chunk = size - offset;
        if (chunk > I2C_CHUNK) chunk = I2C_CHUNK;

        const uint16_t current_reg = (uint16_t)(reg + offset);
        uint8_t regbuf[2] = {
            (uint8_t)(current_reg >> 8),
            (uint8_t)(current_reg & 0xffU),
        };

        if (i2c_write_blocking(I2C_PORT, addr7, regbuf, 2, true) != 2) {
            return VL53L8CX_STATUS_ERROR;
        }
        if (i2c_read_blocking(
                I2C_PORT, addr7, values + offset, chunk, false) != (int)chunk) {
            return VL53L8CX_STATUS_ERROR;
        }
        offset += chunk;
    }
    return VL53L8CX_STATUS_OK;
}

uint8_t pico_vl53_wait(void *handle, uint32_t ms) {
    (void)handle;
    sleep_ms(ms);
    return VL53L8CX_STATUS_OK;
}
