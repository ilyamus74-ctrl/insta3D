package com.maklertour.data.tof

data class TofFrameV1(
    val protocolVersion: Int,
    val slot: Int,
    val width: Int,
    val height: Int,
    val frequencyHz: Int,
    val siliconTemperatureC: Int,
    val sequence: Long,
    val rp2040TimestampUs: Long,
    val irqTimestampValid: Boolean,
    val distanceMm: IntArray,
    val rangeSigmaMm: IntArray,
    val targetStatus: IntArray,
    val nbTargetDetected: IntArray,
    val hostReceivedElapsedRealtimeNs: Long,
) {
    val zoneCount: Int
        get() = width * height

    fun isZoneValid(index: Int): Boolean {
        if (index !in 0 until zoneCount) return false
        return nbTargetDetected[index] > 0 &&
            targetStatus[index] in VALID_TARGET_STATUSES &&
            distanceMm[index] > 0
    }

    companion object {
        private val VALID_TARGET_STATUSES = setOf(5, 6, 9)
    }
}

data class TofSyncReplyV1(
    val protocolVersion: Int,
    val nonce: Long,
    val rp2040RxTimestampUs: Long,
    val rp2040TxTimestampUs: Long,
    val hostReceivedElapsedRealtimeNs: Long,
)

data class TofParseBatch(
    val frames: List<TofFrameV1>,
    val syncReplies: List<TofSyncReplyV1> = emptyList(),
    val crcErrors: Int,
    val malformedHeaders: Int,
)
