package com.maklertour.data.phonecamera

data class PhoneScanCalibrationMetadata(
    val baselinePitchDeg: Float?,
    val baselineRollDeg: Float?,
    val baselineQuaternion: List<Float>?,
    val calibrationTimestamp: String?,
    val rollGreenThresholdDeg: Float,
    val pitchGreenThresholdDeg: Float,
    val rollYellowThresholdDeg: Float,
    val pitchYellowThresholdDeg: Float,
    val markerMode: String,
    val markersUsed: Boolean,
)
