package com.maklertour.data.tof

import android.graphics.Bitmap
import com.maklertour.data.calibration.DualPhoneLiveIntrinsicsEstimate
import com.maklertour.data.calibration.OpenCvCalibrationBoardDetector
import com.maklertour.data.rig.CalibrationBoardType
import com.maklertour.data.rig.CalibrationSettings
import org.opencv.calib3d.Calib3d
import org.opencv.core.CvType
import org.opencv.core.Mat
import org.opencv.core.MatOfDouble
import org.opencv.core.MatOfPoint2f
import org.opencv.core.MatOfPoint3f
import org.opencv.core.Point
import org.opencv.core.Point3
import org.opencv.core.Size
import org.opencv.objdetect.CharucoBoard
import org.opencv.objdetect.Objdetect
import kotlin.math.sqrt

data class TofCameraBoardPlaneEstimate(
    val solved: Boolean,
    val plane: TofCameraBoardPlane? = null,
    val cornersUsed: Int = 0,
    val status: String,
)

/**
 * ChArUco pose -> physical board plane in CAMERA_A coordinates.
 *
 * It deliberately does not solve ToF extrinsics. LM03.4B2 consumes these planes
 * together with accepted ToF range frames from TofCameraFramePairer.
 */
class TofCameraCharucoPlaneEstimator(
    private val detector: OpenCvCalibrationBoardDetector =
        OpenCvCalibrationBoardDetector(),
) {
    fun estimate(
        bitmap: Bitmap,
        settings: CalibrationSettings,
        cameraIntrinsics: DualPhoneLiveIntrinsicsEstimate,
    ): TofCameraBoardPlaneEstimate {
        if (settings.boardType != CalibrationBoardType.CHARUCO) {
            return failed("LM03.4 requires a ChArUco target")
        }
        if (!cameraIntrinsics.acceptable) {
            return failed("CAMERA_A intrinsics are not acceptable")
        }

        val detection = detector.detect(bitmap, settings)
        if (!detection.found) {
            return failed(detection.qualityMessage)
        }
        if (
            detection.charucoIds.size != detection.normalizedCornerPoints.size ||
            detection.charucoIds.size < MIN_SOLVE_CORNERS
        ) {
            return failed(
                "Need at least $MIN_SOLVE_CORNERS matched ChArUco corners",
                detection.charucoIds.size,
            )
        }

        val fx = cameraIntrinsics.fx ?: return failed("CAMERA_A fx missing")
        val fy = cameraIntrinsics.fy ?: return failed("CAMERA_A fy missing")
        val cx = cameraIntrinsics.cx ?: return failed("CAMERA_A cx missing")
        val cy = cameraIntrinsics.cy ?: return failed("CAMERA_A cy missing")
        val k1 = cameraIntrinsics.k1 ?: return failed("CAMERA_A k1 missing")
        val k2 = cameraIntrinsics.k2 ?: return failed("CAMERA_A k2 missing")

        val dictionary =
            Objdetect.getPredefinedDictionary(
                OpenCvCalibrationBoardDetector.dictionaryId(
                    settings.charucoDictionary,
                ),
            )
        val board =
            CharucoBoard(
                Size(
                    settings.charucoSquaresX.toDouble(),
                    settings.charucoSquaresY.toDouble(),
                ),
                settings.charucoSquareLengthMm.toFloat(),
                settings.charucoMarkerLengthMm.toFloat(),
                dictionary,
            ).also {
                it.setLegacyPattern(settings.charucoLegacyPattern)
            }
        val boardCorners = board.getChessboardCorners().toArray()

        val objectPoints = mutableListOf<Point3>()
        val imagePoints = mutableListOf<Point>()
        detection.charucoIds.forEachIndexed { index, id ->
            if (id !in boardCorners.indices) return@forEachIndexed
            val boardPoint = boardCorners[id]
            val normalized = detection.normalizedCornerPoints[index]
            objectPoints += Point3(boardPoint.x, boardPoint.y, boardPoint.z)
            imagePoints += Point(
                normalized.x.toDouble() * detection.imageWidth.toDouble(),
                normalized.y.toDouble() * detection.imageHeight.toDouble(),
            )
        }

        if (objectPoints.size < MIN_SOLVE_CORNERS) {
            return failed(
                "Not enough valid ChArUco object/image correspondences",
                objectPoints.size,
            )
        }

        val objectMat = MatOfPoint3f(*objectPoints.toTypedArray())
        val imageMat = MatOfPoint2f(*imagePoints.toTypedArray())
        val cameraMatrix = Mat.eye(3, 3, CvType.CV_64F)
        val distCoeffs = MatOfDouble(k1, k2, 0.0, 0.0, 0.0)
        val rvec = Mat()
        val tvec = Mat()
        val rotation = Mat()

        try {
            cameraMatrix.put(0, 0, fx)
            cameraMatrix.put(1, 1, fy)
            cameraMatrix.put(0, 2, cx)
            cameraMatrix.put(1, 2, cy)

            val poseSolved =
                Calib3d.solvePnP(
                    objectMat,
                    imageMat,
                    cameraMatrix,
                    distCoeffs,
                    rvec,
                    tvec,
                    false,
                    Calib3d.SOLVEPNP_ITERATIVE,
                )
            if (!poseSolved) {
                return failed("ChArUco solvePnP failed", objectPoints.size)
            }

            Calib3d.Rodrigues(rvec, rotation)

            var nx = rotation.scalar(0, 2)
            var ny = rotation.scalar(1, 2)
            var nz = rotation.scalar(2, 2)
            val norm = sqrt(nx * nx + ny * ny + nz * nz)
            if (!norm.isFinite() || norm <= 0.0) {
                return failed(
                    "Invalid ChArUco board-plane normal",
                    objectPoints.size,
                )
            }
            nx /= norm
            ny /= norm
            nz /= norm

            val tx = tvec.scalar(0, 0)
            val ty = tvec.scalar(1, 0)
            val tz = tvec.scalar(2, 0)
            if (!listOf(tx, ty, tz).all { it.isFinite() } || tz <= 0.0) {
                return failed(
                    "Invalid ChArUco board translation",
                    objectPoints.size,
                )
            }

            val plane =
                TofCameraBoardPlane(
                    normalX = nx,
                    normalY = ny,
                    normalZ = nz,
                    dMm = -(nx * tx + ny * ty + nz * tz),
                    charucoCornersUsed = objectPoints.size,
                )
            if (!plane.structurallyValid) {
                return failed("Invalid ChArUco board plane", objectPoints.size)
            }

            return TofCameraBoardPlaneEstimate(
                solved = true,
                plane = plane,
                cornersUsed = objectPoints.size,
                status = "ChArUco CAMERA_A board plane solved",
            )
        } catch (error: Throwable) {
            return failed(
                "ChArUco board-plane solve failed: " +
                    (error.message ?: error.javaClass.simpleName),
                objectPoints.size,
            )
        } finally {
            objectMat.release()
            imageMat.release()
            cameraMatrix.release()
            distCoeffs.release()
            rvec.release()
            tvec.release()
            rotation.release()
        }
    }

    private fun Mat.scalar(row: Int, column: Int): Double =
        get(row, column)?.getOrNull(0) ?: Double.NaN

    private fun failed(
        status: String,
        cornersUsed: Int = 0,
    ): TofCameraBoardPlaneEstimate =
        TofCameraBoardPlaneEstimate(
            solved = false,
            cornersUsed = cornersUsed,
            status = status,
        )

    companion object {
        const val MIN_SOLVE_CORNERS = 6
    }
}
