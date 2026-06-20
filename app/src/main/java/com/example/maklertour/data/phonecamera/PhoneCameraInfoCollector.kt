package com.maklertour.data.phonecamera

import android.content.Context
import android.hardware.camera2.CameraCharacteristics
import android.hardware.camera2.CameraManager
import android.os.Build
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.time.Instant

data class SelectedPhoneVideoInfo(
    val width: Int?,
    val height: Int?,
    val fps: Int?,
)

class PhoneCameraInfoCollector(private val context: Context) {
    fun writeCameraInfo(baseDir: File, selectedVideoInfo: SelectedPhoneVideoInfo? = null): File {
        val manager = context.getSystemService(CameraManager::class.java)
        val cameraId = manager.cameraIdList.firstOrNull { id ->
            manager.getCameraCharacteristics(id).get(CameraCharacteristics.LENS_FACING) == CameraCharacteristics.LENS_FACING_BACK
        } ?: manager.cameraIdList.first()
        val chars = manager.getCameraCharacteristics(cameraId)
        val file = File(baseDir, "camera_info.json")
        val json = JSONObject()
            .put("device_manufacturer", Build.MANUFACTURER)
            .put("device_model", Build.MODEL)
            .put("camera_id", cameraId)
            .put("lens_facing", chars.get(CameraCharacteristics.LENS_FACING).toLensFacingName())
            .put("focal_lengths_mm", JSONArray(chars.get(CameraCharacteristics.LENS_INFO_AVAILABLE_FOCAL_LENGTHS)?.toList() ?: emptyList<Float>()))
            .put("sensor_physical_size_mm", chars.get(CameraCharacteristics.SENSOR_INFO_PHYSICAL_SIZE)?.let { JSONObject().put("width", it.width).put("height", it.height) } ?: JSONObject.NULL)
            .put("active_array_size", chars.get(CameraCharacteristics.SENSOR_INFO_ACTIVE_ARRAY_SIZE)?.let { rect ->
                JSONObject()
                    .put("left", rect.left)
                    .put("top", rect.top)
                    .put("right", rect.right)
                    .put("bottom", rect.bottom)
                    .put("width", rect.width())
                    .put("height", rect.height())
            } ?: JSONObject.NULL)
            .put("pixel_array_size", chars.get(CameraCharacteristics.SENSOR_INFO_PIXEL_ARRAY_SIZE)?.let { JSONObject().put("width", it.width).put("height", it.height) } ?: JSONObject.NULL)
            .put("selected_video_width", selectedVideoInfo?.width ?: JSONObject.NULL)
            .put("selected_video_height", selectedVideoInfo?.height ?: JSONObject.NULL)
            .put("selected_fps", selectedVideoInfo?.fps ?: JSONObject.NULL)
            .put("available_target_fps_ranges", JSONArray(chars.get(CameraCharacteristics.CONTROL_AE_AVAILABLE_TARGET_FPS_RANGES)?.map { range -> JSONObject().put("lower", range.lower).put("upper", range.upper) } ?: emptyList<JSONObject>()))
            .put("available_capabilities", JSONArray(chars.get(CameraCharacteristics.REQUEST_AVAILABLE_CAPABILITIES)?.toList() ?: emptyList<Int>()))
            .put("timestamp", Instant.now().toString())
        file.writeText(json.toString(2))
        return file
    }


    private fun Int?.toLensFacingName(): String = when (this) {
        CameraCharacteristics.LENS_FACING_BACK -> "BACK"
        CameraCharacteristics.LENS_FACING_FRONT -> "FRONT"
        CameraCharacteristics.LENS_FACING_EXTERNAL -> "EXTERNAL"
        else -> "UNKNOWN"
    }
}
