#ifndef TOF_FRAME_V1_H
#define TOF_FRAME_V1_H

#include <stdbool.h>
#include <stdint.h>

#include "vl53l8cx_api.h"

void tof_frame_v1_write(
    uint8_t slot,
    uint8_t resolution,
    uint8_t frequency_hz,
    uint32_t sequence,
    uint64_t timestamp_us,
    bool irq_timestamp_valid,
    const VL53L8CX_ResultsData *results);

#endif
