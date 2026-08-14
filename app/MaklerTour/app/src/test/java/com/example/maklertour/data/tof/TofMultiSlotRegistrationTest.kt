package com.maklertour.data.tof

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Test

class TofMultiSlotRegistrationTest {
    @Test
    fun nearestForSlotNeverSelectsCloserFrameFromAnotherTof() {
        val slot0 = frame(
            slot = 0,
            sequence = 10,
            rp2040TimestampUs = 1_000L,
        )
        val slot1Closer = frame(
            slot = 1,
            sequence = 11,
            rp2040TimestampUs = 1_005L,
        )

        val pair = TofCameraFramePairer.nearestForSlot(
            cameraElapsedRealtimeNs = 1_006_000L,
            frames = listOf(slot0, slot1Closer),
            tofSlot = 0,
            mapper = { it * 1_000L },
        )

        assertNotNull(pair)
        assertEquals(0, pair!!.frame.slot)
        assertEquals(10L, pair.sequence)
        assertEquals(-6L, pair.signedDeltaUs)
    }

    @Test
    fun activeProfileStoreSupportsExactlyThreeRuntimeSlotsContract() {
        assertEquals(3, TofCameraCalibrationStore.MAX_TOF_SLOTS)
        assertEquals(0, TofCameraCalibrationStore.PRIMARY_TOF_SLOT)
    }

    private fun frame(
        slot: Int,
        sequence: Long,
        rp2040TimestampUs: Long,
    ): TofFrameV1 {
        val count = 64
        return TofFrameV1(
            protocolVersion = 1,
            slot = slot,
            width = 8,
            height = 8,
            frequencyHz = 15,
            siliconTemperatureC = 25,
            sequence = sequence,
            rp2040TimestampUs = rp2040TimestampUs,
            irqTimestampValid = true,
            distanceMm = IntArray(count) { 1_000 },
            rangeSigmaMm = IntArray(count) { 8 },
            targetStatus = IntArray(count) { 5 },
            nbTargetDetected = IntArray(count) { 1 },
            hostReceivedElapsedRealtimeNs = rp2040TimestampUs * 1_000L,
        )
    }
}
