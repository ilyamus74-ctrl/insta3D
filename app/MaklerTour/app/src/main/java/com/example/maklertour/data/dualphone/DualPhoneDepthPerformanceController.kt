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
 * LM02.4.1 adaptive depth budget with warm-up exclusion and hysteresis.
 *
 * Media remains at 10 FPS. OpenCV/JIT warm-up samples do not trigger a permanent
 * downgrade. Sustained slow windows downgrade the profile, while a long stable
 * window may promote it again. Thermal status remains an immediate floor but is
 * not latched forever after the device cools.
 */
class DualPhoneDepthPerformanceController(context: Context) {
    private val powerManager =
        context.applicationContext.getSystemService(Context.POWER_SERVICE) as PowerManager
    private val processingDurationsMs = ArrayDeque<Long>()
    private var adaptiveLevel = 0
    private var warmupRemaining = INITIAL_WARMUP_SAMPLES
    private var slowWindows = 0
    private var fastWindows = 0
    private var observedSelectionMode = DualPhoneDepthProfileSelection.current()

    @Synchronized
    fun snapshot(): DualPhoneDepthPerformanceSnapshot {
        val selectionMode = syncSelectionMode()
        val thermal = thermalState()
        val thermalFloor = when (thermal) {
            DualPhoneDepthThermalState.UNSUPPORTED,
            DualPhoneDepthThermalState.NORMAL -> 0
            DualPhoneDepthThermalState.WARM -> 2
            DualPhoneDepthThermalState.HOT -> 4
            DualPhoneDepthThermalState.CRITICAL -> 5
        }
        val requestedLevel = when (selectionMode) {
            DualPhoneDepthProfileMode.AUTO -> adaptiveLevel
            DualPhoneDepthProfileMode.MANUAL_ULTRA_960 -> 0
            DualPhoneDepthProfileMode.MANUAL_HIGH_640 -> 1
            DualPhoneDepthProfileMode.MANUAL_QUALITY_480 -> 2
            DualPhoneDepthProfileMode.MANUAL_BALANCED_320 -> 3
        }
        val level = maxOf(thermalFloor, requestedLevel)
        return DualPhoneDepthPerformanceSnapshot(
            thermalState = thermal,
            profile = PROFILES[level],
            processingP50Ms = percentile(0.50),
            processingP95Ms = percentile(0.95),
        )
    }

    @Synchronized
    fun recordProcessing(durationMs: Long) {
        if (warmupRemaining > 0) {
            warmupRemaining -= 1
            return
        }
        processingDurationsMs.addLast(durationMs.coerceAtLeast(0L))
        while (processingDurationsMs.size > MAX_PROCESSING_SAMPLES) {
            processingDurationsMs.removeFirst()
        }
        val selectionMode = syncSelectionMode()
        if (selectionMode != DualPhoneDepthProfileMode.AUTO) return
        if (processingDurationsMs.size < MIN_SAMPLES_FOR_DECISION) return

        val p95 = percentile(0.95) ?: return
        val downgradeThreshold = when (adaptiveLevel) {
            0 -> ULTRA_MAX_P95_MS
            1 -> HIGH_RES_MAX_P95_MS
            2 -> QUALITY_MAX_P95_MS
            3 -> BALANCED_MAX_P95_MS
            else -> Long.MAX_VALUE
        }
        if (p95 > downgradeThreshold) {
            slowWindows += 1
        } else {
            slowWindows = 0
        }

        val upgradeThreshold = when (adaptiveLevel) {
            1 -> ULTRA_UPGRADE_P95_MS
            2 -> HIGH_RES_UPGRADE_P95_MS
            3 -> QUALITY_UPGRADE_P95_MS
            4 -> THROTTLED_UPGRADE_P95_MS
            else -> null
        }
        if (upgradeThreshold != null && p95 <= upgradeThreshold) {
            fastWindows += 1
        } else {
            fastWindows = 0
        }

        when {
            slowWindows >= DOWNGRADE_WINDOWS && adaptiveLevel < MAX_ADAPTIVE_LEVEL -> {
                adaptiveLevel += 1
                resetDecisionWindow(TRANSITION_WARMUP_SAMPLES)
            }
            fastWindows >= UPGRADE_WINDOWS && adaptiveLevel > 0 -> {
                adaptiveLevel -= 1
                resetDecisionWindow(TRANSITION_WARMUP_SAMPLES)
            }
        }
    }

    @Synchronized
    fun reset() {
        processingDurationsMs.clear()
        adaptiveLevel = 0
        warmupRemaining = INITIAL_WARMUP_SAMPLES
        slowWindows = 0
        fastWindows = 0
    }

    private fun resetDecisionWindow(warmupSamples: Int) {
        processingDurationsMs.clear()
        warmupRemaining = warmupSamples
        slowWindows = 0
        fastWindows = 0
    }

    private fun syncSelectionMode(): DualPhoneDepthProfileMode {
        val selected = DualPhoneDepthProfileSelection.current()
        if (selected != observedSelectionMode) {
            observedSelectionMode = selected
            adaptiveLevel = 0
            resetDecisionWindow(INITIAL_WARMUP_SAMPLES)
        }
        return selected
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
        private const val MIN_SAMPLES_FOR_DECISION = 12
        private const val INITIAL_WARMUP_SAMPLES = 12
        private const val TRANSITION_WARMUP_SAMPLES = 6
        private const val DOWNGRADE_WINDOWS = 3
        private const val UPGRADE_WINDOWS = 12
        private const val MAX_ADAPTIVE_LEVEL = 4
        private const val ULTRA_MAX_P95_MS = 340L
        private const val HIGH_RES_MAX_P95_MS = 190L
        private const val QUALITY_MAX_P95_MS = 150L
        private const val BALANCED_MAX_P95_MS = 175L
        private const val ULTRA_UPGRADE_P95_MS = 170L
        private const val HIGH_RES_UPGRADE_P95_MS = 95L
        private const val QUALITY_UPGRADE_P95_MS = 110L
        private const val THROTTLED_UPGRADE_P95_MS = 135L

        private val PROFILES = listOf(
            DualPhoneDepthPerformanceProfile(
                name = "ULTRA_960",
                workWidth = 960,
                workHeight = 540,
                minProcessingIntervalMs = 400L,
                enableLeftRightCheck = true,
            ),
            DualPhoneDepthPerformanceProfile(
                name = "HIGH_640",
                workWidth = 640,
                workHeight = 360,
                minProcessingIntervalMs = 250L,
                enableLeftRightCheck = true,
            ),
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
