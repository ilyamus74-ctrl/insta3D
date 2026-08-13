#ifndef TOF_SYNC_V1_H
#define TOF_SYNC_V1_H

#include <stdint.h>

void tof_sync_v1_write(uint32_t nonce, uint64_t rx_timestamp_us);

#endif
