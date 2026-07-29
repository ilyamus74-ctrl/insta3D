package com.maklertour.data.phonecamera

import android.content.Context
import android.hardware.camera2.CameraCharacteristics
import android.hardware.camera2.CameraManager
import android.hardware.camera2.CameraMetadata
import android.os.Build
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.time.Instant

class PhoneCameraInfoCollector(private val context: Context) {
    private val lensRepository = PhoneCameraLensRepository(context)

    fun writeCameraInfo(baseDir: File, selectedVideoInfo: SelectedPhoneVideoInfo? = null, selectedLens: PhoneCameraLensOption? = null, requestedZoomRatio: Float = 1.0f, effectiveZoomRatio: Float = requestedZoomRatio, minZoomRatio: Float? = null, maxZoomRatio: Float? = null, calibrationResolutionInfo: PhoneCalibrationResolutionInfo? = null): File {
        val lens = selectedLens ?: lensRepository.selectedOrDefault().first
        val manager = context.getSystemService(CameraManager::class.java)
        val chars = manager.getCameraCharacteristics(lens.cameraId)
        val sensorTimestampSource = chars.get(
            CameraCharacteristics.SENSOR_INFO_TIMESTAMP_SOURCE,
        )
        val sensorTimestampSourceName = when (sensorTimestampSource) {
            CameraMetadata.SENSOR_INFO_TIMESTAMP_SOURCE_REALTIME ->
                "REALTIME"
            CameraMetadata.SENSOR_INFO_TIMESTAMP_SOURCE_UNKNOWN ->
                "UNKNOWN"
            else -> "UNAVAILABLE"
        }
        val file = File(baseDir, "camera_info.json")
        val json = JSONObject()
            .put("device_manufacturer", Build.MANUFACTURER)
            .put("device_model", Build.MODEL)
            .put("selected_camera_id", lens.cameraId)
            .put("lens_label", lens.lensLabel)
            .put("requested_zoom_ratio", requestedZoomRatio.toDouble())
            .put("effective_zoom_ratio", effectiveZoomRatio.toDouble())
            .put("selected_zoom_ratio", effectiveZoomRatio.toDouble())
            .put("lens_preset_label", zoomPresetLabel(effectiveZoomRatio))
            .put("logical_multi_camera_capable", lens.logicalMultiCameraCapable)
            .put("physical_camera_ids", JSONArray(lens.physicalCameraIds))
            .put("min_zoom_ratio", minZoomRatio ?: lens.minZoomRatio ?: JSONObject.NULL)
            .put("max_zoom_ratio", maxZoomRatio ?: lens.maxZoomRatio ?: JSONObject.NULL)
            .put("ultrawide_zoom_ratio_note", if (minZoomRatio != null && minZoomRatio <= 0.5f && kotlin.math.abs(effectiveZoomRatio - 0.5f) <= 0.05f) "CameraX confirmed ultrawide-like 0.5x zoom ratio." else JSONObject.NULL)
            .put("focal_length_mm", lens.primaryFocalLengthMm ?: JSONObject.NULL)
            .put("sensor_physical_size_mm", lens.sensorPhysicalSizeMm?.let { JSONObject().put("width", it.width).put("height", it.height) } ?: JSONObject.NULL)
            .put("approximate_fov_deg", lens.approximateFovDeg?.let { JSONObject().put("horizontal", it.horizontal).put("vertical", it.vertical) } ?: JSONObject.NULL)
            .put("resolution", JSONObject().put("width", selectedVideoInfo?.width ?: JSONObject.NULL).put("height", selectedVideoInfo?.height ?: JSONObject.NULL))
            .put("fps", selectedVideoInfo?.fps ?: JSONObject.NULL)
            .put("stabilization_mode", JSONObject.NULL)
            .put("zoom_warning", if (kotlin.math.abs(requestedZoomRatio - effectiveZoomRatio) > 0.01f) "requested ${zoomPresetLabel(requestedZoomRatio)} but CameraX applied ${zoomPresetLabel(effectiveZoomRatio)}" else JSONObject.NULL)
            .put("camera", lens.toJson(selectedVideoInfo, requestedZoomRatio = requestedZoomRatio, effectiveZoomRatio = effectiveZoomRatio, minZoomRatioOverride = minZoomRatio, maxZoomRatioOverride = maxZoomRatio, calibrationResolutionInfo = calibrationResolutionInfo))
            .put("camera_id", lens.cameraId)
            .put("lens_facing", lens.lensFacing)
            .put("focal_lengths_mm", JSONArray(lens.focalLengthsMm))
            .put("active_array_size", lens.activeArraySize?.let { JSONObject().put("left", it.left).put("top", it.top).put("right", it.right).put("bottom", it.bottom).put("width", it.width).put("height", it.height) } ?: JSONObject.NULL)
            .put("pixel_array_size", chars.get(CameraCharacteristics.SENSOR_INFO_PIXEL_ARRAY_SIZE)?.let { JSONObject().put("width", it.width).put("height", it.height) } ?: JSONObject.NULL)
            .put(
                "sensor_timestamp_source",
                sensorTimestampSource ?: JSONObject.NULL,
            )
            .put(
                "sensor_timestamp_source_name",
                sensorTimestampSourceName,
            )
            .put("selected_video_width", selectedVideoInfo?.width ?: JSONObject.NULL)
            .put("selected_video_height", selectedVideoInfo?.height ?: JSONObject.NULL)
            .put("selected_fps", selectedVideoInfo?.fps ?: JSONObject.NULL)
            .put("requested_profile_width", calibrationResolutionInfo?.requestedProfileWidth ?: JSONObject.NULL)
            .put("requested_profile_height", calibrationResolutionInfo?.requestedProfileHeight ?: JSONObject.NULL)
            .put("requested_calibration_width", calibrationResolutionInfo?.requestedWidth ?: JSONObject.NULL)
            .put("requested_calibration_height", calibrationResolutionInfo?.requestedHeight ?: JSONObject.NULL)
            .put("actual_calibration_width", calibrationResolutionInfo?.actualWidth ?: JSONObject.NULL)
            .put("actual_calibration_height", calibrationResolutionInfo?.actualHeight ?: JSONObject.NULL)
            .put("calibration_resolution_reason", calibrationResolutionInfo?.reason ?: JSONObject.NULL)
            .put("supported_resolutions", JSONArray(lens.supportedVideoSizes.map { JSONObject().put("width", it.width).put("height", it.height) }))
            .put("supported_fps", JSONArray(lens.supportedFpsRanges.map { JSONObject().put("lower", it.lower).put("upper", it.upper) }))
            .put("available_target_fps_ranges", JSONArray(lens.supportedFpsRanges.map { JSONObject().put("lower", it.lower).put("upper", it.upper) }))
            .put("available_capabilities", JSONArray(chars.get(CameraCharacteristics.REQUEST_AVAILABLE_CAPABILITIES)?.toList() ?: emptyList<Int>()))
            .put("raw_camera2_metadata", lensRepository.rawMetadataJson(lens.cameraId))
            .put("timestamp", Instant.now().toString())
        file.writeText(json.toString(2))
        return file
    }
}
