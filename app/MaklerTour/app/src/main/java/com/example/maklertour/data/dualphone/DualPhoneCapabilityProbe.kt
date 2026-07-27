package com.maklertour.data.dualphone

import android.content.Context
import android.os.Build
import com.maklertour.data.phonecamera.PhoneCameraLensRepository
import com.maklertour.data.phonecamera.SelectedPhoneVideoInfo
import com.maklertour.data.phonecamera.toJson
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.time.Instant

class DualPhoneCapabilityProbe(context: Context) {
    private val appContext = context.applicationContext
    private val lensRepository = PhoneCameraLensRepository(appContext)

    fun buildReport(settings: DualPhoneStereoSettings): JSONObject {
        val selectedCameraId = lensRepository.getSelectedCameraId()
            ?: runCatching {
                lensRepository.selectedOrDefault().first.cameraId
            }.getOrNull()

        val cameras = JSONArray()
        lensRepository.listBackCameras().forEach { camera ->
            val selectedMode = lensRepository.getSelectedVideoMode(
                camera.cameraId,
                camera.supportedVideoModes,
            )
            cameras.put(
                camera.toJson(
                    selectedVideoInfo = SelectedPhoneVideoInfo(
                        width = selectedMode?.width,
                        height = selectedMode?.height,
                        fps = selectedMode?.fps,
                    ),
                ).put(
                    "camera2_characteristics",
                    lensRepository.rawMetadataJson(camera.cameraId),
                ),
            )
        }

        return JSONObject()
            .put("schema_version", 1)
            .put("capture_type", "dual_phone_stereo_video")
            .put("device_id", settings.deviceId)
            .put("role", settings.role.name)
            .put("transport", settings.transport.name)
            .put("master_controls_upload", true)
            .put("manufacturer", Build.MANUFACTURER)
            .put("model", Build.MODEL)
            .put("device", Build.DEVICE)
            .put("android_sdk", Build.VERSION.SDK_INT)
            .put("selected_camera_id", selectedCameraId ?: JSONObject.NULL)
            .put(
                "preferred_video_mode_id",
                settings.preferredVideoModeId ?: JSONObject.NULL,
            )
            .put("cameras", cameras)
            .put("generated_at", Instant.now().toString())
    }

    fun writeReport(settings: DualPhoneStereoSettings): File {
        val directory = File(
            appContext.filesDir,
            "dual_phone/capabilities",
        ).apply { mkdirs() }
        val destination = File(
            directory,
            "capabilities_${safeToken(settings.deviceId)}.json",
        )
        val temporary = File(destination.parentFile, destination.name + ".tmp")
        temporary.writeText(buildReport(settings).toString(2))
        if (!temporary.renameTo(destination)) {
            destination.writeText(temporary.readText())
            temporary.delete()
        }
        return destination
    }

    private fun safeToken(value: String): String =
        value.replace(Regex("[^A-Za-z0-9._-]"), "_").take(96)
}
