package com.example.maklertour.data.dualphone

import org.opencv.core.Core
import org.opencv.core.CvType
import org.opencv.core.Mat
import org.opencv.core.MatOfByte
import org.opencv.core.Size
import org.opencv.imgcodecs.Imgcodecs
import org.opencv.imgproc.Imgproc
import kotlin.math.roundToInt

internal data class DualPhoneRawCameraOverlayProjection(
    val pairedMasterJpeg: ByteArray,
    val denseOverlayPng: ByteArray,
    val strictOutlinePng: ByteArray,
    val displayRotationDegrees: Int,
    val masterFrameSequence: Long,
)

/**
 * Projects rectified depth products back into the natural MASTER frame.
 *
 * OpenCV rectification maps describe, for every rectified pixel, the source
 * coordinate in masterInput. We invert that relationship by bounded forward
 * splatting. The resulting alpha PNGs have exactly the same unrotated pixel
 * dimensions as the paired MASTER JPEG and can use the same UI transform.
 */
internal object DualPhoneRawCameraOverlayProjector {
    fun project(
        pairedMasterJpeg: ByteArray,
        masterRaw: Mat,
        masterInput: Mat,
        mapMasterX: Mat,
        mapMasterY: Mat,
        depthMaster: Mat,
        workMaster: Mat,
        denseDepthJpeg: ByteArray,
        strictDepthJpeg: ByteArray,
        processingRotation: Int?,
        displayRotationDegrees: Int,
        masterFrameSequence: Long,
    ): DualPhoneRawCameraOverlayProjection {
        val denseWork = decodeColor(denseDepthJpeg)
        val strictWork = decodeColor(strictDepthJpeg)
        val denseDepthSize = Mat()
        val strictDepthSize = Mat()
        val denseRectified = Mat()
        val strictRectified = Mat()
        val denseInput = Mat()
        val strictMaskInput = Mat()
        val strictClosed = Mat()
        val strictGradient = Mat()
        val strictDilated = Mat()
        val strictInput = Mat()
        val denseRaw = Mat()
        val strictRaw = Mat()
        val kernel = Imgproc.getStructuringElement(
            Imgproc.MORPH_RECT,
            Size(3.0, 3.0),
        )

        try {
            check(denseWork.cols() == workMaster.cols())
            check(denseWork.rows() == workMaster.rows())
            check(strictWork.cols() == workMaster.cols())
            check(strictWork.rows() == workMaster.rows())

            val depthSize = Size(
                depthMaster.cols().toDouble(),
                depthMaster.rows().toDouble(),
            )
            Imgproc.resize(
                denseWork,
                denseDepthSize,
                depthSize,
                0.0,
                0.0,
                Imgproc.INTER_NEAREST,
            )
            Imgproc.resize(
                strictWork,
                strictDepthSize,
                depthSize,
                0.0,
                0.0,
                Imgproc.INTER_NEAREST,
            )
            restoreRectifiedOrientation(
                denseDepthSize,
                denseRectified,
                processingRotation,
            )
            restoreRectifiedOrientation(
                strictDepthSize,
                strictRectified,
                processingRotation,
            )

            check(denseRectified.cols() == mapMasterX.cols())
            check(denseRectified.rows() == mapMasterX.rows())
            check(mapMasterX.cols() == mapMasterY.cols())
            check(mapMasterX.rows() == mapMasterY.rows())

            splatRectifiedToInput(
                denseRectified = denseRectified,
                strictRectified = strictRectified,
                mapMasterX = mapMasterX,
                mapMasterY = mapMasterY,
                sourceWidth = masterInput.cols(),
                sourceHeight = masterInput.rows(),
                denseOutput = denseInput,
                strictMaskOutput = strictMaskInput,
            )

            Imgproc.morphologyEx(
                strictMaskInput,
                strictClosed,
                Imgproc.MORPH_CLOSE,
                kernel,
            )
            Imgproc.morphologyEx(
                strictClosed,
                strictGradient,
                Imgproc.MORPH_GRADIENT,
                kernel,
            )
            Imgproc.dilate(strictGradient, strictDilated, kernel)
            createGreenOutline(strictDilated, strictInput)

            val rawSize = Size(
                masterRaw.cols().toDouble(),
                masterRaw.rows().toDouble(),
            )
            Imgproc.resize(
                denseInput,
                denseRaw,
                rawSize,
                0.0,
                0.0,
                Imgproc.INTER_NEAREST,
            )
            Imgproc.resize(
                strictInput,
                strictRaw,
                rawSize,
                0.0,
                0.0,
                Imgproc.INTER_NEAREST,
            )

            return DualPhoneRawCameraOverlayProjection(
                pairedMasterJpeg = pairedMasterJpeg,
                denseOverlayPng = encodePng(denseRaw),
                strictOutlinePng = encodePng(strictRaw),
                displayRotationDegrees = displayRotationDegrees,
                masterFrameSequence = masterFrameSequence,
            )
        } finally {
            listOf(
                denseWork,
                strictWork,
                denseDepthSize,
                strictDepthSize,
                denseRectified,
                strictRectified,
                denseInput,
                strictMaskInput,
                strictClosed,
                strictGradient,
                strictDilated,
                strictInput,
                denseRaw,
                strictRaw,
                kernel,
            ).forEach { it.release() }
        }
    }

    private fun restoreRectifiedOrientation(
        source: Mat,
        output: Mat,
        processingRotation: Int?,
    ) {
        when (processingRotation) {
            Core.ROTATE_90_COUNTERCLOCKWISE ->
                Core.rotate(source, output, Core.ROTATE_90_CLOCKWISE)
            Core.ROTATE_90_CLOCKWISE ->
                Core.rotate(source, output, Core.ROTATE_90_COUNTERCLOCKWISE)
            else -> source.copyTo(output)
        }
    }

    private fun splatRectifiedToInput(
        denseRectified: Mat,
        strictRectified: Mat,
        mapMasterX: Mat,
        mapMasterY: Mat,
        sourceWidth: Int,
        sourceHeight: Int,
        denseOutput: Mat,
        strictMaskOutput: Mat,
    ) {
        val densePixels = ByteArray(sourceWidth * sourceHeight * 4)
        val strictPixels = ByteArray(sourceWidth * sourceHeight)
        val rectifiedWidth = denseRectified.cols()
        val mapXRow = FloatArray(rectifiedWidth)
        val mapYRow = FloatArray(rectifiedWidth)
        val denseRow = ByteArray(rectifiedWidth * 3)
        val strictRow = ByteArray(rectifiedWidth * 3)

        for (row in 0 until denseRectified.rows()) {
            mapMasterX.get(row, 0, mapXRow)
            mapMasterY.get(row, 0, mapYRow)
            denseRectified.get(row, 0, denseRow)
            strictRectified.get(row, 0, strictRow)
            for (column in 0 until rectifiedWidth) {
                val mappedX = mapXRow[column]
                val mappedY = mapYRow[column]
                if (!mappedX.isFinite() || !mappedY.isFinite()) continue
                val sourceX = mappedX.roundToInt()
                val sourceY = mappedY.roundToInt()
                if (sourceX !in 0 until sourceWidth || sourceY !in 0 until sourceHeight) {
                    continue
                }
                val colorIndex = column * 3
                val denseValid = colorSum(denseRow, colorIndex) > VALID_COLOR_SUM
                val strictValid = colorSum(strictRow, colorIndex) > VALID_COLOR_SUM
                if (!denseValid && !strictValid) continue

                for (dy in -DENSE_SPLAT_RADIUS..DENSE_SPLAT_RADIUS) {
                    val y = sourceY + dy
                    if (y !in 0 until sourceHeight) continue
                    for (dx in -DENSE_SPLAT_RADIUS..DENSE_SPLAT_RADIUS) {
                        val x = sourceX + dx
                        if (x !in 0 until sourceWidth) continue
                        val pixelIndex = y * sourceWidth + x
                        if (denseValid) {
                            val outputIndex = pixelIndex * 4
                            densePixels[outputIndex] = denseRow[colorIndex]
                            densePixels[outputIndex + 1] = denseRow[colorIndex + 1]
                            densePixels[outputIndex + 2] = denseRow[colorIndex + 2]
                            densePixels[outputIndex + 3] = DENSE_ALPHA.toByte()
                        }
                        if (strictValid) {
                            strictPixels[pixelIndex] = 0xff.toByte()
                        }
                    }
                }
            }
        }

        denseOutput.create(sourceHeight, sourceWidth, CvType.CV_8UC4)
        denseOutput.put(0, 0, densePixels)
        strictMaskOutput.create(sourceHeight, sourceWidth, CvType.CV_8UC1)
        strictMaskOutput.put(0, 0, strictPixels)
    }

    private fun createGreenOutline(mask: Mat, output: Mat) {
        val maskBytes = ByteArray(mask.rows() * mask.cols())
        mask.get(0, 0, maskBytes)
        val pixels = ByteArray(mask.rows() * mask.cols() * 4)
        for (index in maskBytes.indices) {
            if ((maskBytes[index].toInt() and 0xff) == 0) continue
            val outputIndex = index * 4
            pixels[outputIndex] = 40
            pixels[outputIndex + 1] = 0xff.toByte()
            pixels[outputIndex + 2] = 0
            pixels[outputIndex + 3] = 0xff.toByte()
        }
        output.create(mask.rows(), mask.cols(), CvType.CV_8UC4)
        output.put(0, 0, pixels)
    }

    private fun colorSum(row: ByteArray, offset: Int): Int =
        (row[offset].toInt() and 0xff) +
            (row[offset + 1].toInt() and 0xff) +
            (row[offset + 2].toInt() and 0xff)

    private fun decodeColor(bytes: ByteArray): Mat {
        val encoded = MatOfByte(*bytes)
        return try {
            Imgcodecs.imdecode(encoded, Imgcodecs.IMREAD_COLOR).also { decoded ->
                check(!decoded.empty()) { "Registered overlay decode failed" }
            }
        } finally {
            encoded.release()
        }
    }

    private fun encodePng(mat: Mat): ByteArray {
        val output = MatOfByte()
        return try {
            check(Imgcodecs.imencode(".png", mat, output)) {
                "Registered overlay PNG encode failed"
            }
            output.toArray()
        } finally {
            output.release()
        }
    }

    private const val VALID_COLOR_SUM = 45
    private const val DENSE_SPLAT_RADIUS = 1
    private const val DENSE_ALPHA = 150
}
