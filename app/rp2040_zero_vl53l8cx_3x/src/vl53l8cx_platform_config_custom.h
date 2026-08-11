#pragma once

#define VL53L8CX_NB_TARGET_PER_ZONE 1U

/* Keep only data useful for first metric rig. */
#define VL53L8CX_DISABLE_AMBIENT_PER_SPAD
#define VL53L8CX_DISABLE_NB_SPADS_ENABLED
#define VL53L8CX_DISABLE_SIGNAL_PER_SPAD
#define VL53L8CX_DISABLE_REFLECTANCE_PERCENT
#define VL53L8CX_DISABLE_MOTION_INDICATOR

/* Kept:
 * - nb_target_detected
 * - range_sigma_mm
 * - distance_mm
 * - target_status
 */
