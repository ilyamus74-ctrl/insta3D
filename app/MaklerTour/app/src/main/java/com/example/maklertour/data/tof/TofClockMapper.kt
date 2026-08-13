package com.maklertour.data.tof

import java.util.ArrayDeque
import kotlin.math.abs
import kotlin.math.roundToInt
import kotlin.math.roundToLong
import kotlin.math.sqrt

enum class TofClockModelPhase {
    EMPTY,
    WARMING_UP,
    ARRIVAL_MODEL_READY,
}

data class TofClockState(
    val generation: Long = 0,
    val phase: TofClockModelPhase = TofClockModelPhase.EMPTY,
    val sampleCount: Int = 0,
    val windowSpanMs: Long = 0,
    val driftPpm: Double? = null,
    val arrivalResidualRmsUs: Double? = null,
    val arrivalResidualP50Us: Double? = null,
    val arrivalResidualP95Us: Double? = null,
    val lastPredictedHostArrivalNs: Long? = null,
    val lastArrivalResidualUs: Double? = null,
)

/**
 * LM03.3.0 diagnostic fit:
 *
 * Android USB arrival ns ~= a * RP2040 event ns + b
 *
 * This characterizes relative clock rate and USB-arrival jitter only.
 * The fitted offset still contains unknown one-way USB transport latency,
 * therefore it is not a fusion-ready event-time mapping.
 */
class TofClockMapper(
    private val maxSamples: Int = DEFAULT_MAX_SAMPLES,
    private val minSamples: Int = DEFAULT_MIN_SAMPLES,
    private val minSpanNs: Long = DEFAULT_MIN_SPAN_NS,
) {
    private data class Sample(
        val rp2040Ns: Long,
        val hostArrivalNs: Long,
    )

    private val samples = ArrayDeque<Sample>()
    private var generation = 0L
    private var lastRp2040Ns: Long? = null
    private var lastHostArrivalNs: Long? = null

    @Synchronized
    fun reset(): TofClockState {
        resetInternal()
        return TofClockState(generation = generation)
    }

    @Synchronized
    fun add(
        rp2040TimestampUs: Long,
        hostReceivedElapsedRealtimeNs: Long,
    ): TofClockState {
        require(rp2040TimestampUs >= 0L)
        require(hostReceivedElapsedRealtimeNs > 0L)

        val rp2040Ns = rp2040TimestampUs * NS_PER_US
        val previousRp = lastRp2040Ns
        val previousHost = lastHostArrivalNs

        if (
            (previousRp != null && rp2040Ns < previousRp) ||
            (previousHost != null && hostReceivedElapsedRealtimeNs < previousHost)
        ) {
            resetInternal()
        }

        samples.addLast(
            Sample(
                rp2040Ns = rp2040Ns,
                hostArrivalNs = hostReceivedElapsedRealtimeNs,
            )
        )
        while (samples.size > maxSamples) {
            samples.removeFirst()
        }

        lastRp2040Ns = rp2040Ns
        lastHostArrivalNs = hostReceivedElapsedRealtimeNs

        return fit()
    }

    @Synchronized
    fun snapshot(): TofClockState = fit()

    private fun resetInternal() {
        samples.clear()
        lastRp2040Ns = null
        lastHostArrivalNs = null
        generation++
    }

    private fun fit(): TofClockState {
        if (samples.isEmpty()) {
            return TofClockState(
                generation = generation,
                phase = TofClockModelPhase.EMPTY,
            )
        }

        val first = samples.first
        val last = samples.last
        val spanNs = (last.rp2040Ns - first.rp2040Ns).coerceAtLeast(0L)

        val warming = TofClockState(
            generation = generation,
            phase = TofClockModelPhase.WARMING_UP,
            sampleCount = samples.size,
            windowSpanMs = spanNs / NS_PER_MS,
        )

        if (samples.size < minSamples || spanNs < minSpanNs) {
            return warming
        }

        val x0 = first.rp2040Ns
        val y0 = first.hostArrivalNs

        var sumX = 0.0
        var sumY = 0.0

        for (sample in samples) {
            sumX += (sample.rp2040Ns - x0).toDouble()
            sumY += (sample.hostArrivalNs - y0).toDouble()
        }

        val count = samples.size.toDouble()
        val meanX = sumX / count
        val meanY = sumY / count

        var covariance = 0.0
        var varianceX = 0.0

        for (sample in samples) {
            val x = (sample.rp2040Ns - x0).toDouble()
            val y = (sample.hostArrivalNs - y0).toDouble()
            val dx = x - meanX
            covariance += dx * (y - meanY)
            varianceX += dx * dx
        }

        if (varianceX <= 0.0) return warming

        val slope = covariance / varianceX
        if (!slope.isFinite()) return warming

        val interceptRelativeNs = meanY - slope * meanX
        val residualsNs = DoubleArray(samples.size)

        var residualSquareSum = 0.0
        var index = 0
        for (sample in samples) {
            val x = (sample.rp2040Ns - x0).toDouble()
            val y = (sample.hostArrivalNs - y0).toDouble()
            val predictedY = slope * x + interceptRelativeNs
            val residual = y - predictedY
            residualsNs[index++] = residual
            residualSquareSum += residual * residual
        }

        val absoluteResidualsUs =
            residualsNs
                .map { abs(it) / NS_PER_US.toDouble() }
                .sorted()

        val lastX = (last.rp2040Ns - x0).toDouble()
        val predictedLastHostNs =
            y0 + (slope * lastX + interceptRelativeNs).roundToLong()
        val lastResidualUs =
            (last.hostArrivalNs - predictedLastHostNs) / NS_PER_US.toDouble()

        return TofClockState(
            generation = generation,
            phase = TofClockModelPhase.ARRIVAL_MODEL_READY,
            sampleCount = samples.size,
            windowSpanMs = spanNs / NS_PER_MS,
            driftPpm = (slope - 1.0) * PPM_SCALE,
            arrivalResidualRmsUs =
                sqrt(residualSquareSum / samples.size.toDouble()) / NS_PER_US.toDouble(),
            arrivalResidualP50Us = percentile(absoluteResidualsUs, 0.50),
            arrivalResidualP95Us = percentile(absoluteResidualsUs, 0.95),
            lastPredictedHostArrivalNs = predictedLastHostNs,
            lastArrivalResidualUs = lastResidualUs,
        )
    }

    private fun percentile(sortedValues: List<Double>, fraction: Double): Double? {
        if (sortedValues.isEmpty()) return null
        val index = ((sortedValues.size - 1) * fraction)
            .roundToInt()
            .coerceIn(0, sortedValues.lastIndex)
        return sortedValues[index]
    }

    companion object {
        private const val NS_PER_US = 1_000L
        private const val NS_PER_MS = 1_000_000L
        private const val PPM_SCALE = 1_000_000.0

        const val DEFAULT_MAX_SAMPLES = 450
        const val DEFAULT_MIN_SAMPLES = 90
        const val DEFAULT_MIN_SPAN_NS = 5_000_000_000L
    }
}
