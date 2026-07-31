package com.maklertour.data.calibration

import com.maklertour.data.dualphone.DualPhoneCalibrationObservation
import com.maklertour.data.rig.CalibrationBoardType
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
import java.util.Locale
import kotlin.math.abs
import kotlin.math.max
import kotlin.math.sqrt

data class DualPhoneStereoCoachSnapshot(
    val collectedPairs: Int = 0,
    val liveRmsPx: Double? = null,
    val liveBaselineMm: Double? = null,
    val meanEpipolarErrorPx: Double? = null,
    val commonCorners: Int = 0,
    val frameDeltaMs: Double? = null,
    val coveragePercent: Int = 0,
    val coverageGrid: String = "···/···/···",
    val guidance: String = "Поставьте доску в общую область обеих камер",
) {
    fun summaryRu(): String = buildString {
        append("Пар: $collectedPairs")
        liveRmsPx?.let {
            append(" · live RMS ")
            append(String.format(Locale.US, "%.3f px", it))
        }
        liveBaselineMm?.let {
            append(" · базис ")
            append(String.format(Locale.US, "%.1f мм", it))
        }
        meanEpipolarErrorPx?.let {
            append(" · epi ")
            append(String.format(Locale.US, "%.2f px", it))
        }
    }
}

class DualPhoneStereoCoachEstimator : Closeable {
    private data class PairSample(
        val objectPoints: MatOfPoint3f,
        val masterPoints: MatOfPoint2f,
        val slavePoints: MatOfPoint2f,
        val imageSize: Size,
        val commonIds: Int,
        val frameDeltaMs: Double?,
        val centreX: Double,
        val centreY: Double,
        val areaFraction: Double,
        val tilted: Boolean,
    )

    private data class SolveModel(
        val rms: Double,
        val rotation: List<Double>,
        val translation: List<Double>,
        val fundamental: List<Double>,
        val baselineMm: Double,
        val perPairEpipolarErrors: List<Double>,
    )

    private val samples = mutableListOf<PairSample>()

    val pairCount: Int
        get() = samples.size

    @Synchronized
    fun addAcceptedPair(
        master: DualPhoneCalibrationObservation,
        slave: DualPhoneCalibrationObservation,
        settings: CalibrationSettings,
        frameDeltaMs: Double?,
    ): Boolean {
        if (
            master.imageWidth <= 0 ||
            master.imageHeight <= 0 ||
            master.imageWidth != slave.imageWidth ||
            master.imageHeight != slave.imageHeight
        ) return false

        val masterById = master.charucoCorners.associateBy { it.id }
        val slaveById = slave.charucoCorners.associateBy { it.id }
        val commonIds = masterById.keys.intersect(slaveById.keys).sorted()
        if (commonIds.size < MIN_COMMON_BOARD_IDS) return false

        val objectPoints = mutableListOf<Point3>()
        val masterPoints = mutableListOf<Point>()
        val slavePoints = mutableListOf<Point>()
        commonIds.forEach { id ->
            val objectPoint = objectPointFor(id, settings) ?: return@forEach
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
        if (objectPoints.size < MIN_COMMON_BOARD_IDS) return false

        samples += PairSample(
            objectPoints = MatOfPoint3f(*objectPoints.toTypedArray()),
            masterPoints = MatOfPoint2f(*masterPoints.toTypedArray()),
            slavePoints = MatOfPoint2f(*slavePoints.toTypedArray()),
            imageSize = Size(master.imageWidth.toDouble(), master.imageHeight.toDouble()),
            commonIds = objectPoints.size,
            frameDeltaMs = frameDeltaMs,
            centreX = (master.centreX + slave.centreX) / 2.0,
            centreY = (master.centreY + slave.centreY) / 2.0,
            areaFraction = (master.boardAreaFraction + slave.boardAreaFraction) / 2.0,
            tilted = listOf(
                master.rollDegrees,
                slave.rollDegrees,
            ).any { abs(it) >= 8.0 } ||
                listOf(master.yawSkew, slave.yawSkew, master.pitchSkew, slave.pitchSkew)
                    .any { abs(it) >= 0.05 },
        )
        while (samples.size > MAX_PAIRS) samples.removeAt(0).release()
        return true
    }

    @Synchronized
    fun snapshot(
        master: DualPhoneLiveIntrinsicsEstimate,
        slave: DualPhoneLiveIntrinsicsEstimate,
        operatorBaselineMm: Double?,
    ): DualPhoneStereoCoachSnapshot {
        val bins = coverageBins(samples.indices.toList())
        val model = if (
            master.acceptable && slave.acceptable && samples.size >= MIN_PAIRS_FOR_LIVE_SOLVE
        ) {
            runCatching {
                solveIndices(samples.indices.toList(), master, slave)
            }.getOrNull()
        } else {
            null
        }
        return DualPhoneStereoCoachSnapshot(
            collectedPairs = samples.size,
            liveRmsPx = model?.rms,
            liveBaselineMm = model?.baselineMm,
            meanEpipolarErrorPx = model?.perPairEpipolarErrors?.averageOrNull(),
            commonCorners = samples.lastOrNull()?.commonIds ?: 0,
            frameDeltaMs = samples.lastOrNull()?.frameDeltaMs,
            coveragePercent = (bins.size * 100 / 9).coerceIn(0, 100),
            coverageGrid = coverageGrid(bins),
            guidance = coverageGuidance(bins, samples.count { it.tilted }, operatorBaselineMm, model),
        )
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
        if (samples.size < MIN_PAIRS_FOR_FINAL_SOLVE) {
            return failed("Недостаточно стереопар: ${samples.size}/$MIN_PAIRS_FOR_FINAL_SOLVE")
        }

        val active = samples.indices.toMutableList()
        var rejected = 0
        var model = solveIndices(active, master, slave)
        while (active.size > MIN_PAIRS_AFTER_REJECTION && rejected < MAX_REJECTED_PAIRS) {
            val errors = model.perPairEpipolarErrors
            val median = errors.median()
            val worstIndexInActive = errors.indices.maxByOrNull { errors[it] } ?: break
            val worstError = errors[worstIndexInActive]
            val threshold = max(MAX_PAIR_EPIPOLAR_ERROR_PX, median * OUTLIER_MEDIAN_MULTIPLIER)
            if (!worstError.isFinite() || worstError <= threshold) break
            active.removeAt(worstIndexInActive)
            rejected += 1
            model = solveIndices(active, master, slave)
        }

        val delta = operatorBaselineMm?.takeIf { it.isFinite() }?.let {
            model.baselineMm - it
        }
        val coverage = (coverageBins(active).size * 100 / 9).coerceIn(0, 100)
        val meanEpi = model.perPairEpipolarErrors.averageOrNull()
        val maxDelta = active.mapNotNull { samples[it].frameDeltaMs }.maxOrNull()
        val status = when {
            model.rms > DualPhoneStereoEstimate.MAX_STEREO_RMS_PX ->
                "Stereo RMS ${format3(model.rms)} px выше допустимых " +
                    "${DualPhoneStereoEstimate.MAX_STEREO_RMS_PX} px"
            meanEpi != null && meanEpi > MAX_FINAL_MEAN_EPIPOLAR_ERROR_PX ->
                "Средняя эпиполярная ошибка ${format2(meanEpi)} px слишком высокая"
            delta != null && abs(delta) > max(15.0, (operatorBaselineMm ?: 0.0) * 0.12) ->
                "Базис отличается от введённого на ${format1(delta)} мм"
            else -> "Stereo R/T рассчитаны; автоматически отброшено пар: $rejected"
        }
        return DualPhoneStereoEstimate(
            solved = true,
            pairsUsed = active.size,
            rms = model.rms,
            rotation = model.rotation,
            translationMm = model.translation,
            baselineMm = model.baselineMm,
            operatorBaselineMm = operatorBaselineMm,
            baselineDeltaMm = delta,
            pairsRejected = rejected,
            meanEpipolarErrorPx = meanEpi,
            maxFrameDeltaMs = maxDelta,
            coveragePercent = coverage,
            status = status,
        )
    }

    private fun solveIndices(
        indices: List<Int>,
        master: DualPhoneLiveIntrinsicsEstimate,
        slave: DualPhoneLiveIntrinsicsEstimate,
    ): SolveModel {
        val selected = indices.map(samples::get)
        val imageSize = selected.last().imageSize
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
                selected.map { it.objectPoints },
                selected.map { it.masterPoints },
                selected.map { it.slavePoints },
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
                TermCriteria(TermCriteria.EPS + TermCriteria.MAX_ITER, 100, 1e-6),
            )
            val translationValues = translation.toFlatList()
            require(translationValues.size >= 3) { "Stereo translation is incomplete" }
            val baseline = sqrt(
                translationValues[0] * translationValues[0] +
                    translationValues[1] * translationValues[1] +
                    translationValues[2] * translationValues[2],
            )
            val fundamentalValues = fundamental.toFlatList()
            SolveModel(
                rms = rms,
                rotation = rotation.toFlatList(),
                translation = translationValues,
                fundamental = fundamentalValues,
                baselineMm = baseline,
                perPairEpipolarErrors = selected.map {
                    symmetricEpipolarError(it, fundamentalValues)
                },
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

    private fun symmetricEpipolarError(sample: PairSample, f: List<Double>): Double {
        if (f.size != 9) return Double.POSITIVE_INFINITY
        val master = sample.masterPoints.toArray()
        val slave = sample.slavePoints.toArray()
        if (master.isEmpty() || master.size != slave.size) return Double.POSITIVE_INFINITY
        var total = 0.0
        master.indices.forEach { index ->
            total += pointLineDistance(slave[index], epipolarLine(f, master[index], transpose = false))
            total += pointLineDistance(master[index], epipolarLine(f, slave[index], transpose = true))
        }
        return total / (master.size * 2.0)
    }

    private fun epipolarLine(f: List<Double>, point: Point, transpose: Boolean): DoubleArray {
        val x = point.x
        val y = point.y
        return if (!transpose) {
            doubleArrayOf(
                f[0] * x + f[1] * y + f[2],
                f[3] * x + f[4] * y + f[5],
                f[6] * x + f[7] * y + f[8],
            )
        } else {
            doubleArrayOf(
                f[0] * x + f[3] * y + f[6],
                f[1] * x + f[4] * y + f[7],
                f[2] * x + f[5] * y + f[8],
            )
        }
    }

    private fun pointLineDistance(point: Point, line: DoubleArray): Double {
        val denominator = sqrt(line[0] * line[0] + line[1] * line[1])
        if (denominator <= 1e-9) return Double.POSITIVE_INFINITY
        return abs(line[0] * point.x + line[1] * point.y + line[2]) / denominator
    }

    private fun objectPointFor(id: Int, settings: CalibrationSettings): Point3? =
        when (settings.boardType) {
            CalibrationBoardType.CHARUCO -> {
                val board = createCharucoBoard(settings)
                board.getChessboardCorners().toArray().getOrNull(id)
            }
            CalibrationBoardType.CHESSBOARD_LEGACY -> {
                val cols = settings.checkerboardInnerCols
                val rows = settings.checkerboardInnerRows
                if (id !in 0 until cols * rows) null else Point3(
                    (id % cols) * settings.squareSizeMm,
                    (id / cols) * settings.squareSizeMm,
                    0.0,
                )
            }
        }

    private fun createCharucoBoard(settings: CalibrationSettings): CharucoBoard {
        val dictionary = Objdetect.getPredefinedDictionary(
            OpenCvCalibrationBoardDetector.dictionaryId(settings.charucoDictionary),
        )
        return CharucoBoard(
            Size(settings.charucoSquaresX.toDouble(), settings.charucoSquaresY.toDouble()),
            settings.charucoSquareLengthMm.toFloat(),
            settings.charucoMarkerLengthMm.toFloat(),
            dictionary,
        ).apply { setLegacyPattern(settings.charucoLegacyPattern) }
    }

    private fun coverageBins(indices: List<Int>): Set<Int> = indices.map { index ->
        val sample = samples[index]
        val x = (sample.centreX * 3.0).toInt().coerceIn(0, 2)
        val y = (sample.centreY * 3.0).toInt().coerceIn(0, 2)
        y * 3 + x
    }.toSet()

    private fun coverageGrid(bins: Set<Int>): String = (0..2).joinToString("/") { row ->
        (0..2).joinToString("") { col -> if (row * 3 + col in bins) "✓" else "·" }
    }

    private fun coverageGuidance(
        bins: Set<Int>,
        tiltedCount: Int,
        operatorBaselineMm: Double?,
        model: SolveModel?,
    ): String {
        val names = listOf(
            "влево и вверх", "выше центра", "вправо и вверх",
            "левее центра", "в центр общей области", "правее центра",
            "влево и вниз", "ниже центра", "вправо и вниз",
        )
        val nextMissing = listOf(4, 3, 5, 1, 7, 0, 2, 6, 8).firstOrNull { it !in bins }
        return when {
            nextMissing != null -> "Следующий кадр: переместите доску ${names[nextMissing]} и замрите"
            tiltedCount < 5 -> "Покрытие заполнено: добавьте небольшой yaw/pitch/roll 10–25°"
            model == null -> "Наберите ещё несколько неподвижных синхронных пар"
            operatorBaselineMm != null && abs(model.baselineMm - operatorBaselineMm) > 30.0 ->
                "Базис пока нестабилен: держите доску неподвижно и меняйте дистанцию"
            model.rms > 2.0 -> "Live RMS высокий: добавьте другой угол и не двигайте доску в момент снимка"
            else -> "Хорошее покрытие; добавьте 2–3 контрольных ракурса"
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

    private fun failed(message: String): DualPhoneStereoEstimate =
        DualPhoneStereoEstimate(solved = false, pairsUsed = samples.size, status = message)

    override fun close() {
        samples.forEach { it.release() }
        samples.clear()
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
        const val MIN_COMMON_BOARD_IDS = 20
        const val MIN_PAIRS_FOR_LIVE_SOLVE = 6
        const val MIN_PAIRS_FOR_FINAL_SOLVE = 10
        private const val MIN_PAIRS_AFTER_REJECTION = 10
        private const val MAX_PAIRS = 24
        private const val MAX_REJECTED_PAIRS = 8
        private const val MAX_PAIR_EPIPOLAR_ERROR_PX = 2.5
        private const val OUTLIER_MEDIAN_MULTIPLIER = 2.5
        private const val MAX_FINAL_MEAN_EPIPOLAR_ERROR_PX = 1.5
    }
}

private fun List<Double>.averageOrNull(): Double? =
    takeIf { it.isNotEmpty() }?.average()

private fun List<Double>.median(): Double {
    if (isEmpty()) return Double.NaN
    val sorted = sorted()
    val middle = sorted.size / 2
    return if (sorted.size % 2 == 0) {
        (sorted[middle - 1] + sorted[middle]) / 2.0
    } else {
        sorted[middle]
    }
}

private fun format1(value: Double): String = String.format(Locale.US, "%+.1f", value)
private fun format2(value: Double): String = String.format(Locale.US, "%.2f", value)
private fun format3(value: Double): String = String.format(Locale.US, "%.3f", value)
