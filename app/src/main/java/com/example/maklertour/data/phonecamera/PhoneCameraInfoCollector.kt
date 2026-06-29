package com.maklertour.data.phonecamera

import android.content.Context
import android.hardware.camera2.CameraCharacteristics
import android.hardware.camera2.CameraManager
import android.os.Build
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.time.Instant

class PhoneCameraInfoCollector(private val context: Context) {
    private val lensRepository = PhoneCameraLensRepository(context)

    fun writeCameraInfo(baseDir: File, selectedVideoInfo: SelectedPhoneVideoInfo? = null, selectedLens: PhoneCameraLensOption? = null, selectedZoomRatio: Float = 1.0f, minZoomRatio: Float? = null, maxZoomRatio: Float? = null): File {
        val lens = selectedLens ?: lensRepository.selectedOrDefault().first
        val manager = context.getSystemService(CameraManager::class.java)
        val chars = manager.getCameraCharacteristics(lens.cameraId)
        val file = File(baseDir, "camera_info.json")
        val json = JSONObject()
            .put("device_manufacturer", Build.MANUFACTURER)
            .put("device_model", Build.MODEL)
            .put("selected_camera_id", lens.cameraId)
            .put("lens_label", lens.lensLabel)
            .put("selected_zoom_ratio", selectedZoomRatio.toDouble())
            .put("lens_preset_label", zoomPresetLabel(selectedZoomRatio))
            .put("logical_multi_camera_capable", lens.logicalMultiCameraCapable)
            .put("physical_camera_ids", JSONArray(lens.physicalCameraIds))
            .put("min_zoom_ratio", minZoomRatio ?: lens.minZoomRatio ?: JSONObject.NULL)
            .put("max_zoom_ratio", maxZoomRatio ?: lens.maxZoomRatio ?: JSONObject.NULL)
            .put("ultrawide_zoom_ratio_note", if (selectedZoomRatio < 1.0f) "Ultrawide selected via CameraX zoom ratio, not a separate cameraId." else JSONObject.NULL)
            .put("focal_length_mm", lens.primaryFocalLengthMm ?: JSONObject.NULL)
            .put("sensor_physical_size_mm", lens.sensorPhysicalSizeMm?.let { JSONObject().put("width", it.width).put("height", it.height) } ?: JSONObject.NULL)
            .put("approximate_fov_deg", lens.approximateFovDeg?.let { JSONObject().put("horizontal", it.horizontal).put("vertical", it.vertical) } ?: JSONObject.NULL)
            .put("resolution", JSONObject().put("width", selectedVideoInfo?.width ?: JSONObject.NULL).put("height", selectedVideoInfo?.height ?: JSONObject.NULL))
            .put("fps", selectedVideoInfo?.fps ?: JSONObject.NULL)
            .put("stabilization_mode", JSONObject.NULL)
            .put("camera", lens.toJson(selectedVideoInfo, selectedZoomRatio = selectedZoomRatio, minZoomRatioOverride = minZoomRatio, maxZoomRatioOverride = maxZoomRatio))
            .put("camera_id", lens.cameraId)
            .put("lens_facing", lens.lensFacing)
            .put("focal_lengths_mm", JSONArray(lens.focalLengthsMm))
            .put("active_array_size", lens.activeArraySize?.let { JSONObject().put("left", it.left).put("top", it.top).put("right", it.right).put("bottom", it.bottom).put("width", it.width).put("height", it.height) } ?: JSONObject.NULL)
            .put("pixel_array_size", chars.get(CameraCharacteristics.SENSOR_INFO_PIXEL_ARRAY_SIZE)?.let { JSONObject().put("width", it.width).put("height", it.height) } ?: JSONObject.NULL)
            .put("selected_video_width", selectedVideoInfo?.width ?: JSONObject.NULL)
            .put("selected_video_height", selectedVideoInfo?.height ?: JSONObject.NULL)
            .put("selected_fps", selectedVideoInfo?.fps ?: JSONObject.NULL)
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
