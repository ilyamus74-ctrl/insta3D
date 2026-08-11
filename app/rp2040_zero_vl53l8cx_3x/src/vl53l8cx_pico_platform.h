#pragma once

#include <stdint.h>
#include "vl53l8cx_api.h"

uint8_t pico_vl53_write(void *handle, uint16_t reg, uint8_t *values, uint32_t size);
uint8_t pico_vl53_read(void *handle, uint16_t reg, uint8_t *values, uint32_t size);
uint8_t pico_vl53_wait(void *handle, uint32_t ms);

void pico_vl53_bind(VL53L8CX_Configuration *cfg);
