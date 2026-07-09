package com.maklertour.data.calibration

import android.graphics.Bitmap
import com.maklertour.data.rig.CalibrationSettings

data class NormalizedCornerPoint(
    val x: Float,
    val y: Float,
)

data class CalibrationDetectionResult(
    val found: Boolean,
    val cornersFound: Int,
    val expectedCorners: Int,
    val imageWidth: Int,
    val imageHeight: Int,
    val qualityMessage: String,
    val normalizedCornerPoints: List<NormalizedCornerPoint> = emptyList(),
)

interface CalibrationBoardDetector {
    fun detect(bitmap: Bitmap, settings: CalibrationSettings): CalibrationDetectionResult
}