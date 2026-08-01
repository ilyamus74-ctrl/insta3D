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
import kotlin.math.max

data class DualPhoneFilteredDepthResult(
    val rawDepthPreviewJpeg: ByteArray,
    val filteredDepthPreviewJpeg: ByteArray,
    val confidencePreviewJpeg: ByteArray,
    val rawValidPercent: Double,
    val filteredValidPercent: Double,
    val stableCoveragePercent: Double,
    val highConfidencePercent: Double,
    val medianDepthMeters: Double?,
    val depthJitterMeters: Double?,
)

/**
 * LM02.2 bounded spatial/temporal filter for rectified stereo input.
 *
 * The engine uses StereoSGBM, rejects weak-texture and out-of-range disparity,
 * removes isolated regions with morphology, keeps at most five filtered disparity
 * maps, and publishes a temporal-median depth plus categorical confidence preview.
 */
class DualPhoneFilteredDepthEngine : Closeable {
    private val temporalDisparities = ArrayDeque<Mat>()
    private val medianDepthHistory = ArrayDeque<Double>()

    @Synchronized
    fun process(
        grayMaster: Mat,
        graySlave: Mat,
        focalPx: Double,
        baselineMm: Double,
    ): DualPhoneFilteredDepthResult {
        require(!grayMaster.empty() && !graySlave.empty())
        require(grayMaster.size() == graySlave.size())
        require(focalPx > 0.0 && baselineMm > 0.0)

        val disparity16 = Mat()
        val rawDisparity = Mat()
        val spatialDisparity = Mat()
        val rawMinMask = Mat()
        val rawMaxMask = Mat()
        val rawValidMask = Mat()
        val gradientX16 = Mat()
        val gradientY16 = Mat()
        val gradientX8 = Mat()
        val gradientY8 = Mat()
        val texture = Mat()
        val textureMask = Mat()
        val strongTextureMask = Mat()
        val filteredMask = Mat()
        val openedMask = Mat()
        val closedMask = Mat()
        val invalidMask = Mat()
        val kernel = Imgproc.getStructuringElement(
            Imgproc.MORPH_ELLIPSE,
            Size(3.0, 3.0),
        )
        val stableDisparity = Mat()
        val stableMask = Mat()
        val highConfidenceMask = Mat()
        val rawHeatmap = Mat()
        val filteredHeatmap = Mat()
        val confidence = Mat.zeros(
            grayMaster.rows(),
            grayMaster.cols(),
            CvType.CV_8UC3,
        )

        try {
            val numDisparities = chooseNumDisparities(grayMaster.cols())
            val blockSize = SGBM_BLOCK_SIZE
            val stereo = StereoSGBM.create(
                0,
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
            try {
                stereo.compute(grayMaster, graySlave, disparity16)
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

            Imgproc.medianBlur(rawDisparity, spatialDisparity, 5)
            Imgproc.Sobel(
                grayMaster,
                gradientX16,
                CvType.CV_16S,
                1,
                0,
                3,
            )
            Imgproc.Sobel(
                grayMaster,
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
                Scalar(HIGH_TEXTURE_GRADIENT.toDouble()),
                strongTextureMask,
                Core.CMP_GT,
            )
            Core.bitwise_and(rawValidMask, textureMask, filteredMask)
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

            pushTemporal(spatialDisparity)
            val temporal = temporalMedian(
                rows = spatialDisparity.rows(),
                columns = spatialDisparity.cols(),
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

            createHeatmap(rawDisparity, rawValidMask, rawHeatmap)
            createHeatmap(stableDisparity, stableMask, filteredHeatmap)

            confidence.setTo(LOW_CONFIDENCE_BGR, rawValidMask)
            confidence.setTo(MEDIUM_CONFIDENCE_BGR, closedMask)
            confidence.setTo(HIGH_CONFIDENCE_BGR, highConfidenceMask)

            val metrics = depthMetrics(
                disparity = stableDisparity,
                mask = stableMask,
                focalPx = focalPx,
                baselineMm = baselineMm,
            )
            val jitter = updateDepthJitter(metrics.medianDepthMeters)
            val totalPixels = grayMaster.rows().toDouble() * grayMaster.cols().toDouble()

            return DualPhoneFilteredDepthResult(
                rawDepthPreviewJpeg = encodeJpeg(rawHeatmap),
                filteredDepthPreviewJpeg = encodeJpeg(filteredHeatmap),
                confidencePreviewJpeg = encodeJpeg(confidence),
                rawValidPercent = percent(rawValidMask, totalPixels),
                filteredValidPercent = percent(closedMask, totalPixels),
                stableCoveragePercent = percent(stableMask, totalPixels),
                highConfidencePercent = percent(highConfidenceMask, totalPixels),
                medianDepthMeters = metrics.medianDepthMeters,
                depthJitterMeters = jitter,
            )
        } finally {
            listOf(
                disparity16,
                rawDisparity,
                spatialDisparity,
                rawMinMask,
                rawMaxMask,
                rawValidMask,
                gradientX16,
                gradientY16,
                gradientX8,
                gradientY8,
                texture,
                textureMask,
                strongTextureMask,
                filteredMask,
                openedMask,
                closedMask,
                invalidMask,
                kernel,
                stableDisparity,
                stableMask,
                highConfidenceMask,
                rawHeatmap,
                filteredHeatmap,
                confidence,
            ).forEach { it.release() }
        }
    }

    @Synchronized
    fun reset() {
        temporalDisparities.forEach { it.release() }
        temporalDisparities.clear()
        medianDepthHistory.clear()
    }

    override fun close() {
        reset()
    }

    private fun pushTemporal(disparity: Mat) {
        temporalDisparities.addLast(disparity.clone())
        while (temporalDisparities.size > TEMPORAL_WINDOW_FRAMES) {
            temporalDisparities.removeFirst().release()
        }
    }

    private fun temporalMedian(rows: Int, columns: Int): TemporalResult {
        val total = rows * columns
        val history = temporalDisparities.map { mat ->
            FloatArray(total).also { values -> mat.get(0, 0, values) }
        }
        val requiredVotes = when (history.size) {
            0, 1 -> 1
            2 -> 2
            else -> MIN_TEMPORAL_VOTES
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
            if (spread <= allowedSpread) {
                output[index] = median
                mask[index] = 0xff.toByte()
            }
        }
        return TemporalResult(output, mask)
    }

    private fun createHeatmap(disparity: Mat, mask: Mat, output: Mat) {
        val normalized = Mat()
        val invalid = Mat()
        try {
            Core.normalize(
                disparity,
                normalized,
                0.0,
                255.0,
                Core.NORM_MINMAX,
                CvType.CV_8U,
                mask,
            )
            Imgproc.applyColorMap(normalized, output, Imgproc.COLORMAP_TURBO)
            Core.bitwise_not(mask, invalid)
            output.setTo(Scalar(0.0, 0.0, 0.0), invalid)
        } finally {
            normalized.release()
            invalid.release()
        }
    }

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
        private const val HIGH_TEXTURE_GRADIENT = 30
        private const val TEMPORAL_WINDOW_FRAMES = 5
        private const val MIN_TEMPORAL_VOTES = 3
        private const val MAX_TEMPORAL_SPREAD_PX = 1.5f
        private const val MAX_TEMPORAL_SPREAD_RATIO = 0.10f
        private const val MIN_DEPTH_METERS = 0.20
        private const val MAX_DEPTH_METERS = 20.0
        private const val METRIC_SAMPLE_STEP = 4
        private const val OUTPUT_JPEG_QUALITY = 82

        private val LOW_CONFIDENCE_BGR = Scalar(0.0, 0.0, 255.0)
        private val MEDIUM_CONFIDENCE_BGR = Scalar(0.0, 165.0, 255.0)
        private val HIGH_CONFIDENCE_BGR = Scalar(0.0, 255.0, 0.0)
    }
}
