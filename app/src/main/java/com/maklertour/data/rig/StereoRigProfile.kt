package com.maklertour.data.rig

import android.content.Context
import org.json.JSONObject
import java.io.File
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

enum class CameraModeSource { PHONE_CAMERA, USB_UVC }
enum class CameraModeSelection { AUTO, MANUAL }
enum class CalibrationStatus { NOT_CALIBRATED, CAPTURED, CALIBRATED }

data class CameraMode(
    val source: CameraModeSource,
    val format: String,
    val width: Int,
    val height: Int,
    val fps: Int,
    val selectedBy: CameraModeSelection,
)

data class CalibrationSettings(
    val checkerboardInnerCols: Int,
    val checkerboardInnerRows: Int,
    val squareSizeMm: Double,
    val requiredPairs: Int,
)

data class StereoRigProfile(
    val rigId: String,
    val cam0Label: String,
    val cam1Label: String,
    val baselineMm: Double?,
    val cam0Mode: CameraMode?,
    val cam1Mode: CameraMode?,
    val calibrationSettings: CalibrationSettings,
    val calibrationStatus: CalibrationStatus,
) {
    companion object
}

class StereoRigProfileStore(private val context: Context) {
    private val prefs = context.getSharedPreferences("stereo_rig_profiles", Context.MODE_PRIVATE)
    private val profilesDir: File get() = File(context.filesDir, "rig_profiles").apply { mkdirs() }

    fun loadActiveProfile(): StereoRigProfile {
        val activeRigId = prefs.getString(KEY_ACTIVE_RIG_ID, null)
        val profile = activeRigId?.let { loadProfile(it) } ?: profilesDir.listFiles()?.firstOrNull { it.extension == "json" }?.let { loadProfile(it.nameWithoutExtension) }
        return profile ?: defaultProfile().also { saveActiveProfile(it) }
    }

    fun saveActiveProfile(profile: StereoRigProfile) {
        saveProfile(profile)
        prefs.edit().putString(KEY_ACTIVE_RIG_ID, profile.rigId).apply()
    }

    fun saveProfile(profile: StereoRigProfile) {
        profilesDir.mkdirs()
        File(profilesDir, "${profile.rigId}.json").writeText(profile.toJson().toString(2))
    }

    fun createCalibrationSession(profile: StereoRigProfile): File {
        val timestamp = SimpleDateFormat("yyyyMMdd_HHmmss_SSS", Locale.US).format(Date())
        val dir = File(context.filesDir, "calibration_sessions/$timestamp").apply { mkdirs() }
        val input = JSONObject()
            .put("active_rig_profile", profile.toJson())
            .put("checkerboard_settings", profile.calibrationSettings.toJson())
            .put("created_timestamp", timestamp)
            .put("created_epoch_ms", System.currentTimeMillis())
        File(dir, "calibration_input.json").writeText(input.toString(2))
        return dir
    }

    private fun loadProfile(rigId: String): StereoRigProfile? = runCatching {
        StereoRigProfile.fromJson(JSONObject(File(profilesDir, "$rigId.json").readText()))
    }.getOrNull()

    companion object {
        private const val KEY_ACTIVE_RIG_ID = "active_rig_id"

        fun defaultProfile(): StereoRigProfile = StereoRigProfile(
            rigId = "phone_usb_default",
            cam0Label = "phone_back_camera",
            cam1Label = "usb_uvc_camera",
            baselineMm = null,
            cam0Mode = CameraMode(CameraModeSource.PHONE_CAMERA, "Auto", 1920, 1080, 30, CameraModeSelection.AUTO),
            cam1Mode = CameraMode(CameraModeSource.USB_UVC, "MJPEG", 640, 480, 30, CameraModeSelection.AUTO),
            calibrationSettings = CalibrationSettings(9, 6, 25.0, 20),
            calibrationStatus = CalibrationStatus.NOT_CALIBRATED,
        )
    }
}

fun StereoRigProfile.toJson(): JSONObject = JSONObject()
    .put("rigId", rigId)
    .put("cam0Label", cam0Label)
    .put("cam1Label", cam1Label)
    .put("baselineMm", baselineMm)
    .put("cam0Mode", cam0Mode?.toJson())
    .put("cam1Mode", cam1Mode?.toJson())
    .put("calibrationSettings", calibrationSettings.toJson())
    .put("calibrationStatus", calibrationStatus.name)

fun CameraMode.toJson(): JSONObject = JSONObject()
    .put("source", source.name)
    .put("format", format)
    .put("width", width)
    .put("height", height)
    .put("fps", fps)
    .put("selectedBy", selectedBy.name)

fun CalibrationSettings.toJson(): JSONObject = JSONObject()
    .put("checkerboardInnerCols", checkerboardInnerCols)
    .put("checkerboardInnerRows", checkerboardInnerRows)
    .put("squareSizeMm", squareSizeMm)
    .put("requiredPairs", requiredPairs)

private fun StereoRigProfile.Companion.fromJson(json: JSONObject): StereoRigProfile = StereoRigProfile(
    rigId = json.optString("rigId"),
    cam0Label = json.optString("cam0Label"),
    cam1Label = json.optString("cam1Label"),
    baselineMm = if (json.isNull("baselineMm")) null else json.optDouble("baselineMm"),
    cam0Mode = json.optJSONObject("cam0Mode")?.toCameraMode(),
    cam1Mode = json.optJSONObject("cam1Mode")?.toCameraMode(),
    calibrationSettings = json.optJSONObject("calibrationSettings")?.toCalibrationSettings() ?: CalibrationSettings(9, 6, 25.0, 20),
    calibrationStatus = runCatching { CalibrationStatus.valueOf(json.optString("calibrationStatus")) }.getOrDefault(CalibrationStatus.NOT_CALIBRATED),
)

private fun JSONObject.toCameraMode(): CameraMode = CameraMode(
    source = runCatching { CameraModeSource.valueOf(optString("source")) }.getOrDefault(CameraModeSource.PHONE_CAMERA),
    format = optString("format"),
    width = optInt("width"),
    height = optInt("height"),
    fps = optInt("fps"),
    selectedBy = runCatching { CameraModeSelection.valueOf(optString("selectedBy")) }.getOrDefault(CameraModeSelection.AUTO),
)

private fun JSONObject.toCalibrationSettings(): CalibrationSettings = CalibrationSettings(
    checkerboardInnerCols = optInt("checkerboardInnerCols", 9),
    checkerboardInnerRows = optInt("checkerboardInnerRows", 6),
    squareSizeMm = optDouble("squareSizeMm", 25.0),
    requiredPairs = optInt("requiredPairs", 20),
)
