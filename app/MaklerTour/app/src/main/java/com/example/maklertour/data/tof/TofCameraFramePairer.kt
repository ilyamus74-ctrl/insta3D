package com.maklertour.data.tof

import kotlin.math.abs

/**
 * Reusable CAMERA_A <-> ToF event-time pairing accepted by LM03.3.2B.
 *
 * CAMERA_A time must already be mapped into Android elapsed-realtime.
 * Raw ToF timestamps are mapped with the current active RP2040 clock fit.
 */
data class TofCameraFramePair(
    val frame: TofFrameV1,
    val mappedElapsedRealtimeNs: Long,
    val signedDeltaNs: Long,
    val absDeltaNs: Long,
    val thresholdUs: Long,
) {
    val sequence: Long
        get() = frame.sequence

    val signedDeltaUs: Long
        get() = signedDeltaNs / 1_000L

    val absDeltaUs: Long
        get() = absDeltaNs / 1_000L

    val accepted: Boolean
        get() = absDeltaUs <= thresholdUs
}

object TofCameraFramePairer {
    const val PAIRING_MARGIN_US = 2_000L

    fun nearestForSlot(
        cameraElapsedRealtimeNs: Long,
        frames: List<TofFrameV1>,
        tofSlot: Int,
        mapper: (Long) -> Long? = {
            TofActiveClockSync.mapRp2040TimestampUsToHostElapsedNs(it)
        },
    ): TofCameraFramePair? =
        nearest(
            cameraElapsedRealtimeNs = cameraElapsedRealtimeNs,
            frames = frames.filter { it.slot == tofSlot },
            mapper = mapper,
        )

    fun nearest(
        cameraElapsedRealtimeNs: Long,
        frames: List<TofFrameV1>,
        mapper: (Long) -> Long? = {
            TofActiveClockSync.mapRp2040TimestampUsToHostElapsedNs(it)
        },
    ): TofCameraFramePair? {
        var bestFrame: TofFrameV1? = null
        var bestMappedNs = 0L
        var bestSignedDeltaNs = 0L
        var bestAbsDeltaNs = Long.MAX_VALUE

        for (frame in frames) {
            if (!frame.irqTimestampValid || frame.frequencyHz <= 0) continue
            val mappedNs = mapper(frame.rp2040TimestampUs) ?: continue
            val signedDeltaNs = mappedNs - cameraElapsedRealtimeNs
            val absDeltaNs = abs(signedDeltaNs)
            if (absDeltaNs < bestAbsDeltaNs) {
                bestFrame = frame
                bestMappedNs = mappedNs
                bestSignedDeltaNs = signedDeltaNs
                bestAbsDeltaNs = absDeltaNs
            }
        }

        val frame = bestFrame ?: return null
        return TofCameraFramePair(
            frame = frame,
            mappedElapsedRealtimeNs = bestMappedNs,
            signedDeltaNs = bestSignedDeltaNs,
            absDeltaNs = bestAbsDeltaNs,
            thresholdUs = thresholdUs(frame.frequencyHz),
        )
    }

    fun thresholdUs(frequencyHz: Int): Long {
        require(frequencyHz > 0) { "frequencyHz must be > 0" }
        return 500_000L / frequencyHz + PAIRING_MARGIN_US
    }
}
