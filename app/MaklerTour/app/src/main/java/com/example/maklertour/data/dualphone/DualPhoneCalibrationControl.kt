package com.maklertour.data.dualphone

import org.json.JSONObject

data class DualPhoneCalibrationObservation(
    val calibrationRunId: String,
    val poseId: String,
    val frameSequence: Long,
    val observedAtElapsedMs: Long,
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
) {
    fun toJson(): JSONObject = JSONObject()
        .put("calibration_run_id", calibrationRunId)
        .put("pose_id", poseId)
        .put("frame_sequence", frameSequence)
        .put("observed_at_elapsed_ms", observedAtElapsedMs)
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

    companion object {
        fun fromJson(json: JSONObject): DualPhoneCalibrationObservation? {
            val runId = json.optString("calibration_run_id").trim()
            val poseId = json.optString("pose_id").trim()
            val sequence = json.optLong("frame_sequence", -1L)
            if (runId.isBlank() || poseId.isBlank() || sequence < 0L) return null
            return DualPhoneCalibrationObservation(
                calibrationRunId = runId,
                poseId = poseId,
                frameSequence = sequence,
                observedAtElapsedMs = json.optLong("observed_at_elapsed_ms", 0L),
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
            )
        }
    }
}
