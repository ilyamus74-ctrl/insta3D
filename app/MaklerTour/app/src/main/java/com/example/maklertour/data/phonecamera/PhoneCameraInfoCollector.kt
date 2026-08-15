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
        val focusMode = lensRepository.getSelectedFocusMode(lens.cameraId)
        val factoryIntrinsics = chars.get(
            CameraCharacteristics.LENS_INTRINSIC_CALIBRATION,
        )?.takeIf { it.size >= 5 }
        val factoryDistortion = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            chars.get(CameraCharacteristics.LENS_DISTORTION)
                ?.takeIf { it.size >= 5 }
        } else {
            null
        }
        val preCorrectionActiveArray = chars.get(
            CameraCharacteristics.SENSOR_INFO_PRE_CORRECTION_ACTIVE_ARRAY_SIZE,
        )
        val zoomKey = String.format(
            java.util.Locale.US,
            "%.3f",
            effectiveZoomRatio,
        ).replace('.', 'p')
        val calibrationProfileKey = buildString {
            append("camera_").append(lens.cameraId)
            append("_")
            append(selectedVideoInfo?.width ?: 0)
            append("x")
            append(selectedVideoInfo?.height ?: 0)
            append("_zoom_").append(zoomKey)
            append("_focus_").append(focusMode.name.lowercase())
        }
        val camera2IntrinsicsJson = factoryIntrinsics?.let { values ->
            JSONObject()
                .put("source", "CAMERA2_LENS_INTRINSIC_CALIBRATION")
                .put(
                    "coordinate_space",
                    "SENSOR_PRE_CORRECTION_ACTIVE_ARRAY_PIXELS",
                )
                .put("fx", values[0].toDouble())
                .put("fy", values[1].toDouble())
                .put("cx", values[2].toDouble())
                .put("cy", values[3].toDouble())
                .put("skew", values[4].toDouble())
                .put("raw", JSONArray(values.toList()))
        }
        val camera2DistortionJson = factoryDistortion?.let { values ->
            JSONObject()
                .put("source", "CAMERA2_LENS_DISTORTION")
                .put("model", "BROWN_CONRADY")
                .put("k1", values[0].toDouble())
                .put("k2", values[1].toDouble())
                .put("k3", values[2].toDouble())
                .put("p1", values[3].toDouble())
                .put("p2", values[4].toDouble())
                .put("raw", JSONArray(values.toList()))
        }
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
            .put("focus_mode", focusMode.name)
            .put(
                "focus_locked",
                focusMode == PhoneCameraFocusMode.INFINITY_FIXED,
            )
            .put(
                "focus_distance_diopters",
                if (focusMode == PhoneCameraFocusMode.INFINITY_FIXED) {
                    0.0
                } else {
                    JSONObject.NULL
                },
            )
            .put(
                "focus_distance_source",
                if (focusMode == PhoneCameraFocusMode.INFINITY_FIXED) {
                    "FIXED_INFINITY_REQUEST"
                } else {
                    "DYNAMIC_CAPTURE_RESULT_REQUIRED"
                },
            )
            .put(
                "intrinsics_source",
                if (camera2IntrinsicsJson != null) {
                    "CAMERA2_FACTORY_SENSOR"
                } else {
                    "UNAVAILABLE"
                },
            )
            .put("calibration_profile_key", calibrationProfileKey)
            .put("calibration_profile_id", JSONObject.NULL)
            .put(
                "camera2_intrinsic_calibration",
                camera2IntrinsicsJson ?: JSONObject.NULL,
            )
            .put(
                "camera2_lens_distortion",
                camera2DistortionJson ?: JSONObject.NULL,
            )
            .put(
                "colmap_camera_prior",
                JSONObject()
                    .put("usable_for_colmap", false)
                    .put(
                        "reason",
                        "Factory Camera2 calibration is sensor-space; " +
                            "a verified video-resolution/crop calibration " +
                            "profile is required before injecting COLMAP params.",
                    ),
            )
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
            .put(
                "pre_correction_active_array_size",
                preCorrectionActiveArray?.let {
                    JSONObject()
                        .put("left", it.left)
                        .put("top", it.top)
                        .put("right", it.right)
                        .put("bottom", it.bottom)
                        .put("width", it.width())
                        .put("height", it.height())
                } ?: JSONObject.NULL,
            )
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

    fun updateRuntimeCaptureState(
        cameraInfoFile: File,
        summary: PhoneFrameTelemetrySummary?,
    ): File {
        if (summary == null || !cameraInfoFile.isFile) return cameraInfoFile
        val json = runCatching {
            JSONObject(cameraInfoFile.readText())
        }.getOrElse { JSONObject() }
        json
            .put(
                "camera2_capture_state",
                summary.camera2CaptureState ?: JSONObject.NULL,
            )
            .put(
                "tof_capture_state",
                summary.tofCaptureState ?: JSONObject.NULL,
            )
            .put(
                "capture_result_telemetry",
                JSONObject()
                    .put("path", summary.path)
                    .put("frame_count", summary.frameCount)
                    .putNullable("observed_fps", summary.observedCaptureResultFps)
                    .put("estimated_missing_results", summary.estimatedMissingCaptureResults),
            )
            .put("runtime_capture_metadata_updated_at", Instant.now().toString())
        cameraInfoFile.writeText(json.toString(2))
        return cameraInfoFile
    }

    private fun JSONObject.putNullable(key: String, value: Any?): JSONObject =
        put(key, value ?: JSONObject.NULL)
}
