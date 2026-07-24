package com.maklertour.data.phonecamera

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import kotlin.math.abs

class AutoPhotoMovementTrackerTest {
    @Test
    fun noReferenceBeforeFirstSuccessfulSave() {
        val tracker = tracker(BrightPointFlowEngine())
        val analysis = tracker.analyze(frame(shiftX = 0))

        assertEquals(AutoPhotoMovementStatus.NO_REFERENCE, analysis.result.status)
        assertNull(analysis.result.referenceSequence)
    }

    @Test
    fun insufficientFeaturesAreReported() {
        val engine = FixedFlowEngine(
            AutoPhotoFlowMeasurement(
                method = "test",
                detectedFeatures = 3,
                tracks = emptyList(),
            ),
        )
        val tracker = tracker(engine)
        val first = tracker.analyze(frame())
        assertTrue(tracker.commit(first, 1))

        val result = tracker.analyze(frame()).result
        assertEquals(
            AutoPhotoMovementStatus.INSUFFICIENT_FEATURES,
            result.status,
        )
        assertEquals(3, result.detectedFeatures)
    }

    @Test
    fun identicalSyntheticFramesProduceLowDisplacement() {
        val tracker = tracker(BrightPointFlowEngine())
        assertTrue(tracker.commit(tracker.analyze(frame(shiftX = 0)), 1))

        val result = tracker.analyze(frame(shiftX = 0)).result

        assertEquals(AutoPhotoMovementStatus.OK, result.status)
        assertTrue((result.medianDisplacementPx ?: 99.0) < 0.01)
        assertTrue((result.p90DisplacementPx ?: 99.0) < 0.01)
    }

    @Test
    fun translatedSyntheticFramesProduceHigherDisplacement() {
        val tracker = tracker(BrightPointFlowEngine())
        assertTrue(tracker.commit(tracker.analyze(frame(shiftX = 0)), 1))

        val result = tracker.analyze(frame(shiftX = 6)).result

        assertEquals(AutoPhotoMovementStatus.OK, result.status)
        assertTrue(abs((result.medianDisplacementPx ?: 0.0) - 6.0) < 0.01)
        assertTrue(abs((result.p90DisplacementPx ?: 0.0) - 6.0) < 0.01)
        assertTrue(abs((result.medianFlowDxPx ?: 0.0) - 6.0) < 0.01)
        assertTrue(abs(result.medianFlowDyPx ?: 99.0) < 0.01)
    }

    @Test
    fun invalidTracksAreFiltered() {
        val tracks = (0 until 10).map { index ->
            AutoPhotoTrackedPoint(
                previousX = index + 10.0,
                previousY = 10.0,
                currentX = index + 11.0,
                currentY = 10.0,
                valid = index < 8,
            )
        } + AutoPhotoTrackedPoint(
            previousX = Double.NaN,
            previousY = 1.0,
            currentX = 2.0,
            currentY = 2.0,
            valid = true,
        )
        val engine = FixedFlowEngine(
            AutoPhotoFlowMeasurement(
                method = "test",
                detectedFeatures = 11,
                tracks = tracks,
            ),
        )
        val tracker = tracker(
            engine = engine,
            minDetectedFeatures = 4,
            minTrackedFeatures = 4,
        )
        assertTrue(tracker.commit(tracker.analyze(frame()), 1))

        val result = tracker.analyze(frame()).result

        assertEquals(AutoPhotoMovementStatus.OK, result.status)
        assertEquals(8, result.trackedFeatures)
    }

    @Test
    fun featureCountIsBoundedByConfiguredMaximum() {
        val engine = RecordingFlowEngine()
        val tracker = tracker(
            engine = engine,
            maxFeatures = 16,
            minDetectedFeatures = 4,
            minTrackedFeatures = 4,
        )
        assertTrue(tracker.commit(tracker.analyze(frame()), 1))

        val result = tracker.analyze(frame()).result

        assertEquals(16, engine.lastMaxFeatures)
        assertEquals(16, result.detectedFeatures)
        assertEquals(16, result.trackedFeatures)
    }

    @Test
    fun failedSaveDoesNotAdvanceReference() {
        val tracker = tracker(BrightPointFlowEngine())
        val candidate = tracker.analyze(frame())

        assertNull(tracker.currentReferenceSequence())
        assertEquals(
            AutoPhotoMovementStatus.NO_REFERENCE,
            tracker.analyze(frame(shiftX = 4)).result.status,
        )

        assertTrue(tracker.commit(candidate, 1))
        assertEquals(1, tracker.currentReferenceSequence())
    }

    @Test
    fun successfulSaveAdvancesReferenceOnlyOnce() {
        val tracker = tracker(BrightPointFlowEngine())
        val candidate = tracker.analyze(frame())

        assertTrue(tracker.commit(candidate, 1))
        assertFalse(tracker.commit(candidate, 1))
        assertFalse(tracker.commit(candidate, 0))
        assertEquals(1, tracker.currentReferenceSequence())
    }

    @Test
    fun resetClearsReference() {
        val tracker = tracker(BrightPointFlowEngine())
        assertTrue(tracker.commit(tracker.analyze(frame()), 1))

        tracker.reset()

        assertNull(tracker.currentReferenceSequence())
        assertEquals(
            AutoPhotoMovementStatus.NO_REFERENCE,
            tracker.analyze(frame()).result.status,
        )
    }

    @Test
    fun metadataContainsFiniteNumbersOrNulls() {
        val result = AutoPhotoMovementResult(
            status = AutoPhotoMovementStatus.OK,
            method = "test",
            referenceSequence = 1,
            analysisTimestampNs = 2,
            analysisWidth = 32,
            analysisHeight = 24,
            detectedFeatures = 12,
            trackedFeatures = 8,
            trackedRatio = Double.NaN,
            medianDisplacementPx = Double.POSITIVE_INFINITY,
            p90DisplacementPx = 4.0,
            estimatedRotationDeg = Double.NEGATIVE_INFINITY,
        )

        val metadata = result.toMetadataMap()

        assertNull(metadata["tracked_ratio"])
        assertNull(metadata["median_displacement_px"])
        assertEquals(4.0, metadata["p90_displacement_px"])
        assertNull(metadata["estimated_rotation_deg"])
    }

    private fun tracker(
        engine: AutoPhotoFlowEngine,
        maxFeatures: Int = 64,
        minDetectedFeatures: Int = 8,
        minTrackedFeatures: Int = 6,
    ) = AutoPhotoMovementTracker(
        flowEngine = engine,
        methodName = "test",
        maxFeatures = maxFeatures,
        minDetectedFeatures = minDetectedFeatures,
        minTrackedFeatures = minTrackedFeatures,
    )

    private fun frame(
        shiftX: Int = 0,
        width: Int = 64,
        height: Int = 48,
    ): AutoPhotoMovementFrame {
        val data = ByteArray(width * height)
        val points = listOf(
            8 to 8,
            16 to 8,
            24 to 8,
            32 to 8,
            40 to 8,
            8 to 20,
            16 to 20,
            24 to 20,
            32 to 20,
            40 to 20,
            12 to 34,
            28 to 34,
        )
        points.forEach { (x, y) ->
            val shifted = x + shiftX
            if (shifted in 0 until width) {
                data[y * width + shifted] = 0xff.toByte()
            }
        }
        return AutoPhotoMovementFrame(
            width = width,
            height = height,
            luma = data,
            timestampNs = shiftX.toLong() + 100,
        )
    }

    private class FixedFlowEngine(
        private val measurement: AutoPhotoFlowMeasurement,
    ) : AutoPhotoFlowEngine {
        override fun track(
            reference: AutoPhotoMovementFrame,
            current: AutoPhotoMovementFrame,
            maxFeatures: Int,
        ): AutoPhotoFlowMeasurement = measurement
    }

    private class RecordingFlowEngine : AutoPhotoFlowEngine {
        var lastMaxFeatures: Int = 0

        override fun track(
            reference: AutoPhotoMovementFrame,
            current: AutoPhotoMovementFrame,
            maxFeatures: Int,
        ): AutoPhotoFlowMeasurement {
            lastMaxFeatures = maxFeatures
            val tracks = (0 until maxFeatures).map { index ->
                AutoPhotoTrackedPoint(
                    previousX = (index % reference.width).toDouble(),
                    previousY = (index / reference.width).toDouble(),
                    currentX = (index % current.width).toDouble(),
                    currentY = (index / current.width).toDouble(),
                )
            }
            return AutoPhotoFlowMeasurement(
                method = "recording",
                detectedFeatures = maxFeatures + 20,
                tracks = tracks,
            )
        }
    }

    private class BrightPointFlowEngine : AutoPhotoFlowEngine {
        override fun track(
            reference: AutoPhotoMovementFrame,
            current: AutoPhotoMovementFrame,
            maxFeatures: Int,
        ): AutoPhotoFlowMeasurement {
            val previous = brightPoints(reference).take(maxFeatures)
            val next = brightPoints(current).take(maxFeatures)
            val shiftX = if (previous.isNotEmpty() && next.isNotEmpty()) {
                next.map { it.first }.average()
                    - previous.map { it.first }.average()
            } else {
                0.0
            }
            val shiftY = if (previous.isNotEmpty() && next.isNotEmpty()) {
                next.map { it.second }.average()
                    - previous.map { it.second }.average()
            } else {
                0.0
            }
            return AutoPhotoFlowMeasurement(
                method = "synthetic",
                detectedFeatures = previous.size,
                tracks = previous.map { (x, y) ->
                    AutoPhotoTrackedPoint(
                        previousX = x.toDouble(),
                        previousY = y.toDouble(),
                        currentX = x + shiftX,
                        currentY = y + shiftY,
                    )
                },
            )
        }

        private fun brightPoints(
            frame: AutoPhotoMovementFrame,
        ): List<Pair<Int, Int>> = buildList {
            for (y in 0 until frame.height) {
                for (x in 0 until frame.width) {
                    if ((frame.luma[y * frame.width + x].toInt() and 0xff) > 200) {
                        add(x to y)
                    }
                }
            }
        }
    }
}
