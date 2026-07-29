package com.maklertour.data.dualphone

import kotlin.math.abs
import kotlin.math.max
import kotlin.math.roundToLong

enum class DualPhoneClockSyncQuality {
    UNSYNCED,
    SYNCING,
    EXCELLENT,
    GOOD,
    FAIR,
    POOR,
    ERROR;

    val isReady: Boolean
        get() = this == EXCELLENT || this == GOOD
}

data class DualPhoneClockSyncSnapshot(
    val quality: DualPhoneClockSyncQuality = DualPhoneClockSyncQuality.UNSYNCED,
    val ready: Boolean = false,
    val offsetNs: Long? = null,
    val medianRttNs: Long? = null,
    val p95RttNs: Long? = null,
    val uncertaintyNs: Long? = null,
    val driftPpm: Double? = null,
    val acceptedSamples: Int = 0,
    val totalSamples: Int = 0,
    val updatedAtElapsedNs: Long? = null,
    val message: String = "Clock sync not started",
    val referenceMasterNs: Long? = null,
)

val DualPhoneClockSyncSnapshot.captureSchedulingAllowed: Boolean
    get() {
        if (ready) return true
        val medianRtt = medianRttNs ?: return false
        val uncertainty = uncertaintyNs ?: return false
        val drift = driftPpm ?: return false
        return quality == DualPhoneClockSyncQuality.FAIR &&
            acceptedSamples >= 6 &&
            medianRtt <= 20_000_000L &&
            uncertainty <= 8_000_000L &&
            abs(drift) <= 200.0
    }

data class DualPhoneClockSyncSample(
    val t1MasterNs: Long,
    val t2SlaveNs: Long,
    val t3SlaveNs: Long,
    val t4MasterNs: Long,
) {
    val roundTripNs: Long
        get() = ((t4MasterNs - t1MasterNs) -
            (t3SlaveNs - t2SlaveNs)).coerceAtLeast(0L)

    val offsetNs: Long
        get() = ((t2SlaveNs - t1MasterNs) +
            (t3SlaveNs - t4MasterNs)) / 2L

    val masterMidpointNs: Long
        get() = t1MasterNs + (t4MasterNs - t1MasterNs) / 2L
}

data class DualPhoneClockSyncRound(
    val referenceMasterNs: Long,
    val offsetNs: Long,
    val medianRttNs: Long,
    val p95RttNs: Long,
    val uncertaintyNs: Long,
    val acceptedSamples: Int,
    val totalSamples: Int,
    val validSamples: Int,
    val acceptedRttNs: List<Long>,
    val rejectedRttNs: List<Long>,
    val acceptedOffsetNs: List<Long>,
)

data class DualPhoneClockSyncModel(
    val referenceMasterNs: Long,
    val offsetAtReferenceNs: Long,
    val driftPpm: Double,
    val medianRttNs: Long,
    val p95RttNs: Long,
    val uncertaintyNs: Long,
    val acceptedSamples: Int,
    val totalSamples: Int,
    val quality: DualPhoneClockSyncQuality,
) {
    fun predictedOffsetNs(masterElapsedNs: Long): Long {
        val elapsedNs = masterElapsedNs - referenceMasterNs
        val driftNs = elapsedNs.toDouble() * driftPpm / 1_000_000.0
        return offsetAtReferenceNs + driftNs.roundToLong()
    }

    fun masterToSlaveNs(masterElapsedNs: Long): Long =
        masterElapsedNs + predictedOffsetNs(masterElapsedNs)

    fun toSnapshot(
        updatedAtElapsedNs: Long,
        message: String,
    ): DualPhoneClockSyncSnapshot = DualPhoneClockSyncSnapshot(
        quality = quality,
        ready = quality.isReady,
        referenceMasterNs = referenceMasterNs,
        offsetNs = offsetAtReferenceNs,
        medianRttNs = medianRttNs,
        p95RttNs = p95RttNs,
        uncertaintyNs = uncertaintyNs,
        driftPpm = driftPpm,
        acceptedSamples = acceptedSamples,
        totalSamples = totalSamples,
        updatedAtElapsedNs = updatedAtElapsedNs,
        message = message,
    )
}

data class DualPhoneClockSyncStabilityDecision(
    val model: DualPhoneClockSyncModel,
    val consecutiveNonReadyRounds: Int,
    val retainedReadyQuality: Boolean,
)

object DualPhoneClockSyncMath {
    const val REQUIRED_CONSECUTIVE_NON_READY_ROUNDS = 3

    private const val MAX_VALID_RTT_NS = 100_000_000L
    private const val MAX_HISTORY_ROUNDS = 12
    private const val MIN_DRIFT_SPAN_NS = 5_000_000_000L

    fun estimateRound(
        samples: List<DualPhoneClockSyncSample>,
        totalProbes: Int = samples.size,
    ): DualPhoneClockSyncRound? {
        val valid = samples
            .filter {
                it.t4MasterNs >= it.t1MasterNs &&
                    it.t3SlaveNs >= it.t2SlaveNs &&
                    it.roundTripNs in 0..MAX_VALID_RTT_NS
            }
            .sortedBy { it.roundTripNs }
        if (valid.size < 3) return null

        val acceptedCount = minOf(
            8,
            maxOf(3, (valid.size + 1) / 2),
        ).coerceAtMost(valid.size)
        val accepted = valid.take(acceptedCount)
        val rejected = valid.drop(acceptedCount)
        val offsets = accepted.map { it.offsetNs }.sorted()
        val rtts = accepted.map { it.roundTripNs }.sorted()
        val midpoints = accepted.map { it.masterMidpointNs }.sorted()
        val offset = median(offsets)
        val mad = median(offsets.map { abs(it - offset) }.sorted())
        val robustOffsetSpread = (mad.toDouble() * 1.4826).roundToLong()
        val medianRtt = median(rtts)
        val uncertainty = max(
            medianRtt / 2L,
            robustOffsetSpread,
        ).coerceAtLeast(100_000L)

        return DualPhoneClockSyncRound(
            referenceMasterNs = median(midpoints),
            offsetNs = offset,
            medianRttNs = medianRtt,
            p95RttNs = percentile(rtts, 0.95),
            uncertaintyNs = uncertainty,
            acceptedSamples = accepted.size,
            totalSamples = totalProbes,
            validSamples = valid.size,
            acceptedRttNs = accepted.map { it.roundTripNs },
            rejectedRttNs = rejected.map { it.roundTripNs },
            acceptedOffsetNs = accepted.map { it.offsetNs },
        )
    }

    fun buildModel(
        rounds: List<DualPhoneClockSyncRound>,
    ): DualPhoneClockSyncModel? {
        if (rounds.isEmpty()) return null
        val history = rounds.takeLast(MAX_HISTORY_ROUNDS)
        val latest = history.last()
        val driftPpm = estimateDriftPpm(history, latest)
        val quality = classifyQuality(latest, driftPpm)
        return DualPhoneClockSyncModel(
            referenceMasterNs = latest.referenceMasterNs,
            offsetAtReferenceNs = latest.offsetNs,
            driftPpm = driftPpm,
            medianRttNs = latest.medianRttNs,
            p95RttNs = latest.p95RttNs,
            uncertaintyNs = latest.uncertaintyNs,
            acceptedSamples = latest.acceptedSamples,
            totalSamples = latest.totalSamples,
            quality = quality,
        )
    }

    fun stabilizeModel(
        previous: DualPhoneClockSyncModel?,
        candidate: DualPhoneClockSyncModel,
        consecutiveNonReadyRounds: Int,
    ): DualPhoneClockSyncStabilityDecision {
        if (candidate.quality.isReady) {
            return DualPhoneClockSyncStabilityDecision(
                model = candidate,
                consecutiveNonReadyRounds = 0,
                retainedReadyQuality = false,
            )
        }

        if (previous?.quality?.isReady == true) {
            val nextCount = consecutiveNonReadyRounds.coerceAtLeast(0) + 1
            if (nextCount < REQUIRED_CONSECUTIVE_NON_READY_ROUNDS) {
                return DualPhoneClockSyncStabilityDecision(
                    model = candidate.copy(quality = previous.quality),
                    consecutiveNonReadyRounds = nextCount,
                    retainedReadyQuality = true,
                )
            }
        }

        return DualPhoneClockSyncStabilityDecision(
            model = candidate,
            consecutiveNonReadyRounds = 0,
            retainedReadyQuality = false,
        )
    }

    private fun estimateDriftPpm(
        history: List<DualPhoneClockSyncRound>,
        latest: DualPhoneClockSyncRound,
    ): Double {
        if (history.size < 2) return 0.0
        val spanNs = latest.referenceMasterNs - history.first().referenceMasterNs
        if (spanNs < MIN_DRIFT_SPAN_NS) return 0.0

        var xx = 0.0
        var xy = 0.0
        history.forEach { round ->
            val x = (round.referenceMasterNs - latest.referenceMasterNs).toDouble()
            val y = (round.offsetNs - latest.offsetNs).toDouble()
            xx += x * x
            xy += x * y
        }
        if (xx <= 0.0) return 0.0
        return (xy / xx * 1_000_000.0).coerceIn(-500.0, 500.0)
    }

    private fun classifyQuality(
        round: DualPhoneClockSyncRound,
        driftPpm: Double,
    ): DualPhoneClockSyncQuality = when {
        round.acceptedSamples >= 6 &&
            round.medianRttNs <= 3_000_000L &&
            round.uncertaintyNs <= 1_500_000L &&
            abs(driftPpm) <= 100.0 -> DualPhoneClockSyncQuality.EXCELLENT

        round.acceptedSamples >= 5 &&
            round.medianRttNs <= 8_000_000L &&
            round.uncertaintyNs <= 4_000_000L &&
            abs(driftPpm) <= 150.0 -> DualPhoneClockSyncQuality.GOOD

        round.acceptedSamples >= 3 &&
            round.medianRttNs <= 20_000_000L &&
            round.uncertaintyNs <= 10_000_000L -> DualPhoneClockSyncQuality.FAIR

        else -> DualPhoneClockSyncQuality.POOR
    }

    private fun median(values: List<Long>): Long {
        require(values.isNotEmpty())
        val middle = values.size / 2
        return if (values.size % 2 == 1) {
            values[middle]
        } else {
            values[middle - 1] + (values[middle] - values[middle - 1]) / 2L
        }
    }

    private fun percentile(values: List<Long>, percentile: Double): Long {
        require(values.isNotEmpty())
        val index = ((values.size - 1) * percentile)
            .roundToLong()
            .toInt()
            .coerceIn(0, values.lastIndex)
        return values[index]
    }
}
