package com.maklertour.data.phonecamera

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import kotlin.math.abs

class DeviceOrientationTrackerTest {
    @Test
    fun classifiesAllPhysicalSides() {
        assertEquals(
            "face_up",
            DeviceOrientationMath.classify(0f, 0f, 9.81f).physicalOrientation,
        )
        assertEquals(
            "face_down",
            DeviceOrientationMath.classify(0f, 0f, -9.81f).physicalOrientation,
        )
        assertEquals(
            "portrait_upright",
            DeviceOrientationMath.classify(0f, 9.81f, 0f).physicalOrientation,
        )
        assertEquals(
            "portrait_upside_down",
            DeviceOrientationMath.classify(0f, -9.81f, 0f).physicalOrientation,
        )
        assertEquals(
            "landscape_right",
            DeviceOrientationMath.classify(9.81f, 0f, 0f).physicalOrientation,
        )
        assertEquals(
            "landscape_left",
            DeviceOrientationMath.classify(-9.81f, 0f, 0f).physicalOrientation,
        )
    }

    @Test
    fun weakVectorIsUnknown() {
        val result = DeviceOrientationMath.classify(0.1f, 0.1f, 0.1f)
        assertEquals("unknown", result.physicalOrientation)
        assertEquals(0.0, result.confidence, 0.0)
    }

    @Test
    fun imageUpUsesDisplayRotation() {
        assertEquals(
            "top",
            DeviceOrientationMath.imageUp(0f, 9.81f, 0).direction,
        )
        assertEquals(
            "right",
            DeviceOrientationMath.imageUp(0f, 9.81f, 90).direction,
        )
        assertEquals(
            "bottom",
            DeviceOrientationMath.imageUp(0f, 9.81f, 180).direction,
        )
        assertEquals(
            "left",
            DeviceOrientationMath.imageUp(0f, 9.81f, 270).direction,
        )
    }

    @Test
    fun imageUpVectorIsNormalized() {
        val result = DeviceOrientationMath.imageUp(3f, 4f, 0)
        val length = kotlin.math.hypot(result.x ?: 0.0, result.y ?: 0.0)
        assertTrue(abs(length - 1.0) < 0.0001)
    }
}
