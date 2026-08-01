package com.example.maklertour.data.dualphone

import com.maklertour.data.calibration.DualPhoneCalibrationProfileResult
import com.maklertour.data.calibration.DualPhoneLiveIntrinsicsEstimate
import com.maklertour.data.calibration.DualPhoneStereoEstimate
import com.maklertour.data.dualphone.DualPhoneControlPhase
import com.maklertour.data.dualphone.DualPhoneControlSnapshot
import com.maklertour.data.dualphone.DualPhoneRole
import com.maklertour.data.dualphone.DualPhoneStereoSettings
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Test

class DualPhoneLiveStreamSessionCoordinatorTest {
    @Test
    fun acceptedProfileBindsSelectedSessionAndCaptureId() {
        val coordinator = coordinator()
        val status = coordinator.reconcile(input())

        assertTrue(status.sessionAccepted)
        assertEquals(DualPhoneLiveStreamSessionBlock.NONE, status.block)
        assertEquals(
            DualPhoneLiveStreamState.PREPARING,
            status.snapshot.state,
        )
        val owner = status.snapshot.owner
        assertNotNull(owner)
        assertEquals("session-a", owner?.sessionUuid)
        assertEquals("capture-a", owner?.dualCaptureId)
        assertEquals("master-camera", owner?.cameraIdentity)
        assertEquals("dual-profile-a", owner?.calibrationIdentity)
        assertTrue(
            owner?.recordingModeIdentity
                ?.contains("calibrated_size=640x360") == true,
        )
    }

    @Test
    fun missingSessionReleasesCurrentOwner() {
        val coordinator = coordinator()
        assertTrue(coordinator.reconcile(input()).sessionAccepted)

        val blocked = coordinator.reconcile(
            input().copy(sessionUuid = null),
        )

        assertEquals(DualPhoneLiveStreamSessionBlock.NO_SESSION, blocked.block)
        assertEquals(DualPhoneLiveStreamState.DISABLED, blocked.snapshot.state)
        assertEquals(null, blocked.snapshot.owner)
    }

    @Test
    fun connectedPeerMustMatchAcceptedCalibration() {
        val coordinator = coordinator()
        val blocked = coordinator.reconcile(
            input().copy(
                control = input().control.copy(
                    peerDeviceId = "unexpected-peer",
                ),
            ),
        )

        assertEquals(
            DualPhoneLiveStreamSessionBlock.PEER_DEVICE_MISMATCH,
            blocked.block,
        )
        assertFalse(blocked.sessionAccepted)
    }


    @Test
    fun activeCalibrationBlocksStreamPreparation() {
        val coordinator = coordinator()
        val blocked = coordinator.reconcile(
            input().copy(
                control = input().control.copy(
                    calibrationActive = true,
                ),
            ),
        )

        assertEquals(
            DualPhoneLiveStreamSessionBlock.CALIBRATION_IN_PROGRESS,
            blocked.block,
        )
        assertFalse(blocked.sessionAccepted)
    }

    @Test
    fun streamCannotBeEnabledForTheFirstTimeDuringRecording() {
        val coordinator = coordinator()
        val blocked = coordinator.reconcile(
            input().copy(
                control = input().control.copy(
                    phase = DualPhoneControlPhase.RECORDING,
                ),
            ),
        )

        assertEquals(
            DualPhoneLiveStreamSessionBlock.CONTROL_PHASE_UNAVAILABLE,
            blocked.block,
        )
        assertFalse(blocked.sessionAccepted)
    }

    @Test
    fun syncVideoNeverCreatesReducedStreamOwner() {
        val coordinator = coordinator()
        val status = coordinator.reconcile(
            input().copy(
                requestedMode = DualPhoneLiveStreamMode.SYNC_VIDEO,
            ),
        )

        assertEquals(
            DualPhoneLiveStreamSessionBlock.MODE_DISABLED,
            status.block,
        )
        assertEquals(DualPhoneLiveStreamState.DISABLED, status.snapshot.state)
        assertEquals(null, status.snapshot.owner)
    }

    @Test
    fun transportAndCaptureTransitionsUseBoundOwner() {
        val coordinator = coordinator()
        coordinator.reconcile(input())

        assertTrue(coordinator.markTransportReady())
        assertEquals(
            DualPhoneLiveStreamState.READY,
            coordinator.snapshot.state,
        )
        assertTrue(coordinator.markCaptureStarted())
        assertEquals(
            DualPhoneLiveStreamState.STREAMING,
            coordinator.snapshot.state,
        )
        assertTrue(coordinator.markCaptureStopRequested())
        assertEquals(
            DualPhoneLiveStreamState.STOPPING,
            coordinator.snapshot.state,
        )
        assertTrue(coordinator.markCaptureStopped())
        assertEquals(
            DualPhoneLiveStreamState.STOPPED,
            coordinator.snapshot.state,
        )
    }

    private fun coordinator() =
        DualPhoneLiveStreamSessionCoordinator(
            streamIdFactory = { "stream-a" },
        )

    private fun input() = DualPhoneLiveStreamSessionInput(
        sessionUuid = "session-a",
        requestedMode = DualPhoneLiveStreamMode.LIVE_METRIC,
        settings = DualPhoneStereoSettings(
            deviceId = "master-device",
            role = DualPhoneRole.MASTER,
            peerDeviceId = "slave-device",
            preferredVideoModeId = "fhd-30",
            rigId = "rig-a",
            rigMountRevision = "rev-a",
            activeCalibrationProfileId = "dual-profile-a",
        ),
        control = DualPhoneControlSnapshot(
            phase = DualPhoneControlPhase.CONNECTED,
            role = DualPhoneRole.MASTER,
            peerDeviceId = "slave-device",
            dualCaptureId = "capture-a",
            connected = true,
        ),
        calibrationProfile = acceptedProfile(),
    )

    private fun acceptedProfile() = DualPhoneCalibrationProfileResult(
        profileId = "dual-profile-a",
        calibrationRunId = "cal-profile-a",
        rigId = "rig-a",
        rigMountRevision = "rev-a",
        masterDeviceId = "master-device",
        slaveDeviceId = "slave-device",
        masterCameraId = "master-camera",
        slaveCameraId = "slave-camera",
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
