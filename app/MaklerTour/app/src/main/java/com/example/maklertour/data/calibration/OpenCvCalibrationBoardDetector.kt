package com.maklertour.data.calibration

import android.graphics.Bitmap
import com.maklertour.data.rig.CalibrationBoardType
import com.maklertour.data.rig.CalibrationSettings
import org.opencv.android.OpenCVLoader
import org.opencv.android.Utils
import org.opencv.calib3d.Calib3d
import org.opencv.core.Mat
import org.opencv.core.MatOfPoint2f
import org.opencv.core.Size
import org.opencv.core.TermCriteria
import org.opencv.imgproc.Imgproc
import org.opencv.objdetect.CharucoBoard
import org.opencv.objdetect.CharucoDetector
import org.opencv.objdetect.CharucoParameters
import org.opencv.objdetect.DetectorParameters
import org.opencv.objdetect.Objdetect
import org.opencv.objdetect.RefineParameters

class OpenCvCalibrationBoardDetector : CalibrationBoardDetector {
    override fun detect(bitmap: Bitmap, settings: CalibrationSettings): CalibrationDetectionResult {
        val expectedCorners = expectedCorners(settings)
        if (expectedCorners <= 0) return bitmap.result(false, 0, expectedCorners, "Invalid calibration board settings")
        if (!openCvReady) return bitmap.result(false, 0, expectedCorners, "OpenCV unavailable")

        val rgba = Mat()
        val gray = Mat()
        return try {
            Utils.bitmapToMat(bitmap, rgba)
            Imgproc.cvtColor(rgba, gray, Imgproc.COLOR_RGBA2GRAY)
            when (settings.boardType) {
                CalibrationBoardType.CHESSBOARD_LEGACY -> detectChessboard(bitmap, gray, settings, expectedCorners)
                CalibrationBoardType.CHARUCO -> detectCharuco(bitmap, gray, settings, expectedCorners)
            }
        } catch (t: Throwable) {
            bitmap.result(false, 0, expectedCorners, "Detection failed: ${t.message ?: t.javaClass.simpleName}")
        } finally {
            rgba.release()
            gray.release()
        }
    }

    private fun detectChessboard(bitmap: Bitmap, gray: Mat, settings: CalibrationSettings, expectedCorners: Int): CalibrationDetectionResult {
        var corners = MatOfPoint2f()
        return try {
            val boardSize = Size(settings.checkerboardInnerCols.toDouble(), settings.checkerboardInnerRows.toDouble())
            val fastFlags = Calib3d.CALIB_CB_ADAPTIVE_THRESH or Calib3d.CALIB_CB_NORMALIZE_IMAGE or Calib3d.CALIB_CB_FAST_CHECK
            var found = Calib3d.findChessboardCorners(gray, boardSize, corners, fastFlags)
            if (!found) {
                corners.release()
                corners = MatOfPoint2f()
                val fullFlags = Calib3d.CALIB_CB_ADAPTIVE_THRESH or Calib3d.CALIB_CB_NORMALIZE_IMAGE
                found = Calib3d.findChessboardCorners(gray, boardSize, corners, fullFlags)
            }
            if (found) Imgproc.cornerSubPix(gray, corners, Size(11.0, 11.0), Size(-1.0, -1.0), TermCriteria(TermCriteria.EPS + TermCriteria.MAX_ITER, 30, 0.1))
            val points = corners.toArray().map { point -> NormalizedCornerPoint((point.x / bitmap.width).toFloat().coerceIn(0f, 1f), (point.y / bitmap.height).toFloat().coerceIn(0f, 1f)) }
            CalibrationDetectionResult(found && points.size == expectedCorners, points.size, expectedCorners, bitmap.width, bitmap.height, if (found && points.size == expectedCorners) "Checkerboard found; corners refined" else "Checkerboard not found; improve lighting, focus, and board coverage", if (found) points else emptyList())
        } finally { corners.release() }
    }

    private fun detectCharuco(bitmap: Bitmap, gray: Mat, settings: CalibrationSettings, expectedCorners: Int): CalibrationDetectionResult {
        val charucoCorners = Mat()
        val charucoIds = Mat()
        return try {
            createCharucoDetector(settings).detectBoard(gray, charucoCorners, charucoIds)
            val foundCount = charucoIds.rows()
            val found = foundCount >= settings.minCharucoCorners
            val points = matToPointList(charucoCorners).map { point -> NormalizedCornerPoint((point.x / bitmap.width).toFloat().coerceIn(0f, 1f), (point.y / bitmap.height).toFloat().coerceIn(0f, 1f)) }
            val ids = matToIdList(charucoIds)
            CalibrationDetectionResult(found, foundCount, expectedCorners, bitmap.width, bitmap.height, if (found) "ChArUco found; $foundCount corners detected" else "ChArUco not found; need at least ${settings.minCharucoCorners} corners", if (found) points else emptyList(), if (found) ids else emptyList())
        } finally { charucoCorners.release(); charucoIds.release() }
    }

    companion object {
        private val openCvReady: Boolean by lazy { runCatching { OpenCVLoader.initDebug() }.getOrDefault(false) }

        fun expectedCorners(settings: CalibrationSettings): Int = when (settings.boardType) {
            CalibrationBoardType.CHESSBOARD_LEGACY -> settings.checkerboardInnerCols * settings.checkerboardInnerRows
            CalibrationBoardType.CHARUCO -> (settings.charucoSquaresX - 1) * (settings.charucoSquaresY - 1)
        }

        fun createCharucoDetector(settings: CalibrationSettings): CharucoDetector {
            val dictionary = Objdetect.getPredefinedDictionary(dictionaryId(settings.charucoDictionary))
            val board = CharucoBoard(Size(settings.charucoSquaresX.toDouble(), settings.charucoSquaresY.toDouble()), settings.charucoSquareLengthMm.toFloat(), settings.charucoMarkerLengthMm.toFloat(), dictionary)
            board.setLegacyPattern(settings.charucoLegacyPattern)
            val charucoParams = CharucoParameters().apply { set_minMarkers(1); set_tryRefineMarkers(true) }
            val detectorParams = DetectorParameters().apply { set_cornerRefinementMethod(Objdetect.CORNER_REFINE_SUBPIX) }
            return CharucoDetector(board, charucoParams, detectorParams, RefineParameters())
        }

        fun dictionaryId(name: String): Int = when (name) {
            "DICT_4X4_50" -> Objdetect.DICT_4X4_50
            else -> Objdetect.DICT_4X4_50
        }

        fun matToPointList(mat: Mat) = (0 until mat.rows()).mapNotNull { row ->
            val xy = mat.get(row, 0) ?: return@mapNotNull null
            if (xy.size < 2) return@mapNotNull null
            org.opencv.core.Point(xy[0], xy[1])
        }

        fun matToIdList(mat: Mat) = (0 until mat.rows()).mapNotNull { row ->
            mat.get(row, 0)?.getOrNull(0)?.toInt()
        }
    }

    private fun Bitmap.result(found: Boolean, cornersFound: Int, expectedCorners: Int, message: String) = CalibrationDetectionResult(found, cornersFound, expectedCorners, width, height, message)
}
