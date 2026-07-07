package com.maklertour.data.calibration

import android.graphics.BitmapFactory
import android.util.Log
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
    private val defaultStereoRmsThresholdPx: Double = DEFAULT_STEREO_RMS_THRESHOLD_PX,
    private val defaultIntrinsicsRmsThresholdPx: Double = DEFAULT_INTRINSICS_RMS_THRESHOLD_PX,
) {
    fun run(sessionDir: File, stereoRmsThresholdPx: Double = defaultStereoRmsThresholdPx): StereoCalibrationResult = runStereoExtrinsics(sessionDir, stereoRmsThresholdPx)

    fun runCam0Intrinsics(sessionDir: File, intrinsicsRmsThresholdPx: Double = defaultIntrinsicsRmsThresholdPx): StereoCalibrationResult =
        runIntrinsics(sessionDir, "cam0", CAM0_INTRINSICS_FILE, intrinsicsRmsThresholdPx)

    fun runCam1Intrinsics(sessionDir: File, intrinsicsRmsThresholdPx: Double = defaultIntrinsicsRmsThresholdPx): StereoCalibrationResult =
        runIntrinsics(sessionDir, "cam1", CAM1_INTRINSICS_FILE, intrinsicsRmsThresholdPx)

    fun runStereoExtrinsics(sessionDir: File, stereoRmsThresholdPx: Double = defaultStereoRmsThresholdPx): StereoCalibrationResult {
        val errors = mutableListOf<String>()
        val resultFile = File(sessionDir, RESULT_FILE)
        fun finish(result: StereoCalibrationResult): StereoCalibrationResult {
            resultFile.writeText(result.toJson().toString(2))
            File(sessionDir, STEREO_EXTRINSICS_FILE).writeText(result.toJson().toString(2))
            return result
        }

        val openCvReady = runCatching { OpenCVLoader.initDebug() }.getOrDefault(false)
        if (!openCvReady) return finish(failed(sessionDir, errors + "OpenCV unavailable"))

        val inputFile = File(sessionDir, "calibration_input.json")
        val manifestFile = File(sessionDir, "pairs_manifest.json")
        if (!inputFile.exists()) return finish(failed(sessionDir, errors + "Missing calibration_input.json"))
        if (!manifestFile.exists()) return finish(failed(sessionDir, errors + "Missing pairs_manifest.json"))

        val cam0Intrinsics = validateIntrinsicsFile(File(sessionDir, CAM0_INTRINSICS_FILE), "cam0")
        if (cam0Intrinsics.error != null) return finish(failed(sessionDir, errors + cam0Intrinsics.error))
        val cam1Intrinsics = validateIntrinsicsFile(File(sessionDir, CAM1_INTRINSICS_FILE), "cam1")
        if (cam1Intrinsics.error != null) return finish(failed(sessionDir, errors + cam1Intrinsics.error))
        val cam0IntrinsicsJson = cam0Intrinsics.json ?: return finish(failed(sessionDir, errors + "cam0 intrinsics not successful"))
        val cam1IntrinsicsJson = cam1Intrinsics.json ?: return finish(failed(sessionDir, errors + "cam1 intrinsics not successful"))
        val input = JSONObject(inputFile.readText())
        val settings = readSettings(input)
        val pairs = filteredPairsForWorkflow(JSONObject(manifestFile.readText()).optJSONArray("pairs") ?: JSONArray(), STEREO_EXTRINSICS_MODE)
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
            val cam0Path = pair.optString("cam0_file")
            val cam1Path = pair.optString("cam1_file")
            if (cam0Path.isBlank() || cam1Path.isBlank()) continue
            val cam0 = detectCorners(File(sessionDir, cam0Path), boardSize, expectedCorners)
            val cam1 = detectCorners(File(sessionDir, cam1Path), boardSize, expectedCorners)
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
        if (pairsUsed < requiredForSuccess) return finish(failed(sessionDir, errors + "Only $pairsUsed usable pairs; need at least $requiredForSuccess", settings, pairs.length(), pairsUsed, cam0ImageSize, cam1ImageSize))

        val cam0Size = cam0ImageSize ?: Size(cam0IntrinsicsJson.optInt("image_width").toDouble(), cam0IntrinsicsJson.optInt("image_height").toDouble())
        val cam1Size = cam1ImageSize ?: Size(cam1IntrinsicsJson.optInt("image_width").toDouble(), cam1IntrinsicsJson.optInt("image_height").toDouble())

        return try {
            val cam0Matrix = matFromNestedJson(cam0IntrinsicsJson.getJSONArray("camera_matrix"))
            val cam1Matrix = matFromNestedJson(cam1IntrinsicsJson.getJSONArray("camera_matrix"))
            val cam0Dist = matFromFlatJson(cam0IntrinsicsJson.getJSONArray("dist_coeffs"))
            val cam1Dist = matFromFlatJson(cam1IntrinsicsJson.getJSONArray("dist_coeffs"))
            val trials = cornerOrderVariants(settings.checkerboardInnerRows, settings.checkerboardInnerCols).map { variant ->
                val trialR = Mat(); val trialT = Mat(); val trialE = Mat(); val trialF = Mat()
                val trialCam1Points = cam1Points.map { transformCornerOrder(it, settings.checkerboardInnerRows, settings.checkerboardInnerCols, variant) }
                val trialCam0Matrix = cam0Matrix.clone()
                val trialCam1Matrix = cam1Matrix.clone()
                val trialCam0Dist = cam0Dist.clone()
                val trialCam1Dist = cam1Dist.clone()
                val rms = runCatching {
                    Calib3d.stereoCalibrate(
                        objectPoints, cam0Points, trialCam1Points, trialCam0Matrix, trialCam0Dist, trialCam1Matrix, trialCam1Dist,
                        cam0Size, trialR, trialT, trialE, trialF, Calib3d.CALIB_FIX_INTRINSIC,
                        TermCriteria(TermCriteria.EPS + TermCriteria.MAX_ITER, 100, 1e-5),
                    )
                }.getOrDefault(Double.POSITIVE_INFINITY)
                CornerOrderTrial(variant, rms, trialR, trialT, trialE, trialF)
            }
            val bestTrial = trials.minByOrNull { it.rms } ?: error("No corner order trials")
            Log.i("StereoCalibrationProcessor", "corner_order_trials ${trials.joinToString(" ") { "${it.variant}=${it.rms}" }} selected=${bestTrial.variant}")
            val r = bestTrial.r
            val t = bestTrial.t
            val e = bestTrial.e
            val f = bestTrial.f
            val stereoRms = bestTrial.rms
            val success = stereoRms.isFinite() && stereoRms <= stereoRmsThresholdPx
            finish(
                StereoCalibrationResult(
                    status = if (success) "success" else "failed", createdAtUtc = utcNow(), sessionPath = sessionDir.absolutePath,
                    checkerboardInnerCols = settings.checkerboardInnerCols, checkerboardInnerRows = settings.checkerboardInnerRows, squareSizeMm = settings.squareSizeMm,
                    pairsTotal = pairs.length(), pairsUsed = pairsUsed, stereoRms = stereoRms,
                    cam0ImageWidth = cam0Size.width.toInt(), cam0ImageHeight = cam0Size.height.toInt(), cam1ImageWidth = cam1Size.width.toInt(), cam1ImageHeight = cam1Size.height.toInt(),
                    cam0CameraMatrix = cam0Matrix.toNestedList(), cam0DistCoeffs = cam0Dist.toFlatList(), cam1CameraMatrix = cam1Matrix.toNestedList(), cam1DistCoeffs = cam1Dist.toFlatList(),
                    stereoR = r.toNestedList(), stereoT = t.toFlatList(), stereoE = e.toNestedList(), stereoF = f.toNestedList(),
                    cornerOrderTrials = trials.map { CornerOrderTrialResult(it.variant, it.rms) },
                    selectedCornerOrderVariant = bestTrial.variant,
                    selectedCornerOrderRms = stereoRms,
                    errors = if (success) errors else errors + "Stereo RMS $stereoRms exceeds threshold $stereoRmsThresholdPx px",
                )
            )
        } catch (t: Throwable) {
            finish(failed(sessionDir, errors + "Stereo calibration failed: ${t.message ?: t.javaClass.simpleName}", settings, pairs.length(), pairsUsed, cam0ImageSize, cam1ImageSize))
        }
    }

    private fun runIntrinsics(sessionDir: File, camera: String, outputName: String, intrinsicsRmsThresholdPx: Double): StereoCalibrationResult {
        val errors = mutableListOf<String>()
        val outputFile = File(sessionDir, outputName)
        fun finish(result: StereoCalibrationResult): StereoCalibrationResult {
            outputFile.writeText(result.toIntrinsicsJson(camera).toString(2))
            return result
        }
        val openCvReady = runCatching { OpenCVLoader.initDebug() }.getOrDefault(false)
        if (!openCvReady) return finish(failed(sessionDir, errors + "OpenCV unavailable"))
        val inputFile = File(sessionDir, "calibration_input.json")
        val manifestFile = File(sessionDir, "pairs_manifest.json")
        if (!inputFile.exists()) return finish(failed(sessionDir, errors + "Missing calibration_input.json"))
        if (!manifestFile.exists()) return finish(failed(sessionDir, errors + "Missing pairs_manifest.json"))
        val settings = readSettings(JSONObject(inputFile.readText()))
        val workflowMode = if (camera == "cam0") CAM0_INTRINSICS_MODE else CAM1_INTRINSICS_MODE
        val frames = filteredPairsForWorkflow(JSONObject(manifestFile.readText()).optJSONArray("pairs") ?: JSONArray(), workflowMode)
        val boardSize = Size(settings.checkerboardInnerCols.toDouble(), settings.checkerboardInnerRows.toDouble())
        val expectedCorners = settings.checkerboardInnerCols * settings.checkerboardInnerRows
        val objectTemplate = buildObjectPoints(settings)
        val objectPoints = mutableListOf<Mat>()
        val imagePoints = mutableListOf<Mat>()
        var imageSize: Size? = null
        for (i in 0 until frames.length()) {
            val frame = frames.optJSONObject(i) ?: continue
            val imagePath = frame.optString("${camera}_file")
            if (imagePath.isBlank()) continue
            val detection = detectCorners(File(sessionDir, imagePath), boardSize, expectedCorners)
            if (detection.corners != null) {
                objectPoints += objectTemplate.clone()
                imagePoints += detection.corners
                imageSize = detection.size
            } else {
                errors += "Skipped ${camera} frame ${frame.optInt("pair_index", i + 1)}: ${detection.error ?: "unknown error"}"
            }
        }
        val framesUsed = imagePoints.size
        val requiredForSuccess = max(minPairs, minOf(settings.requiredPairs, 15))
        if (framesUsed < requiredForSuccess) return finish(failed(sessionDir, errors + "Only $framesUsed usable $camera frames; need at least $requiredForSuccess", settings, frames.length(), framesUsed, imageSize, imageSize))
        val size = imageSize ?: return finish(failed(sessionDir, errors + "Missing $camera image size", settings, frames.length(), framesUsed, imageSize, imageSize))
        return try {
            val cameraMatrix = Mat.eye(3, 3, CvType.CV_64F)
            val distCoeffs = Mat.zeros(8, 1, CvType.CV_64F)
            val rvecs = mutableListOf<Mat>()
            val tvecs = mutableListOf<Mat>()
            val rms = Calib3d.calibrateCamera(objectPoints, imagePoints, size, cameraMatrix, distCoeffs, rvecs, tvecs)
            val success = rms.isFinite() && rms <= intrinsicsRmsThresholdPx
            finish(
                StereoCalibrationResult(
                    status = if (success) "success" else "failed", createdAtUtc = utcNow(), sessionPath = sessionDir.absolutePath,
                    checkerboardInnerCols = settings.checkerboardInnerCols, checkerboardInnerRows = settings.checkerboardInnerRows, squareSizeMm = settings.squareSizeMm,
                    pairsTotal = frames.length(), pairsUsed = framesUsed,
                    cam0Rms = if (camera == "cam0") rms else null, cam1Rms = if (camera == "cam1") rms else null,
                    cam0ImageWidth = if (camera == "cam0") size.width.toInt() else 0, cam0ImageHeight = if (camera == "cam0") size.height.toInt() else 0,
                    cam1ImageWidth = if (camera == "cam1") size.width.toInt() else 0, cam1ImageHeight = if (camera == "cam1") size.height.toInt() else 0,
                    cam0CameraMatrix = if (camera == "cam0") cameraMatrix.toNestedList() else emptyList(), cam0DistCoeffs = if (camera == "cam0") distCoeffs.toFlatList() else emptyList(),
                    cam1CameraMatrix = if (camera == "cam1") cameraMatrix.toNestedList() else emptyList(), cam1DistCoeffs = if (camera == "cam1") distCoeffs.toFlatList() else emptyList(),
                    errors = if (success) errors else errors + if (!rms.isFinite()) {
                        "Intrinsics calibration returned non-finite RMS"
                    } else {
                        "$camera intrinsics RMS $rms exceeds threshold $intrinsicsRmsThresholdPx px"
                    },
                )
            )
        } catch (t: Throwable) {
            finish(failed(sessionDir, errors + "$camera intrinsics calibration failed: ${t.message ?: t.javaClass.simpleName}", settings, frames.length(), framesUsed, imageSize, imageSize))
        }
    }

    private fun filteredPairsForWorkflow(pairs: JSONArray, workflowMode: String): JSONArray {
        val filtered = JSONArray()
        for (i in 0 until pairs.length()) {
            val pair = pairs.optJSONObject(i) ?: continue
            val entryWorkflowMode = pair.optString("calibration_workflow_mode", "")
            if (entryWorkflowMode.isBlank() || entryWorkflowMode == workflowMode) {
                filtered.put(pair)
            }
        }
        return filtered
    }

    private data class IntrinsicsValidation(val json: JSONObject?, val error: String?)

    private fun validateIntrinsicsFile(file: File, camera: String): IntrinsicsValidation {
        val invalid = "$camera intrinsics not successful"
        if (!file.exists()) return IntrinsicsValidation(null, invalid)
        val json = runCatching { JSONObject(file.readText()) }.getOrNull() ?: return IntrinsicsValidation(null, invalid)
        if (json.optString("status") != "success") return IntrinsicsValidation(null, invalid)
        val cameraMatrix = json.optJSONArray("camera_matrix") ?: return IntrinsicsValidation(null, invalid)
        if (cameraMatrix.length() != 3) return IntrinsicsValidation(null, invalid)
        for (rowIndex in 0 until 3) {
            val row = cameraMatrix.optJSONArray(rowIndex) ?: return IntrinsicsValidation(null, invalid)
            if (row.length() != 3) return IntrinsicsValidation(null, invalid)
        }
        val distCoeffs = json.optJSONArray("dist_coeffs") ?: return IntrinsicsValidation(null, invalid)
        if (distCoeffs.length() == 0) return IntrinsicsValidation(null, invalid)
        if (json.optInt("image_width", 0) <= 0 || json.optInt("image_height", 0) <= 0) return IntrinsicsValidation(null, invalid)
        return IntrinsicsValidation(json, null)
    }

    private fun readSettings(input: JSONObject) = CalibrationSettings(
        checkerboardInnerCols = input.optInt("checkerboard_inner_cols", input.optJSONObject("checkerboard_settings")?.optInt("checkerboardInnerCols", 9) ?: 9),
        checkerboardInnerRows = input.optInt("checkerboard_inner_rows", input.optJSONObject("checkerboard_settings")?.optInt("checkerboardInnerRows", 6) ?: 6),
        squareSizeMm = input.optDouble("square_size_mm", input.optJSONObject("checkerboard_settings")?.optDouble("squareSizeMm", 25.0) ?: 25.0),
        requiredPairs = input.optInt("required_pairs", input.optJSONObject("checkerboard_settings")?.optInt("requiredPairs", 20) ?: 20),
    )

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
                Imgproc.cornerSubPix(gray, corners, Size(11.0, 11.0), Size(-1.0, -1.0), TermCriteria(TermCriteria.EPS + TermCriteria.MAX_ITER, 30, 0.1))
                CornerDetection(MatOfPoint2f(*corners.toArray()), Size(bitmap.width.toDouble(), bitmap.height.toDouble()), null)
            } else CornerDetection(null, Size(bitmap.width.toDouble(), bitmap.height.toDouble()), "checkerboard not found")
        } finally { rgba.release(); gray.release(); corners.release(); bitmap.recycle() }
    }

    private fun buildObjectPoints(settings: CalibrationSettings): MatOfPoint3f {
        val points = ArrayList<Point3>()
        for (row in 0 until settings.checkerboardInnerRows) for (col in 0 until settings.checkerboardInnerCols) points += Point3(col * settings.squareSizeMm, row * settings.squareSizeMm, 0.0)
        return MatOfPoint3f(*points.toTypedArray())
    }

    private fun cornerOrderVariants(rows: Int, cols: Int): List<String> = listOf("normal", "reverse_all", "flip_rows", "flip_columns", "rotate_180")

    private fun transformCornerOrder(points: MatOfPoint2f, rows: Int, cols: Int, variant: String): MatOfPoint2f {
        val source = points.toArray().toList()
        val transformed = when (variant) {
            "normal" -> source
            "reverse_all" -> source.asReversed()
            "flip_rows" -> (0 until rows).flatMap { row -> source.subList(row * cols, row * cols + cols).asReversed() }
            "flip_columns" -> (rows - 1 downTo 0).flatMap { row -> source.subList(row * cols, row * cols + cols) }
            "rotate_180" -> (rows - 1 downTo 0).flatMap { row -> source.subList(row * cols, row * cols + cols).asReversed() }
            else -> source
        }
        return MatOfPoint2f(*transformed.toTypedArray())
    }

    private data class CornerDetection(val corners: MatOfPoint2f?, val size: Size?, val error: String?)
    private data class CornerOrderTrial(val variant: String, val rms: Double, val r: Mat, val t: Mat, val e: Mat, val f: Mat)

    companion object {
        const val DEFAULT_STEREO_RMS_THRESHOLD_PX: Double = 2.0
        const val DEFAULT_INTRINSICS_RMS_THRESHOLD_PX: Double = 3.0

        const val RESULT_FILE = "calibration_result.json"
        const val CAM0_INTRINSICS_FILE = "cam0_intrinsics.json"
        const val CAM1_INTRINSICS_FILE = "cam1_intrinsics.json"
        const val STEREO_EXTRINSICS_FILE = "stereo_extrinsics.json"
        private const val CAM0_INTRINSICS_MODE = "CAM0_INTRINSICS"
        private const val CAM1_INTRINSICS_MODE = "CAM1_INTRINSICS"
        private const val STEREO_EXTRINSICS_MODE = "STEREO_EXTRINSICS"
    }
}

data class CornerOrderTrialResult(val variant: String, val rms: Double)

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
    val cornerOrderTrials: List<CornerOrderTrialResult> = emptyList(),
    val selectedCornerOrderVariant: String? = null,
    val selectedCornerOrderRms: Double? = null,
    val errors: List<String> = emptyList(),
) {
    val resultPath: String get() = File(sessionPath, StereoCalibrationProcessor.RESULT_FILE).absolutePath
}

fun StereoCalibrationResult.toIntrinsicsJson(camera: String): JSONObject {
    val rms = if (camera == "cam0") cam0Rms else cam1Rms
    val width = if (camera == "cam0") cam0ImageWidth else cam1ImageWidth
    val height = if (camera == "cam0") cam0ImageHeight else cam1ImageHeight
    val matrix = if (camera == "cam0") cam0CameraMatrix else cam1CameraMatrix
    val dist = if (camera == "cam0") cam0DistCoeffs else cam1DistCoeffs
    return JSONObject()
        .put("status", status)
        .put("camera", camera)
        .put("created_at_utc", createdAtUtc)
        .put("session_path", sessionPath)
        .put("camera_matrix", matrix.toDoubleJsonArray2())
        .put("dist_coeffs", dist.toDoubleJsonArray())
        .put("image_width", width)
        .put("image_height", height)
        .put("rms", rms)
        .put("frames_used", pairsUsed)
        .put("checkerboard_inner_cols", checkerboardInnerCols)
        .put("checkerboard_inner_rows", checkerboardInnerRows)
        .put("square_size_mm", squareSizeMm)
        .put("errors", errors.toStringJsonArray())
}

fun StereoCalibrationResult.toJson(): JSONObject = JSONObject()
    .put("status", status).put("created_at_utc", createdAtUtc).put("session_path", sessionPath)
    .put("checkerboard_inner_cols", checkerboardInnerCols).put("checkerboard_inner_rows", checkerboardInnerRows).put("square_size_mm", squareSizeMm)
    .put("pairs_total", pairsTotal).put("pairs_used", pairsUsed).put("cam0_rms", cam0Rms).put("cam1_rms", cam1Rms).put("stereo_rms", stereoRms)
    .put("cam0_image_width", cam0ImageWidth).put("cam0_image_height", cam0ImageHeight).put("cam1_image_width", cam1ImageWidth).put("cam1_image_height", cam1ImageHeight)
    .put("cam0_camera_matrix", cam0CameraMatrix.toDoubleJsonArray2()).put("cam0_dist_coeffs", cam0DistCoeffs.toDoubleJsonArray())
    .put("cam1_camera_matrix", cam1CameraMatrix.toDoubleJsonArray2()).put("cam1_dist_coeffs", cam1DistCoeffs.toDoubleJsonArray())
    .put("stereo_R", stereoR.toDoubleJsonArray2()).put("stereo_T", stereoT.toDoubleJsonArray()).put("stereo_E", stereoE.toDoubleJsonArray2()).put("stereo_F", stereoF.toDoubleJsonArray2())
    .put("corner_order_trials", cornerOrderTrials.toJsonArray())
    .put("selected_corner_order_variant", selectedCornerOrderVariant ?: JSONObject.NULL)
    .put("selected_corner_order_rms", selectedCornerOrderRms ?: JSONObject.NULL)
    .put("errors", errors.toStringJsonArray())

private fun utcNow(): String = SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss.SSS'Z'", Locale.US).apply { timeZone = TimeZone.getTimeZone("UTC") }.format(Date())
private fun Mat.toFlatList(): List<Double> = (0 until rows()).flatMap { r -> (0 until cols()).map { c -> get(r, c)[0] } }
private fun Mat.toNestedList(): List<List<Double>> = (0 until rows()).map { r -> (0 until cols()).map { c -> get(r, c)[0] } }
private fun List<Double>.toDoubleJsonArray() = JSONArray().also { arr -> forEach { arr.put(it) } }
private fun List<String>.toStringJsonArray() = JSONArray().also { arr -> forEach { arr.put(it) } }
private fun List<CornerOrderTrialResult>.toJsonArray() = JSONArray().also { arr -> forEach { arr.put(JSONObject().put("variant", it.variant).put("rms", it.rms)) } }
private fun List<List<Double>>.toDoubleJsonArray2() = JSONArray().also { outer -> forEach { outer.put(it.toDoubleJsonArray()) } }
private fun matFromNestedJson(json: JSONArray): Mat {
    val rows = json.length()
    val cols = if (rows > 0) json.getJSONArray(0).length() else 0
    val mat = Mat(rows, cols, CvType.CV_64F)
    for (r in 0 until rows) {
        val row = json.getJSONArray(r)
        for (c in 0 until cols) mat.put(r, c, row.getDouble(c))
    }
    return mat
}

private fun matFromFlatJson(json: JSONArray): Mat {
    val mat = Mat(json.length(), 1, CvType.CV_64F)
    for (i in 0 until json.length()) mat.put(i, 0, json.getDouble(i))
    return mat
}
