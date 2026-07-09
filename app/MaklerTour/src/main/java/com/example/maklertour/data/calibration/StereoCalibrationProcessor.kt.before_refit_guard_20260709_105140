package com.maklertour.data.calibration

import android.graphics.BitmapFactory
import android.util.Log
import com.maklertour.data.rig.CalibrationBoardType
import com.maklertour.data.rig.CalibrationSettings
import org.json.JSONArray
import org.json.JSONObject
import org.opencv.android.OpenCVLoader
import org.opencv.calib3d.Calib3d
import org.opencv.core.CvType
import org.opencv.core.Mat
import org.opencv.core.MatOfPoint2f
import org.opencv.core.MatOfPoint3f
import org.opencv.core.Point
import org.opencv.core.Point3
import org.opencv.core.Size
import org.opencv.core.TermCriteria
import org.opencv.imgproc.Imgproc
import org.opencv.objdetect.CharucoBoard
import org.opencv.objdetect.CharucoDetector
import org.opencv.objdetect.CharucoParameters
import org.opencv.objdetect.DetectorParameters
import org.opencv.objdetect.Objdetect
import org.opencv.objdetect.RefineParameters
import java.io.File
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.TimeZone
import kotlin.math.abs
import kotlin.math.max
import kotlin.math.sqrt

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
        val cam0BoardError = validateIntrinsicsBoardType(cam0IntrinsicsJson, settings, "cam0")
        if (cam0BoardError != null) return finish(failed(sessionDir, errors + cam0BoardError, settings))
        val cam1BoardError = validateIntrinsicsBoardType(cam1IntrinsicsJson, settings, "cam1")
        if (cam1BoardError != null) return finish(failed(sessionDir, errors + cam1BoardError, settings))
        val pairs = filteredPairsForWorkflow(JSONObject(manifestFile.readText()).optJSONArray("pairs") ?: JSONArray(), STEREO_EXTRINSICS_MODE)
        val boardSize = Size(settings.checkerboardInnerCols.toDouble(), settings.checkerboardInnerRows.toDouble())
        val expectedCorners = settings.checkerboardInnerCols * settings.checkerboardInnerRows
        val objectTemplate = buildObjectPoints(settings)
        val objectPoints = mutableListOf<Mat>()
        val cam0Points = mutableListOf<Mat>()
        val cam1Points = mutableListOf<Mat>()
        val usablePairIndexes = mutableListOf<Int>()
        var cam0ImageSize: Size? = null
        var cam1ImageSize: Size? = null
        val charucoCommonIdsPerPair = mutableListOf<List<Int>>()
        val rejectedPairReasons = linkedMapOf<Int, String>()

        for (i in 0 until pairs.length()) {
            val pair = pairs.optJSONObject(i) ?: continue
            val cam0Path = pair.optString("cam0_file")
            val cam1Path = pair.optString("cam1_file")
            if (cam0Path.isBlank() || cam1Path.isBlank()) continue
            if (settings.boardType == CalibrationBoardType.CHARUCO) {
                val cam0 = detectCharucoCorners(File(sessionDir, cam0Path), settings)
                val cam1 = detectCharucoCorners(File(sessionDir, cam1Path), settings)
                val commonIds = cam0.ids.intersect(cam1.ids.toSet()).sorted()
                if (cam0.error == null && cam1.error == null && commonIds.size >= STEREO_PROCESSOR_MIN_COMMON_CHARUCO_IDS) {
                    objectPoints += MatOfPoint3f(*commonIds.mapNotNull { cam0.objectPointsById[it] }.toTypedArray())
                    cam0Points += MatOfPoint2f(*commonIds.mapNotNull { cam0.imagePointsById[it] }.toTypedArray())
                    cam1Points += MatOfPoint2f(*commonIds.mapNotNull { cam1.imagePointsById[it] }.toTypedArray())
                    charucoCommonIdsPerPair += commonIds
                    usablePairIndexes += pair.optInt("pair_index", i + 1)
                    cam0ImageSize = cam0.size
                    cam1ImageSize = cam1.size
                } else {
                    val pairIndex = pair.optInt("pair_index", i + 1)
                    val reason = "common ChArUco ids=${commonIds.size}, cam0=${cam0.error ?: "ok"}, cam1=${cam1.error ?: "ok"}"
                    rejectedPairReasons[pairIndex] = reason
                    errors += "Skipped pair $pairIndex: $reason"
                }
            } else {
                val cam0 = detectCorners(File(sessionDir, cam0Path), boardSize, expectedCorners)
                val cam1 = detectCorners(File(sessionDir, cam1Path), boardSize, expectedCorners)
                if (cam0.corners != null && cam1.corners != null) {
                    objectPoints += objectTemplate.clone()
                    cam0Points += cam0.corners
                    cam1Points += cam1.corners
                    usablePairIndexes += pair.optInt("pair_index", i + 1)
                    cam0ImageSize = cam0.size
                    cam1ImageSize = cam1.size
                } else {
                    errors += "Skipped pair ${pair.optInt("pair_index", i + 1)}: cam0=${cam0.error ?: "ok"}, cam1=${cam1.error ?: "ok"}"
                }
            }
        }

        val pairsUsed = cam0Points.size
        val requiredForSuccess = maxOf(STEREO_MIN_FILTERED_PAIRS, minPairs, minOf(settings.requiredPairs, 15))
        if (pairsUsed < STEREO_MIN_FILTERED_PAIRS) return finish(failed(sessionDir, errors + "Not enough valid stereo pairs after common-id filtering: $pairsUsed/$STEREO_MIN_FILTERED_PAIRS", settings, pairs.length(), pairsUsed, cam0ImageSize, cam1ImageSize, rejectedPairIndexes = rejectedPairReasons.keys.toList(), rejectedPairReasons = rejectedPairReasons))
        if (pairsUsed < requiredForSuccess) return finish(failed(sessionDir, errors + "Only $pairsUsed usable pairs; need at least $requiredForSuccess", settings, pairs.length(), pairsUsed, cam0ImageSize, cam1ImageSize, rejectedPairIndexes = rejectedPairReasons.keys.toList(), rejectedPairReasons = rejectedPairReasons))

        val cam0Size = cam0ImageSize ?: Size(cam0IntrinsicsJson.optInt("image_width").toDouble(), cam0IntrinsicsJson.optInt("image_height").toDouble())
        val cam1Size = cam1ImageSize ?: Size(cam1IntrinsicsJson.optInt("image_width").toDouble(), cam1IntrinsicsJson.optInt("image_height").toDouble())

        return try {
            val cam0Matrix = matFromNestedJson(cam0IntrinsicsJson.getJSONArray("camera_matrix"))
            val cam1Matrix = matFromNestedJson(cam1IntrinsicsJson.getJSONArray("camera_matrix"))
            val cam0Dist = matFromFlatJson(cam0IntrinsicsJson.getJSONArray("dist_coeffs"))
            val cam1Dist = matFromFlatJson(cam1IntrinsicsJson.getJSONArray("dist_coeffs"))
            val r: Mat
            val t: Mat
            val e: Mat
            val f: Mat
            val stereoRms: Double
            val trials: List<CornerOrderTrialResult>
            val selectedVariant: String
            val bestCam1Points: List<Mat>
            if (settings.boardType == CalibrationBoardType.CHARUCO) {
                r = Mat(); t = Mat(); e = Mat(); f = Mat()
                stereoRms = Calib3d.stereoCalibrate(
                    objectPoints, cam0Points, cam1Points, cam0Matrix, cam0Dist, cam1Matrix, cam1Dist,
                    cam0Size, r, t, e, f, Calib3d.CALIB_FIX_INTRINSIC,
                    TermCriteria(TermCriteria.EPS + TermCriteria.MAX_ITER, 100, 1e-5),
                )
                trials = emptyList()
                selectedVariant = "charuco_ids"
                bestCam1Points = cam1Points
            } else {
                val trialModels = cornerOrderVariants(settings.checkerboardInnerRows, settings.checkerboardInnerCols).map { variant ->
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
                val bestTrial = trialModels.minByOrNull { it.rms } ?: error("No corner order trials")
                Log.i("StereoCalibrationProcessor", "corner_order_trials ${trialModels.joinToString(" ") { "${it.variant}=${it.rms}" }} selected=${bestTrial.variant}")
                r = bestTrial.r; t = bestTrial.t; e = bestTrial.e; f = bestTrial.f
                stereoRms = bestTrial.rms
                trials = trialModels.map { CornerOrderTrialResult(it.variant, it.rms) }
                selectedVariant = bestTrial.variant
                bestCam1Points = cam1Points.map { transformCornerOrder(it, settings.checkerboardInnerRows, settings.checkerboardInnerCols, bestTrial.variant) }
            }
            val initialPerPairErrors = if (stereoRms.isFinite()) computePerPairEpipolarErrors(cam0Points, bestCam1Points, f, usablePairIndexes) else emptyList()
            val candidateRejectedPairIndexes = selectOutlierPairIndexes(initialPerPairErrors, requiredForSuccess)
            val candidateRejectedSet = candidateRejectedPairIndexes.toSet()
            val acceptedPositions = usablePairIndexes.mapIndexedNotNull { index, pairIndex ->
                if (pairIndex in candidateRejectedSet) null else index
            }

            var finalR = r
            var finalT = t
            var finalE = e
            var finalF = f
            var finalStereoRms = stereoRms
            var finalCam1Points = bestCam1Points
            var finalPerPairErrors = initialPerPairErrors
            var finalPairsUsed = pairsUsed
            var finalRejectedPairIndexes = emptyList<Int>()
            var finalOutlierMode: String? = null

            if (
                stereoRms.isFinite() &&
                candidateRejectedPairIndexes.isNotEmpty() &&
                acceptedPositions.size >= requiredForSuccess &&
                acceptedPositions.size < pairsUsed
            ) {
                val refitR = Mat()
                val refitT = Mat()
                val refitE = Mat()
                val refitF = Mat()

                val refitObjectPoints = acceptedPositions.map { objectPoints[it] }
                val refitCam0Points = acceptedPositions.map { cam0Points[it] }
                val refitCam1Points = acceptedPositions.map { bestCam1Points[it] }
                val refitPairIndexes = acceptedPositions.map { usablePairIndexes[it] }

                val refitRms = runCatching {
                    Calib3d.stereoCalibrate(
                        refitObjectPoints,
                        refitCam0Points,
                        refitCam1Points,
                        cam0Matrix.clone(),
                        cam0Dist.clone(),
                        cam1Matrix.clone(),
                        cam1Dist.clone(),
                        cam0Size,
                        refitR,
                        refitT,
                        refitE,
                        refitF,
                        Calib3d.CALIB_FIX_INTRINSIC,
                        TermCriteria(TermCriteria.EPS + TermCriteria.MAX_ITER, 100, 1e-5),
                    )
                }.getOrDefault(Double.POSITIVE_INFINITY)

                if (refitRms.isFinite()) {
                    finalR = refitR
                    finalT = refitT
                    finalE = refitE
                    finalF = refitF
                    finalStereoRms = refitRms
                    finalCam1Points = refitCam1Points
                    finalPerPairErrors = computePerPairEpipolarErrors(refitCam0Points, refitCam1Points, refitF, refitPairIndexes)
                    finalPairsUsed = acceptedPositions.size
                    candidateRejectedPairIndexes.forEach { rejectedPairReasons[it] = "epipolar error above robust limit" }
                    finalRejectedPairIndexes = (rejectedPairReasons.keys).toList()
                    finalOutlierMode = "common_id_and_epipolar_error_filter"
                    Log.i(
                        "StereoCalibrationProcessor",
                        "outlier_refit accepted initial_rms=$stereoRms refined_rms=$refitRms rejected=${candidateRejectedPairIndexes.joinToString(",")}",
                    )
                } else {
                    Log.i(
                        "StereoCalibrationProcessor",
                        "outlier_refit skipped initial_rms=$stereoRms refit_rms=$refitRms candidate_rejected=${candidateRejectedPairIndexes.joinToString(",")}",
                    )
                }
            }

            val worstPairIndexes = finalPerPairErrors.sortedByDescending { it.error }.take(5).map { it.pairIndex }
            val success = finalStereoRms.isFinite() && finalStereoRms <= stereoRmsThresholdPx
            finish(
                StereoCalibrationResult(
                    status = if (success) "success" else "failed", createdAtUtc = utcNow(), sessionPath = sessionDir.absolutePath,
                    checkerboardInnerCols = settings.checkerboardInnerCols, checkerboardInnerRows = settings.checkerboardInnerRows, squareSizeMm = settings.squareSizeMm,
                    boardType = settings.boardType.name, charucoSquaresX = settings.charucoSquaresX, charucoSquaresY = settings.charucoSquaresY, charucoSquareLengthMm = settings.charucoSquareLengthMm, charucoMarkerLengthMm = settings.charucoMarkerLengthMm, charucoDictionary = settings.charucoDictionary, minCharucoCorners = settings.minCharucoCorners, charucoLegacyPattern = settings.charucoLegacyPattern, charucoCommonIdsPerPair = charucoCommonIdsPerPair,
                    pairsTotal = pairs.length(), pairsUsed = finalPairsUsed, stereoRms = finalStereoRms,
                    initialStereoRms = stereoRms,
                    pairsCandidatesAfterCommonIdFilter = pairsUsed,
                    rejectedPairIndexes = finalRejectedPairIndexes.ifEmpty { rejectedPairReasons.keys.toList() },
                    rejectedPairReasons = rejectedPairReasons,
                    outlierRejectionMode = finalOutlierMode ?: if (rejectedPairReasons.isNotEmpty()) "common_id_and_epipolar_error_filter" else null,
                    perPairErrorsInitial = initialPerPairErrors,
                    outlierFinalRms = finalStereoRms,
                    cam0ImageWidth = cam0Size.width.toInt(), cam0ImageHeight = cam0Size.height.toInt(), cam1ImageWidth = cam1Size.width.toInt(), cam1ImageHeight = cam1Size.height.toInt(),
                    cam0CameraMatrix = cam0Matrix.toNestedList(), cam0DistCoeffs = cam0Dist.toFlatList(), cam1CameraMatrix = cam1Matrix.toNestedList(), cam1DistCoeffs = cam1Dist.toFlatList(),
                    stereoR = finalR.toNestedList(), stereoT = finalT.toFlatList(), stereoE = finalE.toNestedList(), stereoF = finalF.toNestedList(),
                    cornerOrderTrials = trials,
                    selectedCornerOrderVariant = selectedVariant,
                    selectedCornerOrderRms = finalStereoRms,
                    perPairErrors = finalPerPairErrors,
                    worstPairIndexes = worstPairIndexes,
                    errors = if (success) errors else errors + "Stereo RMS $finalStereoRms exceeds threshold $stereoRmsThresholdPx px",
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
        val charucoCornersUsedPerFrame = mutableListOf<Int>()
        for (i in 0 until frames.length()) {
            val frame = frames.optJSONObject(i) ?: continue
            val imagePath = frame.optString("${camera}_file")
            if (imagePath.isBlank()) continue
            if (settings.boardType == CalibrationBoardType.CHARUCO) {
                val detection = detectCharucoCorners(File(sessionDir, imagePath), settings)
                if (detection.error == null && detection.ids.size >= settings.minCharucoCorners) {
                    val sortedIds = detection.ids.sorted()
                    objectPoints += MatOfPoint3f(*sortedIds.mapNotNull { detection.objectPointsById[it] }.toTypedArray())
                    imagePoints += MatOfPoint2f(*sortedIds.mapNotNull { detection.imagePointsById[it] }.toTypedArray())
                    charucoCornersUsedPerFrame += sortedIds.size
                    imageSize = detection.size
                } else {
                    errors += "Skipped ${camera} frame ${frame.optInt("pair_index", i + 1)}: ${detection.error ?: "only ${detection.ids.size} ChArUco corners"}"
                }
            } else {
                val detection = detectCorners(File(sessionDir, imagePath), boardSize, expectedCorners)
                if (detection.corners != null) {
                    objectPoints += objectTemplate.clone()
                    imagePoints += detection.corners
                    imageSize = detection.size
                } else {
                    errors += "Skipped ${camera} frame ${frame.optInt("pair_index", i + 1)}: ${detection.error ?: "unknown error"}"
                }
            }
        }
        val framesUsed = imagePoints.size
        val requiredForSuccess = max(minPairs, minOf(settings.requiredPairs, 15))
        if (framesUsed < requiredForSuccess) return finish(failed(sessionDir, errors + "Only $framesUsed usable $camera frames; need at least $requiredForSuccess", settings, frames.length(), framesUsed, imageSize, imageSize))
        val size = imageSize ?: return finish(failed(sessionDir, errors + "Missing $camera image size", settings, frames.length(), framesUsed, imageSize, imageSize))
        return try {
            val cameraMatrix = Mat.eye(3, 3, CvType.CV_64F)
            val distCoeffs = Mat.zeros(5, 1, CvType.CV_64F)
            val rvecs = mutableListOf<Mat>()
            val tvecs = mutableListOf<Mat>()
            val calibrationFlags = Calib3d.CALIB_FIX_K3 or Calib3d.CALIB_ZERO_TANGENT_DIST
            val rms = Calib3d.calibrateCamera(objectPoints, imagePoints, size, cameraMatrix, distCoeffs, rvecs, tvecs, calibrationFlags)
            val dist = distCoeffs.toFlatList()
            val k1 = dist.getOrElse(0) { 0.0 }
            val k2 = dist.getOrElse(1) { 0.0 }
            val unstableDistortion = abs(k1) > 1.5 || abs(k2) > 3.0
            val success = rms.isFinite() && rms <= intrinsicsRmsThresholdPx && !unstableDistortion
            finish(
                StereoCalibrationResult(
                    status = if (success) "success" else "failed", createdAtUtc = utcNow(), sessionPath = sessionDir.absolutePath,
                    checkerboardInnerCols = settings.checkerboardInnerCols, checkerboardInnerRows = settings.checkerboardInnerRows, squareSizeMm = settings.squareSizeMm,
                    boardType = settings.boardType.name, charucoSquaresX = settings.charucoSquaresX, charucoSquaresY = settings.charucoSquaresY, charucoSquareLengthMm = settings.charucoSquareLengthMm, charucoMarkerLengthMm = settings.charucoMarkerLengthMm, charucoDictionary = settings.charucoDictionary, minCharucoCorners = settings.minCharucoCorners, charucoLegacyPattern = settings.charucoLegacyPattern, charucoCornersUsedPerFrame = charucoCornersUsedPerFrame,
                    pairsTotal = frames.length(), pairsUsed = framesUsed,
                    cam0Rms = if (camera == "cam0") rms else null, cam1Rms = if (camera == "cam1") rms else null,
                    cam0ImageWidth = if (camera == "cam0") size.width.toInt() else 0, cam0ImageHeight = if (camera == "cam0") size.height.toInt() else 0,
                    cam1ImageWidth = if (camera == "cam1") size.width.toInt() else 0, cam1ImageHeight = if (camera == "cam1") size.height.toInt() else 0,
                    cam0CameraMatrix = if (camera == "cam0") cameraMatrix.toNestedList() else emptyList(), cam0DistCoeffs = if (camera == "cam0") dist else emptyList(),
                    cam1CameraMatrix = if (camera == "cam1") cameraMatrix.toNestedList() else emptyList(), cam1DistCoeffs = if (camera == "cam1") dist else emptyList(),
                    calibrationFlags = calibrationFlags,
                    distortionModel = DISTORTION_MODEL,
                    errors = if (success) errors else errors + when {
                        unstableDistortion -> "Unstable intrinsics distortion coefficients; use larger board / cover more image"
                        !rms.isFinite() -> "Intrinsics calibration returned non-finite RMS"
                        else -> "$camera intrinsics RMS $rms exceeds threshold $intrinsicsRmsThresholdPx px"
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
        return runCatching {
            val json = JSONObject(file.readText())
            if (json.optString("status") != "success") {
                IntrinsicsValidation(null, invalid)
            } else {
                val model = json.optString("distortion_model", "")
                if (model != DISTORTION_MODEL) {
                    IntrinsicsValidation(null, "$camera intrinsics use outdated distortion_model=$model; rerun full calibration")
                } else {
                    IntrinsicsValidation(json, null)
                }
            }
        }.getOrElse { IntrinsicsValidation(null, "$camera intrinsics read failed: ${it.message}") }
    }

    private fun validateIntrinsicsBoardType(json: JSONObject, settings: CalibrationSettings, camera: String): String? {
        val intrinsicsBoardType = json.optString("board_type", CalibrationBoardType.CHESSBOARD_LEGACY.name)
        return if (intrinsicsBoardType != settings.boardType.name) "$camera intrinsics board_type=$intrinsicsBoardType does not match current board_type=${settings.boardType.name}; rerun intrinsics" else null
    }

    private fun readSettings(input: JSONObject): CalibrationSettings {
        val nested = input.optJSONObject("checkerboard_settings")
        val boardTypeName = input.optString("board_type", nested?.optString("boardType", CalibrationBoardType.CHARUCO.name) ?: CalibrationBoardType.CHARUCO.name)
        return CalibrationSettings(
            checkerboardInnerCols = input.optInt("checkerboard_inner_cols", nested?.optInt("checkerboardInnerCols", 9) ?: 9),
            checkerboardInnerRows = input.optInt("checkerboard_inner_rows", nested?.optInt("checkerboardInnerRows", 6) ?: 6),
            squareSizeMm = input.optDouble("square_size_mm", nested?.optDouble("squareSizeMm", 25.0) ?: 25.0),
            requiredPairs = input.optInt("required_pairs", nested?.optInt("requiredPairs", 20) ?: 20),
            boardType = runCatching { CalibrationBoardType.valueOf(boardTypeName) }.getOrDefault(CalibrationBoardType.CHARUCO),
            charucoSquaresX = input.optInt("charuco_squares_x", nested?.optInt("charucoSquaresX", 9) ?: 9),
            charucoSquaresY = input.optInt("charuco_squares_y", nested?.optInt("charucoSquaresY", 6) ?: 6),
            charucoSquareLengthMm = input.optDouble("charuco_square_length_mm", nested?.optDouble("charucoSquareLengthMm", 22.0) ?: 22.0),
            charucoMarkerLengthMm = input.optDouble("charuco_marker_length_mm", nested?.optDouble("charucoMarkerLengthMm", 16.0) ?: 16.0),
            charucoDictionary = input.optString("charuco_dictionary", nested?.optString("charucoDictionary", "DICT_4X4_50") ?: "DICT_4X4_50"),
            minCharucoCorners = input.optInt("min_charuco_corners", nested?.optInt("minCharucoCorners", 12) ?: 12),
            charucoLegacyPattern = input.optBoolean("charuco_legacy_pattern", nested?.optBoolean("charucoLegacyPattern", true) ?: true),
        )
    }

    private fun failed(sessionDir: File, errors: List<String>, settings: CalibrationSettings = CalibrationSettings(0, 0, 0.0, 0), pairsTotal: Int = 0, pairsUsed: Int = 0, cam0Size: Size? = null, cam1Size: Size? = null, rejectedPairIndexes: List<Int> = emptyList(), rejectedPairReasons: Map<Int, String> = emptyMap()) = StereoCalibrationResult(
        status = "failed", createdAtUtc = utcNow(), sessionPath = sessionDir.absolutePath,
        checkerboardInnerCols = settings.checkerboardInnerCols, checkerboardInnerRows = settings.checkerboardInnerRows, squareSizeMm = settings.squareSizeMm,
                    boardType = settings.boardType.name, charucoSquaresX = settings.charucoSquaresX, charucoSquaresY = settings.charucoSquaresY, charucoSquareLengthMm = settings.charucoSquareLengthMm, charucoMarkerLengthMm = settings.charucoMarkerLengthMm, charucoDictionary = settings.charucoDictionary, minCharucoCorners = settings.minCharucoCorners, charucoLegacyPattern = settings.charucoLegacyPattern,
        pairsTotal = pairsTotal, pairsUsed = pairsUsed, cam0ImageWidth = cam0Size?.width?.toInt() ?: 0, cam0ImageHeight = cam0Size?.height?.toInt() ?: 0,
        cam1ImageWidth = cam1Size?.width?.toInt() ?: 0, cam1ImageHeight = cam1Size?.height?.toInt() ?: 0, rejectedPairIndexes = rejectedPairIndexes, rejectedPairReasons = rejectedPairReasons, errors = errors,
    )

    private fun detectCharucoCorners(file: File, settings: CalibrationSettings): CharucoDetection {
        if (!file.exists()) return CharucoDetection(null, emptyList(), emptyMap(), emptyMap(), "missing file")
        val bitmap = BitmapFactory.decodeFile(file.absolutePath) ?: return CharucoDetection(null, emptyList(), emptyMap(), emptyMap(), "decode failed")
        val rgba = Mat(); val gray = Mat(); val charucoCorners = Mat(); val charucoIds = Mat()
        return try {
            org.opencv.android.Utils.bitmapToMat(bitmap, rgba)
            Imgproc.cvtColor(rgba, gray, Imgproc.COLOR_RGBA2GRAY)
            createCharucoDetector(settings).detectBoard(gray, charucoCorners, charucoIds)
            val imagePoints = mutableMapOf<Int, Point>()
            val objectPoints = mutableMapOf<Int, Point3>()
            for (row in 0 until charucoIds.rows()) {
                val id = charucoIds.get(row, 0)?.getOrNull(0)?.toInt() ?: continue
                val p = charucoCorners.get(row, 0) ?: continue
                val objectPoint = charucoObjectPoint(id, settings) ?: continue
                imagePoints[id] = Point(p[0], p[1])
                objectPoints[id] = objectPoint
            }
            val ids = imagePoints.keys.sorted()
            val error = if (ids.isEmpty()) "ChArUco not found" else null
            CharucoDetection(Size(bitmap.width.toDouble(), bitmap.height.toDouble()), ids, imagePoints, objectPoints, error)
        } finally { rgba.release(); gray.release(); charucoCorners.release(); charucoIds.release(); bitmap.recycle() }
    }

    private fun charucoObjectPoint(id: Int, settings: CalibrationSettings): Point3? {
        val totalCorners = ((settings.charucoSquaresX - 1) * (settings.charucoSquaresY - 1)).coerceAtLeast(0)
        if (id < 0 || id >= totalCorners) return null

        val boardCorners = createCharucoBoard(settings).getChessboardCorners().toArray()
        return boardCorners.getOrNull(id)
    }

    private fun createCharucoBoard(settings: CalibrationSettings): CharucoBoard {
        val dictionary = Objdetect.getPredefinedDictionary(dictionaryId(settings.charucoDictionary))
        return CharucoBoard(
            Size(settings.charucoSquaresX.toDouble(), settings.charucoSquaresY.toDouble()),
            settings.charucoSquareLengthMm.toFloat(),
            settings.charucoMarkerLengthMm.toFloat(),
            dictionary,
        ).apply {
            setLegacyPattern(settings.charucoLegacyPattern)
        }
    }

    private fun createCharucoDetector(settings: CalibrationSettings): CharucoDetector {
        val board = createCharucoBoard(settings)
        val charucoParams = CharucoParameters().apply { set_minMarkers(1); set_tryRefineMarkers(true) }
        val detectorParams = DetectorParameters().apply { set_cornerRefinementMethod(Objdetect.CORNER_REFINE_SUBPIX) }
        return CharucoDetector(board, charucoParams, detectorParams, RefineParameters())
    }

    private fun dictionaryId(name: String): Int = when (name) {
        "DICT_4X4_50" -> Objdetect.DICT_4X4_50
        else -> Objdetect.DICT_4X4_50
    }

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

    private fun cornerOrderVariants(rows: Int, cols: Int): List<String> = listOf(
        "normal", "reverse_all", "flip_rows", "flip_columns", "rotate_180",
        "transpose", "transpose_flip_rows", "transpose_flip_columns", "transpose_rotate_180",
        "rotate_90", "rotate_270",
    )

    private fun transformCornerOrder(points: Mat, rows: Int, cols: Int, variant: String): Mat {
        val source = MatOfPoint2f(points).toArray().toList()
        fun sourceIndexFor(outputX: Int, outputY: Int): Int = when (variant) {
            "normal" -> outputY * cols + outputX
            "reverse_all", "rotate_180" -> (rows - 1 - outputY) * cols + (cols - 1 - outputX)
            "flip_rows" -> outputY * cols + (cols - 1 - outputX)
            "flip_columns" -> (rows - 1 - outputY) * cols + outputX
            "transpose" -> outputX * rows + outputY
            "transpose_flip_rows", "rotate_90" -> (cols - 1 - outputX) * rows + outputY
            "transpose_flip_columns", "rotate_270" -> outputX * rows + (rows - 1 - outputY)
            "transpose_rotate_180" -> (cols - 1 - outputX) * rows + (rows - 1 - outputY)
            else -> outputY * cols + outputX
        }
        val transformed = (0 until rows).flatMap { y -> (0 until cols).map { x -> source[sourceIndexFor(x, y)] } }
        return MatOfPoint2f(*transformed.toTypedArray())
    }

    private fun selectOutlierPairIndexes(errors: List<PairCalibrationError>, requiredForSuccess: Int): List<Int> {
        val finite = errors.filter { it.error.isFinite() }
        if (finite.size <= requiredForSuccess) return emptyList()

        val sorted = finite.sortedBy { it.error }
        val median = sorted[sorted.size / 2].error
        val threshold = max(STEREO_OUTLIER_HARD_MAX_ERROR_PX, median * STEREO_OUTLIER_MEDIAN_MULTIPLIER)

        val maxReject = minOf(
            (finite.size * STEREO_OUTLIER_MAX_DROP_FRACTION).toInt().coerceAtLeast(1),
            finite.size - requiredForSuccess,
        ).coerceAtLeast(0)

        if (maxReject <= 0) return emptyList()

        val extreme = finite.filter { it.error > 12.0 }.sortedByDescending { it.error }
        val extremeLimited = extreme.take((finite.size - requiredForSuccess).coerceAtLeast(0))
        val remainingSlots = (maxReject - extremeLimited.size).coerceAtLeast(0)
        val dynamic = finite
            .filter { it.error > threshold && it.pairIndex !in extremeLimited.map { extremePair -> extremePair.pairIndex }.toSet() }
            .sortedByDescending { it.error }
            .take(remainingSlots)
        return (extremeLimited + dynamic).map { it.pairIndex }
    }

    private fun computePerPairEpipolarErrors(cam0Points: List<Mat>, cam1Points: List<Mat>, fundamental: Mat, pairIndexes: List<Int>): List<PairCalibrationError> {
        return cam0Points.zip(cam1Points).mapIndexed { index, (leftMat, rightMat) ->
            val left = MatOfPoint2f(leftMat).toArray()
            val right = MatOfPoint2f(rightMat).toArray()
            var total = 0.0
            var count = 0
            for (i in left.indices) {
                val x = left[i].x
                val y = left[i].y
                val a = fundamental.get(0, 0)[0] * x + fundamental.get(0, 1)[0] * y + fundamental.get(0, 2)[0]
                val b = fundamental.get(1, 0)[0] * x + fundamental.get(1, 1)[0] * y + fundamental.get(1, 2)[0]
                val c = fundamental.get(2, 0)[0] * x + fundamental.get(2, 1)[0] * y + fundamental.get(2, 2)[0]
                val denom = sqrt(a * a + b * b)
                if (denom > 0.0) {
                    total += abs(a * right[i].x + b * right[i].y + c) / denom
                    count++
                }
            }
            PairCalibrationError(pairIndexes.getOrElse(index) { index + 1 }, if (count > 0) total / count else Double.POSITIVE_INFINITY)
        }
    }

    private data class CornerDetection(val corners: MatOfPoint2f?, val size: Size?, val error: String?)
    private data class CharucoDetection(val size: Size?, val ids: List<Int>, val imagePointsById: Map<Int, Point>, val objectPointsById: Map<Int, Point3>, val error: String?)
    private data class CornerOrderTrial(val variant: String, val rms: Double, val r: Mat, val t: Mat, val e: Mat, val f: Mat)

    companion object {
        const val DEFAULT_STEREO_RMS_THRESHOLD_PX: Double = 2.0
        const val DEFAULT_INTRINSICS_RMS_THRESHOLD_PX: Double = 3.0
        private const val STEREO_PROCESSOR_MIN_COMMON_CHARUCO_IDS = 35
        private const val STEREO_MIN_FILTERED_PAIRS = 10
        private const val STEREO_OUTLIER_HARD_MAX_ERROR_PX = 6.0
        private const val STEREO_OUTLIER_MEDIAN_MULTIPLIER = 2.5
        private const val STEREO_OUTLIER_MAX_DROP_FRACTION = 0.35

        const val RESULT_FILE = "calibration_result.json"
        const val CAM0_INTRINSICS_FILE = "cam0_intrinsics.json"
        const val CAM1_INTRINSICS_FILE = "cam1_intrinsics.json"
        const val STEREO_EXTRINSICS_FILE = "stereo_extrinsics.json"
        private const val CAM0_INTRINSICS_MODE = "CAM0_INTRINSICS"
        private const val CAM1_INTRINSICS_MODE = "CAM1_INTRINSICS"
        private const val STEREO_EXTRINSICS_MODE = "STEREO_EXTRINSICS"
        private const val DISTORTION_MODEL = "pinhole_k1_k2_fixed_k3_zero_tangent"
    }
}

data class CornerOrderTrialResult(val variant: String, val rms: Double)
data class PairCalibrationError(val pairIndex: Int, val error: Double)

data class StereoCalibrationResult(
    val status: String,
    val createdAtUtc: String,
    val sessionPath: String,
    val checkerboardInnerCols: Int,
    val checkerboardInnerRows: Int,
    val squareSizeMm: Double,
    val boardType: String = CalibrationBoardType.CHESSBOARD_LEGACY.name,
    val charucoSquaresX: Int = 9,
    val charucoSquaresY: Int = 6,
    val charucoSquareLengthMm: Double = 22.0,
    val charucoMarkerLengthMm: Double = 16.0,
    val charucoDictionary: String = "DICT_4X4_50",
    val minCharucoCorners: Int = 12,
    val charucoLegacyPattern: Boolean = true,
    val charucoCornersUsedPerFrame: List<Int> = emptyList(),
    val charucoCommonIdsPerPair: List<List<Int>> = emptyList(),
    val pairsTotal: Int = 0,
    val pairsUsed: Int = 0,
    val cam0Rms: Double? = null,
    val cam1Rms: Double? = null,
    val stereoRms: Double? = null,
    val initialStereoRms: Double? = null,
    val pairsCandidatesAfterCommonIdFilter: Int? = null,
    val rejectedPairIndexes: List<Int> = emptyList(),
    val rejectedPairReasons: Map<Int, String> = emptyMap(),
    val outlierRejectionMode: String? = null,
    val cam0ImageWidth: Int = 0,
    val cam0ImageHeight: Int = 0,
    val cam1ImageWidth: Int = 0,
    val cam1ImageHeight: Int = 0,
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
    val calibrationFlags: Int? = null,
    val distortionModel: String? = null,
    val perPairErrorsInitial: List<PairCalibrationError> = emptyList(),
    val outlierFinalRms: Double? = null,
    val perPairErrors: List<PairCalibrationError> = emptyList(),
    val worstPairIndexes: List<Int> = emptyList(),
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
        .put("board_type", boardType)
        .put("charuco_squares_x", charucoSquaresX)
        .put("charuco_squares_y", charucoSquaresY)
        .put("charuco_square_length_mm", charucoSquareLengthMm)
        .put("charuco_marker_length_mm", charucoMarkerLengthMm)
        .put("charuco_dictionary", charucoDictionary)
        .put("min_charuco_corners", minCharucoCorners)
        .put("charuco_legacy_pattern", charucoLegacyPattern)
        .put("charuco_corners_used_per_frame", charucoCornersUsedPerFrame.toIntJsonArray())
        .put("calibration_flags", calibrationFlags ?: JSONObject.NULL)
        .put("distortion_model", distortionModel ?: JSONObject.NULL)
        .put("errors", errors.toStringJsonArray())
}

fun StereoCalibrationResult.toJson(): JSONObject = JSONObject()
    .put("status", status).put("created_at_utc", createdAtUtc).put("session_path", sessionPath)
    .put("checkerboard_inner_cols", checkerboardInnerCols).put("checkerboard_inner_rows", checkerboardInnerRows).put("square_size_mm", squareSizeMm)
    .put("board_type", boardType).put("charuco_squares_x", charucoSquaresX).put("charuco_squares_y", charucoSquaresY)
    .put("charuco_square_length_mm", charucoSquareLengthMm).put("charuco_marker_length_mm", charucoMarkerLengthMm).put("charuco_dictionary", charucoDictionary)
    .put("min_charuco_corners", minCharucoCorners).put("charuco_legacy_pattern", charucoLegacyPattern)
    .put("charuco_corners_used_per_frame", charucoCornersUsedPerFrame.toIntJsonArray()).put("charuco_common_ids_per_pair", charucoCommonIdsPerPair.toIntListJsonArray())
    .put("pairs_total", pairsTotal).put("pairs_used", pairsUsed).put("cam0_rms", cam0Rms).put("cam1_rms", cam1Rms).put("stereo_rms", stereoRms)
    .put("initial_stereo_rms", initialStereoRms ?: JSONObject.NULL)
    .put("outlier_initial_rms", initialStereoRms ?: JSONObject.NULL)
    .put("pairs_candidates_after_common_id_filter", pairsCandidatesAfterCommonIdFilter ?: pairsUsed)
    .put("rejected_pair_indexes", rejectedPairIndexes.toIntJsonArray())
    .put("rejected_pair_reasons", rejectedPairReasons.toRejectedReasonsJson())
    .put("outlier_rejection_mode", outlierRejectionMode ?: JSONObject.NULL)
    .put("outlier_final_rms", outlierFinalRms ?: stereoRms ?: JSONObject.NULL)
    .put("cam0_image_width", cam0ImageWidth).put("cam0_image_height", cam0ImageHeight).put("cam1_image_width", cam1ImageWidth).put("cam1_image_height", cam1ImageHeight)
    .put("cam0_camera_matrix", cam0CameraMatrix.toDoubleJsonArray2()).put("cam0_dist_coeffs", cam0DistCoeffs.toDoubleJsonArray())
    .put("cam1_camera_matrix", cam1CameraMatrix.toDoubleJsonArray2()).put("cam1_dist_coeffs", cam1DistCoeffs.toDoubleJsonArray())
    .put("stereo_R", stereoR.toDoubleJsonArray2()).put("stereo_T", stereoT.toDoubleJsonArray()).put("stereo_E", stereoE.toDoubleJsonArray2()).put("stereo_F", stereoF.toDoubleJsonArray2())
    .put("corner_order_trials", cornerOrderTrials.toCornerOrderTrialJsonArray())
    .put("selected_corner_order_variant", selectedCornerOrderVariant ?: JSONObject.NULL)
    .put("selected_corner_order_rms", selectedCornerOrderRms ?: JSONObject.NULL)
    .put("calibration_flags", calibrationFlags ?: JSONObject.NULL)
    .put("distortion_model", distortionModel ?: JSONObject.NULL)
    .put("per_pair_epipolar_errors_initial", perPairErrorsInitial.toPairCalibrationErrorJsonArray())
    .put("per_pair_epipolar_errors_final", perPairErrors.toPairCalibrationErrorJsonArray())
    .put("per_pair_epipolar_errors", perPairErrors.toPairCalibrationErrorJsonArray())
    .put("worst_pair_indexes", worstPairIndexes.toIntJsonArray())
    .put("errors", errors.toStringJsonArray())

private fun utcNow(): String = SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss.SSS'Z'", Locale.US).apply { timeZone = TimeZone.getTimeZone("UTC") }.format(Date())
private fun Mat.toFlatList(): List<Double> = (0 until rows()).flatMap { r -> (0 until cols()).map { c -> get(r, c)[0] } }
private fun Mat.toNestedList(): List<List<Double>> = (0 until rows()).map { r -> (0 until cols()).map { c -> get(r, c)[0] } }
private fun List<Double>.toDoubleJsonArray() = JSONArray().also { arr -> forEach { arr.put(it) } }
private fun List<String>.toStringJsonArray() = JSONArray().also { arr -> forEach { arr.put(it) } }
private fun List<Int>.toIntJsonArray() = JSONArray().also { arr -> forEach { arr.put(it) } }
private fun List<List<Int>>.toIntListJsonArray() = JSONArray().also { outer -> forEach { outer.put(it.toIntJsonArray()) } }
private fun List<CornerOrderTrialResult>.toCornerOrderTrialJsonArray() = JSONArray().also { arr -> forEach { arr.put(JSONObject().put("variant", it.variant).put("rms", it.rms)) } }
private fun Map<Int, String>.toRejectedReasonsJson() = JSONObject().also { obj -> forEach { (pairIndex, reason) -> obj.put(pairIndex.toString(), reason) } }
private fun List<PairCalibrationError>.toPairCalibrationErrorJsonArray() = JSONArray().also { arr -> forEach { arr.put(JSONObject().put("pair_index", it.pairIndex).put("epipolar_error_px", it.error)) } }
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
