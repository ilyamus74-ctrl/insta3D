package com.example.maklertour.data.calibration

import com.maklertour.data.dualphone.DualPhoneRole
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class DualPhoneCalibrationCameraIdentityRepairTest {
    @Test
    fun masterRoleBackfillsBothMissingIds() {
        val result = DualPhoneCalibrationCameraIdentityRepair.repair(
            profile = acceptedProfile(null, null),
            localRole = DualPhoneRole.MASTER,
            localCameraId = "master-camera",
            peerCameraId = "slave-camera",
        )

        assertTrue(result.successful)
        assertTrue(result.changed)
        assertEquals("master-camera", result.profile?.masterCameraId)
        assertEquals("slave-camera", result.profile?.slaveCameraId)
    }

    @Test
    fun slaveRoleMapsLocalCameraToSlaveSide() {
        val result = DualPhoneCalibrationCameraIdentityRepair.repair(
            profile = acceptedProfile(null, null),
            localRole = DualPhoneRole.SLAVE,
            localCameraId = "slave-camera",
            peerCameraId = "master-camera",
        )

        assertEquals("master-camera", result.profile?.masterCameraId)
        assertEquals("slave-camera", result.profile?.slaveCameraId)
    }

    @Test
    fun existingDifferentIdIsNeverOverwritten() {
        val result = DualPhoneCalibrationCameraIdentityRepair.repair(
            profile = acceptedProfile("stored-master", "stored-slave"),
            localRole = DualPhoneRole.MASTER,
            localCameraId = "different-master",
            peerCameraId = "stored-slave",
        )

        assertFalse(result.successful)
        assertNull(result.profile)
        assertTrue(result.message.contains("conflict"))
    }

    @Test
    fun missingPeerIdKeepsRepairBlocked() {
        val result = DualPhoneCalibrationCameraIdentityRepair.repair(
            profile = acceptedProfile(null, null),
            localRole = DualPhoneRole.MASTER,
            localCameraId = "master-camera",
            peerCameraId = null,
        )

        assertFalse(result.successful)
        assertNull(result.profile)
        assertTrue(result.message.contains("SLAVE camera ID"))
    }

    private fun acceptedProfile(
        masterCameraId: String?,
        slaveCameraId: String?,
    ) = DualPhoneCalibrationProfileResult(
        profileId = "dual-profile",
        calibrationRunId = "cal-profile",
        rigId = "rig-a",
        rigMountRevision = "rev-a",
        masterDeviceId = "master-device",
        slaveDeviceId = "slave-device",
        masterCameraId = masterCameraId,
        slaveCameraId = slaveCameraId,
        masterIntrinsics = acceptedIntrinsics(),
        slaveIntrinsics = acceptedIntrinsics(),
        stereo = DualPhoneStereoEstimate(
            solved = true,
            pairsUsed = 15,
            rms = 0.615,
            rotation = listOf(
                1.0, 0.0, 0.0,
                0.0, 1.0, 0.0,
                0.0, 0.0, 1.0,
            ),
            translationMm = listOf(0.0, 221.8, 0.0),
            baselineMm = 221.8,
            operatorBaselineMm = 215.0,
            baselineDeltaMm = 6.8,
            pairsRejected = 3,
            meanEpipolarErrorPx = 0.84,
            coveragePercent = 100,
            status = "accepted",
        ),
        createdAtEpochMs = 1L,
        status = DualPhoneCalibrationProfileResult.STATUS_SUCCESS,
    )

    private fun acceptedIntrinsics() = DualPhoneLiveIntrinsicsEstimate(
        acceptedFrames = 15,
        solved = true,
        imageWidth = 640,
        imageHeight = 360,
        rms = 0.5,
        fx = 600.0,
        fy = 600.0,
        cx = 320.0,
        cy = 180.0,
        k1 = 0.01,
        k2 = -0.02,
        status = "accepted",
    )
}
