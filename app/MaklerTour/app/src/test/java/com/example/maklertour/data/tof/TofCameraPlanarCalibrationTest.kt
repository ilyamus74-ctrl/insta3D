package com.maklertour.data.tof

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Test

class TofCameraPlanarCalibrationTest {
    @Test
    fun nearestPairUsesEventTimeAndDynamic15HzThreshold() {
        val frames = listOf(
            frame(sequence = 1, timestampUs = 1_000),
            frame(sequence = 2, timestampUs = 2_000),
        )

        val pair =
            TofCameraFramePairer.nearest(
                cameraElapsedRealtimeNs = 1_400_000L,
                frames = frames,
                mapper = { timestampUs -> timestampUs * 1_000L },
            )

        assertNotNull(pair)
        pair!!
        assertEquals(1L, pair.sequence)
        assertEquals(-400L, pair.signedDeltaUs)
        assertEquals(400L, pair.absDeltaUs)
        assertEquals(35_333L, pair.thresholdUs)
        assertTrue(pair.accepted)
    }

    @Test
    fun farCandidateIsReturnedButRejected() {
        val pair =
            TofCameraFramePairer.nearest(
                cameraElapsedRealtimeNs = 100_000_000L,
                frames = listOf(frame(sequence = 9, timestampUs = 1_000)),
                mapper = { timestampUs -> timestampUs * 1_000L },
            )

        assertNotNull(pair)
        pair!!
        assertEquals(99_000L, pair.absDeltaUs)
        assertFalse(pair.accepted)
    }

    @Test
    fun planarSampleKeepsOnlyValidTofZones() {
        val tofFrame =
            frame(
                sequence = 11,
                timestampUs = 50_000,
                validZones = setOf(0, 5, 63),
            )
        val pair =
            TofCameraFramePair(
                frame = tofFrame,
                mappedElapsedRealtimeNs = 50_000_000L,
                signedDeltaNs = 500_000L,
                absDeltaNs = 500_000L,
                thresholdUs = 35_333L,
            )
        val plane =
            TofCameraBoardPlane(
                normalX = 0.0,
                normalY = 0.0,
                normalZ = 1.0,
                dMm = -1_000.0,
                charucoCornersUsed = 12,
            )

        val sample =
            TofCameraPlanarCalibrationSampleBuilder.fromAcceptedPair(
                cameraElapsedRealtimeNs = 49_500_000L,
                boardPlane = plane,
                pair = pair,
            )

        assertNotNull(sample)
        sample!!
        assertEquals(3, sample.validZoneCount)
        assertEquals(listOf(0, 5, 63), sample.zones.map { it.zoneIndex })
        assertTrue(sample.structurallyValid)
        assertEquals(
            0.0,
            plane.signedDistanceMm(0.0, 0.0, 1_000.0),
            1e-9,
        )
    }

    @Test
    fun planarSampleDropsStatusValidNearGhostRange() {
        val tofFrame =
            frame(
                sequence = 12,
                timestampUs = 60_000,
                validZones = setOf(0, 5, 63),
            )
        tofFrame.distanceMm[5] = 17
        val pair =
            TofCameraFramePair(
                frame = tofFrame,
                mappedElapsedRealtimeNs = 60_000_000L,
                signedDeltaNs = 500_000L,
                absDeltaNs = 500_000L,
                thresholdUs = 35_333L,
            )
        val plane =
            TofCameraBoardPlane(
                normalX = 0.0,
                normalY = 0.0,
                normalZ = 1.0,
                dMm = -800.0,
                charucoCornersUsed = 12,
            )

        val sample =
            TofCameraPlanarCalibrationSampleBuilder.fromAcceptedPair(
                cameraElapsedRealtimeNs = 59_500_000L,
                boardPlane = plane,
                pair = pair,
            )

        assertNotNull(sample)
        sample!!
        assertEquals(listOf(0, 63), sample.zones.map { it.zoneIndex })
        assertTrue(
            sample.zones.all {
                it.distanceMm >=
                    TofCameraPlanarCalibrationSampleBuilder.MIN_CALIBRATION_RANGE_MM
            },
        )
    }

    private fun frame(
        sequence: Long,
        timestampUs: Long,
        validZones: Set<Int> = (0 until 64).toSet(),
    ): TofFrameV1 {
        val distance = IntArray(64) { 1_000 + it }
        val sigma = IntArray(64) { 10 }
        val status = IntArray(64) { index -> if (index in validZones) 5 else 1 }
        val detected = IntArray(64) { index -> if (index in validZones) 1 else 0 }
        return TofFrameV1(
            protocolVersion = 1,
            slot = 0,
            width = 8,
            height = 8,
            frequencyHz = 15,
            siliconTemperatureC = 45,
            sequence = sequence,
            rp2040TimestampUs = timestampUs,
            irqTimestampValid = true,
            distanceMm = distance,
            rangeSigmaMm = sigma,
            targetStatus = status,
            nbTargetDetected = detected,
            hostReceivedElapsedRealtimeNs = 0L,
        )
    }
}
