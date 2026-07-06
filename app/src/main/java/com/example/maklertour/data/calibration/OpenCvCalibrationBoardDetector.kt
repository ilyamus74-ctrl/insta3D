package com.maklertour.data.calibration

import android.graphics.Bitmap
import com.maklertour.data.rig.CalibrationSettings
import org.opencv.android.OpenCVLoader
import org.opencv.android.Utils
import org.opencv.calib3d.Calib3d
import org.opencv.core.Mat
import org.opencv.core.MatOfPoint2f
import org.opencv.core.Size
import org.opencv.core.TermCriteria
import org.opencv.imgproc.Imgproc

class OpenCvCalibrationBoardDetector : CalibrationBoardDetector {
    override fun detect(bitmap: Bitmap, settings: CalibrationSettings): CalibrationDetectionResult {
        val expectedCorners = settings.checkerboardInnerCols * settings.checkerboardInnerRows
        if (settings.checkerboardInnerCols <= 0 || settings.checkerboardInnerRows <= 0) {
            return bitmap.result(false, 0, expectedCorners, "Invalid checkerboard settings")
        }
        if (!openCvReady) {
            return bitmap.result(false, 0, expectedCorners, "OpenCV unavailable")
        }

        val rgba = Mat()
        val gray = Mat()
        var corners = MatOfPoint2f()
        return try {
            Utils.bitmapToMat(bitmap, rgba)
            Imgproc.cvtColor(rgba, gray, Imgproc.COLOR_RGBA2GRAY)
            val boardSize = Size(settings.checkerboardInnerCols.toDouble(), settings.checkerboardInnerRows.toDouble())
            val fastFlags = Calib3d.CALIB_CB_ADAPTIVE_THRESH or Calib3d.CALIB_CB_NORMALIZE_IMAGE or Calib3d.CALIB_CB_FAST_CHECK
            var found = Calib3d.findChessboardCorners(gray, boardSize, corners, fastFlags)
            if (!found) {
                corners.release()
                corners = MatOfPoint2f()
                val fullFlags = Calib3d.CALIB_CB_ADAPTIVE_THRESH or Calib3d.CALIB_CB_NORMALIZE_IMAGE
                found = Calib3d.findChessboardCorners(gray, boardSize, corners, fullFlags)
            }
            if (found) {
                Imgproc.cornerSubPix(
                    gray,
                    corners,
                    Size(11.0, 11.0),
                    Size(-1.0, -1.0),
                    TermCriteria(
                        TermCriteria.EPS + TermCriteria.MAX_ITER,
                        30,
                        0.1,
                    ),
                )
            }
            val points = corners.toArray().map { point ->
                NormalizedCornerPoint(
                    x = (point.x / bitmap.width.toDouble()).toFloat().coerceIn(0f, 1f),
                    y = (point.y / bitmap.height.toDouble()).toFloat().coerceIn(0f, 1f),
                )
            }
            CalibrationDetectionResult(
                found = found && points.size == expectedCorners,
                cornersFound = points.size,
                expectedCorners = expectedCorners,
                imageWidth = bitmap.width,
                imageHeight = bitmap.height,
                qualityMessage = if (found && points.size == expectedCorners) "Checkerboard found; corners refined" else "Checkerboard not found; improve lighting, focus, and board coverage",
                normalizedCornerPoints = if (found) points else emptyList(),
            )
        } catch (t: Throwable) {
            bitmap.result(false, 0, expectedCorners, "Detection failed: ${t.message ?: t.javaClass.simpleName}")
        } finally {
            rgba.release()
            gray.release()
            corners.release()
        }
    }

    companion object {
        private val openCvReady: Boolean by lazy {
            runCatching { OpenCVLoader.initDebug() }.getOrDefault(false)
        }
    }

    private fun Bitmap.result(found: Boolean, cornersFound: Int, expectedCorners: Int, message: String) = CalibrationDetectionResult(
        found = found,
        cornersFound = cornersFound,
        expectedCorners = expectedCorners,
        imageWidth = width,
        imageHeight = height,
        qualityMessage = message,
    )
}