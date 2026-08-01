package com.example.maklertour.data.dualphone

import java.io.Closeable
import java.util.ArrayDeque
import java.util.Arrays
import org.opencv.calib3d.StereoSGBM
import org.opencv.core.Core
import org.opencv.core.CvType
import org.opencv.core.Mat
import org.opencv.core.MatOfByte
import org.opencv.core.MatOfInt
import org.opencv.core.Scalar
import org.opencv.core.Size
import org.opencv.imgcodecs.Imgcodecs
import org.opencv.imgproc.Imgproc
import kotlin.math.abs
import kotlin.math.max
import kotlin.math.roundToInt

enum class DualPhoneDepthTemporalMode {
    STATIC,
    MOVING,
    RESET,
}

private enum class DualPhoneDenseSceneProfile(
    val leftRightTolerancePx: Float,
    val textureThreshold: Int,
) {
    TEXTURED(3.0f, 5),
    LOW_TEXTURE(4.5f, 2),
    MOVING(3.5f, 4),
    STATIC_REFINE(2.5f, 5),
}

data class DualPhoneFilteredDepthResult(
    val rawDepthPreviewJpeg: ByteArray,
    val filteredDepthPreviewJpeg: ByteArray,
    val strictDepthPreviewJpeg: ByteArray,
    val confidencePreviewJpeg: ByteArray,
    val rawValidPercent: Double,
    val filteredValidPercent: Double,
    val denseCoveragePercent: Double,
    val stableCoveragePercent: Double,
    val highConfidencePercent: Double,
    val medianDepthMeters: Double?,
    val depthJitterMeters: Double?,
    val motionScorePercent: Double,
    val temporalMode: DualPhoneDepthTemporalMode,
    val leftRightAcceptedPercent: Double,
    val denseLeftRightAcceptedPercent: Double,
    val textureAcceptedPercent: Double,
    val morphologyAcceptedPercent: Double,
)

/**
 * LM02.4 motion-aware bounded filter for rectified stereo input.
 *
 * CLAHE reduces exposure mismatch before StereoSGBM. Optional reverse disparity
 * applies a left-right consistency gate. Motion controls the finite temporal
 * history: STATIC keeps 3-of-5 consensus, MOVING uses 2-of-2/3 and RESET publishes
 * the current spatial map without dragging stale pixels across the scene.
 */
class DualPhoneFilteredDepthEngine : Closeable {
    private val temporalDisparities = ArrayDeque<Mat>()
    private val medianDepthHistory = ArrayDeque<Double>()
    private var previousMotionFrame: Mat? = null
    private var activeRows = 0
    private var activeColumns = 0
    private var activeDenseSceneProfile = DualPhoneDenseSceneProfile.TEXTURED
    private var pendingDenseSceneProfile = DualPhoneDenseSceneProfile.TEXTURED
    private var pendingDenseSceneFrames = 0

    @Synchronized
    fun process(
        grayMaster: Mat,
        graySlave: Mat,
        focalPx: Double,
        baselineMm: Double,
        enableLeftRightCheck: Boolean,
    ): DualPhoneFilteredDepthResult {
        require(!grayMaster.empty() && !graySlave.empty())
        require(grayMaster.size() == graySlave.size())
        require(focalPx > 0.0 && baselineMm > 0.0)
        if (grayMaster.rows() != activeRows || grayMaster.cols() != activeColumns) {
            resetHistoriesForSize(grayMaster.rows(), grayMaster.cols())
        }

        val normalizedMaster = Mat()
        val normalizedSlave = Mat()
        val disparity16 = Mat()
        val rightDisparity16 = Mat()
        val rawDisparity = Mat()
        val rightDisparity = Mat()
        val spatialDisparity = Mat()
        val rawMinMask = Mat()
        val rawMaxMask = Mat()
        val rawValidMask = Mat()
        val leftRightMask = Mat()
        val denseLeftRightMask = Mat()
        val consistentValidMask = Mat()
        val denseConsistentValidMask = Mat()
        val gradientX16 = Mat()
        val gradientY16 = Mat()
        val gradientX8 = Mat()
        val gradientY8 = Mat()
        val texture = Mat()
        val textureMask = Mat()
        val denseTextureMask = Mat()
        val strongTextureMask = Mat()
        val filteredMask = Mat()
        val denseFilteredMask = Mat()
        val openedMask = Mat()
        val closedMask = Mat()
        val denseClosedMask = Mat()
        val invalidMask = Mat()
        val denseInvalidMask = Mat()
        val denseDisparity = Mat()
        val kernel = Imgproc.getStructuringElement(
            Imgproc.MORPH_ELLIPSE,
            Size(3.0, 3.0),
        )
        val stableDisparity = Mat()
        val stableMask = Mat()
        val highConfidenceMask = Mat()
        val rawHeatmap = Mat()
        val filteredHeatmap = Mat()
        val strictHeatmap = Mat()
        val confidence = Mat.zeros(
            grayMaster.rows(),
            grayMaster.cols(),
            CvType.CV_8UC3,
        )
        val claheMaster = Imgproc.createCLAHE(CLAHE_CLIP_LIMIT, CLAHE_TILE_GRID)
        val claheSlave = Imgproc.createCLAHE(CLAHE_CLIP_LIMIT, CLAHE_TILE_GRID)

        try {
            claheMaster.apply(grayMaster, normalizedMaster)
            claheSlave.apply(graySlave, normalizedSlave)
            val motionScore = calculateMotionScore(normalizedMaster)
            val temporalMode = temporalMode(motionScore)

            val numDisparities = chooseNumDisparities(normalizedMaster.cols())
            val stereo = createStereo(minDisparity = 0, numDisparities = numDisparities)
            try {
                stereo.compute(normalizedMaster, normalizedSlave, disparity16)
            } finally {
                stereo.clear()
            }
            disparity16.convertTo(rawDisparity, CvType.CV_32F, 1.0 / 16.0)

            Core.compare(
                rawDisparity,
                Scalar(MIN_VALID_DISPARITY),
                rawMinMask,
                Core.CMP_GT,
            )
            Core.compare(
                rawDisparity,
                Scalar((numDisparities - 1).toDouble()),
                rawMaxMask,
                Core.CMP_LT,
            )
            Core.bitwise_and(rawMinMask, rawMaxMask, rawValidMask)

            val rawValidCount = Core.countNonZero(rawValidMask)
            val rawCoveragePercent = if (grayMaster.total() <= 0L) {
                0.0
            } else {
                rawValidCount * 100.0 / grayMaster.total().toDouble()
            }
            val denseSceneProfile = stabilizeDenseSceneProfile(
                chooseDenseSceneProfile(motionScore, rawCoveragePercent),
            )
            if (enableLeftRightCheck) {
                val reverse = createStereo(
                    minDisparity = -numDisparities,
                    numDisparities = numDisparities,
                )
                try {
                    reverse.compute(normalizedSlave, normalizedMaster, rightDisparity16)
                } finally {
                    reverse.clear()
                }
                rightDisparity16.convertTo(
                    rightDisparity,
                    CvType.CV_32F,
                    1.0 / 16.0,
                )
                createLeftRightConsistencyMask(
                    leftDisparity = rawDisparity,
                    rightDisparity = rightDisparity,
                    output = leftRightMask,
                    tolerancePx = STRICT_LEFT_RIGHT_TOLERANCE_PX,
                )
                createLeftRightConsistencyMask(
                    leftDisparity = rawDisparity,
                    rightDisparity = rightDisparity,
                    output = denseLeftRightMask,
                    tolerancePx = denseSceneProfile.leftRightTolerancePx,
                )
                Core.bitwise_and(rawValidMask, leftRightMask, consistentValidMask)
                Core.bitwise_and(rawValidMask, denseLeftRightMask, denseConsistentValidMask)
            } else {
                rawValidMask.copyTo(consistentValidMask)
                rawValidMask.copyTo(denseConsistentValidMask)
            }
            val strictConsistentCount = Core.countNonZero(consistentValidMask)
            val denseConsistentCount = Core.countNonZero(denseConsistentValidMask)
            val leftRightAcceptedPercent = when {
                !enableLeftRightCheck -> 100.0
                rawValidCount == 0 -> 0.0
                else -> strictConsistentCount * 100.0 / rawValidCount
            }
            val denseLeftRightAcceptedPercent = when {
                !enableLeftRightCheck -> 100.0
                rawValidCount == 0 -> 0.0
                else -> denseConsistentCount * 100.0 / rawValidCount
            }

            Imgproc.medianBlur(rawDisparity, spatialDisparity, 5)
            Imgproc.Sobel(
                normalizedMaster,
                gradientX16,
                CvType.CV_16S,
                1,
                0,
                3,
            )
            Imgproc.Sobel(
                normalizedMaster,
                gradientY16,
                CvType.CV_16S,
                0,
                1,
                3,
            )
            Core.convertScaleAbs(gradientX16, gradientX8)
            Core.convertScaleAbs(gradientY16, gradientY8)
            Core.addWeighted(gradientX8, 0.5, gradientY8, 0.5, 0.0, texture)
            Core.compare(
                texture,
                Scalar(MIN_TEXTURE_GRADIENT.toDouble()),
                textureMask,
                Core.CMP_GT,
            )
            Core.compare(
                texture,
                Scalar(denseSceneProfile.textureThreshold.toDouble()),
                denseTextureMask,
                Core.CMP_GT,
            )
            Core.compare(
                texture,
                Scalar(HIGH_TEXTURE_GRADIENT.toDouble()),
                strongTextureMask,
                Core.CMP_GT,
            )
            Core.bitwise_and(consistentValidMask, textureMask, filteredMask)
            Core.bitwise_and(
                denseConsistentValidMask,
                denseTextureMask,
                denseFilteredMask,
            )
            Imgproc.morphologyEx(
                denseFilteredMask,
                denseClosedMask,
                Imgproc.MORPH_CLOSE,
                kernel,
            )
            spatialDisparity.copyTo(denseDisparity)
            Core.bitwise_not(denseClosedMask, denseInvalidMask)
            denseDisparity.setTo(Scalar(0.0), denseInvalidMask)
            Imgproc.morphologyEx(
                filteredMask,
                openedMask,
                Imgproc.MORPH_OPEN,
                kernel,
            )
            Imgproc.morphologyEx(
                openedMask,
                closedMask,
                Imgproc.MORPH_CLOSE,
                kernel,
            )
            Core.bitwise_not(closedMask, invalidMask)
            spatialDisparity.setTo(Scalar(0.0), invalidMask)

            prepareTemporalHistory(temporalMode)
            pushTemporal(spatialDisparity)
            val temporal = temporalMedian(
                rows = spatialDisparity.rows(),
                columns = spatialDisparity.cols(),
                mode = temporalMode,
            )
            stableDisparity.create(
                spatialDisparity.rows(),
                spatialDisparity.cols(),
                CvType.CV_32F,
            )
            stableDisparity.put(0, 0, temporal.disparity)
            stableMask.create(
                spatialDisparity.rows(),
                spatialDisparity.cols(),
                CvType.CV_8U,
            )
            stableMask.put(0, 0, temporal.mask)
            Core.bitwise_and(stableMask, strongTextureMask, highConfidenceMask)

            createMetricHeatmap(
                disparity = rawDisparity,
                mask = rawValidMask,
                focalPx = focalPx,
                baselineMm = baselineMm,
                output = rawHeatmap,
            )
            createMetricHeatmap(
                disparity = denseDisparity,
                mask = denseClosedMask,
                focalPx = focalPx,
                baselineMm = baselineMm,
                output = filteredHeatmap,
            )
            createMetricHeatmap(
                disparity = stableDisparity,
                mask = stableMask,
                focalPx = focalPx,
                baselineMm = baselineMm,
                output = strictHeatmap,
            )

            confidence.setTo(LOW_CONFIDENCE_BGR, rawValidMask)
            confidence.setTo(MEDIUM_CONFIDENCE_BGR, denseClosedMask)
            confidence.setTo(HIGH_CONFIDENCE_BGR, highConfidenceMask)

            val metrics = depthMetrics(
                disparity = stableDisparity,
                mask = stableMask,
                focalPx = focalPx,
                baselineMm = baselineMm,
            )
            val jitter = updateDepthJitter(metrics.medianDepthMeters)
            val totalPixels = grayMaster.rows().toDouble() * grayMaster.cols().toDouble()
            val denseTextureCount = Core.countNonZero(denseFilteredMask)
            val denseMorphologyCount = Core.countNonZero(denseClosedMask)

            return DualPhoneFilteredDepthResult(
                rawDepthPreviewJpeg = encodeJpeg(rawHeatmap),
                filteredDepthPreviewJpeg = encodeJpeg(filteredHeatmap),
                strictDepthPreviewJpeg = encodeJpeg(strictHeatmap),
                confidencePreviewJpeg = encodeJpeg(confidence),
                rawValidPercent = percent(rawValidMask, totalPixels),
                filteredValidPercent = percent(closedMask, totalPixels),
                denseCoveragePercent = percent(denseClosedMask, totalPixels),
                stableCoveragePercent = percent(stableMask, totalPixels),
                highConfidencePercent = percent(highConfidenceMask, totalPixels),
                medianDepthMeters = metrics.medianDepthMeters,
                depthJitterMeters = jitter,
                motionScorePercent = motionScore,
                temporalMode = temporalMode,
                leftRightAcceptedPercent = leftRightAcceptedPercent,
                denseLeftRightAcceptedPercent = denseLeftRightAcceptedPercent,
                textureAcceptedPercent = ratioPercent(denseTextureCount, denseConsistentCount),
                morphologyAcceptedPercent = ratioPercent(denseMorphologyCount, denseTextureCount),
            )
        } finally {
            claheMaster.collectGarbage()
            claheSlave.collectGarbage()
            listOf(
                normalizedMaster,
                normalizedSlave,
                disparity16,
                rightDisparity16,
                rawDisparity,
                rightDisparity,
                spatialDisparity,
                rawMinMask,
                rawMaxMask,
                rawValidMask,
                leftRightMask,
                denseLeftRightMask,
                consistentValidMask,
                denseConsistentValidMask,
                gradientX16,
                gradientY16,
                gradientX8,
                gradientY8,
                texture,
                textureMask,
                denseTextureMask,
                strongTextureMask,
                filteredMask,
                denseFilteredMask,
                openedMask,
                closedMask,
                denseClosedMask,
                invalidMask,
                denseInvalidMask,
                denseDisparity,
                kernel,
                stableDisparity,
                stableMask,
                highConfidenceMask,
                rawHeatmap,
                filteredHeatmap,
                strictHeatmap,
                confidence,
            ).forEach { it.release() }
        }
    }

    @Synchronized
    fun reset() {
        clearTemporalDisparities()
        medianDepthHistory.clear()
        previousMotionFrame?.release()
        previousMotionFrame = null
        activeRows = 0
        activeColumns = 0
        activeDenseSceneProfile = DualPhoneDenseSceneProfile.TEXTURED
        pendingDenseSceneProfile = DualPhoneDenseSceneProfile.TEXTURED
        pendingDenseSceneFrames = 0
    }

    override fun close() {
        reset()
    }

    private fun createStereo(
        minDisparity: Int,
        numDisparities: Int,
    ): StereoSGBM {
        val blockSize = SGBM_BLOCK_SIZE
        return StereoSGBM.create(
            minDisparity,
            numDisparities,
            blockSize,
            8 * blockSize * blockSize,
            32 * blockSize * blockSize,
            1,
            31,
            10,
            80,
            2,
            StereoSGBM.MODE_SGBM_3WAY,
        )
    }

    private fun resetHistoriesForSize(rows: Int, columns: Int) {
        clearTemporalDisparities()
        medianDepthHistory.clear()
        previousMotionFrame?.release()
        previousMotionFrame = null
        activeRows = rows
        activeColumns = columns
    }

    private fun calculateMotionScore(current: Mat): Double {
        val reduced = Mat()
        val difference = Mat()
        try {
            Imgproc.resize(
                current,
                reduced,
                MOTION_SAMPLE_SIZE,
                0.0,
                0.0,
                Imgproc.INTER_AREA,
            )
            val previous = previousMotionFrame
            val score = if (previous == null || previous.size() != reduced.size()) {
                0.0
            } else {
                Core.absdiff(previous, reduced, difference)
                Core.mean(difference).`val`[0] * 100.0 / 255.0
            }
            previousMotionFrame?.release()
            previousMotionFrame = reduced.clone()
            return score
        } finally {
            reduced.release()
            difference.release()
        }
    }

    private fun chooseDenseSceneProfile(
        motionScorePercent: Double,
        rawCoveragePercent: Double,
    ): DualPhoneDenseSceneProfile = when {
        motionScorePercent >= RESET_MOTION_PERCENT ->
            DualPhoneDenseSceneProfile.MOVING
        rawCoveragePercent < LOW_TEXTURE_RAW_PERCENT ->
            DualPhoneDenseSceneProfile.LOW_TEXTURE
        motionScorePercent <= STATIC_REFINE_MOTION_PERCENT &&
            rawCoveragePercent >= STATIC_REFINE_RAW_PERCENT ->
            DualPhoneDenseSceneProfile.STATIC_REFINE
        else -> DualPhoneDenseSceneProfile.TEXTURED
    }

    private fun stabilizeDenseSceneProfile(
        candidate: DualPhoneDenseSceneProfile,
    ): DualPhoneDenseSceneProfile {
        if (candidate == DualPhoneDenseSceneProfile.MOVING) {
            activeDenseSceneProfile = candidate
            pendingDenseSceneProfile = candidate
            pendingDenseSceneFrames = 0
            return activeDenseSceneProfile
        }
        if (candidate == activeDenseSceneProfile) {
            pendingDenseSceneProfile = candidate
            pendingDenseSceneFrames = 0
            return activeDenseSceneProfile
        }
        if (candidate != pendingDenseSceneProfile) {
            pendingDenseSceneProfile = candidate
            pendingDenseSceneFrames = 1
        } else {
            pendingDenseSceneFrames += 1
        }
        if (pendingDenseSceneFrames >= DENSE_PROFILE_HYSTERESIS_FRAMES) {
            activeDenseSceneProfile = candidate
            pendingDenseSceneFrames = 0
        }
        return activeDenseSceneProfile
    }

    private fun temporalMode(motionScorePercent: Double): DualPhoneDepthTemporalMode =
        when {
            motionScorePercent >= RESET_MOTION_PERCENT ->
                DualPhoneDepthTemporalMode.RESET
            motionScorePercent >= MOVING_MOTION_PERCENT ->
                DualPhoneDepthTemporalMode.MOVING
            else -> DualPhoneDepthTemporalMode.STATIC
        }

    private fun prepareTemporalHistory(mode: DualPhoneDepthTemporalMode) {
        when (mode) {
            DualPhoneDepthTemporalMode.RESET -> clearTemporalDisparities()
            DualPhoneDepthTemporalMode.MOVING -> {
                while (temporalDisparities.size > 1) {
                    temporalDisparities.removeFirst().release()
                }
            }
            DualPhoneDepthTemporalMode.STATIC -> Unit
        }
    }

    private fun pushTemporal(disparity: Mat) {
        temporalDisparities.addLast(disparity.clone())
        while (temporalDisparities.size > TEMPORAL_WINDOW_FRAMES) {
            temporalDisparities.removeFirst().release()
        }
    }

    private fun clearTemporalDisparities() {
        temporalDisparities.forEach { it.release() }
        temporalDisparities.clear()
    }

    private fun temporalMedian(
        rows: Int,
        columns: Int,
        mode: DualPhoneDepthTemporalMode,
    ): TemporalResult {
        val total = rows * columns
        val history = temporalDisparities.map { mat ->
            FloatArray(total).also { values -> mat.get(0, 0, values) }
        }
        val requiredVotes = when (mode) {
            DualPhoneDepthTemporalMode.RESET -> 1
            DualPhoneDepthTemporalMode.MOVING -> minOf(2, history.size)
            DualPhoneDepthTemporalMode.STATIC -> when (history.size) {
                0, 1 -> 1
                2 -> 2
                else -> MIN_TEMPORAL_VOTES
            }
        }
        val output = FloatArray(total)
        val mask = ByteArray(total)
        val candidates = FloatArray(TEMPORAL_WINDOW_FRAMES)

        for (index in 0 until total) {
            var count = 0
            for (frame in history) {
                val value = frame[index]
                if (value > MIN_VALID_DISPARITY.toFloat()) {
                    candidates[count] = value
                    count += 1
                }
            }
            if (count < requiredVotes) continue
            Arrays.sort(candidates, 0, count)
            val median = candidates[count / 2]
            val spread = candidates[count - 1] - candidates[0]
            val allowedSpread = max(
                MAX_TEMPORAL_SPREAD_PX,
                median * MAX_TEMPORAL_SPREAD_RATIO,
            )
            if (mode == DualPhoneDepthTemporalMode.RESET || spread <= allowedSpread) {
                output[index] = median
                mask[index] = 0xff.toByte()
            }
        }
        return TemporalResult(output, mask)
    }

    private fun createLeftRightConsistencyMask(
        leftDisparity: Mat,
        rightDisparity: Mat,
        output: Mat,
        tolerancePx: Float,
    ) {
        val rows = leftDisparity.rows()
        val columns = leftDisparity.cols()
        val total = rows * columns
        val left = FloatArray(total)
        val right = FloatArray(total)
        val mask = ByteArray(total)
        leftDisparity.get(0, 0, left)
        rightDisparity.get(0, 0, right)

        for (row in 0 until rows) {
            val rowOffset = row * columns
            for (column in 0 until columns) {
                val index = rowOffset + column
                val leftValue = left[index]
                if (leftValue <= MIN_VALID_DISPARITY.toFloat()) continue
                val rightColumn = (column - leftValue).roundToInt()
                if (rightColumn !in 0 until columns) continue
                val rightValue = right[rowOffset + rightColumn]
                if (
                    rightValue < -MIN_VALID_DISPARITY.toFloat() &&
                    abs(leftValue + rightValue) <= tolerancePx
                ) {
                    mask[index] = 0xff.toByte()
                }
            }
        }
        output.create(rows, columns, CvType.CV_8U)
        output.put(0, 0, mask)
    }

    private fun createMetricHeatmap(
        disparity: Mat,
        mask: Mat,
        focalPx: Double,
        baselineMm: Double,
        output: Mat,
    ) {
        val total = disparity.rows() * disparity.cols()
        val disparityValues = FloatArray(total)
        val maskValues = ByteArray(total)
        val bgr = ByteArray(total * 3)
        disparity.get(0, 0, disparityValues)
        mask.get(0, 0, maskValues)

        for (index in 0 until total) {
            if (maskValues[index].toInt() and 0xff == 0) continue
            val disparityPx = disparityValues[index].toDouble()
            if (disparityPx <= MIN_VALID_DISPARITY) continue
            val meters = focalPx * baselineMm / disparityPx / 1_000.0
            writeMetricColor(bgr, index * 3, meters)
        }
        output.create(disparity.rows(), disparity.cols(), CvType.CV_8UC3)
        output.put(0, 0, bgr)
    }

    private fun writeMetricColor(
        output: ByteArray,
        offset: Int,
        meters: Double,
    ) {
        if (!meters.isFinite()) return
        val clamped = meters.coerceIn(METRIC_NEAR_METERS, METRIC_FAR_METERS)
        var upper = 1
        while (
            upper < METRIC_COLOR_STOPS.size &&
            clamped > METRIC_COLOR_STOPS[upper].meters
        ) {
            upper += 1
        }
        upper = upper.coerceAtMost(METRIC_COLOR_STOPS.lastIndex)
        val lower = (upper - 1).coerceAtLeast(0)
        val from = METRIC_COLOR_STOPS[lower]
        val to = METRIC_COLOR_STOPS[upper]
        val span = (to.meters - from.meters).coerceAtLeast(0.0001)
        val ratio = ((clamped - from.meters) / span).coerceIn(0.0, 1.0)
        output[offset] = interpolateColor(from.blue, to.blue, ratio)
        output[offset + 1] = interpolateColor(from.green, to.green, ratio)
        output[offset + 2] = interpolateColor(from.red, to.red, ratio)
    }

    private fun interpolateColor(from: Int, to: Int, ratio: Double): Byte =
        (from + (to - from) * ratio)
            .roundToInt()
            .coerceIn(0, 255)
            .toByte()

    private data class MetricColorStop(
        val meters: Double,
        val blue: Int,
        val green: Int,
        val red: Int,
    )

    private fun depthMetrics(
        disparity: Mat,
        mask: Mat,
        focalPx: Double,
        baselineMm: Double,
    ): DepthMetrics {
        val total = disparity.rows() * disparity.cols()
        val disparityValues = FloatArray(total)
        val maskValues = ByteArray(total)
        disparity.get(0, 0, disparityValues)
        mask.get(0, 0, maskValues)
        val depths = ArrayList<Double>()

        var index = 0
        while (index < total) {
            if (maskValues[index].toInt() and 0xff != 0) {
                val d = disparityValues[index].toDouble()
                if (d > MIN_VALID_DISPARITY) {
                    val meters = focalPx * baselineMm / d / 1_000.0
                    if (meters in MIN_DEPTH_METERS..MAX_DEPTH_METERS) {
                        depths += meters
                    }
                }
            }
            index += METRIC_SAMPLE_STEP
        }
        depths.sort()
        return DepthMetrics(
            medianDepthMeters = depths.takeIf { it.isNotEmpty() }
                ?.get(depths.size / 2),
        )
    }

    private fun updateDepthJitter(medianDepthMeters: Double?): Double? {
        if (medianDepthMeters == null || !medianDepthMeters.isFinite()) return null
        medianDepthHistory.addLast(medianDepthMeters)
        while (medianDepthHistory.size > TEMPORAL_WINDOW_FRAMES) {
            medianDepthHistory.removeFirst()
        }
        if (medianDepthHistory.size < 2) return 0.0
        return medianDepthHistory.maxOrNull()!! - medianDepthHistory.minOrNull()!!
    }

    private fun percent(mask: Mat, totalPixels: Double): Double =
        if (totalPixels <= 0.0) 0.0 else Core.countNonZero(mask) * 100.0 / totalPixels

    private fun ratioPercent(numerator: Int, denominator: Int): Double =
        if (denominator <= 0) 0.0 else {
            numerator.toDouble() * 100.0 / denominator.toDouble()
        }

    private fun encodeJpeg(mat: Mat): ByteArray {
        val output = MatOfByte()
        val parameters = MatOfInt(
            Imgcodecs.IMWRITE_JPEG_QUALITY,
            OUTPUT_JPEG_QUALITY,
        )
        return try {
            check(Imgcodecs.imencode(".jpg", mat, output, parameters)) {
                "Filtered depth JPEG encode failed"
            }
            output.toArray()
        } finally {
            output.release()
            parameters.release()
        }
    }

    private fun chooseNumDisparities(width: Int): Int {
        val maximum = ((width / 4) / 16 * 16).coerceAtLeast(16)
        return minOf(DEFAULT_NUM_DISPARITIES, maximum)
    }

    private data class TemporalResult(
        val disparity: FloatArray,
        val mask: ByteArray,
    )

    private data class DepthMetrics(
        val medianDepthMeters: Double?,
    )

    companion object {
        private const val DEFAULT_NUM_DISPARITIES = 64
        private const val SGBM_BLOCK_SIZE = 5
        private const val MIN_VALID_DISPARITY = 1.0
        private const val MIN_TEXTURE_GRADIENT = 12
        private const val DENSE_MIN_TEXTURE_GRADIENT = 5
        private const val HIGH_TEXTURE_GRADIENT = 30
        private const val TEMPORAL_WINDOW_FRAMES = 5
        private const val MIN_TEMPORAL_VOTES = 3
        private const val MAX_TEMPORAL_SPREAD_PX = 1.5f
        private const val MAX_TEMPORAL_SPREAD_RATIO = 0.10f
        private const val MOVING_MOTION_PERCENT = 2.5
        private const val RESET_MOTION_PERCENT = 8.0
        private const val STRICT_LEFT_RIGHT_TOLERANCE_PX = 1.5f
        private const val DENSE_LEFT_RIGHT_TOLERANCE_PX = 3.0f
        private const val LOW_TEXTURE_RAW_PERCENT = 18.0
        private const val STATIC_REFINE_RAW_PERCENT = 25.0
        private const val STATIC_REFINE_MOTION_PERCENT = 0.8
        private const val DENSE_PROFILE_HYSTERESIS_FRAMES = 3
        private const val METRIC_NEAR_METERS = 0.5
        private const val METRIC_FAR_METERS = 6.0
        private val METRIC_COLOR_STOPS = arrayOf(
            MetricColorStop(0.5, 0, 0, 255),
            MetricColorStop(1.0, 0, 165, 255),
            MetricColorStop(2.0, 0, 255, 255),
            MetricColorStop(3.0, 0, 255, 0),
            MetricColorStop(4.0, 255, 255, 0),
            MetricColorStop(6.0, 255, 0, 0),
        )
        private const val CLAHE_CLIP_LIMIT = 2.0
        private val CLAHE_TILE_GRID = Size(8.0, 8.0)
        private val MOTION_SAMPLE_SIZE = Size(80.0, 60.0)
        private const val MIN_DEPTH_METERS = 0.20
        private const val MAX_DEPTH_METERS = 20.0
        private const val METRIC_SAMPLE_STEP = 4
        private const val OUTPUT_JPEG_QUALITY = 82

        private val LOW_CONFIDENCE_BGR = Scalar(0.0, 0.0, 255.0)
        private val MEDIUM_CONFIDENCE_BGR = Scalar(0.0, 165.0, 255.0)
        private val HIGH_CONFIDENCE_BGR = Scalar(0.0, 255.0, 0.0)
    }
}
