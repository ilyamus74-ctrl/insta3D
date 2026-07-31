package com.maklertour.data.calibration

import com.maklertour.data.dualphone.DualPhoneCalibrationObservation
import com.maklertour.data.rig.CalibrationSettings
import org.opencv.calib3d.Calib3d
import org.opencv.core.CvType
import org.opencv.core.Mat
import org.opencv.core.MatOfPoint2f
import org.opencv.core.MatOfPoint3f
import org.opencv.core.Point
import org.opencv.core.Point3
import org.opencv.core.Size
import org.opencv.core.TermCriteria
import org.opencv.objdetect.CharucoBoard
import org.opencv.objdetect.Objdetect
import java.io.Closeable
import java.util.ArrayDeque
import kotlin.math.abs
import kotlin.math.sqrt

class DualPhoneStereoCalibrationEstimator : Closeable {
    private data class PairSample(
        val objectPoints: MatOfPoint3f,
        val masterPoints: MatOfPoint2f,
        val slavePoints: MatOfPoint2f,
        val imageSize: Size,
        val commonIds: Int,
    )

    private val samples = ArrayDeque<PairSample>()

    val pairCount: Int
        get() = samples.size

    @Synchronized
    fun addAcceptedPair(
        master: DualPhoneCalibrationObservation,
        slave: DualPhoneCalibrationObservation,
        settings: CalibrationSettings,
    ): Boolean {
        if (
            master.imageWidth <= 0 ||
            master.imageHeight <= 0 ||
            master.imageWidth != slave.imageWidth ||
            master.imageHeight != slave.imageHeight
        ) {
            return false
        }
        val masterById = master.charucoCorners.associateBy { it.id }
        val slaveById = slave.charucoCorners.associateBy { it.id }
        val commonIds = masterById.keys.intersect(slaveById.keys).sorted()
        if (commonIds.size < MIN_COMMON_CHARUCO_IDS) return false

        val board = createCharucoBoard(settings)
        val boardCorners = board.getChessboardCorners().toArray()
        val objectPoints = mutableListOf<Point3>()
        val masterPoints = mutableListOf<Point>()
        val slavePoints = mutableListOf<Point>()
        commonIds.forEach { id ->
            val objectPoint = boardCorners.getOrNull(id) ?: return@forEach
            val masterPoint = masterById[id] ?: return@forEach
            val slavePoint = slaveById[id] ?: return@forEach
            objectPoints += objectPoint
            masterPoints += Point(
                masterPoint.normalizedX * master.imageWidth,
                masterPoint.normalizedY * master.imageHeight,
            )
            slavePoints += Point(
                slavePoint.normalizedX * slave.imageWidth,
                slavePoint.normalizedY * slave.imageHeight,
            )
        }
        if (objectPoints.size < MIN_COMMON_CHARUCO_IDS) return false

        samples.addLast(
            PairSample(
                objectPoints = MatOfPoint3f(*objectPoints.toTypedArray()),
                masterPoints = MatOfPoint2f(*masterPoints.toTypedArray()),
                slavePoints = MatOfPoint2f(*slavePoints.toTypedArray()),
                imageSize = Size(master.imageWidth.toDouble(), master.imageHeight.toDouble()),
                commonIds = objectPoints.size,
            ),
        )
        while (samples.size > MAX_PAIRS) {
            samples.removeFirst().release()
        }
        return true
    }

    @Synchronized
    fun solve(
        master: DualPhoneLiveIntrinsicsEstimate,
        slave: DualPhoneLiveIntrinsicsEstimate,
        operatorBaselineMm: Double?,
    ): DualPhoneStereoEstimate {
        if (!master.acceptable || !slave.acceptable) {
            return failed("Сначала нужны корректные intrinsics MASTER и SLAVE")
        }
        if (samples.size < MIN_PAIRS_FOR_SOLVE) {
            return failed(
                "Недостаточно стереопар: ${samples.size}/$MIN_PAIRS_FOR_SOLVE",
            )
        }
        val imageSize = samples.last().imageSize
        if (
            master.imageWidth != imageSize.width.toInt() ||
            master.imageHeight != imageSize.height.toInt() ||
            slave.imageWidth != imageSize.width.toInt() ||
            slave.imageHeight != imageSize.height.toInt()
        ) {
            return failed("Размер кадров не совпадает с размером intrinsics")
        }

        val masterMatrix = cameraMatrix(master)
        val slaveMatrix = cameraMatrix(slave)
        val masterDist = distortion(master)
        val slaveDist = distortion(slave)
        val rotation = Mat()
        val translation = Mat()
        val essential = Mat()
        val fundamental = Mat()
        return try {
            val rms = Calib3d.stereoCalibrate(
                samples.map { it.objectPoints },
                samples.map { it.masterPoints },
                samples.map { it.slavePoints },
                masterMatrix,
                masterDist,
                slaveMatrix,
                slaveDist,
                imageSize,
                rotation,
                translation,
                essential,
                fundamental,
                Calib3d.CALIB_FIX_INTRINSIC,
                TermCriteria(
                    TermCriteria.EPS + TermCriteria.MAX_ITER,
                    100,
                    1e-6,
                ),
            )
            val translationValues = translation.toFlatList()
            val baseline = if (translationValues.size >= 3) {
                sqrt(
                    translationValues[0] * translationValues[0] +
                        translationValues[1] * translationValues[1] +
                        translationValues[2] * translationValues[2],
                )
            } else {
                Double.NaN
            }
            val delta = if (
                operatorBaselineMm != null &&
                operatorBaselineMm.isFinite() &&
                baseline.isFinite()
            ) {
                baseline - operatorBaselineMm
            } else {
                null
            }
            val solved = rms.isFinite() &&
                baseline.isFinite() &&
                baseline > 0.0 &&
                rotation.rows() == 3 &&
                rotation.cols() == 3
            val warning = if (
                solved &&
                delta != null &&
                abs(delta) > maxOf(10.0, operatorBaselineMm!! * 0.10)
            ) {
                "Калибровка рассчитана, но базис отличается от введённого на " +
                    "${"%.1f".format(java.util.Locale.US, delta)} мм"
            } else {
                null
            }
            DualPhoneStereoEstimate(
                solved = solved,
                pairsUsed = samples.size,
                rms = rms.takeIf { it.isFinite() },
                rotation = rotation.toFlatList(),
                translationMm = translationValues,
                baselineMm = baseline.takeIf { it.isFinite() },
                operatorBaselineMm = operatorBaselineMm,
                baselineDeltaMm = delta,
                status = when {
                    !solved -> "Stereo solve returned an invalid model"
                    rms > DualPhoneStereoEstimate.MAX_STEREO_RMS_PX ->
                        "Stereo RMS ${"%.3f".format(java.util.Locale.US, rms)} px " +
                            "выше допустимых ${DualPhoneStereoEstimate.MAX_STEREO_RMS_PX} px"
                    warning != null -> warning
                    else -> "Stereo R/T рассчитаны"
                },
            )
        } catch (error: Throwable) {
            failed(
                "Stereo solve failed: ${error.message ?: error.javaClass.simpleName}",
            )
        } finally {
            masterMatrix.release()
            slaveMatrix.release()
            masterDist.release()
            slaveDist.release()
            rotation.release()
            translation.release()
            essential.release()
            fundamental.release()
        }
    }

    override fun close() {
        synchronized(this) {
            samples.forEach { it.release() }
            samples.clear()
        }
    }

    private fun failed(message: String): DualPhoneStereoEstimate =
        DualPhoneStereoEstimate(
            solved = false,
            pairsUsed = samples.size,
            status = message,
        )

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

    private fun cameraMatrix(estimate: DualPhoneLiveIntrinsicsEstimate): Mat =
        Mat.eye(3, 3, CvType.CV_64F).apply {
            put(0, 0, estimate.fx!!)
            put(1, 1, estimate.fy!!)
            put(0, 2, estimate.cx!!)
            put(1, 2, estimate.cy!!)
        }

    private fun distortion(estimate: DualPhoneLiveIntrinsicsEstimate): Mat =
        Mat.zeros(5, 1, CvType.CV_64F).apply {
            put(0, 0, estimate.k1!!)
            put(1, 0, estimate.k2!!)
        }

    private fun PairSample.release() {
        objectPoints.release()
        masterPoints.release()
        slavePoints.release()
    }

    private fun Mat.toFlatList(): List<Double> =
        (0 until rows()).flatMap { row ->
            (0 until cols()).map { col -> get(row, col)[0] }
        }

    companion object {
        const val MIN_COMMON_CHARUCO_IDS = 20
        const val MIN_PAIRS_FOR_SOLVE = 8
        private const val MAX_PAIRS = 20
    }
}
