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
        framesFile: File? = null,
        createdAt: String,
        finishedAt: String,
        durationSec: Long,
        fileSizeBytes: Long,
        calibration: PhoneScanCalibrationMetadata? = null,
        selectedVideoInfo: SelectedPhoneVideoInfo? = null,
        selectedLens: PhoneCameraLensOption? = null,
        requestedZoomRatio: Float = 1.0f,
        effectiveZoomRatio: Float = requestedZoomRatio,
        minZoomRatio: Float? = null,
        maxZoomRatio: Float? = null,
    ): File {
        val file = File(baseDir, "manifest.json")
        val files = JSONArray()
            .put(JSONObject().put("name", videoFile.name).put("path", videoFile.name).put("file_size_bytes", videoFile.length()))
            .put(JSONObject().put("name", cameraInfoFile.name).put("path", cameraInfoFile.name).put("file_size_bytes", cameraInfoFile.length()))
        if (imuFile != null && imuFile.exists() && imuFile.length() > 0L) {
            files.put(JSONObject().put("name", imuFile.name).put("path", imuFile.name).put("file_size_bytes", imuFile.length()))
        }
        if (framesFile != null && framesFile.exists() && framesFile.length() > 0L) {
            files.put(JSONObject().put("name", framesFile.name).put("path", framesFile.name).put("file_size_bytes", framesFile.length()))
        }
        val cameraInfo = runCatching {
            JSONObject(cameraInfoFile.readText())
        }.getOrNull()
        fun cameraInfoValue(key: String): Any =
            cameraInfo?.opt(key)?.takeUnless { it == JSONObject.NULL }
                ?: JSONObject.NULL

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
            .put("frames", if (framesFile != null && framesFile.exists() && framesFile.length() > 0L) framesFile.name else JSONObject.NULL)
            .put("calibration", calibration?.toJson() ?: JSONObject.NULL)
            .put("selected_camera_id", selectedLens?.cameraId ?: JSONObject.NULL)
            .put("camera_id", selectedLens?.cameraId ?: JSONObject.NULL)
            .put("lens_label", selectedLens?.lensLabel ?: JSONObject.NULL)
            .put("focus_mode", cameraInfoValue("focus_mode"))
            .put("focus_locked", cameraInfoValue("focus_locked"))
            .put(
                "focus_distance_diopters",
                cameraInfoValue("focus_distance_diopters"),
            )
            .put("intrinsics_source", cameraInfoValue("intrinsics_source"))
            .put(
                "calibration_profile_key",
                cameraInfoValue("calibration_profile_key"),
            )
            .put(
                "calibration_profile_id",
                cameraInfoValue("calibration_profile_id"),
            )
            .put("requested_zoom_ratio", requestedZoomRatio.toDouble())
            .put("effective_zoom_ratio", effectiveZoomRatio.toDouble())
            .put("selected_zoom_ratio", effectiveZoomRatio.toDouble())
            .put("lens_preset_label", zoomPresetLabel(effectiveZoomRatio))
            .put("logical_multi_camera_capable", selectedLens?.logicalMultiCameraCapable ?: JSONObject.NULL)
            .put("physical_camera_ids", JSONArray(selectedLens?.physicalCameraIds ?: emptyList<String>()))
            .put("min_zoom_ratio", minZoomRatio ?: selectedLens?.minZoomRatio ?: JSONObject.NULL)
            .put("max_zoom_ratio", maxZoomRatio ?: selectedLens?.maxZoomRatio ?: JSONObject.NULL)
            .put("ultrawide_zoom_ratio_note", if (minZoomRatio != null && minZoomRatio <= 0.5f && kotlin.math.abs(effectiveZoomRatio - 0.5f) <= 0.05f) "CameraX confirmed ultrawide-like 0.5x zoom ratio." else JSONObject.NULL)
            .put("focal_length_mm", selectedLens?.primaryFocalLengthMm ?: JSONObject.NULL)
            .put("focal_lengths_mm", JSONArray(selectedLens?.focalLengthsMm ?: emptyList<Float>()))
            .put("sensor_physical_size_mm", selectedLens?.sensorPhysicalSizeMm?.let { JSONObject().put("width", it.width).put("height", it.height) } ?: JSONObject.NULL)
            .put("approximate_fov_deg", selectedLens?.approximateFovDeg?.let { JSONObject().put("horizontal", it.horizontal).put("vertical", it.vertical) } ?: JSONObject.NULL)
            .put("resolution", JSONObject().put("width", selectedVideoInfo?.width ?: JSONObject.NULL).put("height", selectedVideoInfo?.height ?: JSONObject.NULL))
            .put("fps", selectedVideoInfo?.fps ?: JSONObject.NULL)
            .put("stabilization_mode", JSONObject.NULL)
            .put("zoom_warning", if (kotlin.math.abs(requestedZoomRatio - effectiveZoomRatio) > 0.01f) "requested ${zoomPresetLabel(requestedZoomRatio)} but CameraX applied ${zoomPresetLabel(effectiveZoomRatio)}" else JSONObject.NULL)
            .put("camera", selectedLens?.toJson(selectedVideoInfo, requestedZoomRatio = requestedZoomRatio, effectiveZoomRatio = effectiveZoomRatio, minZoomRatioOverride = minZoomRatio, maxZoomRatioOverride = maxZoomRatio) ?: JSONObject.NULL)
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
