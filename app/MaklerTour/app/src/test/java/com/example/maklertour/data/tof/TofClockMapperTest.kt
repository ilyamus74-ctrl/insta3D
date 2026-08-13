package com.maklertour.data.tof

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Test
import kotlin.math.abs
import kotlin.math.roundToLong

class TofClockMapperTest {
    @Test
    fun estimatesClockRateAndArrivalJitter() {
        val mapper = TofClockMapper(
            maxSamples = 240,
            minSamples = 60,
            minSpanNs = 2_000_000_000L,
        )

        val rpStartUs = 1_000_000L
        val androidBaseNs = 50_000_000_000L
        val expectedDriftPpm = 35.0
        val rate = 1.0 + expectedDriftPpm / 1_000_000.0

        val jitterNs = longArrayOf(
            -120_000L, 20_000L, 80_000L, 0L, 150_000L, -40_000L, 30_000L,
        )

        var state = TofClockState()
        repeat(180) { i ->
            val rpUs = rpStartUs + i * 66_667L
            val rpDeltaNs = (rpUs - rpStartUs) * 1_000L
            val hostNs =
                androidBaseNs +
                    (rpDeltaNs.toDouble() * rate).roundToLong() +
                    2_000_000L +
                    jitterNs[i % jitterNs.size]

            state = mapper.add(rpUs, hostNs)
        }

        assertEquals(TofClockModelPhase.ARRIVAL_MODEL_READY, state.phase)
        val drift = state.driftPpm
        assertNotNull(drift)
        assertTrue(abs(drift!! - expectedDriftPpm) < 20.0)

        val p95 = state.arrivalResidualP95Us
        assertNotNull(p95)
        assertTrue(p95!! < 300.0)
    }

    @Test
    fun rp2040ClockRollbackStartsNewGeneration() {
        val mapper = TofClockMapper(maxSamples = 20, minSamples = 4, minSpanNs = 1L)

        mapper.add(1_000L, 10_000_000L)
        mapper.add(2_000L, 11_000_000L)
        val before = mapper.snapshot()

        val after = mapper.add(100L, 12_000_000L)

        assertTrue(after.generation > before.generation)
        assertEquals(1, after.sampleCount)
        assertEquals(TofClockModelPhase.WARMING_UP, after.phase)
    }
}
