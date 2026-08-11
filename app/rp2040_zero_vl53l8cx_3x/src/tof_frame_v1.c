#include "tof_frame_v1.h"

#include <stddef.h>

#include "pico/stdio.h"

#define TOF_V1_MAX_ZONES 64U
#define TOF_V1_HEADER_BYTES 28U
#define TOF_V1_BYTES_PER_ZONE 6U
#define TOF_V1_CRC_BYTES 4U
#define TOF_V1_MAX_FRAME_BYTES \
    (TOF_V1_HEADER_BYTES + TOF_V1_MAX_ZONES * TOF_V1_BYTES_PER_ZONE + TOF_V1_CRC_BYTES)

static void put_u16_le(uint8_t *dst, size_t *off, uint16_t v) {
    dst[(*off)++] = (uint8_t)(v & 0xffU);
    dst[(*off)++] = (uint8_t)((v >> 8) & 0xffU);
}

static void put_u32_le(uint8_t *dst, size_t *off, uint32_t v) {
    for (unsigned i = 0; i < 4U; ++i) {
        dst[(*off)++] = (uint8_t)((v >> (8U * i)) & 0xffU);
    }
}

static void put_u64_le(uint8_t *dst, size_t *off, uint64_t v) {
    for (unsigned i = 0; i < 8U; ++i) {
        dst[(*off)++] = (uint8_t)((v >> (8U * i)) & 0xffU);
    }
}

static uint32_t crc32_ieee(const uint8_t *data, size_t len) {
    uint32_t crc = 0xffffffffU;
    for (size_t i = 0; i < len; ++i) {
        crc ^= data[i];
        for (unsigned b = 0; b < 8U; ++b) {
            const uint32_t mask = (uint32_t)-(int32_t)(crc & 1U);
            crc = (crc >> 1) ^ (0xedb88320U & mask);
        }
    }
    return ~crc;
}

void tof_frame_v1_write(
    uint8_t slot,
    uint8_t resolution,
    uint8_t frequency_hz,
    uint32_t sequence,
    uint64_t timestamp_us,
    bool irq_timestamp_valid,
    const VL53L8CX_ResultsData *results) {

    if (results == NULL) return;

    const uint8_t width =
        (resolution == VL53L8CX_RESOLUTION_8X8) ? 8U : 4U;
    const uint8_t zone_count = (uint8_t)(width * width);
    const uint16_t payload_bytes =
        (uint16_t)(zone_count * TOF_V1_BYTES_PER_ZONE);

    uint8_t frame[TOF_V1_MAX_FRAME_BYTES];
    size_t off = 0;

    frame[off++] = 'T';
    frame[off++] = 'O';
    frame[off++] = 'F';
    frame[off++] = '1';

    frame[off++] = 1U;
    frame[off++] = slot;
    frame[off++] = width;
    frame[off++] = width;
    frame[off++] = frequency_hz;
    frame[off++] = (uint8_t)results->silicon_temp_degc;
    frame[off++] = zone_count;
    frame[off++] = irq_timestamp_valid ? 0x01U : 0x00U;

    put_u32_le(frame, &off, sequence);
    put_u64_le(frame, &off, timestamp_us);
    put_u16_le(frame, &off, payload_bytes);
    put_u16_le(frame, &off, 0U);

    for (uint8_t z = 0; z < zone_count; ++z) {
        put_u16_le(frame, &off, (uint16_t)results->distance_mm[z]);
    }
    for (uint8_t z = 0; z < zone_count; ++z) {
        put_u16_le(frame, &off, results->range_sigma_mm[z]);
    }
    for (uint8_t z = 0; z < zone_count; ++z) {
        frame[off++] = results->target_status[z];
    }
    for (uint8_t z = 0; z < zone_count; ++z) {
        frame[off++] = results->nb_target_detected[z];
    }

    const uint32_t crc = crc32_ieee(frame, off);
    put_u32_le(frame, &off, crc);

    /*
     * Do not send binary frames through fwrite()/newlib stdout:
     * Pico newlib _write() enables CR/LF translation, which can turn a raw
     * 0x0A payload byte into 0x0D 0x0A and invalidate the frame CRC.
     * stdio_put_string(..., cr_translation=false) preserves bytes exactly.
     */
    (void)stdio_put_string((const char *)frame, (int)off, false, false);
}
