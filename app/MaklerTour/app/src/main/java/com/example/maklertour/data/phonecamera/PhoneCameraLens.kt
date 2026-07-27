package com.maklertour.data.phonecamera

import android.content.Context
import android.content.SharedPreferences
import android.graphics.Rect
import android.hardware.camera2.CameraCharacteristics
import android.hardware.camera2.CameraManager
import android.hardware.camera2.params.StreamConfigurationMap
import android.os.Build
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
private const val KEY_ZOOM_RATIO = "selected_zoom_ratio"
private const val KEY_VIDEO_MODE_PREFIX = "selected_video_mode_"

data class SelectedPhoneVideoInfo(
    val width: Int?,
    val height: Int?,
    val fps: Int?,
)

data class PhoneCalibrationResolutionInfo(
    val requestedWidth: Int?,
    val requestedHeight: Int?,
    val actualWidth: Int?,
    val actualHeight: Int?,
    val requestedProfileWidth: Int? = requestedWidth,
    val requestedProfileHeight: Int? = requestedHeight,
    val reason: String? = null,
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
    val highSpeedVideoConfigurations: List<HighSpeedVideoConfiguration>,
    val supportedVideoModes: List<PhoneVideoMode>,
    val approximateFovDeg: FovInfo?,
    val logicalMultiCameraCapable: Boolean = false,
    val physicalCameraIds: List<String> = emptyList(),
    val minZoomRatio: Float? = null,
    val maxZoomRatio: Float? = null,
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
data class HighSpeedVideoConfiguration(
    val width: Int,
    val height: Int,
    val lowerFps: Int,
    val upperFps: Int,
)
data class FovInfo(val horizontal: Double, val vertical: Double)
data class PhoneLensPreset(val label: String, val zoomRatio: Float)

data class PhoneCameraBindResult(
    val success: Boolean,
    val error: String? = null,
    val requestedZoomRatio: Float,
    val effectiveZoomRatio: Float? = null,
    val minZoomRatio: Float? = null,
    val maxZoomRatio: Float? = null,
    val cameraId: String? = null,
    val activeBoundCameraId: String? = cameraId,
    val cameraXZoomStateCurrent: Float? = null,
    val bindStatus: String = if (success) "bound" else "failed",
)

data class PhoneCameraZoomState(
    val requestedZoomRatio: Float,
    val effectiveZoomRatio: Float? = null,
    val minZoomRatio: Float? = null,
    val maxZoomRatio: Float? = null,
    val cameraId: String? = null,
    val bindStatus: String = "not_bound",
    val error: String? = null,
    val cameraXZoomStateCurrent: Float? = null,
) {
    val warning: String? get() {
        val effective = effectiveZoomRatio ?: return null
        return if (kotlin.math.abs(requestedZoomRatio - effective) > 0.01f) {
            "Requested ${zoomPresetLabel(requestedZoomRatio)}, but CameraX applied ${zoomPresetLabel(effective)}."
        } else null
    }
}

class PhoneCameraLensRepository(private val context: Context) {
    private val prefs: SharedPreferences = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
    private val manager: CameraManager = context.getSystemService(CameraManager::class.java)

    fun listBackCameras(): List<PhoneCameraLensOption> = manager.cameraIdList.mapNotNull { id ->
        runCatching { buildOption(id) }.getOrNull()
    }.filter { it.lensFacing == "BACK" }.sortedWith(compareBy<PhoneCameraLensOption> { it.primaryFocalLengthMm ?: Float.MAX_VALUE }.thenBy { it.cameraId })

    fun getSelectedCameraId(): String? = prefs.getString(KEY_CAMERA_ID, null)

    fun getSelectedZoomRatio(): Float = prefs.getFloat(KEY_ZOOM_RATIO, 1.0f)

    fun saveSelectedCameraId(cameraId: String) {
        prefs.edit().putString(KEY_CAMERA_ID, cameraId).apply()
    }

    fun saveSelectedZoomRatio(zoomRatio: Float) {
        prefs.edit().putFloat(KEY_ZOOM_RATIO, zoomRatio).apply()
    }

    fun saveSelection(cameraId: String, zoomRatio: Float) {
        prefs.edit().putString(KEY_CAMERA_ID, cameraId).putFloat(KEY_ZOOM_RATIO, zoomRatio).apply()
    }

    fun saveSelectedVideoMode(cameraId: String, mode: PhoneVideoMode) {
        prefs.edit()
            .putString(KEY_VIDEO_MODE_PREFIX + cameraId, mode.id)
            .apply()
    }

    fun getSelectedVideoMode(
        cameraId: String,
        availableModes: List<PhoneVideoMode>,
    ): PhoneVideoMode? {
        val savedId = prefs.getString(KEY_VIDEO_MODE_PREFIX + cameraId, null)
        return availableModes.firstOrNull { it.id == savedId }
            ?: PhoneVideoModePolicy.defaultMode(availableModes)
    }

    fun lensPresets(): List<PhoneLensPreset> = listOf(PhoneLensPreset("0.5x", 0.5f), PhoneLensPreset("1x", 1.0f), PhoneLensPreset("2x", 2.0f), PhoneLensPreset("3x", 3.0f))

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
        val streamMap = chars.get(
            CameraCharacteristics.SCALER_STREAM_CONFIGURATION_MAP,
        )
        val highSpeedConfigurations = highSpeedVideoConfigurations(streamMap)
        val capabilities = chars.get(
            CameraCharacteristics.REQUEST_AVAILABLE_CAPABILITIES,
        )?.toList() ?: emptyList()
        return JSONObject()
            .put("hardware_level", chars.get(CameraCharacteristics.INFO_SUPPORTED_HARDWARE_LEVEL) ?: JSONObject.NULL)
            .put("capabilities", JSONArray(capabilities))
            .put("logical_multi_camera_capable", isLogicalMultiCamera(chars))
            .put(
                "constrained_high_speed_video_capable",
                capabilities.contains(
                    CameraCharacteristics
                        .REQUEST_AVAILABLE_CAPABILITIES_CONSTRAINED_HIGH_SPEED_VIDEO,
                ),
            )
            .put(
                "high_speed_video_configurations",
                JSONArray(
                    highSpeedConfigurations.map { configuration ->
                        JSONObject()
                            .put("width", configuration.width)
                            .put("height", configuration.height)
                            .put("lower_fps", configuration.lowerFps)
                            .put("upper_fps", configuration.upperFps)
                    },
                ),
            )
            .put("physical_camera_ids", JSONArray(physicalCameraIds(chars)))
            .put("available_stabilization_modes", JSONArray(chars.get(CameraCharacteristics.CONTROL_AVAILABLE_VIDEO_STABILIZATION_MODES)?.toList() ?: emptyList<Int>()))
            .put(
                "sensor_timestamp_source",
                chars.get(CameraCharacteristics.SENSOR_INFO_TIMESTAMP_SOURCE)
                    ?: JSONObject.NULL,
            )
            .put(
                "manual_sensor_capable",
                capabilities.contains(
                    CameraCharacteristics.REQUEST_AVAILABLE_CAPABILITIES_MANUAL_SENSOR,
                ),
            )
            .put("timestamp", Instant.now().toString())
    }

    private fun buildOption(cameraId: String): PhoneCameraLensOption {
        val chars = manager.getCameraCharacteristics(cameraId)
        val facing = chars.get(CameraCharacteristics.LENS_FACING).toLensFacingName()
        val focal = chars.get(CameraCharacteristics.LENS_INFO_AVAILABLE_FOCAL_LENGTHS)?.toList() ?: emptyList()
        val sensor = chars.get(CameraCharacteristics.SENSOR_INFO_PHYSICAL_SIZE)?.let { SensorPhysicalSize(it.width, it.height) }
        val active = chars.get(CameraCharacteristics.SENSOR_INFO_ACTIVE_ARRAY_SIZE)?.toActiveArraySize()
        val streamMap = chars.get(
            CameraCharacteristics.SCALER_STREAM_CONFIGURATION_MAP,
        )
        val sizes = streamMap
            ?.getOutputSizes(android.media.MediaRecorder::class.java)
            ?.map { VideoSizeInfo(it.width, it.height) }
            ?.distinct()
            ?: emptyList()
        val fps = chars.get(CameraCharacteristics.CONTROL_AE_AVAILABLE_TARGET_FPS_RANGES)?.map { FpsRangeInfo(it.lower, it.upper) } ?: emptyList()
        val highSpeedConfigurations = highSpeedVideoConfigurations(streamMap)
        val highSpeedBySize = highSpeedConfigurations.groupBy {
            it.width to it.height
        }
        val sizeCapabilities = sizes.map { size ->
            val highSpeedRanges = highSpeedBySize[
                size.width to size.height
            ].orEmpty().map {
                it.lowerFps..it.upperFps
            }
            val minFrameDurationNs = runCatching {
                streamMap?.getOutputMinFrameDuration(
                    android.media.MediaRecorder::class.java,
                    android.util.Size(size.width, size.height),
                ) ?: 0L
            }.getOrDefault(0L)
            val maxFps = if (minFrameDurationNs > 0L) {
                (1_000_000_000L / minFrameDurationNs)
                    .toInt()
                    .coerceAtLeast(1)
            } else {
                30
            }
            PhoneVideoSizeCapability(
                width = size.width,
                height = size.height,
                maxFps = maxFps,
                highSpeedFpsRanges = highSpeedRanges,
            )
        }
        val videoModes = PhoneVideoModePolicy.availableModes(
            sizeCapabilities = sizeCapabilities,
            supportedFpsRanges = fps.map { it.lower..it.upper },
        )
        val fov = if (sensor != null && focal.minOrNull() != null) FovInfo(fov(sensor.width, focal.minOrNull()!!), fov(sensor.height, focal.minOrNull()!!)) else null
        val logical = isLogicalMultiCamera(chars)
        val physicalIds = physicalCameraIds(chars)
        return PhoneCameraLensOption(
            cameraId = cameraId,
            lensFacing = facing,
            lensLabel = friendlyLabel(focal.minOrNull(), listBackFocals(), logical),
            focalLengthsMm = focal,
            sensorPhysicalSizeMm = sensor,
            activeArraySize = active,
            supportedVideoSizes = sizes,
            supportedFpsRanges = fps,
            highSpeedVideoConfigurations = highSpeedConfigurations,
            supportedVideoModes = videoModes,
            approximateFovDeg = fov,
            logicalMultiCameraCapable = logical,
            physicalCameraIds = physicalIds,
        )
    }

    private fun highSpeedVideoConfigurations(
        streamMap: StreamConfigurationMap?,
    ): List<HighSpeedVideoConfiguration> {
        if (streamMap == null) return emptyList()
        return runCatching {
            streamMap.highSpeedVideoSizes.orEmpty().flatMap { size ->
                streamMap.getHighSpeedVideoFpsRangesFor(size)
                    .orEmpty()
                    .map { range ->
                        HighSpeedVideoConfiguration(
                            width = size.width,
                            height = size.height,
                            lowerFps = range.lower,
                            upperFps = range.upper,
                        )
                    }
            }.distinct().sortedWith(
                compareByDescending<HighSpeedVideoConfiguration> {
                    it.width * it.height
                }.thenByDescending { it.upperFps },
            )
        }.getOrDefault(emptyList())
    }

    private fun listBackFocals(): List<Float> = manager.cameraIdList.mapNotNull { id ->
        val c = manager.getCameraCharacteristics(id)
        if (c.get(CameraCharacteristics.LENS_FACING) == CameraCharacteristics.LENS_FACING_BACK) c.get(CameraCharacteristics.LENS_INFO_AVAILABLE_FOCAL_LENGTHS)?.minOrNull() else null
    }.sorted()

    private fun friendlyLabel(focal: Float?, all: List<Float>, logical: Boolean = false): String = when {
        focal == null && logical -> "Logical camera 1x"
        focal == null -> "Back camera"
        all.size >= 2 && focal == all.first() -> "Ultrawide 0.5x"
        all.size >= 3 && focal == all.last() -> "Tele 2x"
        else -> "Main camera 1x"
    }

    private fun fov(sensorMm: Float, focalMm: Float): Double = Math.toDegrees(2.0 * atan(sensorMm / (2.0 * focalMm)))

    private fun isLogicalMultiCamera(chars: CameraCharacteristics): Boolean = chars.get(CameraCharacteristics.REQUEST_AVAILABLE_CAPABILITIES)?.contains(CameraCharacteristics.REQUEST_AVAILABLE_CAPABILITIES_LOGICAL_MULTI_CAMERA) == true

    private fun physicalCameraIds(chars: CameraCharacteristics): List<String> = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) chars.physicalCameraIds.toList().sorted() else emptyList()
}


fun PhoneCameraLensOption.toJson(selectedVideoInfo: SelectedPhoneVideoInfo? = null, stabilizationMode: String? = null, requestedZoomRatio: Float = 1.0f, effectiveZoomRatio: Float = requestedZoomRatio, minZoomRatioOverride: Float? = null, maxZoomRatioOverride: Float? = null, calibrationResolutionInfo: PhoneCalibrationResolutionInfo? = null): JSONObject = JSONObject()
    .put("selected_camera_id", cameraId)
    .put("camera_id", cameraId)
    .put("lens_label", lensLabel)
    .put("requested_zoom_ratio", requestedZoomRatio.toDouble())
    .put("effective_zoom_ratio", effectiveZoomRatio.toDouble())
    .put("selected_zoom_ratio", effectiveZoomRatio.toDouble())
    .put("lens_preset_label", zoomPresetLabel(effectiveZoomRatio))
    .put("logical_multi_camera_capable", logicalMultiCameraCapable)
    .put("physical_camera_ids", JSONArray(physicalCameraIds))
    .put("min_zoom_ratio", minZoomRatioOverride ?: minZoomRatio ?: JSONObject.NULL)
    .put("max_zoom_ratio", maxZoomRatioOverride ?: maxZoomRatio ?: JSONObject.NULL)
    .put(
        "ae_fps_ranges",
        JSONArray(
            supportedFpsRanges.map { range ->
                JSONObject()
                    .put("lower", range.lower)
                    .put("upper", range.upper)
            },
        ),
    )
    .put(
        "high_speed_video_configurations",
        JSONArray(
            highSpeedVideoConfigurations.map { configuration ->
                JSONObject()
                    .put("width", configuration.width)
                    .put("height", configuration.height)
                    .put("lower_fps", configuration.lowerFps)
                    .put("upper_fps", configuration.upperFps)
            },
        ),
    )
    .put("ultrawide_zoom_ratio_note", if (minZoomRatioOverride != null && minZoomRatioOverride <= 0.5f && kotlin.math.abs(effectiveZoomRatio - 0.5f) <= 0.05f) "CameraX confirmed ultrawide-like 0.5x zoom ratio." else JSONObject.NULL)
    .put("lens_facing", lensFacing)
    .put("focal_length_mm", primaryFocalLengthMm ?: JSONObject.NULL)
    .put("focal_lengths_mm", JSONArray(focalLengthsMm))
    .put("sensor_physical_size_mm", sensorPhysicalSizeMm?.let { JSONObject().put("width", it.width).put("height", it.height) } ?: JSONObject.NULL)
    .put("active_array_size", activeArraySize?.let { JSONObject().put("left", it.left).put("top", it.top).put("right", it.right).put("bottom", it.bottom).put("width", it.width).put("height", it.height) } ?: JSONObject.NULL)
    .put("approximate_fov_deg", approximateFovDeg?.let { JSONObject().put("horizontal", it.horizontal).put("vertical", it.vertical) } ?: JSONObject.NULL)
    .put("resolution", JSONObject().put("width", selectedVideoInfo?.width ?: JSONObject.NULL).put("height", selectedVideoInfo?.height ?: JSONObject.NULL))
    .put("fps", selectedVideoInfo?.fps ?: JSONObject.NULL)
    .put("video_mode_candidates", JSONArray(supportedVideoModes.map { mode -> JSONObject().put("id", mode.id).put("width", mode.width).put("height", mode.height).put("fps", mode.fps).put("quality", mode.qualityKey).put("support", mode.support.wireValue) }))
    .put("requested_profile_width", calibrationResolutionInfo?.requestedProfileWidth ?: JSONObject.NULL)
    .put("requested_profile_height", calibrationResolutionInfo?.requestedProfileHeight ?: JSONObject.NULL)
    .put("requested_calibration_width", calibrationResolutionInfo?.requestedWidth ?: JSONObject.NULL)
    .put("requested_calibration_height", calibrationResolutionInfo?.requestedHeight ?: JSONObject.NULL)
    .put("actual_calibration_width", calibrationResolutionInfo?.actualWidth ?: JSONObject.NULL)
    .put("actual_calibration_height", calibrationResolutionInfo?.actualHeight ?: JSONObject.NULL)
    .put("calibration_resolution_reason", calibrationResolutionInfo?.reason ?: JSONObject.NULL)
    .put("stabilization_mode", stabilizationMode ?: JSONObject.NULL)
    .put("timestamp", Instant.now().toString())

private fun Int?.toLensFacingName(): String = when (this) {
    CameraCharacteristics.LENS_FACING_BACK -> "BACK"
    CameraCharacteristics.LENS_FACING_FRONT -> "FRONT"
    CameraCharacteristics.LENS_FACING_EXTERNAL -> "EXTERNAL"
    else -> "UNKNOWN"
}

private fun Rect.toActiveArraySize() = ActiveArraySize(left, top, right, bottom, width(), height())
fun zoomPresetLabel(zoomRatio: Float): String = String.format(java.util.Locale.US, "%.1fx", zoomRatio).replace(".0x", "x")
