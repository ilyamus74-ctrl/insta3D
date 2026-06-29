package com.maklertour.data.phonecamera

import android.content.Context
import android.content.SharedPreferences
import android.graphics.Rect
import android.hardware.camera2.CameraCharacteristics
import android.hardware.camera2.CameraManager
import androidx.camera.camera2.interop.Camera2CameraInfo
import androidx.camera.core.CameraFilter
import androidx.camera.core.CameraSelector
import androidx.camera.core.CameraInfo
import androidx.camera.camera2.interop.ExperimentalCamera2Interop
import org.json.JSONArray
import org.json.JSONObject
import java.time.Instant
import kotlin.math.atan

private const val PREFS = "phone_camera_lens"
private const val KEY_CAMERA_ID = "selected_camera_id"

data class SelectedPhoneVideoInfo(
    val width: Int?,
    val height: Int?,
    val fps: Int?,
)

data class PhoneCameraLensOption(
    val cameraId: String,
    val lensFacing: String,
    val lensLabel: String,
    val focalLengthsMm: List<Float>,
    val sensorPhysicalSizeMm: SensorPhysicalSize?,
    val activeArraySize: ActiveArraySize?,
    val supportedVideoSizes: List<VideoSizeInfo>,
    val supportedFpsRanges: List<FpsRangeInfo>,
    val approximateFovDeg: FovInfo?,
) {
    val primaryFocalLengthMm: Float? get() = focalLengthsMm.minOrNull()
    val summary: String get() = buildString {
        append(lensLabel).append(" · ID ").append(cameraId)
        primaryFocalLengthMm?.let { append(" · ").append(String.format(java.util.Locale.US, "%.1f mm", it)) }
        approximateFovDeg?.let { append(" · FOV ≈ ").append(String.format(java.util.Locale.US, "%.0f°×%.0f°", it.horizontal, it.vertical)) }
    }
}

data class SensorPhysicalSize(val width: Float, val height: Float)
data class ActiveArraySize(val left: Int, val top: Int, val right: Int, val bottom: Int, val width: Int, val height: Int)
data class VideoSizeInfo(val width: Int, val height: Int)
data class FpsRangeInfo(val lower: Int, val upper: Int)
data class FovInfo(val horizontal: Double, val vertical: Double)

class PhoneCameraLensRepository(private val context: Context) {
    private val prefs: SharedPreferences = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
    private val manager: CameraManager = context.getSystemService(CameraManager::class.java)

    fun listBackCameras(): List<PhoneCameraLensOption> = manager.cameraIdList.mapNotNull { id ->
        runCatching { buildOption(id) }.getOrNull()
    }.filter { it.lensFacing == "BACK" }.sortedWith(compareBy<PhoneCameraLensOption> { it.primaryFocalLengthMm ?: Float.MAX_VALUE }.thenBy { it.cameraId })

    fun getSelectedCameraId(): String? = prefs.getString(KEY_CAMERA_ID, null)

    fun saveSelectedCameraId(cameraId: String) {
        prefs.edit().putString(KEY_CAMERA_ID, cameraId).apply()
    }

    fun selectedOrDefault(): Pair<PhoneCameraLensOption, String?> {
        val options = listBackCameras()
        val saved = getSelectedCameraId()
        val selected = options.firstOrNull { it.cameraId == saved }
        if (selected != null) return selected to null
        val fallback = options.firstOrNull { it.lensLabel.contains("Main") } ?: options.firstOrNull() ?: error("No back camera available")
        val warning = if (saved != null) "Selected camera $saved is unavailable; using ${fallback.cameraId}." else null
        return fallback to warning
    }

    @OptIn(ExperimentalCamera2Interop::class)
    fun cameraSelectorFor(cameraId: String): CameraSelector = CameraSelector.Builder()
        .addCameraFilter(CameraFilter { infos: List<CameraInfo> -> infos.filter { Camera2CameraInfo.from(it).cameraId == cameraId } })
        .build()

    fun selectedCameraSelector(): Pair<CameraSelector, PhoneCameraLensOption> {
        val (option, _) = selectedOrDefault()
        return cameraSelectorFor(option.cameraId) to option
    }

    fun rawMetadataJson(cameraId: String): JSONObject {
        val chars = manager.getCameraCharacteristics(cameraId)
        return JSONObject()
            .put("hardware_level", chars.get(CameraCharacteristics.INFO_SUPPORTED_HARDWARE_LEVEL) ?: JSONObject.NULL)
            .put("capabilities", JSONArray(chars.get(CameraCharacteristics.REQUEST_AVAILABLE_CAPABILITIES)?.toList() ?: emptyList<Int>()))
            .put("available_stabilization_modes", JSONArray(chars.get(CameraCharacteristics.CONTROL_AVAILABLE_VIDEO_STABILIZATION_MODES)?.toList() ?: emptyList<Int>()))
            .put("timestamp", Instant.now().toString())
    }

    private fun buildOption(cameraId: String): PhoneCameraLensOption {
        val chars = manager.getCameraCharacteristics(cameraId)
        val facing = chars.get(CameraCharacteristics.LENS_FACING).toLensFacingName()
        val focal = chars.get(CameraCharacteristics.LENS_INFO_AVAILABLE_FOCAL_LENGTHS)?.toList() ?: emptyList()
        val sensor = chars.get(CameraCharacteristics.SENSOR_INFO_PHYSICAL_SIZE)?.let { SensorPhysicalSize(it.width, it.height) }
        val active = chars.get(CameraCharacteristics.SENSOR_INFO_ACTIVE_ARRAY_SIZE)?.toActiveArraySize()
        val sizes = chars.get(CameraCharacteristics.SCALER_STREAM_CONFIGURATION_MAP)?.getOutputSizes(android.media.MediaRecorder::class.java)?.map { VideoSizeInfo(it.width, it.height) }?.distinct() ?: emptyList()
        val fps = chars.get(CameraCharacteristics.CONTROL_AE_AVAILABLE_TARGET_FPS_RANGES)?.map { FpsRangeInfo(it.lower, it.upper) } ?: emptyList()
        val fov = if (sensor != null && focal.minOrNull() != null) FovInfo(fov(sensor.width, focal.minOrNull()!!), fov(sensor.height, focal.minOrNull()!!)) else null
        return PhoneCameraLensOption(cameraId, facing, friendlyLabel(focal.minOrNull(), listBackFocals()), focal, sensor, active, sizes, fps, fov)
    }

    private fun listBackFocals(): List<Float> = manager.cameraIdList.mapNotNull { id ->
        val c = manager.getCameraCharacteristics(id)
        if (c.get(CameraCharacteristics.LENS_FACING) == CameraCharacteristics.LENS_FACING_BACK) c.get(CameraCharacteristics.LENS_INFO_AVAILABLE_FOCAL_LENGTHS)?.minOrNull() else null
    }.sorted()

    private fun friendlyLabel(focal: Float?, all: List<Float>): String = when {
        focal == null -> "Back camera"
        all.size >= 2 && focal == all.first() -> "Ultrawide 0.5x"
        all.size >= 3 && focal == all.last() -> "Tele 2x"
        else -> "Main camera 1x"
    }

    private fun fov(sensorMm: Float, focalMm: Float): Double = Math.toDegrees(2.0 * atan(sensorMm / (2.0 * focalMm)))
}

fun PhoneCameraLensOption.toJson(selectedVideoInfo: SelectedPhoneVideoInfo? = null, stabilizationMode: String? = null): JSONObject = JSONObject()
    .put("selected_camera_id", cameraId)
    .put("camera_id", cameraId)
    .put("lens_label", lensLabel)
    .put("lens_facing", lensFacing)
    .put("focal_length_mm", primaryFocalLengthMm ?: JSONObject.NULL)
    .put("focal_lengths_mm", JSONArray(focalLengthsMm))
    .put("sensor_physical_size_mm", sensorPhysicalSizeMm?.let { JSONObject().put("width", it.width).put("height", it.height) } ?: JSONObject.NULL)
    .put("active_array_size", activeArraySize?.let { JSONObject().put("left", it.left).put("top", it.top).put("right", it.right).put("bottom", it.bottom).put("width", it.width).put("height", it.height) } ?: JSONObject.NULL)
    .put("approximate_fov_deg", approximateFovDeg?.let { JSONObject().put("horizontal", it.horizontal).put("vertical", it.vertical) } ?: JSONObject.NULL)
    .put("resolution", JSONObject().put("width", selectedVideoInfo?.width ?: JSONObject.NULL).put("height", selectedVideoInfo?.height ?: JSONObject.NULL))
    .put("fps", selectedVideoInfo?.fps ?: JSONObject.NULL)
    .put("stabilization_mode", stabilizationMode ?: JSONObject.NULL)
    .put("timestamp", Instant.now().toString())

private fun Int?.toLensFacingName(): String = when (this) {
    CameraCharacteristics.LENS_FACING_BACK -> "BACK"
    CameraCharacteristics.LENS_FACING_FRONT -> "FRONT"
    CameraCharacteristics.LENS_FACING_EXTERNAL -> "EXTERNAL"
    else -> "UNKNOWN"
}

private fun Rect.toActiveArraySize() = ActiveArraySize(left, top, right, bottom, width(), height())