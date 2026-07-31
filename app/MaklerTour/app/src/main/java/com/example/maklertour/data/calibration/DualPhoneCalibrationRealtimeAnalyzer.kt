package com.maklertour.data.calibration

import android.graphics.Bitmap
import android.graphics.Color
import android.os.SystemClock
import com.maklertour.data.dualphone.DualPhoneCalibrationObservation
import com.maklertour.data.dualphone.DualPhoneCalibrationPoseTarget
import com.maklertour.data.dualphone.DualPhoneCalibrationStage
import com.maklertour.data.phonecamera.CalibrationFrame
import com.maklertour.data.rig.CalibrationSettings
import kotlin.math.abs
import kotlin.math.atan2
import kotlin.math.hypot
import kotlin.math.max
import kotlin.math.min
import java.util.ArrayDeque

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
    val noveltyScore: Double,
    val coveragePercent: Int,
    val acceptedGeometryCount: Int,
    val captureProgress: Float,
    val guidance: String,
    val status: String,
) {
    fun toObservation(
        calibrationRunId: String,
        poseId: String,
        stage: DualPhoneCalibrationStage =
            DualPhoneCalibrationStage.MASTER_INTRINSICS,
    ): DualPhoneCalibrationObservation = DualPhoneCalibrationObservation(
        calibrationRunId = calibrationRunId,
        calibrationStage = stage,
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

/**
 * Realtime ChArUco quality and automatic diversity gate.
 *
 * Pose IDs are retained only as synchronization slots between Master and Slave.
 * Intrinsics capture no longer requires the operator to reproduce a named pose.
 * A frame is accepted when it is sharp, exposed, stable and sufficiently different
 * from already accepted board geometries.
 */
class DualPhoneCalibrationRealtimeAnalyzer(
    private val detector: CalibrationBoardDetector = OpenCvCalibrationBoardDetector(),
) {
    private var previousGeometry: BoardGeometry? = null
    private var stableSinceElapsedMs: Long? = null
    private var activePoseId: String? = null
    private var lastReadyGeometry: BoardGeometry? = null
    private val acceptedGeometries = ArrayDeque<BoardGeometry>()

    fun reset() {
        previousGeometry = null
        stableSinceElapsedMs = null
        activePoseId = null
        lastReadyGeometry = null
        acceptedGeometries.clear()
    }

    fun analyze(
        frame: CalibrationFrame,
        target: DualPhoneCalibrationPoseTarget,
        settings: CalibrationSettings,
    ): DualPhoneCalibrationRealtimeResult {
        if (activePoseId != target.id) {
            lastReadyGeometry?.let { accepted ->
                acceptedGeometries.addLast(accepted)
                while (acceptedGeometries.size > MAX_ACCEPTED_GEOMETRIES) {
                    acceptedGeometries.removeFirst()
                }
            }
            previousGeometry = null
            stableSinceElapsedMs = null
            lastReadyGeometry = null
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
        val exposureOk = light.meanLuma in MIN_MEAN_LUMA..MAX_MEAN_LUMA &&
            light.darkFraction <= MAX_EXTREME_PIXEL_FRACTION &&
            light.brightFraction <= MAX_EXTREME_PIXEL_FRACTION
        val sharpnessOk = light.sharpness >= MIN_SHARPNESS_SCORE
        val stable = stableMs >= REQUIRED_STABLE_MS
        val noveltyScore = geometry?.noveltyAgainst(acceptedGeometries) ?: 0.0
        val novel = acceptedGeometries.isEmpty() || noveltyScore >= MIN_NOVELTY_SCORE
        val qualityReady = boardFound &&
            !boardClipped &&
            exposureOk &&
            sharpnessOk &&
            novel &&
            stable

        if (qualityReady && geometry != null) {
            lastReadyGeometry = geometry
        }

        val coverageGeometries = buildList {
            addAll(acceptedGeometries)
            if (geometry != null && boardFound && !boardClipped) add(geometry)
        }
        val coveragePercent = coveragePercent(coverageGeometries)
        val guidance = coverageGuidance(coverageGeometries)
        val captureProgress = (stableMs.toFloat() / REQUIRED_STABLE_MS.toFloat())
            .coerceIn(0f, 1f)

        val status = when {
            !boardFound -> "Покажите камере всю ChArUco-доску"
            boardClipped -> "Отодвиньте доску от края — все углы должны быть в кадре"
            !exposureOk -> "Измените освещение — уберите тень или блики"
            !sharpnessOk -> "Подождите фокус или держите доску неподвижнее"
            !novel -> "Измените ракурс: сдвиньте, приблизьте или наклоните доску"
            !stable && motion != null && motion > MAX_MOTION_SCORE ->
                "Замедлите движение доски"
            !stable -> "Замрите на мгновение — снимок будет сделан автоматически"
            else -> "Хороший новый ракурс — автоматический снимок"
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
            poseMatches = novel,
            qualityReady = qualityReady,
            noveltyScore = noveltyScore,
            coveragePercent = coveragePercent,
            acceptedGeometryCount = acceptedGeometries.size,
            captureProgress = captureProgress,
            guidance = guidance,
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

    private fun coveragePercent(geometries: Collection<BoardGeometry>): Int {
        if (geometries.isEmpty()) return 0
        val positionBins = geometries.map { geometry ->
            val xBin = (geometry.centreX * 3.0).toInt().coerceIn(0, 2)
            val yBin = (geometry.centreY * 3.0).toInt().coerceIn(0, 2)
            yBin * 3 + xBin
        }.toSet().size
        val sizeBins = geometries.map { geometry ->
            when {
                geometry.areaFraction < 0.12 -> 0
                geometry.areaFraction < 0.30 -> 1
                else -> 2
            }
        }.toSet().size
        val angleBins = buildSet {
            geometries.forEach { geometry ->
                if (abs(geometry.yawSkew) < 0.05 &&
                    abs(geometry.pitchSkew) < 0.05 &&
                    abs(geometry.rollDegrees) < 8.0
                ) add("front")
                if (geometry.yawSkew >= 0.05) add("yaw+")
                if (geometry.yawSkew <= -0.05) add("yaw-")
                if (geometry.pitchSkew >= 0.05) add("pitch+")
                if (geometry.pitchSkew <= -0.05) add("pitch-")
                if (geometry.rollDegrees >= 8.0) add("roll+")
                if (geometry.rollDegrees <= -8.0) add("roll-")
            }
        }.size
        val score =
            positionBins / 9.0 * 45.0 +
                sizeBins / 3.0 * 20.0 +
                angleBins / 7.0 * 35.0
        return score.toInt().coerceIn(0, 100)
    }

    private fun coverageGuidance(geometries: Collection<BoardGeometry>): String {
        if (geometries.isEmpty()) return "Покажите доску и плавно меняйте её положение"
        val positionBins = geometries.map { geometry ->
            (geometry.centreY * 3.0).toInt().coerceIn(0, 2) * 3 +
                (geometry.centreX * 3.0).toInt().coerceIn(0, 2)
        }.toSet().size
        val sizeBins = geometries.map { geometry ->
            when {
                geometry.areaFraction < 0.12 -> 0
                geometry.areaFraction < 0.30 -> 1
                else -> 2
            }
        }.toSet().size
        val tilted = geometries.count { geometry ->
            abs(geometry.yawSkew) >= 0.05 ||
                abs(geometry.pitchSkew) >= 0.05 ||
                abs(geometry.rollDegrees) >= 8.0
        }
        return when {
            positionBins < 5 -> "Перемещайте доску к другим краям и углам кадра"
            sizeBins < 3 -> "Покажите доску ближе и дальше от камеры"
            tilted < 5 -> "Добавьте наклоны и повороты доски"
            else -> "Продолжайте плавно менять ракурс — хорошие кадры снимаются сами"
        }
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

        fun noveltyAgainst(previous: Collection<BoardGeometry>): Double {
            if (previous.isEmpty()) return 1.0
            return previous.minOf { accepted ->
                hypot(centreX - accepted.centreX, centreY - accepted.centreY) * 2.2 +
                    abs(areaFraction - accepted.areaFraction) * 1.8 +
                    angleDistanceDegrees(rollDegrees, accepted.rollDegrees) / 90.0 * 0.35 +
                    abs(yawSkew - accepted.yawSkew) * 0.8 +
                    abs(pitchSkew - accepted.pitchSkew) * 0.8
            }
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
        private const val MAX_MOTION_SCORE = 0.12
        private const val REQUIRED_STABLE_MS = 220L
        private const val MIN_NOVELTY_SCORE = 0.10
        private const val MAX_ACCEPTED_GEOMETRIES = 24
    }
}

private fun angleDistanceDegrees(first: Double, second: Double): Double {
    val raw = abs(first - second) % 360.0
    return min(raw, 360.0 - raw)
}

private fun Collection<Double>.averageOrZero(): Double =
    if (isEmpty()) 0.0 else average()

private fun normalizedDifference(first: Double, second: Double): Double {
    val denominator = first + second
    return if (denominator <= 1e-9) 0.0 else (first - second) / denominator
}
