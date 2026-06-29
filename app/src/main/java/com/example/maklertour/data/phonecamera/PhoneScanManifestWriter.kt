package com.maklertour.data.phonecamera

import org.json.JSONArray
import org.json.JSONObject
import java.io.File

class PhoneScanManifestWriter {
    fun write(
        baseDir: File,
        scanId: String,
        sessionId: String,
        videoFile: File,
        cameraInfoFile: File,
        imuFile: File?,
        createdAt: String,
        finishedAt: String,
        durationSec: Long,
        fileSizeBytes: Long,
        calibration: PhoneScanCalibrationMetadata? = null,
    ): File {
        val file = File(baseDir, "manifest.json")
        val files = JSONArray()
            .put(JSONObject().put("name", videoFile.name).put("path", videoFile.name).put("file_size_bytes", videoFile.length()))
            .put(JSONObject().put("name", cameraInfoFile.name).put("path", cameraInfoFile.name).put("file_size_bytes", cameraInfoFile.length()))
        if (imuFile != null && imuFile.exists() && imuFile.length() > 0L) {
            files.put(JSONObject().put("name", imuFile.name).put("path", imuFile.name).put("file_size_bytes", imuFile.length()))
        }
        val json = JSONObject()
            .put("scan_id", scanId)
            .put("session_id", sessionId)
            .put("source", "PHONE_CAMERA")
            .put("created_at", createdAt)
            .put("finished_at", finishedAt)
            .put("duration_sec", durationSec)
            .put("file_size_bytes", fileSizeBytes)
            .put("video", videoFile.name)
            .put("camera_info", cameraInfoFile.name)
            .put("imu", if (imuFile != null && imuFile.exists() && imuFile.length() > 0L) imuFile.name else JSONObject.NULL)
            .put("calibration", calibration?.toJson() ?: JSONObject.NULL)
            .put("files", files)
        file.writeText(json.toString(2))
        return file
    }
}


private fun PhoneScanCalibrationMetadata.toJson(): JSONObject {
    val thresholds = JSONObject()
        .put("green", JSONObject().put("roll_deg", rollGreenThresholdDeg).put("pitch_deg", pitchGreenThresholdDeg))
        .put("yellow", JSONObject().put("roll_deg", rollYellowThresholdDeg).put("pitch_deg", pitchYellowThresholdDeg))
    val baseline = JSONObject()
        .put("pitch_deg", baselinePitchDeg ?: JSONObject.NULL)
        .put("roll_deg", baselineRollDeg ?: JSONObject.NULL)
        .put("quaternion", baselineQuaternion?.let { values -> JSONArray(values) } ?: JSONObject.NULL)
    return JSONObject()
        .put("baseline", baseline)
        .put("calibration_timestamp", calibrationTimestamp ?: JSONObject.NULL)
        .put("level_thresholds", thresholds)
        .put("marker_mode", markerMode)
        .put("markers_used", markersUsed)
}
