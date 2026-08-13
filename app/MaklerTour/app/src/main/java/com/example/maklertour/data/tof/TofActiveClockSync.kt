package com.maklertour.data.tof

import java.util.ArrayDeque
import kotlin.math.roundToInt
import kotlin.math.sqrt

enum class TofActiveSyncPhase { EMPTY, WARMING_UP, READY }

data class TofActiveSyncState(
    val phase: TofActiveSyncPhase = TofActiveSyncPhase.EMPTY,
    val sampleCount: Int = 0,
    val lastRttUs: Long? = null,
    val bestRttUs: Long? = null,
    val rttP50Us: Long? = null,
    val rttP95Us: Long? = null,
    val driftPpm: Double? = null,
    val modelRmsUs: Double? = null,
)

object TofActiveClockSync {
    private data class Pending(val hostSendNs: Long)
    private data class Sample(val rpMidNs: Long, val hostMidNs: Long, val rttNs: Long)

    private val pending = LinkedHashMap<Long, Pending>()
    private val samples = ArrayDeque<Sample>()
    private var nextNonce = 1L

    @Synchronized
    fun reset() {
        pending.clear()
        samples.clear()
    }

    @Synchronized
    fun beginRequest(hostSendNs: Long): Long {
        val nonce = nextNonce and 0xffff_ffffL
        nextNonce = (nextNonce + 1L) and 0xffff_ffffL
        if (nextNonce == 0L) nextNonce = 1L
        pending[nonce] = Pending(hostSendNs)
        return nonce
    }

    @Synchronized
    fun cancelRequest(nonce: Long) {
        pending.remove(nonce and 0xffff_ffffL)
    }

    @Synchronized
    fun observe(reply: TofSyncReplyV1): TofActiveSyncState? {
        val request = pending.remove(reply.nonce and 0xffff_ffffL) ?: return null
        val hostReceiveNs = reply.hostReceivedElapsedRealtimeNs
        if (hostReceiveNs <= request.hostSendNs) return null

        val rpRxNs = reply.rp2040RxTimestampUs * 1000L
        val rpTxNs = reply.rp2040TxTimestampUs * 1000L
        if (rpTxNs < rpRxNs) return null

        val deviceNs = rpTxNs - rpRxNs
        val wireRttNs = ((hostReceiveNs - request.hostSendNs) - deviceNs).coerceAtLeast(0L)
        val rpMidNs = rpRxNs + deviceNs / 2L
        val hostMidNs = request.hostSendNs + (hostReceiveNs - request.hostSendNs) / 2L

        samples.addLast(Sample(rpMidNs, hostMidNs, wireRttNs))
        while (samples.size > 120) samples.removeFirst()

        return fit()
    }

    private fun fit(): TofActiveSyncState {
        if (samples.isEmpty()) return TofActiveSyncState()

        val all = samples.toList()
        val rtts = all.map { it.rttNs }.sorted()
        val base = TofActiveSyncState(
            phase = TofActiveSyncPhase.WARMING_UP,
            sampleCount = all.size,
            lastRttUs = all.last().rttNs / 1000L,
            bestRttUs = rtts.first() / 1000L,
            rttP50Us = percentile(rtts, 0.50) / 1000L,
            rttP95Us = percentile(rtts, 0.95) / 1000L,
        )

        if (all.size < 10 || all.last().rpMidNs - all.first().rpMidNs < 8_000_000_000L) {
            return base
        }

        val selected = all.sortedBy { it.rttNs }
            .take(maxOf(10, (all.size + 1) / 2).coerceAtMost(all.size))
            .sortedBy { it.rpMidNs }

        val x0 = selected.first().rpMidNs
        val y0 = selected.first().hostMidNs
        val meanX = selected.map { (it.rpMidNs - x0).toDouble() }.average()
        val meanY = selected.map { (it.hostMidNs - y0).toDouble() }.average()

        var cov = 0.0
        var varX = 0.0
        for (s in selected) {
            val x = (s.rpMidNs - x0).toDouble()
            val y = (s.hostMidNs - y0).toDouble()
            cov += (x - meanX) * (y - meanY)
            varX += (x - meanX) * (x - meanX)
        }
        if (varX <= 0.0) return base

        val slope = cov / varX
        val intercept = meanY - slope * meanX
        var sumSq = 0.0
        for (s in selected) {
            val x = (s.rpMidNs - x0).toDouble()
            val y = (s.hostMidNs - y0).toDouble()
            val e = y - (slope * x + intercept)
            sumSq += e * e
        }

        return base.copy(
            phase = TofActiveSyncPhase.READY,
            driftPpm = (slope - 1.0) * 1_000_000.0,
            modelRmsUs = sqrt(sumSq / selected.size) / 1000.0,
        )
    }

    private fun percentile(sorted: List<Long>, f: Double): Long {
        val i = ((sorted.size - 1) * f).roundToInt().coerceIn(0, sorted.lastIndex)
        return sorted[i]
    }
}
