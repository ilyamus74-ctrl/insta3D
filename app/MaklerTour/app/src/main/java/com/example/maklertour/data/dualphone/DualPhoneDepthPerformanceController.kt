package com.example.maklertour.data.dualphone

import android.content.Context
import android.os.Build
import android.os.PowerManager
import java.util.ArrayDeque

enum class DualPhoneDepthThermalState {
    UNSUPPORTED,
    NORMAL,
    WARM,
    HOT,
    CRITICAL,
}

data class DualPhoneDepthPerformanceProfile(
    val name: String,
    val workWidth: Int,
    val workHeight: Int,
    val minProcessingIntervalMs: Long,
    val enableLeftRightCheck: Boolean,
    val paused: Boolean = false,
) {
    val targetDepthFps: Double
        get() = if (paused) 0.0 else 1_000.0 / minProcessingIntervalMs
}

data class DualPhoneDepthPerformanceSnapshot(
    val thermalState: DualPhoneDepthThermalState,
    val profile: DualPhoneDepthPerformanceProfile,
    val processingP50Ms: Long?,
    val processingP95Ms: Long?,
)

/**
 * LM02.4 adaptive depth budget.
 *
 * Media remains at 10 FPS. Depth receives a finite CPU budget that leaves margin
 * for Compose, CameraX and the future full-resolution texture recording path.
 * A stream may downgrade, but never upgrades again until reset, preventing thermal
 * or p95 oscillation during a scan.
 */
class DualPhoneDepthPerformanceController(context: Context) {
    private val powerManager =
        context.applicationContext.getSystemService(Context.POWER_SERVICE) as PowerManager
    private val processingDurationsMs = ArrayDeque<Long>()
    private var performanceFloor = 0
    private var thermalFloorLatch = 0

    @Synchronized
    fun snapshot(): DualPhoneDepthPerformanceSnapshot {
        val thermal = thermalState()
        val thermalFloor = when (thermal) {
            DualPhoneDepthThermalState.UNSUPPORTED,
            DualPhoneDepthThermalState.NORMAL -> 0
            DualPhoneDepthThermalState.WARM -> 1
            DualPhoneDepthThermalState.HOT -> 2
            DualPhoneDepthThermalState.CRITICAL -> 3
        }
        thermalFloorLatch = maxOf(thermalFloorLatch, thermalFloor)
        val level = maxOf(thermalFloorLatch, performanceFloor)
        return DualPhoneDepthPerformanceSnapshot(
            thermalState = thermal,
            profile = PROFILES[level],
            processingP50Ms = percentile(0.50),
            processingP95Ms = percentile(0.95),
        )
    }

    @Synchronized
    fun recordProcessing(durationMs: Long) {
        processingDurationsMs.addLast(durationMs.coerceAtLeast(0L))
        while (processingDurationsMs.size > MAX_PROCESSING_SAMPLES) {
            processingDurationsMs.removeFirst()
        }
        if (processingDurationsMs.size < MIN_SAMPLES_FOR_DOWNGRADE) return
        val p95 = percentile(0.95) ?: return
        when {
            performanceFloor == 0 && p95 > QUALITY_MAX_P95_MS -> {
                performanceFloor = 1
                processingDurationsMs.clear()
            }
            performanceFloor == 1 && p95 > BALANCED_MAX_P95_MS -> {
                performanceFloor = 2
                processingDurationsMs.clear()
            }
        }
    }

    @Synchronized
    fun reset() {
        processingDurationsMs.clear()
        performanceFloor = 0
        thermalFloorLatch = 0
    }

    private fun thermalState(): DualPhoneDepthThermalState {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.Q) {
            return DualPhoneDepthThermalState.UNSUPPORTED
        }
        return when (powerManager.currentThermalStatus) {
            PowerManager.THERMAL_STATUS_NONE,
            PowerManager.THERMAL_STATUS_LIGHT -> DualPhoneDepthThermalState.NORMAL
            PowerManager.THERMAL_STATUS_MODERATE -> DualPhoneDepthThermalState.WARM
            PowerManager.THERMAL_STATUS_SEVERE -> DualPhoneDepthThermalState.HOT
            else -> DualPhoneDepthThermalState.CRITICAL
        }
    }

    private fun percentile(fraction: Double): Long? {
        if (processingDurationsMs.isEmpty()) return null
        val values = processingDurationsMs.sorted()
        val index = ((values.size - 1) * fraction).toInt().coerceIn(values.indices)
        return values[index]
    }

    companion object {
        private const val MAX_PROCESSING_SAMPLES = 30
        private const val MIN_SAMPLES_FOR_DOWNGRADE = 8
        private const val QUALITY_MAX_P95_MS = 140L
        private const val BALANCED_MAX_P95_MS = 160L

        private val PROFILES = listOf(
            DualPhoneDepthPerformanceProfile(
                name = "QUALITY_480",
                workWidth = 480,
                workHeight = 270,
                minProcessingIntervalMs = 200L,
                enableLeftRightCheck = true,
            ),
            DualPhoneDepthPerformanceProfile(
                name = "BALANCED_320",
                workWidth = 320,
                workHeight = 240,
                minProcessingIntervalMs = 200L,
                enableLeftRightCheck = true,
            ),
            DualPhoneDepthPerformanceProfile(
                name = "THROTTLED_320",
                workWidth = 320,
                workHeight = 240,
                minProcessingIntervalMs = 333L,
                enableLeftRightCheck = false,
            ),
            DualPhoneDepthPerformanceProfile(
                name = "THERMAL_PAUSED",
                workWidth = 320,
                workHeight = 240,
                minProcessingIntervalMs = 1_000L,
                enableLeftRightCheck = false,
                paused = true,
            ),
        )
    }
}
