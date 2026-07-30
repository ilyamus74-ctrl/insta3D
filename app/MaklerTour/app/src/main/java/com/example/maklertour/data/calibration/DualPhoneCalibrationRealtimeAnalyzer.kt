package com.maklertour.data.calibration

import android.graphics.Bitmap
import android.graphics.Color
import android.os.SystemClock
import com.maklertour.data.dualphone.DualPhoneCalibrationObservation
import com.maklertour.data.dualphone.DualPhoneCalibrationPoseTarget
import com.maklertour.data.phonecamera.CalibrationFrame
import com.maklertour.data.rig.CalibrationSettings
import kotlin.math.abs
import kotlin.math.atan2
import kotlin.math.hypot
import kotlin.math.max
import kotlin.math.min

data class DualPhoneCalibrationRealtimeResult(
    val frameSequence: Long,
    val frameTimestampNs: Long,
    val imageProxyRotationDegrees: Int,
    val detection: CalibrationDetectionResult,
    val sharpnessScore: Double,
    val meanLuma: Double,
    val darkPixelFraction: Double,
    val brightPixelFraction: Double,
    val motionScore: Double?,
    val stableMs: Long,
    val boardAreaFraction: Double,
    val centreX: Double,
    val centreY: Double,
    val rollDegrees: Double,
    val yawSkew: Double,
    val pitchSkew: Double,
    val boardClipped: Boolean,
    val poseMatches: Boolean,
    val qualityReady: Boolean,
    val status: String,
) {
    fun toObservation(
        calibrationRunId: String,
        poseId: String,
    ): DualPhoneCalibrationObservation = DualPhoneCalibrationObservation(
        calibrationRunId = calibrationRunId,
        poseId = poseId,
        frameSequence = frameSequence,
        observedAtElapsedMs = SystemClock.elapsedRealtime(),
        boardFound = detection.found,
        cornersFound = detection.cornersFound,
        expectedCorners = detection.expectedCorners,
        sharpnessScore = sharpnessScore,
        meanLuma = meanLuma,
        motionScore = motionScore,
        stableMs = stableMs,
        boardAreaFraction = boardAreaFraction,
        boardClipped = boardClipped,
        poseMatches = poseMatches,
        qualityReady = qualityReady,
        status = status,
    )
}

class DualPhoneCalibrationRealtimeAnalyzer(
    private val detector: CalibrationBoardDetector = OpenCvCalibrationBoardDetector(),
) {
    private var previousGeometry: BoardGeometry? = null
    private var stableSinceElapsedMs: Long? = null
    private var activePoseId: String? = null

    fun reset() {
        previousGeometry = null
        stableSinceElapsedMs = null
        activePoseId = null
    }

    fun analyze(
        frame: CalibrationFrame,
        target: DualPhoneCalibrationPoseTarget,
        settings: CalibrationSettings,
    ): DualPhoneCalibrationRealtimeResult {
        if (activePoseId != target.id) {
            previousGeometry = null
            stableSinceElapsedMs = null
            activePoseId = target.id
        }

        val detection = detector.detect(frame.bitmap, settings)
        val geometry = boardGeometry(detection, settings)
        val light = sampleLightAndSharpness(frame.bitmap)
        val nowMs = SystemClock.elapsedRealtime()
        val motion = if (geometry != null && previousGeometry != null) {
            geometry.motionFrom(requireNotNull(previousGeometry))
        } else {
            null
        }

        if (geometry != null && motion != null && motion <= MAX_MOTION_SCORE) {
            if (stableSinceElapsedMs == null) stableSinceElapsedMs = nowMs
        } else {
            stableSinceElapsedMs = null
        }
        val stableMs = stableSinceElapsedMs?.let { (nowMs - it).coerceAtLeast(0L) } ?: 0L
        previousGeometry = geometry

        val boardFound = detection.found && geometry != null
        val boardClipped = geometry?.clipped ?: false
        val poseMatches = geometry?.matches(target) == true
        val exposureOk = light.meanLuma in MIN_MEAN_LUMA..MAX_MEAN_LUMA &&
            light.darkFraction <= MAX_EXTREME_PIXEL_FRACTION &&
            light.brightFraction <= MAX_EXTREME_PIXEL_FRACTION
        val sharpnessOk = light.sharpness >= MIN_SHARPNESS_SCORE
        val stable = stableMs >= REQUIRED_STABLE_MS
        val qualityReady = boardFound &&
            !boardClipped &&
            exposureOk &&
            sharpnessOk &&
            poseMatches &&
            stable

        val status = when {
            !boardFound -> "Find the complete ChArUco board"
            boardClipped -> "Board is too close to an edge; keep every detected corner inside"
            !exposureOk -> "Improve lighting; avoid deep shadows and glare"
            !sharpnessOk -> "Image is soft; hold the board still and wait for focus"
            !poseMatches -> "Move the board to the requested pose"
            !stable -> "Hold still"
            else -> "Ready on this phone"
        }

        return DualPhoneCalibrationRealtimeResult(
            frameSequence = frame.sequence,
            frameTimestampNs = frame.timestampNs,
            imageProxyRotationDegrees = frame.imageProxyRotationDegrees,
            detection = detection,
            sharpnessScore = light.sharpness,
            meanLuma = light.meanLuma,
            darkPixelFraction = light.darkFraction,
            brightPixelFraction = light.brightFraction,
            motionScore = motion,
            stableMs = stableMs,
            boardAreaFraction = geometry?.areaFraction ?: 0.0,
            centreX = geometry?.centreX ?: 0.0,
            centreY = geometry?.centreY ?: 0.0,
            rollDegrees = geometry?.rollDegrees ?: 0.0,
            yawSkew = geometry?.yawSkew ?: 0.0,
            pitchSkew = geometry?.pitchSkew ?: 0.0,
            boardClipped = boardClipped,
            poseMatches = poseMatches,
            qualityReady = qualityReady,
            status = status,
        )
    }

    private fun boardGeometry(
        detection: CalibrationDetectionResult,
        settings: CalibrationSettings,
    ): BoardGeometry? {
        if (!detection.found) return null
        val points = detection.normalizedCornerPoints
        val ids = detection.charucoIds
        if (points.isEmpty() || points.size != ids.size) return null

        val minX = points.minOf { it.x }.toDouble()
        val maxX = points.maxOf { it.x }.toDouble()
        val minY = points.minOf { it.y }.toDouble()
        val maxY = points.maxOf { it.y }.toDouble()
        val width = (maxX - minX).coerceAtLeast(0.0)
        val height = (maxY - minY).coerceAtLeast(0.0)
        val centreX = (minX + maxX) / 2.0
        val centreY = (minY + maxY) / 2.0
        val area = width * height
        val clipped = minX < EDGE_MARGIN ||
            minY < EDGE_MARGIN ||
            maxX > 1.0 - EDGE_MARGIN ||
            maxY > 1.0 - EDGE_MARGIN

        val boardCols = (settings.charucoSquaresX - 1).coerceAtLeast(1)
        val indexed = ids.zip(points).map { (id, point) ->
            IndexedCorner(
                id = id,
                row = id / boardCols,
                col = id % boardCols,
                x = point.x.toDouble(),
                y = point.y.toDouble(),
            )
        }

        var horizontalX = 0.0
        var horizontalY = 0.0
        var horizontalWeight = 0.0
        for (firstIndex in indexed.indices) {
            for (secondIndex in firstIndex + 1 until indexed.size) {
                val first = indexed[firstIndex]
                val second = indexed[secondIndex]
                if (first.row != second.row) continue
                val columnDelta = second.col - first.col
                if (abs(columnDelta) < 2) continue
                val sign = if (columnDelta > 0) 1.0 else -1.0
                horizontalX += (second.x - first.x) * sign
                horizontalY += (second.y - first.y) * sign
                horizontalWeight += abs(columnDelta).toDouble()
            }
        }
        val roll = if (horizontalWeight > 0.0) {
            Math.toDegrees(atan2(horizontalY, horizontalX))
        } else {
            0.0
        }

        val rowSpans = indexed.groupBy { it.row }
            .mapValues { (_, row) -> row.maxOf { it.x } - row.minOf { it.x } }
        val colSpans = indexed.groupBy { it.col }
            .mapValues { (_, col) -> col.maxOf { it.y } - col.minOf { it.y } }
        val rowMid = indexed.map { it.row }.average()
        val colMid = indexed.map { it.col }.average()
        val topSpan = rowSpans.filterKeys { it < rowMid }.values.averageOrZero()
        val bottomSpan = rowSpans.filterKeys { it > rowMid }.values.averageOrZero()
        val leftSpan = colSpans.filterKeys { it < colMid }.values.averageOrZero()
        val rightSpan = colSpans.filterKeys { it > colMid }.values.averageOrZero()
        val pitchSkew = normalizedDifference(bottomSpan, topSpan)
        val yawSkew = normalizedDifference(rightSpan, leftSpan)

        return BoardGeometry(
            centreX = centreX,
            centreY = centreY,
            areaFraction = area,
            rollDegrees = roll,
            yawSkew = yawSkew,
            pitchSkew = pitchSkew,
            clipped = clipped,
        )
    }

    private fun sampleLightAndSharpness(bitmap: Bitmap): LightSample {
        val sampleWidth = min(SAMPLE_MAX_WIDTH, bitmap.width).coerceAtLeast(1)
        val sampleHeight = min(
            SAMPLE_MAX_HEIGHT,
            max(1, (bitmap.height.toDouble() / bitmap.width * sampleWidth).toInt()),
        )
        val luma = DoubleArray(sampleWidth * sampleHeight)
        var total = 0.0
        var dark = 0
        var bright = 0
        for (y in 0 until sampleHeight) {
            val sourceY = min(bitmap.height - 1, y * bitmap.height / sampleHeight)
            for (x in 0 until sampleWidth) {
                val sourceX = min(bitmap.width - 1, x * bitmap.width / sampleWidth)
                val pixel = bitmap.getPixel(sourceX, sourceY)
                val value =
                    0.2126 * Color.red(pixel) +
                        0.7152 * Color.green(pixel) +
                        0.0722 * Color.blue(pixel)
                val index = y * sampleWidth + x
                luma[index] = value
                total += value
                if (value <= DARK_LUMA) dark += 1
                if (value >= BRIGHT_LUMA) bright += 1
            }
        }

        var laplacianTotal = 0.0
        var laplacianCount = 0
        if (sampleWidth >= 3 && sampleHeight >= 3) {
            for (y in 1 until sampleHeight - 1) {
                for (x in 1 until sampleWidth - 1) {
                    val index = y * sampleWidth + x
                    val laplacian = abs(
                        4.0 * luma[index] -
                            luma[index - 1] -
                            luma[index + 1] -
                            luma[index - sampleWidth] -
                            luma[index + sampleWidth],
                    )
                    laplacianTotal += laplacian
                    laplacianCount += 1
                }
            }
        }
        val count = luma.size.coerceAtLeast(1)
        return LightSample(
            meanLuma = total / count,
            sharpness = if (laplacianCount > 0) {
                laplacianTotal / laplacianCount
            } else {
                0.0
            },
            darkFraction = dark.toDouble() / count,
            brightFraction = bright.toDouble() / count,
        )
    }

    private data class IndexedCorner(
        val id: Int,
        val row: Int,
        val col: Int,
        val x: Double,
        val y: Double,
    )

    private data class BoardGeometry(
        val centreX: Double,
        val centreY: Double,
        val areaFraction: Double,
        val rollDegrees: Double,
        val yawSkew: Double,
        val pitchSkew: Double,
        val clipped: Boolean,
    ) {
        fun motionFrom(previous: BoardGeometry): Double =
            hypot(centreX - previous.centreX, centreY - previous.centreY) * 4.0 +
                abs(areaFraction - previous.areaFraction) * 2.0 +
                abs(rollDegrees - previous.rollDegrees) / 90.0 +
                abs(yawSkew - previous.yawSkew) +
                abs(pitchSkew - previous.pitchSkew)

        fun matches(target: DualPhoneCalibrationPoseTarget): Boolean {
            if (
                abs(centreX - target.centreX.toDouble()) >
                target.centreToleranceX.toDouble()
            ) return false
            if (
                abs(centreY - target.centreY.toDouble()) >
                target.centreToleranceY.toDouble()
            ) return false
            if (areaFraction !in target.minAreaFraction.toDouble()..target.maxAreaFraction.toDouble()) {
                return false
            }
            if (!signedThreshold(rollDegrees, target.minAbsRollDegrees.toDouble(), target.rollSign)) {
                return false
            }
            if (!signedThreshold(yawSkew, target.minAbsYawSkew.toDouble(), target.yawSign)) {
                return false
            }
            if (!signedThreshold(pitchSkew, target.minAbsPitchSkew.toDouble(), target.pitchSign)) {
                return false
            }
            return true
        }

        private fun signedThreshold(value: Double, minimum: Double, sign: Int): Boolean {
            if (minimum <= 0.0) return true
            if (abs(value) < minimum) return false
            return sign == 0 || (sign > 0 && value > 0.0) || (sign < 0 && value < 0.0)
        }
    }

    private data class LightSample(
        val meanLuma: Double,
        val sharpness: Double,
        val darkFraction: Double,
        val brightFraction: Double,
    )

    companion object {
        private const val SAMPLE_MAX_WIDTH = 160
        private const val SAMPLE_MAX_HEIGHT = 120
        private const val EDGE_MARGIN = 0.025
        private const val DARK_LUMA = 18.0
        private const val BRIGHT_LUMA = 242.0
        private const val MIN_MEAN_LUMA = 35.0
        private const val MAX_MEAN_LUMA = 220.0
        private const val MAX_EXTREME_PIXEL_FRACTION = 0.45
        private const val MIN_SHARPNESS_SCORE = 5.5
        private const val MAX_MOTION_SCORE = 0.075
        private const val REQUIRED_STABLE_MS = 450L
    }
}

private fun Collection<Double>.averageOrZero(): Double =
    if (isEmpty()) 0.0 else average()

private fun normalizedDifference(first: Double, second: Double): Double {
    val denominator = first + second
    return if (denominator <= 1e-9) 0.0 else (first - second) / denominator
}
