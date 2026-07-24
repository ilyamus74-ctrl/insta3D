package com.maklertour.data.phonecamera

import org.junit.Assert.assertEquals
import org.junit.Test

class AutoPhotoCaptureManagerTest {
    @Test
    fun frameNameUsesDeterministicContinuousSequence() {
        assertEquals("frame_000001.jpg", AutoPhotoCaptureManager.frameName(1))
        assertEquals("frame_000123.jpg", AutoPhotoCaptureManager.frameName(123))
    }

    @Test
    fun rulesRejectWhenPausedOrCaptureIsInFlight() {
        val settings = AutoPhotoSettings(storageReserveBytes = 0)
        assertEquals("camera_not_ready", AutoPhotoCaptureRules.shouldCapture(false, false, 0.0, 1, 1_000, 0, 99.0, 0, Long.MAX_VALUE, settings))
        assertEquals("capture_in_progress", AutoPhotoCaptureRules.shouldCapture(true, true, 0.0, 1, 1_000, 0, 99.0, 0, Long.MAX_VALUE, settings))
    }

    @Test
    fun rulesExposeSpecificTerminalReasons() {
        val settings = AutoPhotoSettings(maxPhotos = 1, storageReserveBytes = 100)
        assertEquals("max_photos_reached", AutoPhotoCaptureRules.shouldCapture(true, false, 0.0, 1, 2_000, 0, 99.0, 1, 1_000, settings))
        assertEquals("storage_reserve", AutoPhotoCaptureRules.shouldCapture(true, false, 0.0, 1, 2_000, 0, 99.0, 0, 1, settings))
    }

    @Test
    fun rulesAcceptStableSharpFrame() {
        val settings = AutoPhotoSettings(storageReserveBytes = 0)
        assertEquals("accepted", AutoPhotoCaptureRules.shouldCapture(true, false, 0.0, 1, 2_000, 0, 99.0, 0, Long.MAX_VALUE, settings))
    }

    @Test
    fun movementMetricsDoNotChangeExistingCaptureDecision() {
        val settings = AutoPhotoSettings(
            storageReserveBytes = 0,
            visualMovementMetricsEnabled = true,
        )
        val reasons = listOf(
            AutoPhotoCaptureRules.shouldCapture(false, false, 0.0, 1, 2_000, 0, 99.0, 0, Long.MAX_VALUE, settings),
            AutoPhotoCaptureRules.shouldCapture(true, true, 0.0, 1, 2_000, 0, 99.0, 0, Long.MAX_VALUE, settings),
            AutoPhotoCaptureRules.shouldCapture(true, false, 99.0, 1, 2_000, 0, 99.0, 0, Long.MAX_VALUE, settings),
            AutoPhotoCaptureRules.shouldCapture(true, false, 0.0, 1_950, 2_000, 0, 99.0, 0, Long.MAX_VALUE, settings),
            AutoPhotoCaptureRules.shouldCapture(true, false, 0.0, 1, 500, 0, 99.0, 0, Long.MAX_VALUE, settings),
            AutoPhotoCaptureRules.shouldCapture(true, false, 0.0, 1, 2_000, 0, 1.0, 0, Long.MAX_VALUE, settings),
            AutoPhotoCaptureRules.shouldCapture(true, false, 0.0, 1, 2_000, 0, 99.0, 0, Long.MAX_VALUE, settings),
        )

        assertEquals(
            listOf(
                "camera_not_ready",
                "capture_in_progress",
                "motion_too_high",
                "not_stable_long_enough",
                "minimum_interval",
                "too_blurry",
                "accepted",
            ),
            reasons,
        )
    }
}
