package com.maklertour.data.tof

import com.maklertour.data.calibration.DualPhoneLiveIntrinsicsEstimate
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Test

class TofCameraExtrinsicsProfileTest {
    @Test
    fun identityExtrinsicsProjectCentralZoneToPrincipalPoint() {
        val projection = TofCameraProjector.projectZoneCenter(
            zoneIndex = 27,
            distanceMm = 1_000,
            profile = profile(
                tofIntrinsics = TofZoneIntrinsics(
                    fxZones = 8.0,
                    fyZones = 8.0,
                    cxZones = 3.0,
                    cyZones = 3.0,
                ),
            ),
            cameraIntrinsics = cameraIntrinsics(),
        )

        assertNotNull(projection)
        projection!!
        assertEquals(640.0, projection.uPx, 1e-9)
        assertEquals(360.0, projection.vPx, 1e-9)
        assertEquals(1_000.0, projection.cameraZmm, 1e-9)
    }

    @Test
    fun translationToCameraMovesProjectedPixel() {
        val projection = TofCameraProjector.projectZoneCenter(
            zoneIndex = 27,
            distanceMm = 1_000,
            profile = profile(
                tofIntrinsics = TofZoneIntrinsics(
                    fxZones = 8.0,
                    fyZones = 8.0,
                    cxZones = 3.0,
                    cyZones = 3.0,
                ),
                translationMm = listOf(100.0, 0.0, 0.0),
            ),
            cameraIntrinsics = cameraIntrinsics(),
        )

        assertNotNull(projection)
        projection!!
        assertEquals(740.0, projection.uPx, 1e-9)
        assertEquals(360.0, projection.vPx, 1e-9)
    }

    @Test
    fun profileIsBoundToRigMountRevisionAndCameraIdentity() {
        val profile = profile(
            tofIntrinsics = TofZoneIntrinsics(
                fxZones = 8.0,
                fyZones = 8.0,
                cxZones = 3.5,
                cyZones = 3.5,
            ),
        )

        assertTrue(
            profile.matchesRig(
                rigId = "rig-1",
                rigMountRevision = "mount-a",
                masterDeviceId = "master-1",
                masterCameraId = "0",
            ),
        )
        assertFalse(
            profile.matchesRig(
                rigId = "rig-1",
                rigMountRevision = "mount-b",
                masterDeviceId = "master-1",
                masterCameraId = "0",
            ),
        )
    }

    private fun profile(
        tofIntrinsics: TofZoneIntrinsics,
        translationMm: List<Double> = listOf(0.0, 0.0, 0.0),
    ): TofCameraExtrinsicsProfile =
        TofCameraExtrinsicsProfile(
            rigId = "rig-1",
            rigMountRevision = "mount-a",
            masterDeviceId = "master-1",
            masterCameraId = "0",
            cameraCalibrationProfileId = "dual-cal-1",
            tofSlot = 0,
            tofWidth = 8,
            tofHeight = 8,
            tofIntrinsics = tofIntrinsics,
            rotationToCamera = listOf(
                1.0, 0.0, 0.0,
                0.0, 1.0, 0.0,
                0.0, 0.0, 1.0,
            ),
            translationToCameraMm = translationMm,
            sampleCount = 10,
            solver = "TEST",
            createdAtEpochMs = 0L,
            status = TofCameraExtrinsicsProfile.STATUS_SOLVED,
        )

    private fun cameraIntrinsics(): DualPhoneLiveIntrinsicsEstimate =
        DualPhoneLiveIntrinsicsEstimate(
            acceptedFrames = 10,
            solved = true,
            imageWidth = 1280,
            imageHeight = 720,
            rms = 0.5,
            fx = 1_000.0,
            fy = 1_000.0,
            cx = 640.0,
            cy = 360.0,
            k1 = 0.0,
            k2 = 0.0,
            status = "test",
        )
}
