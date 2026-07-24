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
        assertEquals(150L, settings.stableDwellMs)
        assertTrue(settings.movementCaptureEnabled)
        assertEquals(2_500L, settings.movementMaxCaptureIntervalMs)
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
    }

    @Test
    fun trackingFailureUsesControlledFallback() {
        val waiting = decide(
            movement = movement(AutoPhotoMovementStatus.TRACKING_FAILED),
            nowMs = 2_000,
            lastCaptureMs = 0,
        )
        val fallback = decide(
            movement = movement(AutoPhotoMovementStatus.TRACKING_FAILED),
            nowMs = 3_000,
            lastCaptureMs = 0,
        )

        assertFalse(waiting.shouldCapture)
        assertEquals("movement_tracking_failed", waiting.reason)
        assertTrue(fallback.shouldCapture)
        assertEquals("accepted_fallback", fallback.reason)
        assertTrue(fallback.fallback)
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
    )
}
