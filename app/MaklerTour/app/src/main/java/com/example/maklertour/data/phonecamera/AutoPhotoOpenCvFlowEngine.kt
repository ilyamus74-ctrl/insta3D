package com.maklertour.data.phonecamera

import android.os.SystemClock
import androidx.camera.core.ImageProxy
import org.opencv.android.OpenCVLoader
import org.opencv.core.CvType
import org.opencv.core.Mat
import org.opencv.core.MatOfByte
import org.opencv.core.MatOfFloat
import org.opencv.core.MatOfPoint
import org.opencv.core.MatOfPoint2f
import org.opencv.imgproc.Imgproc
import org.opencv.video.Video
import kotlin.math.min

internal class OpenCvAutoPhotoFlowEngine : AutoPhotoFlowEngine {
    override fun track(
        reference: AutoPhotoMovementFrame,
        current: AutoPhotoMovementFrame,
        maxFeatures: Int,
    ): AutoPhotoFlowMeasurement {
        if (!openCvReady) {
            return AutoPhotoFlowMeasurement(
                method = METHOD,
                available = false,
                detail = "OpenCV unavailable",
            )
        }

        val previousMat = Mat(
            reference.height,
            reference.width,
            CvType.CV_8UC1,
        )
        val currentMat = Mat(
            current.height,
            current.width,
            CvType.CV_8UC1,
        )
        val corners = MatOfPoint()
        val previousPoints = MatOfPoint2f()
        val currentPoints = MatOfPoint2f()
        val status = MatOfByte()
        val error = MatOfFloat()

        return try {
            previousMat.put(0, 0, reference.luma)
            currentMat.put(0, 0, current.luma)

            Imgproc.goodFeaturesToTrack(
                previousMat,
                corners,
                maxFeatures,
                0.01,
                8.0,
            )

            val detected = corners.toArray()
            if (detected.isEmpty()) {
                return AutoPhotoFlowMeasurement(
                    method = METHOD,
                    detectedFeatures = 0,
                    detail = "no reference features",
                )
            }

            previousPoints.fromArray(*detected)
            Video.calcOpticalFlowPyrLK(
                previousMat,
                currentMat,
                previousPoints,
                currentPoints,
                status,
                error,
            )

            val next = currentPoints.toArray()
            val flags = status.toArray()
            val tracks = detected.indices.map { index ->
                val currentPoint = next.getOrNull(index)
                AutoPhotoTrackedPoint(
                    previousX = detected[index].x,
                    previousY = detected[index].y,
                    currentX = currentPoint?.x ?: Double.NaN,
                    currentY = currentPoint?.y ?: Double.NaN,
                    valid = currentPoint != null
                        && flags.getOrNull(index)?.toInt() == 1,
                )
            }

            AutoPhotoFlowMeasurement(
                method = METHOD,
                detectedFeatures = detected.size,
                tracks = tracks,
            )
        } catch (t: Throwable) {
            AutoPhotoFlowMeasurement(
                method = METHOD,
                detectedFeatures = 0,
                tracks = emptyList(),
                detail = "OpenCV flow failed: ${t.javaClass.simpleName}",
            )
        } finally {
            previousMat.release()
            currentMat.release()
            corners.release()
            previousPoints.release()
            currentPoints.release()
            status.release()
            error.release()
        }
    }

    companion object {
        const val METHOD = "pyr_lk"

        private val openCvReady: Boolean by lazy {
            runCatching { OpenCVLoader.initDebug() }.getOrDefault(false)
        }
    }
}

internal object AutoPhotoMovementFrameFactory {
    fun fromImageProxy(
        image: ImageProxy,
        targetWidth: Int,
        targetHeight: Int,
    ): AutoPhotoMovementFrame {
        require(targetWidth > 0 && targetHeight > 0) {
            "movement target dimensions must be positive"
        }

        val plane = image.planes.firstOrNull()
            ?: throw IllegalArgumentException("ImageProxy has no Y plane")
        val sourceWidth = image.width
        val sourceHeight = image.height
        require(sourceWidth > 0 && sourceHeight > 0) {
            "ImageProxy dimensions must be positive"
        }

        val width = min(targetWidth, sourceWidth)
        val height = min(targetHeight, sourceHeight)
        val luma = ByteArray(width * height)
        val buffer = plane.buffer.duplicate()
        val base = buffer.position()
        val limit = buffer.limit()

        for (targetY in 0 until height) {
            val sourceY = (targetY.toLong() * sourceHeight / height)
                .toInt()
                .coerceIn(0, sourceHeight - 1)
            for (targetX in 0 until width) {
                val sourceX = (targetX.toLong() * sourceWidth / width)
                    .toInt()
                    .coerceIn(0, sourceWidth - 1)
                val sourceIndex = base
                    + sourceY * plane.rowStride
                    + sourceX * plane.pixelStride
                luma[targetY * width + targetX] =
                    if (sourceIndex in base until limit) {
                        buffer.get(sourceIndex)
                    } else {
                        0
                    }
            }
        }

        val timestampNs = image.imageInfo.timestamp
            .takeIf { it > 0L }
            ?: SystemClock.elapsedRealtimeNanos()

        return AutoPhotoMovementFrame(
            width = width,
            height = height,
            luma = luma,
            timestampNs = timestampNs,
        )
    }
}
