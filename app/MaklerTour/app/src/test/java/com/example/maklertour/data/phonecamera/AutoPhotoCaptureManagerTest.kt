package com.maklertour.data.phonecamera

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class AutoPhotoCaptureManagerTest {
    @Test
    fun frameNameUsesDeterministicContinuousSequence() {
        assertEquals("frame_000001.jpg", AutoPhotoCaptureManager.frameName(1))
        assertEquals("frame_000123.jpg", AutoPhotoCaptureManager.frameName(123))
    }

    @Test
    fun m02DefaultsIncreaseCaptureOpportunity() {
        val settings = AutoPhotoSettings()
        assertEquals(600L, settings.autoPhotoIntervalMs)
        assertEquals(250L, settings.stableDwellMs)
        assertTrue(settings.movementCaptureEnabled)
        assertFalse(settings.movementFallbackEnabled)
        assertEquals(1_200L, settings.captureConfirmationMs)
        assertEquals(6.0, settings.movementMinMedianDisplacementPx, 0.0)
        assertEquals(30.0, settings.movementMaxMedianDisplacementPx, 0.0)
        assertEquals(0.55, settings.movementMinTrackedRatio, 0.0)
    }

    @Test
    fun baseRulesStillProtectCameraSharpnessAndStorage() {
        val settings = AutoPhotoSettings(storageReserveBytes = 100)
        assertEquals(
            "camera_not_ready",
            AutoPhotoCaptureRules.shouldCapture(
                false, false, 0.0, 1, 1_000, 0, 99.0, 0, Long.MAX_VALUE, settings,
            ),
        )
        assertEquals(
            "motion_too_high",
            AutoPhotoCaptureRules.shouldCapture(
                true, false, 99.0, 1, 2_000, 0, 99.0, 0, Long.MAX_VALUE, settings,
            ),
        )
        assertEquals(
            "too_blurry",
            AutoPhotoCaptureRules.shouldCapture(
                true, false, 0.0, 1, 2_000, 0, 1.0, 0, Long.MAX_VALUE, settings,
            ),
        )
        assertEquals(
            "storage_reserve",
            AutoPhotoCaptureRules.shouldCapture(
                true, false, 0.0, 1, 2_000, 0, 99.0, 0, 1, settings,
            ),
        )
    }

    @Test
    fun firstPhotoCreatesReference() {
        val decision = decide(
            movement = movement(AutoPhotoMovementStatus.NO_REFERENCE),
            savedCount = 0,
        )

        assertTrue(decision.shouldCapture)
        assertEquals("accepted_first_reference", decision.reason)
        assertTrue(decision.commitReference)
    }

    @Test
    fun stationaryCameraIsRejected() {
        val decision = decide(
            movement = movement(
                status = AutoPhotoMovementStatus.OK,
                median = 1.0,
                p90 = 2.0,
                trackedRatio = 0.9,
                rotation = 0.5,
            ),
        )

        assertFalse(decision.shouldCapture)
        assertEquals("move_camera", decision.reason)
        assertEquals(AutoPhotoGuidancePhase.MOVE, decision.phase)
        assertTrue(decision.movementProgressPercent in 0..99)
    }

    @Test
    fun usefulMovementIsAccepted() {
        val decision = decide(
            movement = movement(
                status = AutoPhotoMovementStatus.OK,
                median = 12.0,
                p90 = 20.0,
                trackedRatio = 0.75,
                rotation = 3.0,
            ),
        )

        assertTrue(decision.shouldCapture)
        assertEquals("accepted_movement", decision.reason)
        assertTrue(decision.commitReference)
        assertEquals(AutoPhotoGuidancePhase.HOLD, decision.phase)
    }

    @Test
    fun lostOverlapIsRejectedWithoutFallback() {
        val decision = decide(
            movement = movement(
                status = AutoPhotoMovementStatus.OK,
                median = 70.0,
                p90 = 100.0,
                trackedRatio = 0.2,
                rotation = 25.0,
            ),
            nowMs = 10_000,
            lastCaptureMs = 0,
        )

        assertFalse(decision.shouldCapture)
        assertEquals("overlap_too_low", decision.reason)
        assertEquals(AutoPhotoGuidancePhase.RECOVER, decision.phase)
        assertFalse(decision.commitReference)
    }

    @Test
    fun insufficientFeaturesRequestsTextureWithoutRecover() {
        val decision = decide(
            movement = movement(AutoPhotoMovementStatus.INSUFFICIENT_FEATURES),
            nowMs = 10_000,
            lastCaptureMs = 0,
        )

        assertFalse(decision.shouldCapture)
        assertEquals("movement_features_low", decision.reason)
        assertEquals(AutoPhotoGuidancePhase.SEEK_TEXTURE, decision.phase)
        assertTrue(decision.guidance.contains("участок с деталями"))
        assertFalse(decision.fallback)
        assertFalse(decision.commitReference)
    }

    @Test
    fun trackingFailureWithoutFlowRequestsTextureWithoutRecover() {
        val decision = decide(
            movement = movement(AutoPhotoMovementStatus.TRACKING_FAILED),
            nowMs = 10_000,
            lastCaptureMs = 0,
        )

        assertFalse(decision.shouldCapture)
        assertEquals("movement_tracking_failed", decision.reason)
        assertEquals(AutoPhotoGuidancePhase.SEEK_TEXTURE, decision.phase)
        assertFalse(decision.fallback)
        assertFalse(decision.commitReference)
    }

    @Test
    fun enablingFallbackDoesNotCaptureUnmeasuredFrames() {
        val decision = AutoPhotoMovementCapturePolicy.decide(
            baseReason = "accepted",
            movement = movement(AutoPhotoMovementStatus.TRACKING_FAILED),
            savedCount = 1,
            nowMs = 10_000,
            lastCaptureMs = 0,
            settings = AutoPhotoSettings(
                storageReserveBytes = 0,
                movementFallbackEnabled = true,
            ),
        )

        assertFalse(decision.shouldCapture)
        assertEquals(AutoPhotoGuidancePhase.SEEK_TEXTURE, decision.phase)
        assertFalse(decision.commitReference)
    }

    @Test
    fun recoveryGuidanceUsesFlowDirection() {
        val decision = decide(
            movement = movement(
                status = AutoPhotoMovementStatus.OK,
                median = 35.0,
                p90 = 45.0,
                trackedRatio = 0.8,
                rotation = 3.0,
                flowDx = 12.0,
                flowDy = 2.0,
            ),
        )

        assertEquals(AutoPhotoGuidancePhase.RECOVER, decision.phase)
        assertTrue(decision.guidance.contains("влево"))
    }

    @Test
    fun baseRejectionAlwaysWinsBeforeMovement() {
        val decision = AutoPhotoMovementCapturePolicy.decide(
            baseReason = "too_blurry",
            movement = movement(
                status = AutoPhotoMovementStatus.OK,
                median = 20.0,
                p90 = 30.0,
                trackedRatio = 0.9,
                rotation = 5.0,
            ),
            savedCount = 1,
            nowMs = 5_000,
            lastCaptureMs = 0,
            settings = AutoPhotoSettings(storageReserveBytes = 0),
        )

        assertFalse(decision.shouldCapture)
        assertEquals("too_blurry", decision.reason)
    }

    private fun decide(
        movement: AutoPhotoMovementResult,
        savedCount: Int = 1,
        nowMs: Long = 2_000,
        lastCaptureMs: Long = 0,
    ): AutoPhotoMovementCaptureDecision =
        AutoPhotoMovementCapturePolicy.decide(
            baseReason = "accepted",
            movement = movement,
            savedCount = savedCount,
            nowMs = nowMs,
            lastCaptureMs = lastCaptureMs,
            settings = AutoPhotoSettings(storageReserveBytes = 0),
        )

    private fun movement(
        status: AutoPhotoMovementStatus,
        median: Double? = null,
        p90: Double? = null,
        trackedRatio: Double? = null,
        rotation: Double? = null,
        flowDx: Double? = null,
        flowDy: Double? = null,
    ) = AutoPhotoMovementResult(
        status = status,
        method = "test",
        referenceSequence = 1,
        analysisTimestampNs = 1,
        analysisWidth = 320,
        analysisHeight = 180,
        detectedFeatures = 100,
        trackedFeatures = 80,
        trackedRatio = trackedRatio,
        medianDisplacementPx = median,
        p90DisplacementPx = p90,
        estimatedRotationDeg = rotation,
        medianFlowDxPx = flowDx,
        medianFlowDyPx = flowDy,
    )
}
