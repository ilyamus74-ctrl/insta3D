package com.maklertour.data.dualphone

import org.json.JSONArray
import org.json.JSONObject

data class DualPhoneCharucoCorner(
    val id: Int,
    val normalizedX: Double,
    val normalizedY: Double,
) {
    fun toJson(): JSONObject = JSONObject()
        .put("id", id)
        .put("x", normalizedX)
        .put("y", normalizedY)

    companion object {
        fun fromJson(json: JSONObject): DualPhoneCharucoCorner? {
            val id = json.optInt("id", -1)
            val x = json.optDouble("x", Double.NaN)
            val y = json.optDouble("y", Double.NaN)
            if (id < 0 || !x.isFinite() || !y.isFinite()) return null
            return DualPhoneCharucoCorner(id, x, y)
        }
    }
}

data class DualPhoneCalibrationObservation(
    val calibrationRunId: String,
    val poseId: String,
    val frameSequence: Long,
    val observedAtElapsedMs: Long,
    val frameTimestampNs: Long = 0L,
    val boardFound: Boolean,
    val cornersFound: Int,
    val expectedCorners: Int,
    val sharpnessScore: Double,
    val meanLuma: Double,
    val motionScore: Double?,
    val stableMs: Long,
    val boardAreaFraction: Double,
    val boardClipped: Boolean,
    val poseMatches: Boolean,
    val qualityReady: Boolean,
    val status: String,
    val imageWidth: Int = 0,
    val imageHeight: Int = 0,
    val charucoCorners: List<DualPhoneCharucoCorner> = emptyList(),
    val centreX: Double = 0.0,
    val centreY: Double = 0.0,
    val rollDegrees: Double = 0.0,
    val yawSkew: Double = 0.0,
    val pitchSkew: Double = 0.0,
    val calibrationStage: DualPhoneCalibrationStage =
        DualPhoneCalibrationStage.MASTER_INTRINSICS,
    val captureElapsedRealtimeNs: Long = frameTimestampNs,
    val timestampSource: String = "UNKNOWN",
    val captureRequestId: String? = null,
    val captureTargetElapsedRealtimeNs: Long? = null,
    val cameraControlStatus: String = "UNKNOWN",
) {
    fun toJson(): JSONObject = JSONObject()
        .put("calibration_run_id", calibrationRunId)
        .put("calibration_stage", calibrationStage.wireValue)
        .put("pose_id", poseId)
        .put("frame_sequence", frameSequence)
        .put("observed_at_elapsed_ms", observedAtElapsedMs)
        .put("frame_timestamp_ns", frameTimestampNs)
        .put("capture_elapsed_realtime_ns", captureElapsedRealtimeNs)
        .put("timestamp_source", timestampSource)
        .put("capture_request_id", captureRequestId ?: JSONObject.NULL)
        .put(
            "capture_target_elapsed_realtime_ns",
            captureTargetElapsedRealtimeNs ?: JSONObject.NULL,
        )
        .put("camera_control_status", cameraControlStatus)
        .put("board_found", boardFound)
        .put("corners_found", cornersFound)
        .put("expected_corners", expectedCorners)
        .put("sharpness_score", sharpnessScore)
        .put("mean_luma", meanLuma)
        .put("motion_score", motionScore ?: JSONObject.NULL)
        .put("stable_ms", stableMs)
        .put("board_area_fraction", boardAreaFraction)
        .put("board_clipped", boardClipped)
        .put("pose_matches", poseMatches)
        .put("quality_ready", qualityReady)
        .put("status", status)
        .put("image_width", imageWidth)
        .put("image_height", imageHeight)
        .put(
            "charuco_corners",
            JSONArray().also { array ->
                charucoCorners.forEach { array.put(it.toJson()) }
            },
        )
        .put("centre_x", centreX)
        .put("centre_y", centreY)
        .put("roll_degrees", rollDegrees)
        .put("yaw_skew", yawSkew)
        .put("pitch_skew", pitchSkew)

    companion object {
        fun fromJson(json: JSONObject): DualPhoneCalibrationObservation? {
            val runId = json.optString("calibration_run_id").trim()
            val poseId = json.optString("pose_id").trim()
            val sequence = json.optLong("frame_sequence", -1L)
            if (runId.isBlank() || poseId.isBlank() || sequence < 0L) return null
            return DualPhoneCalibrationObservation(
                calibrationRunId = runId,
                calibrationStage = DualPhoneCalibrationStage.fromWire(
                    json.optString("calibration_stage"),
                ),
                poseId = poseId,
                frameSequence = sequence,
                observedAtElapsedMs = json.optLong("observed_at_elapsed_ms", 0L),
                frameTimestampNs = json.optLong("frame_timestamp_ns", 0L),
                captureElapsedRealtimeNs = json.optLong(
                    "capture_elapsed_realtime_ns",
                    json.optLong("frame_timestamp_ns", 0L),
                ),
                timestampSource = json.optString("timestamp_source", "UNKNOWN"),
                captureRequestId = json.optNullableString("capture_request_id"),
                captureTargetElapsedRealtimeNs =
                    json.optNullableLong("capture_target_elapsed_realtime_ns"),
                cameraControlStatus =
                    json.optString("camera_control_status", "UNKNOWN"),
                boardFound = json.optBoolean("board_found", false),
                cornersFound = json.optInt("corners_found", 0),
                expectedCorners = json.optInt("expected_corners", 0),
                sharpnessScore = json.optDouble("sharpness_score", 0.0),
                meanLuma = json.optDouble("mean_luma", 0.0),
                motionScore = if (
                    !json.has("motion_score") || json.isNull("motion_score")
                ) {
                    null
                } else {
                    json.optDouble("motion_score")
                },
                stableMs = json.optLong("stable_ms", 0L),
                boardAreaFraction = json.optDouble("board_area_fraction", 0.0),
                boardClipped = json.optBoolean("board_clipped", false),
                poseMatches = json.optBoolean("pose_matches", false),
                qualityReady = json.optBoolean("quality_ready", false),
                status = json.optString("status", "Waiting for calibration quality"),
                imageWidth = json.optInt("image_width", 0),
                imageHeight = json.optInt("image_height", 0),
                charucoCorners = buildList {
                    val array = json.optJSONArray("charuco_corners") ?: JSONArray()
                    for (index in 0 until array.length()) {
                        val corner = array.optJSONObject(index)?.let {
                            DualPhoneCharucoCorner.fromJson(it)
                        }
                        if (corner != null) add(corner)
                    }
                },
                centreX = json.optDouble("centre_x", 0.0),
                centreY = json.optDouble("centre_y", 0.0),
                rollDegrees = json.optDouble("roll_degrees", 0.0),
                yawSkew = json.optDouble("yaw_skew", 0.0),
                pitchSkew = json.optDouble("pitch_skew", 0.0),
            )
        }
    }
}

private fun JSONObject.optNullableString(key: String): String? =
    if (!has(key) || isNull(key)) null else optString(key).takeIf { it.isNotBlank() }

private fun JSONObject.optNullableLong(key: String): Long? =
    if (!has(key) || isNull(key)) null else optLong(key)
