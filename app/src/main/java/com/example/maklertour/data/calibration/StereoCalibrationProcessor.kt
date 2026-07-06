package com.maklertour.data.calibration

import android.graphics.BitmapFactory
import com.maklertour.data.rig.CalibrationSettings
import org.json.JSONArray
import org.json.JSONObject
import org.opencv.android.OpenCVLoader
import org.opencv.calib3d.Calib3d
import org.opencv.core.CvType
import org.opencv.core.Mat
import org.opencv.core.MatOfPoint2f
import org.opencv.core.MatOfPoint3f
import org.opencv.core.Point3
import org.opencv.core.Size
import org.opencv.core.TermCriteria
import org.opencv.imgproc.Imgproc
import java.io.File
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.TimeZone
import kotlin.math.max

class StereoCalibrationProcessor(
    private val minPairs: Int = 10,
    private val defaultStereoRmsThresholdPx: Double = 2.0,
) {
    fun run(sessionDir: File, stereoRmsThresholdPx: Double = defaultStereoRmsThresholdPx): StereoCalibrationResult {
        val errors = mutableListOf<String>()
        val resultFile = File(sessionDir, RESULT_FILE)
        fun finish(result: StereoCalibrationResult): StereoCalibrationResult {
            resultFile.writeText(result.toJson().toString(2))
            return result
        }

        val openCvReady = runCatching { OpenCVLoader.initDebug() }.getOrDefault(false)
        if (!openCvReady) {
            return finish(failed(sessionDir, errors + "OpenCV unavailable"))
        }

        val inputFile = File(sessionDir, "calibration_input.json")
        val manifestFile = File(sessionDir, "pairs_manifest.json")
        if (!inputFile.exists()) return finish(failed(sessionDir, errors + "Missing calibration_input.json"))
        if (!manifestFile.exists()) return finish(failed(sessionDir, errors + "Missing pairs_manifest.json"))

        val input = JSONObject(inputFile.readText())
        val settings = CalibrationSettings(
            checkerboardInnerCols = input.optInt("checkerboard_inner_cols", input.optJSONObject("checkerboard_settings")?.optInt("checkerboardInnerCols", 9) ?: 9),
            checkerboardInnerRows = input.optInt("checkerboard_inner_rows", input.optJSONObject("checkerboard_settings")?.optInt("checkerboardInnerRows", 6) ?: 6),
            squareSizeMm = input.optDouble("square_size_mm", input.optJSONObject("checkerboard_settings")?.optDouble("squareSizeMm", 25.0) ?: 25.0),
            requiredPairs = input.optInt("required_pairs", input.optJSONObject("checkerboard_settings")?.optInt("requiredPairs", 20) ?: 20),
        )
        val pairs = JSONObject(manifestFile.readText()).optJSONArray("pairs") ?: JSONArray()
        val boardSize = Size(settings.checkerboardInnerCols.toDouble(), settings.checkerboardInnerRows.toDouble())
        val expectedCorners = settings.checkerboardInnerCols * settings.checkerboardInnerRows
        val objectTemplate = buildObjectPoints(settings)
        val objectPoints = mutableListOf<Mat>()
        val cam0Points = mutableListOf<Mat>()
        val cam1Points = mutableListOf<Mat>()
        var cam0ImageSize: Size? = null
        var cam1ImageSize: Size? = null

        for (i in 0 until pairs.length()) {
            val pair = pairs.optJSONObject(i) ?: continue
            val cam0File = File(sessionDir, pair.optString("cam0_file"))
            val cam1File = File(sessionDir, pair.optString("cam1_file"))
            val cam0 = detectCorners(cam0File, boardSize, expectedCorners)
            val cam1 = detectCorners(cam1File, boardSize, expectedCorners)
            if (cam0.corners != null && cam1.corners != null) {
                objectPoints += objectTemplate.clone()
                cam0Points += cam0.corners
                cam1Points += cam1.corners
                cam0ImageSize = cam0.size
                cam1ImageSize = cam1.size
            } else {
                errors += "Skipped pair ${pair.optInt("pair_index", i + 1)}: cam0=${cam0.error ?: "ok"}, cam1=${cam1.error ?: "ok"}"
            }
        }

        val pairsUsed = cam0Points.size
        val requiredForSuccess = max(minPairs, minOf(settings.requiredPairs, 15))
        if (pairsUsed < requiredForSuccess) {
            return finish(failed(sessionDir, errors + "Only $pairsUsed usable pairs; need at least $requiredForSuccess", settings, pairs.length(), pairsUsed, cam0ImageSize, cam1ImageSize))
        }

        val cam0Size = cam0ImageSize ?: return finish(failed(sessionDir, errors + "Missing cam0 image size", settings, pairs.length(), pairsUsed, cam0ImageSize, cam1ImageSize))
        val cam1Size = cam1ImageSize ?: return finish(failed(sessionDir, errors + "Missing cam1 image size", settings, pairs.length(), pairsUsed, cam0ImageSize, cam1ImageSize))

        return try {
            val cam0Matrix = Mat.eye(3, 3, CvType.CV_64F)
            val cam1Matrix = Mat.eye(3, 3, CvType.CV_64F)
            val cam0Dist = Mat.zeros(8, 1, CvType.CV_64F)
            val cam1Dist = Mat.zeros(8, 1, CvType.CV_64F)
            val rvecs0 = mutableListOf<Mat>()
            val tvecs0 = mutableListOf<Mat>()
            val rvecs1 = mutableListOf<Mat>()
            val tvecs1 = mutableListOf<Mat>()
            val cam0Rms = Calib3d.calibrateCamera(objectPoints, cam0Points, cam0Size, cam0Matrix, cam0Dist, rvecs0, tvecs0)
            val cam1Rms = Calib3d.calibrateCamera(objectPoints, cam1Points, cam1Size, cam1Matrix, cam1Dist, rvecs1, tvecs1)
            val r = Mat(); val t = Mat(); val e = Mat(); val f = Mat()
            val stereoRms = Calib3d.stereoCalibrate(
                objectPoints, cam0Points, cam1Points, cam0Matrix, cam0Dist, cam1Matrix, cam1Dist,
                cam0Size, r, t, e, f, Calib3d.CALIB_FIX_INTRINSIC,
                TermCriteria(TermCriteria.EPS + TermCriteria.MAX_ITER, 100, 1e-5),
            )
            val finite = listOf(cam0Rms, cam1Rms, stereoRms).all { it.isFinite() }
            val success = finite && stereoRms <= stereoRmsThresholdPx
            finish(
                StereoCalibrationResult(
                    status = if (success) "success" else "failed",
                    createdAtUtc = utcNow(), sessionPath = sessionDir.absolutePath,
                    checkerboardInnerCols = settings.checkerboardInnerCols, checkerboardInnerRows = settings.checkerboardInnerRows,
                    squareSizeMm = settings.squareSizeMm, pairsTotal = pairs.length(), pairsUsed = pairsUsed,
                    cam0Rms = cam0Rms, cam1Rms = cam1Rms, stereoRms = stereoRms,
                    cam0ImageWidth = cam0ImageSize?.width?.toInt() ?: 0, cam0ImageHeight = cam0ImageSize?.height?.toInt() ?: 0,
                    cam1ImageWidth = cam1ImageSize?.width?.toInt() ?: 0, cam1ImageHeight = cam1ImageSize?.height?.toInt() ?: 0,
                    cam0CameraMatrix = cam0Matrix.toNestedList(), cam0DistCoeffs = cam0Dist.toFlatList(),
                    cam1CameraMatrix = cam1Matrix.toNestedList(), cam1DistCoeffs = cam1Dist.toFlatList(),
                    stereoR = r.toNestedList(), stereoT = t.toFlatList(), stereoE = e.toNestedList(), stereoF = f.toNestedList(),
                    errors = if (success) errors else errors + if (!finite) "Calibration returned non-finite RMS" else "Stereo RMS $stereoRms exceeds threshold $stereoRmsThresholdPx px",
                )
            )
        } catch (t: Throwable) {
            finish(failed(sessionDir, errors + "Calibration failed: ${t.message ?: t.javaClass.simpleName}", settings, pairs.length(), pairsUsed, cam0ImageSize, cam1ImageSize))
        }
    }

    private fun failed(sessionDir: File, errors: List<String>, settings: CalibrationSettings = CalibrationSettings(0, 0, 0.0, 0), pairsTotal: Int = 0, pairsUsed: Int = 0, cam0Size: Size? = null, cam1Size: Size? = null) = StereoCalibrationResult(
        status = "failed", createdAtUtc = utcNow(), sessionPath = sessionDir.absolutePath,
        checkerboardInnerCols = settings.checkerboardInnerCols, checkerboardInnerRows = settings.checkerboardInnerRows, squareSizeMm = settings.squareSizeMm,
        pairsTotal = pairsTotal, pairsUsed = pairsUsed, cam0ImageWidth = cam0Size?.width?.toInt() ?: 0, cam0ImageHeight = cam0Size?.height?.toInt() ?: 0,
        cam1ImageWidth = cam1Size?.width?.toInt() ?: 0, cam1ImageHeight = cam1Size?.height?.toInt() ?: 0, errors = errors,
    )

    private fun detectCorners(file: File, boardSize: Size, expectedCorners: Int): CornerDetection {
        if (!file.exists()) return CornerDetection(null, null, "missing file")
        val bitmap = BitmapFactory.decodeFile(file.absolutePath) ?: return CornerDetection(null, null, "decode failed")
        val rgba = Mat(); val gray = Mat(); val corners = MatOfPoint2f()
        return try {
            org.opencv.android.Utils.bitmapToMat(bitmap, rgba)
            Imgproc.cvtColor(rgba, gray, Imgproc.COLOR_RGBA2GRAY)
            val found = Calib3d.findChessboardCorners(gray, boardSize, corners, Calib3d.CALIB_CB_ADAPTIVE_THRESH or Calib3d.CALIB_CB_NORMALIZE_IMAGE)
            if (found && corners.rows() == expectedCorners) {
                Imgproc.cornerSubPix(
                    gray,
                    corners,
                    Size(11.0, 11.0),
                    Size(-1.0, -1.0),
                    TermCriteria(TermCriteria.EPS + TermCriteria.MAX_ITER, 30, 0.1),
                )
                val refinedCorners = MatOfPoint2f(*corners.toArray())
                CornerDetection(refinedCorners, Size(bitmap.width.toDouble(), bitmap.height.toDouble()), null)
            } else {
                CornerDetection(null, Size(bitmap.width.toDouble(), bitmap.height.toDouble()), "checkerboard not found")
            }
        } finally { rgba.release(); gray.release(); corners.release(); bitmap.recycle() }
    }

    private fun buildObjectPoints(settings: CalibrationSettings): MatOfPoint3f {
        val points = ArrayList<Point3>()
        for (row in 0 until settings.checkerboardInnerRows) for (col in 0 until settings.checkerboardInnerCols) points += Point3(col * settings.squareSizeMm, row * settings.squareSizeMm, 0.0)
        return MatOfPoint3f(*points.toTypedArray())
    }

    private data class CornerDetection(val corners: MatOfPoint2f?, val size: Size?, val error: String?)

    companion object { const val RESULT_FILE = "calibration_result.json" }
}

data class StereoCalibrationResult(
    val status: String,
    val createdAtUtc: String,
    val sessionPath: String,
    val checkerboardInnerCols: Int,
    val checkerboardInnerRows: Int,
    val squareSizeMm: Double,
    val pairsTotal: Int,
    val pairsUsed: Int,
    val cam0Rms: Double? = null,
    val cam1Rms: Double? = null,
    val stereoRms: Double? = null,
    val cam0ImageWidth: Int,
    val cam0ImageHeight: Int,
    val cam1ImageWidth: Int,
    val cam1ImageHeight: Int,
    val cam0CameraMatrix: List<List<Double>> = emptyList(),
    val cam0DistCoeffs: List<Double> = emptyList(),
    val cam1CameraMatrix: List<List<Double>> = emptyList(),
    val cam1DistCoeffs: List<Double> = emptyList(),
    val stereoR: List<List<Double>> = emptyList(),
    val stereoT: List<Double> = emptyList(),
    val stereoE: List<List<Double>> = emptyList(),
    val stereoF: List<List<Double>> = emptyList(),
    val errors: List<String> = emptyList(),
) {
    val resultPath: String get() = File(sessionPath, StereoCalibrationProcessor.RESULT_FILE).absolutePath
}

fun StereoCalibrationResult.toJson(): JSONObject = JSONObject()
    .put("status", status).put("created_at_utc", createdAtUtc).put("session_path", sessionPath)
    .put("checkerboard_inner_cols", checkerboardInnerCols).put("checkerboard_inner_rows", checkerboardInnerRows).put("square_size_mm", squareSizeMm)
    .put("pairs_total", pairsTotal).put("pairs_used", pairsUsed).put("cam0_rms", cam0Rms).put("cam1_rms", cam1Rms).put("stereo_rms", stereoRms)
    .put("cam0_image_width", cam0ImageWidth).put("cam0_image_height", cam0ImageHeight).put("cam1_image_width", cam1ImageWidth).put("cam1_image_height", cam1ImageHeight)
    .put("cam0_camera_matrix", cam0CameraMatrix.toDoubleJsonArray2()).put("cam0_dist_coeffs", cam0DistCoeffs.toDoubleJsonArray())
    .put("cam1_camera_matrix", cam1CameraMatrix.toDoubleJsonArray2()).put("cam1_dist_coeffs", cam1DistCoeffs.toDoubleJsonArray())
    .put("stereo_R", stereoR.toDoubleJsonArray2()).put("stereo_T", stereoT.toDoubleJsonArray()).put("stereo_E", stereoE.toDoubleJsonArray2()).put("stereo_F", stereoF.toDoubleJsonArray2())
    .put("errors", errors.toStringJsonArray())

private fun utcNow(): String = SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss.SSS'Z'", Locale.US).apply { timeZone = TimeZone.getTimeZone("UTC") }.format(Date())
private fun Mat.toFlatList(): List<Double> = (0 until rows()).flatMap { r -> (0 until cols()).map { c -> get(r, c)[0] } }
private fun Mat.toNestedList(): List<List<Double>> = (0 until rows()).map { r -> (0 until cols()).map { c -> get(r, c)[0] } }
private fun List<Double>.toDoubleJsonArray() = JSONArray().also { arr -> forEach { arr.put(it) } }
private fun List<String>.toStringJsonArray() = JSONArray().also { arr -> forEach { arr.put(it) } }
private fun List<List<Double>>.toDoubleJsonArray2() = JSONArray().also { outer -> forEach { outer.put(it.toDoubleJsonArray()) } }