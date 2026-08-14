package com.maklertour.data.tof

import com.maklertour.data.calibration.DualPhoneLiveIntrinsicsEstimate
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class TofCameraHoldoutValidatorTest {
    @Test
    fun frozenProfilePassesIndependentConsistentPlanes() {
        val profile = TofCameraExtrinsicsProfile(
            rigId = "rig",
            rigMountRevision = "rev",
            masterDeviceId = "master",
            masterCameraId = "0",
            cameraCalibrationProfileId = "stereo",
            tofSlot = 0,
            tofWidth = 8,
            tofHeight = 8,
            tofIntrinsics = TofZoneIntrinsics(
                fxZones = 10.6,
                fyZones = 11.0,
                cxZones = 3.5,
                cyZones = 3.5,
            ),
            rotationToCamera = listOf(
                1.0, 0.0, 0.0,
                0.0, 1.0, 0.0,
                0.0, 0.0, 1.0,
            ),
            translationToCameraMm = listOf(80.0, 4.0, 20.0),
            sampleCount = 18,
            solver = "test-solver",
            createdAtEpochMs = 1L,
            status = TofCameraExtrinsicsProfile.STATUS_SOLVED,
        )
        val intrinsics = DualPhoneLiveIntrinsicsEstimate(
            acceptedFrames = 15,
            solved = true,
            imageWidth = 1920,
            imageHeight = 1080,
            rms = 0.5,
            fx = 1300.0,
            fy = 1300.0,
            cx = 960.0,
            cy = 540.0,
            k1 = 0.0,
            k2 = 0.0,
            status = "ok",
        )
        val samples = (0 until 12).map { sampleIndex ->
            val cameraPlaneZ = 650.0 + sampleIndex * 45.0
            val axialDepth = (cameraPlaneZ - 20.0).toInt()
            TofCameraPlanarCalibrationSample(
                cameraElapsedRealtimeNs = 1_000_000_000L + sampleIndex * 100_000_000L,
                tofMappedElapsedRealtimeNs =
                    1_000_500_000L + sampleIndex * 100_000_000L,
                tofSequence = sampleIndex.toLong(),
                pairDeltaUs = 500L,
                pairThresholdUs = 35_333L,
                tofSlot = 0,
                tofWidth = 8,
                tofHeight = 8,
                boardPlane = TofCameraBoardPlane(
                    normalX = 0.0,
                    normalY = 0.0,
                    normalZ = 1.0,
                    dMm = -cameraPlaneZ,
                    charucoCornersUsed = 20,
                ),
                zones = (0 until 64).map { zoneIndex ->
                    TofZoneRangeObservation(
                        zoneIndex = zoneIndex,
                        distanceMm = axialDepth,
                        sigmaMm = 8,
                        targetStatus = 5,
                        nbTargetDetected = 1,
                    )
                },
            )
        }

        val result = TofCameraHoldoutValidator().validate(
            profile = profile,
            cameraIntrinsics = intrinsics,
            samples = samples,
        )

        assertTrue(result.status, result.successful)
        assertEquals(12, result.sampleCount)
        assertTrue(result.retainedZoneCoverageCount >= 39)
        assertTrue(result.retainedZoneCoveragePercent >= 60.0)
        assertTrue((result.planeRmsMm ?: Double.MAX_VALUE) < 1.0)
        assertTrue((result.planeP95Mm ?: Double.MAX_VALUE) < 1.0)
        assertTrue((result.reprojectionRmsPx ?: Double.MAX_VALUE) < 1.0)
    }
}
