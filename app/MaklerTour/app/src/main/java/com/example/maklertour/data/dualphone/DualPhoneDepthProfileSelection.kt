package com.example.maklertour.data.dualphone

import android.os.SystemClock
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlin.math.abs
import kotlin.math.max
import kotlin.math.min

enum class DualPhoneDepthProfileMode(
    val shortLabel: String,
    val requestedProfileName: String?,
) {
    AUTO("AUTO", null),
    MANUAL_ULTRA_960("U960", "ULTRA_960"),
    MANUAL_HIGH_640("H640", "HIGH_640"),
    MANUAL_QUALITY_480("Q480", "QUALITY_480"),
    MANUAL_BALANCED_320("B320", "BALANCED_320"),
}

/**
 * Process-local operator selection for LM02.7A.2.
 *
 * AUTO keeps the adaptive controller authoritative. Manual selections disable
 * timing-based downgrade but never bypass Android thermal floors.
 */
object DualPhoneDepthProfileSelection {
    private val mutableState = MutableStateFlow(DualPhoneDepthProfileMode.AUTO)
    private var suppressOverlayUntilElapsedMs: Long = 0L

    val state: StateFlow<DualPhoneDepthProfileMode> = mutableState.asStateFlow()

    fun current(): DualPhoneDepthProfileMode = mutableState.value

    fun select(mode: DualPhoneDepthProfileMode) {
        if (mutableState.value == mode) return
        mutableState.value = mode
        suppressOverlayUntilElapsedMs =
            SystemClock.elapsedRealtime() + OVERLAY_TRANSITION_HOLD_MS
    }

    fun overlayMatchesActiveMap(
        activeProfileName: String,
        workWidth: Int,
        workHeight: Int,
        thermalState: String,
    ): Boolean {
        if (SystemClock.elapsedRealtime() < suppressOverlayUntilElapsedMs) return false
        if (workWidth <= 0 || workHeight <= 0) return false

        val selected = current()
        val requested = selected.requestedProfileName
        val thermalOverride =
            thermalState == DualPhoneDepthThermalState.WARM.name ||
                thermalState == DualPhoneDepthThermalState.HOT.name ||
                thermalState == DualPhoneDepthThermalState.CRITICAL.name
        if (
            selected != DualPhoneDepthProfileMode.AUTO &&
            requested != activeProfileName &&
            !thermalOverride
        ) {
            return false
        }

        val expected = expectedWorkEnvelope(activeProfileName) ?: return false
        val actualLong = max(workWidth, workHeight)
        val actualShort = min(workWidth, workHeight)
        return abs(actualLong - expected.first) <= DIMENSION_TOLERANCE_PX &&
            abs(actualShort - expected.second) <= DIMENSION_TOLERANCE_PX
    }

    private fun expectedWorkEnvelope(profileName: String): Pair<Int, Int>? = when (
        profileName
    ) {
        "ULTRA_960" -> 960 to 540
        "HIGH_640" -> 640 to 360
        "QUALITY_480" -> 480 to 270
        "BALANCED_320",
        "THROTTLED_320" -> 320 to 180
        else -> null
    }

    private const val OVERLAY_TRANSITION_HOLD_MS = 1_200L
    private const val DIMENSION_TOLERANCE_PX = 6
}
