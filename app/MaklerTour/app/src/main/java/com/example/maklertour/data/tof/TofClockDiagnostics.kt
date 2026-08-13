package com.maklertour.data.tof

import kotlin.math.roundToLong

/**
 * LM03.3.0 process-wide clock/USB-arrival diagnostics.
 *
 * Kept separate from the USB transport so this measurement step cannot change
 * framing, permission handling or CDC I/O behavior.
 */
object TofClockDiagnostics {
    private val mapper = TofClockMapper()

    @Synchronized
    fun observe(frame: TofFrameV1): TofClockState =
        mapper.add(
            rp2040TimestampUs = frame.rp2040TimestampUs,
            hostReceivedElapsedRealtimeNs = frame.hostReceivedElapsedRealtimeNs,
        )

    fun logSuffix(clock: TofClockState): String {
        val driftPpm = clock.driftPpm?.roundToLong()
        val rmsUs = clock.arrivalResidualRmsUs?.roundToLong()
        val p95Us = clock.arrivalResidualP95Us?.roundToLong()
        val lastUs = clock.lastArrivalResidualUs?.roundToLong()

        return "clock=${clock.phase} clockN=${clock.sampleCount} " +
            "clockSpanMs=${clock.windowSpanMs} driftPpm=${driftPpm ?: "-"} " +
            "clockRmsUs=${rmsUs ?: "-"} clockP95Us=${p95Us ?: "-"} " +
            "clockLastUs=${lastUs ?: "-"} clockGen=${clock.generation}"
    }
}
