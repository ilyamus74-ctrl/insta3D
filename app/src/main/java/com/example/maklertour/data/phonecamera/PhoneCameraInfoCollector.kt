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
    fun writeCameraInfo(baseDir: File): File {
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
            .put("lens_facing", chars.get(CameraCharacteristics.LENS_FACING))
            .put("sensor_size", chars.get(CameraCharacteristics.SENSOR_INFO_PHYSICAL_SIZE)?.let { JSONObject().put("width", it.width).put("height", it.height) })
            .put("focal_lengths", JSONArray(chars.get(CameraCharacteristics.LENS_INFO_AVAILABLE_FOCAL_LENGTHS)?.toList() ?: emptyList<Float>()))
            .put("active_array_size", chars.get(CameraCharacteristics.SENSOR_INFO_ACTIVE_ARRAY_SIZE)?.flattenToString())
            .put("pixel_array_size", chars.get(CameraCharacteristics.SENSOR_INFO_PIXEL_ARRAY_SIZE)?.let { JSONObject().put("width", it.width).put("height", it.height) })
            .put("available_capabilities", JSONArray(chars.get(CameraCharacteristics.REQUEST_AVAILABLE_CAPABILITIES)?.toList() ?: emptyList<Int>()))
            .put("timestamp", Instant.now().toString())
        file.writeText(json.toString(2))
        return file
    }
}