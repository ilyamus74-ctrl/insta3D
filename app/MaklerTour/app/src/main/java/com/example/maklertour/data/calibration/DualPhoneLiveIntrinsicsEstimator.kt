package com.maklertour.data.calibration

import android.graphics.Bitmap
import com.maklertour.data.rig.CalibrationBoardType
import com.maklertour.data.rig.CalibrationSettings
import org.opencv.android.OpenCVLoader
import org.opencv.calib3d.Calib3d
import org.opencv.core.CvType
import org.opencv.core.Mat
import org.opencv.core.MatOfPoint2f
import org.opencv.core.MatOfPoint3f
import org.opencv.core.Point
import org.opencv.core.Point3
import org.opencv.core.Size
import org.opencv.objdetect.CharucoBoard
import org.opencv.objdetect.Objdetect
import org.json.JSONObject
import java.io.Closeable
import java.util.ArrayDeque
import java.util.Locale
import kotlin.math.abs

data class DualPhoneLiveIntrinsicsEstimate(
    val acceptedFrames: Int,
    val solved: Boolean,
    val imageWidth: Int = 0,
    val imageHeight: Int = 0,
    val rms: Double? = null,
    val fx: Double? = null,
    val fy: Double? = null,
    val cx: Double? = null,
    val cy: Double? = null,
    val k1: Double? = null,
    val k2: Double? = null,
    val status: String,
) {
    val acceptable: Boolean
        get() = solved &&
            acceptedFrames >= MIN_FINAL_FRAMES &&
            imageWidth > 0 &&
            imageHeight > 0 &&
            rms != null &&
            rms.isFinite() &&
            rms <= MAX_INTRINSICS_RMS_PX &&
            listOf(fx, fy, cx, cy, k1, k2).all { it != null && it.isFinite() }

    fun summary(): String = when {
        solved && rms != null -> buildString {
            append("Предварительные intrinsics: RMS ")
            append(String.format(Locale.US, "%.3f", rms))
            append(" px · fx ")
            append(String.format(Locale.US, "%.1f", fx))
            append(" · fy ")
            append(String.format(Locale.US, "%.1f", fy))
            append(" · k1 ")
            append(String.format(Locale.US, "%.4f", k1))
            append(" · k2 ")
            append(String.format(Locale.US, "%.4f", k2))
        }
        else -> status
    }

    fun toJson(): JSONObject = JSONObject()
        .put("accepted_frames", acceptedFrames)
        .put("solved", solved)
        .put("image_width", imageWidth)
        .put("image_height", imageHeight)
        .put("rms", rms ?: JSONObject.NULL)
        .put("fx", fx ?: JSONObject.NULL)
        .put("fy", fy ?: JSONObject.NULL)
        .put("cx", cx ?: JSONObject.NULL)
        .put("cy", cy ?: JSONObject.NULL)
        .put("k1", k1 ?: JSONObject.NULL)
        .put("k2", k2 ?: JSONObject.NULL)
        .put("status", status)

    companion object {
        const val MIN_FINAL_FRAMES = 10
        const val MAX_INTRINSICS_RMS_PX = 3.0

        fun fromJson(json: JSONObject): DualPhoneLiveIntrinsicsEstimate =
            DualPhoneLiveIntrinsicsEstimate(
                acceptedFrames = json.optInt("accepted_frames", 0),
                solved = json.optBoolean("solved", false),
                imageWidth = json.optInt("image_width", 0),
                imageHeight = json.optInt("image_height", 0),
                rms = json.optFiniteDouble("rms"),
                fx = json.optFiniteDouble("fx"),
                fy = json.optFiniteDouble("fy"),
                cx = json.optFiniteDouble("cx"),
                cy = json.optFiniteDouble("cy"),
                k1 = json.optFiniteDouble("k1"),
                k2 = json.optFiniteDouble("k2"),
                status = json.optString("status", "Intrinsics unavailable"),
            )
    }
}

/**
 * Lightweight in-memory preview solver.
 *
 * It recalculates a provisional K/D model after each accepted intrinsics frame.
 * A result becomes final only after the minimum frame count and RMS validation pass.
 */
class DualPhoneLiveIntrinsicsEstimator(
    private val detector: CalibrationBoardDetector = OpenCvCalibrationBoardDetector(),
) : Closeable {
    private data class Sample(
        val objectPoints: MatOfPoint3f,
        val imagePoints: MatOfPoint2f,
        val imageSize: Size,
    )

    private val samples = ArrayDeque<Sample>()

    fun addAcceptedFrame(
        bitmap: Bitmap,
        settings: CalibrationSettings,
    ): DualPhoneLiveIntrinsicsEstimate {
        if (!openCvReady) {
            return status("OpenCV недоступен — предварительный расчёт не выполнен")
        }
        val detection = detector.detect(bitmap, settings)
        val sample = createSample(detection, settings)
            ?: return status("Кадр сохранён, но для расчёта недостаточно углов доски")

        samples.addLast(sample)
        while (samples.size > MAX_SAMPLES) {
            samples.removeFirst().release()
        }
        if (samples.size < MIN_SAMPLES_FOR_SOLVE) {
            return status(
                "Автосъёмка: ${samples.size}/$MIN_SAMPLES_FOR_SOLVE кадров до первого расчёта K/D",
            )
        }
        return solve()
    }

    override fun close() {
        samples.forEach { it.release() }
        samples.clear()
    }

    private fun solve(): DualPhoneLiveIntrinsicsEstimate {
        val imageSize = samples.last().imageSize
        val cameraMatrix = Mat.eye(3, 3, CvType.CV_64F)
        val distCoeffs = Mat.zeros(5, 1, CvType.CV_64F)
        val rvecs = mutableListOf<Mat>()
        val tvecs = mutableListOf<Mat>()
        return try {
            val flags = Calib3d.CALIB_FIX_K3 or Calib3d.CALIB_ZERO_TANGENT_DIST
            val rms = Calib3d.calibrateCamera(
                samples.map { it.objectPoints },
                samples.map { it.imagePoints },
                imageSize,
                cameraMatrix,
                distCoeffs,
                rvecs,
                tvecs,
                flags,
            )
            val fx = cameraMatrix.get(0, 0)?.getOrNull(0)
            val fy = cameraMatrix.get(1, 1)?.getOrNull(0)
            val cx = cameraMatrix.get(0, 2)?.getOrNull(0)
            val cy = cameraMatrix.get(1, 2)?.getOrNull(0)
            val k1 = distCoeffs.get(0, 0)?.getOrNull(0)
            val k2 = distCoeffs.get(1, 0)?.getOrNull(0)
            val stable = rms.isFinite() &&
                listOf(fx, fy, cx, cy, k1, k2).all { it != null && it.isFinite() } &&
                abs(k1 ?: 0.0) <= 1.5 &&
                abs(k2 ?: 0.0) <= 3.0
            DualPhoneLiveIntrinsicsEstimate(
                acceptedFrames = samples.size,
                solved = stable,
                imageWidth = imageSize.width.toInt(),
                imageHeight = imageSize.height.toInt(),
                rms = rms.takeIf { it.isFinite() },
                fx = fx,
                fy = fy,
                cx = cx,
                cy = cy,
                k1 = k1,
                k2 = k2,
                status = if (stable) {
                    "Предварительный K/D обновлён"
                } else {
                    "Предварительный расчёт нестабилен — добавьте другие ракурсы"
                },
            )
        } catch (error: Throwable) {
            status(
                "Предварительный расчёт не удался: " +
                    (error.message ?: error.javaClass.simpleName),
            )
        } finally {
            cameraMatrix.release()
            distCoeffs.release()
            rvecs.forEach { it.release() }
            tvecs.forEach { it.release() }
        }
    }

    private fun createSample(
        detection: CalibrationDetectionResult,
        settings: CalibrationSettings,
    ): Sample? {
        if (!detection.found || detection.normalizedCornerPoints.isEmpty()) return null
        val imageSize = Size(
            detection.imageWidth.toDouble(),
            detection.imageHeight.toDouble(),
        )
        return when (settings.boardType) {
            CalibrationBoardType.CHARUCO -> {
                if (detection.charucoIds.size != detection.normalizedCornerPoints.size) {
                    return null
                }
                val board = createCharucoBoard(settings)
                val boardCorners = board.getChessboardCorners().toArray()
                val objectPoints = mutableListOf<Point3>()
                val imagePoints = mutableListOf<Point>()
                detection.charucoIds.zip(detection.normalizedCornerPoints)
                    .sortedBy { it.first }
                    .forEach { (id, point) ->
                        val objectPoint = boardCorners.getOrNull(id) ?: return@forEach
                        objectPoints += objectPoint
                        imagePoints += Point(
                            point.x.toDouble() * detection.imageWidth,
                            point.y.toDouble() * detection.imageHeight,
                        )
                    }
                if (objectPoints.size < settings.minCharucoCorners) return null
                Sample(
                    objectPoints = MatOfPoint3f(*objectPoints.toTypedArray()),
                    imagePoints = MatOfPoint2f(*imagePoints.toTypedArray()),
                    imageSize = imageSize,
                )
            }
            CalibrationBoardType.CHESSBOARD_LEGACY -> {
                val expected = settings.checkerboardInnerCols * settings.checkerboardInnerRows
                if (detection.normalizedCornerPoints.size != expected) return null
                val objectPoints = buildList {
                    for (row in 0 until settings.checkerboardInnerRows) {
                        for (col in 0 until settings.checkerboardInnerCols) {
                            add(
                                Point3(
                                    col * settings.squareSizeMm,
                                    row * settings.squareSizeMm,
                                    0.0,
                                ),
                            )
                        }
                    }
                }
                val imagePoints = detection.normalizedCornerPoints.map { point ->
                    Point(
                        point.x.toDouble() * detection.imageWidth,
                        point.y.toDouble() * detection.imageHeight,
                    )
                }
                Sample(
                    objectPoints = MatOfPoint3f(*objectPoints.toTypedArray()),
                    imagePoints = MatOfPoint2f(*imagePoints.toTypedArray()),
                    imageSize = imageSize,
                )
            }
        }
    }

    private fun createCharucoBoard(settings: CalibrationSettings): CharucoBoard {
        val dictionary = Objdetect.getPredefinedDictionary(
            OpenCvCalibrationBoardDetector.dictionaryId(settings.charucoDictionary),
        )
        return CharucoBoard(
            Size(
                settings.charucoSquaresX.toDouble(),
                settings.charucoSquaresY.toDouble(),
            ),
            settings.charucoSquareLengthMm.toFloat(),
            settings.charucoMarkerLengthMm.toFloat(),
            dictionary,
        ).apply {
            setLegacyPattern(settings.charucoLegacyPattern)
        }
    }

    private fun Sample.release() {
        objectPoints.release()
        imagePoints.release()
    }

    private fun status(message: String): DualPhoneLiveIntrinsicsEstimate =
        DualPhoneLiveIntrinsicsEstimate(
            acceptedFrames = samples.size,
            solved = false,
            imageWidth = samples.lastOrNull()?.imageSize?.width?.toInt() ?: 0,
            imageHeight = samples.lastOrNull()?.imageSize?.height?.toInt() ?: 0,
            status = message,
        )

    companion object {
        private const val MIN_SAMPLES_FOR_SOLVE = 6
        private const val MAX_SAMPLES = 20
        private val openCvReady: Boolean by lazy {
            runCatching { OpenCVLoader.initDebug() }.getOrDefault(false)
        }
    }
}

private fun JSONObject.optFiniteDouble(name: String): Double? =
    if (!has(name) || isNull(name)) null else optDouble(name).takeIf { it.isFinite() }
