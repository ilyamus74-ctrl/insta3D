#include "tof_sync_v1.h"

#include <stddef.h>

#include "pico/stdlib.h"
#include "pico/stdio.h"

#define TOF_SYNC_V1_BYTES 32U

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

void tof_sync_v1_write(uint32_t nonce, uint64_t rx_timestamp_us) {
    uint8_t packet[TOF_SYNC_V1_BYTES];
    size_t off = 0;

    packet[off++] = 'T';
    packet[off++] = 'S';
    packet[off++] = 'Y';
    packet[off++] = '1';
    packet[off++] = 1U;
    packet[off++] = 0U;
    packet[off++] = 0U;
    packet[off++] = 0U;

    put_u32_le(packet, &off, nonce);
    put_u64_le(packet, &off, rx_timestamp_us);

    const uint64_t tx_timestamp_us = time_us_64();
    put_u64_le(packet, &off, tx_timestamp_us);

    const uint32_t crc = crc32_ieee(packet, off);
    put_u32_le(packet, &off, crc);

    (void)stdio_put_string((const char *)packet, (int)off, false, false);
}
