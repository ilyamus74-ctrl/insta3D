package com.maklertour.data.phonecamera

import android.content.Context
import android.hardware.camera2.CameraCharacteristics
import android.hardware.camera2.CameraManager
import android.hardware.camera2.CameraMetadata
import android.hardware.camera2.CaptureResult
import android.hardware.camera2.TotalCaptureResult
import android.media.MediaExtractor
import android.media.MediaFormat
import android.os.Build
import android.os.SystemClock
import com.maklertour.data.tof.TofCameraFramePairer
import com.maklertour.data.tof.TofUsbRuntime
import org.json.JSONArray
import org.json.JSONObject
import java.io.BufferedWriter
import java.io.File
import java.util.ArrayDeque
import kotlin.math.max
import kotlin.math.roundToLong

data class PhoneVideoTelemetryContext(
    val dualCaptureId: String,
    val role: String,
    val scheduledElapsedRealtimeNs: Long,
    val clockOffsetNs: Long?,
    val clockUncertaintyNs: Long?,
    val clockDriftPpm: Double?,
)

data class PhoneFrameTelemetrySummary(
    val path: String,
    val frameCount: Long,
    val firstSensorTimestampNs: Long?,
    val lastSensorTimestampNs: Long?,
    val firstReceiveElapsedNs: Long?,
    val lastReceiveElapsedNs: Long?,
    val observedCaptureResultFps: Double?,
    val estimatedMissingCaptureResults: Long,
    val camera2CaptureState: JSONObject? = null,
    val tofCaptureState: JSONObject? = null,
)

data class PhoneEncoderPtsSummary(
    val path: String,
    val sampleCount: Long,
    val firstPtsUs: Long?,
    val lastPtsUs: Long?,
    val observedFps: Double?,
    val status: String,
)

class DualPhoneFrameTelemetryRecorder(context: Context) {
    private val appContext = context.applicationContext
    private val lock = Any()
    private var writer: BufferedWriter? = null
    private var outputFile: File? = null
    private var expectedFps: Int? = null
    private var frameWidth: Int? = null
    private var frameHeight: Int? = null
    private var targetRotationDegrees: Int = 0
    private var frameCount = 0L
    private var firstSensorTimestampNs: Long? = null
    private var lastSensorTimestampNs: Long? = null
    private var firstReceiveElapsedNs: Long? = null
    private var lastReceiveElapsedNs: Long? = null
    private var cameraTimestampSourceName: String = "UNAVAILABLE"
    private var firstCamera2State: JSONObject? = null
    private var lastCamera2State: JSONObject? = null
    private val cropRegions = linkedSetOf<String>()
    private val focalLengthsMm = linkedSetOf<Double>()
    private val activePhysicalCameraIds = linkedSetOf<String>()
    private val videoStabilizationModes = linkedSetOf<Int>()
    private val opticalStabilizationModes = linkedSetOf<Int>()
    private val distortionCorrectionModes = linkedSetOf<Int>()
    private var zoomRatioMin: Double? = null
    private var zoomRatioMax: Double? = null
    private var focusDistanceMin: Double? = null
    private var focusDistanceMax: Double? = null
    private var tofStatusAtStart: String = "STOPPED"
    private var tofFramesOkAtStart: Long = 0L
    private var tofCrcErrorsAtStart: Long = 0L
    private var tofSequenceDropsAtStart: Long = 0L
    private var tofAcceptedPairs: Long = 0L
    private var tofRejectedPairs: Long = 0L
    private var tofUnpairedResults: Long = 0L
    private var tofPairingSkippedTimestampDomain: Long = 0L
    private val tofPairAbsDeltaUs = ArrayDeque<Long>()

    fun start(
        baseDir: File,
        context: PhoneVideoTelemetryContext?,
        cameraId: String,
        videoModeId: String?,
        width: Int?,
        height: Int?,
        fps: Int?,
        rotationDegrees: Int,
        startCallElapsedNs: Long,
    ): File = synchronized(lock) {
        stopLocked()
        baseDir.mkdirs()
        val file = File(baseDir, "frames.jsonl")
        file.delete()
        outputFile = file
        expectedFps = fps
        frameWidth = width
        frameHeight = height
        targetRotationDegrees = rotationDegrees
        frameCount = 0L
        firstSensorTimestampNs = null
        lastSensorTimestampNs = null
        firstReceiveElapsedNs = null
        lastReceiveElapsedNs = null
        firstCamera2State = null
        lastCamera2State = null
        cropRegions.clear()
        focalLengthsMm.clear()
        activePhysicalCameraIds.clear()
        videoStabilizationModes.clear()
        opticalStabilizationModes.clear()
        distortionCorrectionModes.clear()
        zoomRatioMin = null
        zoomRatioMax = null
        focusDistanceMin = null
        focusDistanceMax = null
        tofAcceptedPairs = 0L
        tofRejectedPairs = 0L
        tofUnpairedResults = 0L
        tofPairingSkippedTimestampDomain = 0L
        tofPairAbsDeltaUs.clear()
        cameraTimestampSourceName = cameraTimestampSourceName(cameraId)
        TofUsbRuntime.get(appContext).state.value.let { tofState ->
            tofStatusAtStart = tofState.status.name
            tofFramesOkAtStart = tofState.framesOk
            tofCrcErrorsAtStart = tofState.crcErrors
            tofSequenceDropsAtStart = tofState.sequenceDrops
        }
        writer = file.bufferedWriter().also { active ->
            active.write(
                JSONObject()
                    .put("type", "metadata")
                    .put("schema_version", 2)
                    .put(
                        "capture_scope",
                        if (context == null) "PHONE_VIDEO" else "DUAL_PHONE_VIDEO",
                    )
                    .putNullable("dual_capture_id", context?.dualCaptureId)
                    .putNullable("role", context?.role)
                    .put("camera_id", cameraId)
                    .putNullable("video_mode_id", videoModeId)
                    .putNullable("width", width)
                    .putNullable("height", height)
                    .putNullable("fps_requested", fps)
                    .put("rotation_degrees", rotationDegrees)
                    .putNullable(
                        "scheduled_start_elapsed_ns",
                        context?.scheduledElapsedRealtimeNs,
                    )
                    .put("start_call_elapsed_ns", startCallElapsedNs)
                    .putNullable("clock_offset_ns", context?.clockOffsetNs)
                    .putNullable(
                        "clock_uncertainty_ns",
                        context?.clockUncertaintyNs,
                    )
                    .putNullable("clock_drift_ppm", context?.clockDriftPpm)
                    .put(
                        "sensor_timestamp_source",
                        "CAMERA2_CAPTURE_RESULT_SENSOR_TIMESTAMP",
                    )
                    .put(
                        "sensor_timestamp_source_name",
                        cameraTimestampSourceName,
                    )
                    .put(
                        "receive_timestamp_source",
                        "SYSTEM_CLOCK_ELAPSED_REALTIME_NANOS",
                    )
                    .put(
                        "encoder_mapping_status",
                        "UNVERIFIED_SEPARATE_TIMELINES",
                    )
                    .toString(),
            )
            active.newLine()
            active.flush()
        }
        file
    }

    fun record(result: TotalCaptureResult) {
        val sensorTimestampNs =
            result.get(CaptureResult.SENSOR_TIMESTAMP) ?: return
        val receiveElapsedNs = SystemClock.elapsedRealtimeNanos()
        val camera2State = camera2State(result)
        val tofPair = if (cameraTimestampSourceName == "REALTIME") {
            TofCameraFramePairer.nearest(
                cameraElapsedRealtimeNs = sensorTimestampNs,
                frames = TofUsbRuntime.get(appContext).recentFramesSnapshot(),
            )
        } else {
            null
        }
        synchronized(lock) {
            val active = writer ?: return
            val index = frameCount
            frameCount += 1L
            observeCamera2State(result, camera2State)
            if (cameraTimestampSourceName != "REALTIME") {
                tofPairingSkippedTimestampDomain += 1L
            } else if (tofPair == null) {
                tofUnpairedResults += 1L
            } else {
                if (tofPair.accepted) tofAcceptedPairs += 1L else tofRejectedPairs += 1L
                tofPairAbsDeltaUs.addLast(tofPair.absDeltaUs)
                while (tofPairAbsDeltaUs.size > MAX_TOF_PAIRING_SAMPLES) {
                    tofPairAbsDeltaUs.removeFirst()
                }
            }
            if (firstSensorTimestampNs == null) {
                firstSensorTimestampNs = sensorTimestampNs
            }
            if (firstReceiveElapsedNs == null) {
                firstReceiveElapsedNs = receiveElapsedNs
            }
            lastSensorTimestampNs = sensorTimestampNs
            lastReceiveElapsedNs = receiveElapsedNs
            active.write(
                JSONObject()
                    .put("type", "frame")
                    .put("schema_version", 2)
                    .put("frame_index", index)
                    .put("camera_frame_number", result.frameNumber)
                    .put("sensor_timestamp_ns", sensorTimestampNs)
                    .put("elapsed_realtime_ns", receiveElapsedNs)
                    .put("elapsed_realtime_receive_ns", receiveElapsedNs)
                    .put("encoder_pts_us", JSONObject.NULL)
                    .put(
                        "encoder_mapping_status",
                        "UNVERIFIED_SEPARATE_TIMELINES",
                    )
                    .putNullable(
                        "exposure_time_ns",
                        result.get(CaptureResult.SENSOR_EXPOSURE_TIME),
                    )
                    .putNullable(
                        "frame_duration_ns",
                        result.get(CaptureResult.SENSOR_FRAME_DURATION),
                    )
                    .putNullable(
                        "sensitivity_iso",
                        result.get(CaptureResult.SENSOR_SENSITIVITY),
                    )
                    .putNullable(
                        "rolling_shutter_skew_ns",
                        result.get(
                            CaptureResult.SENSOR_ROLLING_SHUTTER_SKEW,
                        ),
                    )
                    .putNullable(
                        "focus_distance_diopters",
                        result.get(CaptureResult.LENS_FOCUS_DISTANCE),
                    )
                    .put("camera2_runtime", camera2State)
                    .put(
                        "tof_pair",
                        tofPair?.let { pair ->
                            JSONObject()
                                .put("sequence", pair.sequence)
                                .put(
                                    "mapped_elapsed_realtime_ns",
                                    pair.mappedElapsedRealtimeNs,
                                )
                                .put("signed_delta_us", pair.signedDeltaUs)
                                .put("abs_delta_us", pair.absDeltaUs)
                                .put("threshold_us", pair.thresholdUs)
                                .put("accepted", pair.accepted)
                        } ?: JSONObject.NULL,
                    )
                    .putNullable("width", frameWidth)
                    .putNullable("height", frameHeight)
                    .put("rotation_degrees", targetRotationDegrees)
                    .toString(),
            )
            active.newLine()
            if (frameCount % 120L == 0L) {
                active.flush()
            }
        }
    }

    fun stop(): PhoneFrameTelemetrySummary? = synchronized(lock) {
        stopLocked()
    }

    private fun stopLocked(): PhoneFrameTelemetrySummary? {
        val file = outputFile ?: return null
        writer?.apply {
            flush()
            close()
        }
        writer = null
        outputFile = null
        val firstSensor = firstSensorTimestampNs
        val lastSensor = lastSensorTimestampNs
        val durationNs = if (
            firstSensor != null && lastSensor != null &&
            lastSensor > firstSensor
        ) {
            lastSensor - firstSensor
        } else {
            null
        }
        val observedFps = if (durationNs != null && frameCount > 1L) {
            (frameCount - 1L).toDouble() * 1_000_000_000.0 /
                durationNs.toDouble()
        } else {
            null
        }
        val expectedCount = if (
            durationNs != null && expectedFps != null
        ) {
            (durationNs.toDouble() * expectedFps!!.toDouble() /
                1_000_000_000.0).roundToLong() + 1L
        } else {
            frameCount
        }
        return PhoneFrameTelemetrySummary(
            path = file.absolutePath,
            frameCount = frameCount,
            firstSensorTimestampNs = firstSensorTimestampNs,
            lastSensorTimestampNs = lastSensorTimestampNs,
            firstReceiveElapsedNs = firstReceiveElapsedNs,
            lastReceiveElapsedNs = lastReceiveElapsedNs,
            observedCaptureResultFps = observedFps,
            estimatedMissingCaptureResults = max(
                0L,
                expectedCount - frameCount,
            ),
            camera2CaptureState = camera2CaptureStateJson(),
            tofCaptureState = tofCaptureStateJson(),
        )
    }

    private fun observeCamera2State(
        result: TotalCaptureResult,
        state: JSONObject,
    ) {
        if (firstCamera2State == null) firstCamera2State = state
        lastCamera2State = state

        result.get(CaptureResult.SCALER_CROP_REGION)?.let { rect ->
            cropRegions += "${rect.left},${rect.top},${rect.right},${rect.bottom}"
        }
        result.get(CaptureResult.LENS_FOCAL_LENGTH)?.toDouble()?.let {
            focalLengthsMm += it
        }
        result.get(CaptureResult.LENS_FOCUS_DISTANCE)?.toDouble()?.let {
            focusDistanceMin = minValue(focusDistanceMin, it)
            focusDistanceMax = maxValue(focusDistanceMax, it)
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            result.get(CaptureResult.CONTROL_ZOOM_RATIO)?.toDouble()?.let {
                zoomRatioMin = minValue(zoomRatioMin, it)
                zoomRatioMax = maxValue(zoomRatioMax, it)
            }
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            result.get(
                CaptureResult.LOGICAL_MULTI_CAMERA_ACTIVE_PHYSICAL_ID,
            )?.let { activePhysicalCameraIds += it }
        }
        result.get(CaptureResult.CONTROL_VIDEO_STABILIZATION_MODE)?.let {
            videoStabilizationModes += it
        }
        result.get(CaptureResult.LENS_OPTICAL_STABILIZATION_MODE)?.let {
            opticalStabilizationModes += it
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            result.get(CaptureResult.DISTORTION_CORRECTION_MODE)?.let {
                distortionCorrectionModes += it
            }
        }
    }

    private fun camera2State(result: TotalCaptureResult): JSONObject {
        val crop = result.get(CaptureResult.SCALER_CROP_REGION)
        val out = JSONObject()
            .put("source", "CAMERA2_TOTAL_CAPTURE_RESULT")
            .put("camera_frame_number", result.frameNumber)
            .putNullable(
                "sensor_timestamp_ns",
                result.get(CaptureResult.SENSOR_TIMESTAMP),
            )
            .put(
                "crop_region",
                crop?.let {
                    JSONObject()
                        .put("left", it.left)
                        .put("top", it.top)
                        .put("right", it.right)
                        .put("bottom", it.bottom)
                        .put("width", it.width())
                        .put("height", it.height())
                } ?: JSONObject.NULL,
            )
            .putNullable(
                "focal_length_mm",
                result.get(CaptureResult.LENS_FOCAL_LENGTH),
            )
            .putNullable(
                "focus_distance_diopters",
                result.get(CaptureResult.LENS_FOCUS_DISTANCE),
            )
            .putNullable("af_mode", result.get(CaptureResult.CONTROL_AF_MODE))
            .putNullable("af_state", result.get(CaptureResult.CONTROL_AF_STATE))
            .putNullable("ae_mode", result.get(CaptureResult.CONTROL_AE_MODE))
            .putNullable("ae_state", result.get(CaptureResult.CONTROL_AE_STATE))
            .putNullable("awb_mode", result.get(CaptureResult.CONTROL_AWB_MODE))
            .putNullable("awb_state", result.get(CaptureResult.CONTROL_AWB_STATE))
            .putNullable(
                "exposure_time_ns",
                result.get(CaptureResult.SENSOR_EXPOSURE_TIME),
            )
            .putNullable(
                "sensitivity_iso",
                result.get(CaptureResult.SENSOR_SENSITIVITY),
            )
            .putNullable(
                "frame_duration_ns",
                result.get(CaptureResult.SENSOR_FRAME_DURATION),
            )
            .putNullable(
                "video_stabilization_mode",
                result.get(CaptureResult.CONTROL_VIDEO_STABILIZATION_MODE),
            )
            .putNullable(
                "optical_stabilization_mode",
                result.get(CaptureResult.LENS_OPTICAL_STABILIZATION_MODE),
            )
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            out.putNullable(
                "distortion_correction_mode",
                result.get(CaptureResult.DISTORTION_CORRECTION_MODE),
            )
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            out.putNullable(
                "active_physical_camera_id",
                result.get(
                    CaptureResult.LOGICAL_MULTI_CAMERA_ACTIVE_PHYSICAL_ID,
                ),
            )
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            out.putNullable(
                "zoom_ratio",
                result.get(CaptureResult.CONTROL_ZOOM_RATIO),
            )
        }
        return out
    }

    private fun camera2CaptureStateJson(): JSONObject {
        val zoomStable =
            zoomRatioMin == null || zoomRatioMax == null ||
                kotlin.math.abs(zoomRatioMax!! - zoomRatioMin!!) <= 0.001
        val geometryStable =
            cropRegions.size <= 1 &&
                focalLengthsMm.size <= 1 &&
                activePhysicalCameraIds.size <= 1 &&
                zoomStable
        return JSONObject()
            .put("schema_version", 1)
            .put("source", "CAMERA2_TOTAL_CAPTURE_RESULT")
            .put("capture_result_count", frameCount)
            .put("sensor_timestamp_source_name", cameraTimestampSourceName)
            .put("first_capture_result", firstCamera2State ?: JSONObject.NULL)
            .put("last_capture_result", lastCamera2State ?: JSONObject.NULL)
            .put(
                "observed_runtime",
                JSONObject()
                    .put("crop_regions", JSONArray(cropRegions.toList()))
                    .put("crop_regions_count", cropRegions.size)
                    .put("zoom_ratio_min", zoomRatioMin ?: JSONObject.NULL)
                    .put("zoom_ratio_max", zoomRatioMax ?: JSONObject.NULL)
                    .put("focal_lengths_mm", JSONArray(focalLengthsMm.toList()))
                    .put(
                        "focus_distance_diopters_min",
                        focusDistanceMin ?: JSONObject.NULL,
                    )
                    .put(
                        "focus_distance_diopters_max",
                        focusDistanceMax ?: JSONObject.NULL,
                    )
                    .put(
                        "active_physical_camera_ids",
                        JSONArray(activePhysicalCameraIds.toList()),
                    )
                    .put(
                        "video_stabilization_modes",
                        JSONArray(videoStabilizationModes.toList()),
                    )
                    .put(
                        "optical_stabilization_modes",
                        JSONArray(opticalStabilizationModes.toList()),
                    )
                    .put(
                        "distortion_correction_modes",
                        JSONArray(distortionCorrectionModes.toList()),
                    )
                    .put("geometry_stable", geometryStable),
            )
    }

    private fun tofCaptureStateJson(): JSONObject {
        val runtime = TofUsbRuntime.get(appContext)
        val state = runtime.state.value
        val latest = runtime.latestFrame.value
        val sortedDeltaUs = tofPairAbsDeltaUs.toList().sorted()
        val timestampDomainValid = cameraTimestampSourceName == "REALTIME"
        return JSONObject()
            .put("schema_version", 1)
            .put(
                "available",
                state.deviceId != null || latest != null ||
                    state.framesOk > tofFramesOkAtStart,
            )
            .put("active", state.status.name == "STREAMING")
            .put("source", "VL53L8CX_RP2040_USB")
            .put("usb_status_start", tofStatusAtStart)
            .put("usb_status_end", state.status.name)
            .putNullable("device_id", state.deviceId)
            .putNullable("vendor_id", state.vendorId)
            .putNullable("product_id", state.productId)
            .put("frames_ok_start", tofFramesOkAtStart)
            .put("frames_ok_end", state.framesOk)
            .put(
                "frames_during_capture",
                (state.framesOk - tofFramesOkAtStart).coerceAtLeast(0L),
            )
            .put(
                "crc_errors_during_capture",
                (state.crcErrors - tofCrcErrorsAtStart).coerceAtLeast(0L),
            )
            .put(
                "sequence_drops_during_capture",
                (state.sequenceDrops - tofSequenceDropsAtStart)
                    .coerceAtLeast(0L),
            )
            .putNullable("last_frame_age_ms", runtime.lastFrameAgeMs())
            .put(
                "latest_frame",
                latest?.let { frame ->
                    JSONObject()
                        .put("sequence", frame.sequence)
                        .put("slot", frame.slot)
                        .put("width", frame.width)
                        .put("height", frame.height)
                        .put("frequency_hz", frame.frequencyHz)
                        .put(
                            "silicon_temperature_c",
                            frame.siliconTemperatureC,
                        )
                        .put(
                            "rp2040_timestamp_us",
                            frame.rp2040TimestampUs,
                        )
                        .put("irq_timestamp_valid", frame.irqTimestampValid)
                        .put(
                            "host_received_elapsed_realtime_ns",
                            frame.hostReceivedElapsedRealtimeNs,
                        )
                } ?: JSONObject.NULL,
            )
            .put(
                "camera2_pairing",
                JSONObject()
                    .put(
                        "status",
                        if (timestampDomainValid) {
                            "ACTIVE"
                        } else {
                            "SKIPPED_CAMERA_TIMESTAMP_SOURCE_" +
                                cameraTimestampSourceName
                        },
                    )
                    .put(
                        "camera_timestamp_source_name",
                        cameraTimestampSourceName,
                    )
                    .put(
                        "tof_timestamp_source",
                        "RP2040_IRQ_MAPPED_TO_HOST_ELAPSED_REALTIME",
                    )
                    .put("accepted_pairs", tofAcceptedPairs)
                    .put("rejected_pairs", tofRejectedPairs)
                    .put("unpaired_capture_results", tofUnpairedResults)
                    .put(
                        "skipped_timestamp_domain_results",
                        tofPairingSkippedTimestampDomain,
                    )
                    .put(
                        "delta_us_min",
                        sortedDeltaUs.firstOrNull() ?: JSONObject.NULL,
                    )
                    .put(
                        "delta_us_median",
                        percentile(sortedDeltaUs, 0.50) ?: JSONObject.NULL,
                    )
                    .put(
                        "delta_us_p95",
                        percentile(sortedDeltaUs, 0.95) ?: JSONObject.NULL,
                    )
                    .put(
                        "delta_us_max",
                        sortedDeltaUs.lastOrNull() ?: JSONObject.NULL,
                    )
                    .put(
                        "acceptance_threshold",
                        "half_ToF_period_plus_2000us",
                    ),
            )
    }

    private fun cameraTimestampSourceName(cameraId: String): String =
        runCatching {
            val manager =
                appContext.getSystemService(CameraManager::class.java)
            when (
                manager.getCameraCharacteristics(cameraId).get(
                    CameraCharacteristics.SENSOR_INFO_TIMESTAMP_SOURCE,
                )
            ) {
                CameraMetadata.SENSOR_INFO_TIMESTAMP_SOURCE_REALTIME ->
                    "REALTIME"
                CameraMetadata.SENSOR_INFO_TIMESTAMP_SOURCE_UNKNOWN ->
                    "UNKNOWN"
                else -> "UNAVAILABLE"
            }
        }.getOrDefault("UNAVAILABLE")

    private fun minValue(current: Double?, value: Double): Double =
        current?.let { kotlin.math.min(it, value) } ?: value

    private fun maxValue(current: Double?, value: Double): Double =
        current?.let { kotlin.math.max(it, value) } ?: value

    private fun percentile(sorted: List<Long>, fraction: Double): Long? {
        if (sorted.isEmpty()) return null
        val index = ((sorted.size - 1) * fraction)
            .roundToLong()
            .toInt()
            .coerceIn(0, sorted.lastIndex)
        return sorted[index]
    }

    private fun JSONObject.putNullable(
        key: String,
        value: Any?,
    ): JSONObject = put(key, value ?: JSONObject.NULL)

    private companion object {
        const val MAX_TOF_PAIRING_SAMPLES = 4096
    }
}

object Mp4VideoPtsExtractor {
    fun extract(
        videoFile: File,
        outputFile: File = File(
            videoFile.parentFile,
            "encoder_pts.jsonl",
        ),
    ): PhoneEncoderPtsSummary {
        outputFile.parentFile?.mkdirs()
        outputFile.delete()
        return runCatching {
            val extractor = MediaExtractor()
            try {
                extractor.setDataSource(videoFile.absolutePath)
                val videoTrack = (0 until extractor.trackCount)
                    .firstOrNull { index ->
                        extractor.getTrackFormat(index)
                            .getString(MediaFormat.KEY_MIME)
                            ?.startsWith("video/") == true
                    }
                    ?: error("MP4 has no video track")
                val format = extractor.getTrackFormat(videoTrack)
                extractor.selectTrack(videoTrack)
                var sampleCount = 0L
                var firstPtsUs: Long? = null
                var lastPtsUs: Long? = null
                outputFile.bufferedWriter().use { writer ->
                    writer.write(
                        JSONObject()
                            .put("type", "metadata")
                            .put("schema_version", 1)
                            .put("source", "ANDROID_MEDIA_EXTRACTOR")
                            .put("video_path", videoFile.absolutePath)
                            .putNullable(
                                "mime",
                                format.stringOrNull(MediaFormat.KEY_MIME),
                            )
                            .putNullable(
                                "width",
                                format.integerOrNull(MediaFormat.KEY_WIDTH),
                            )
                            .putNullable(
                                "height",
                                format.integerOrNull(MediaFormat.KEY_HEIGHT),
                            )
                            .putNullable(
                                "declared_frame_rate",
                                format.integerOrNull(
                                    MediaFormat.KEY_FRAME_RATE,
                                ),
                            )
                            .put(
                                "capture_result_mapping_status",
                                "UNVERIFIED_ORDINAL_ONLY",
                            )
                            .toString(),
                    )
                    writer.newLine()
                    while (true) {
                        val ptsUs = extractor.sampleTime
                        if (ptsUs < 0L) break
                        if (firstPtsUs == null) firstPtsUs = ptsUs
                        lastPtsUs = ptsUs
                        writer.write(
                            JSONObject()
                                .put("type", "sample")
                                .put("sample_index", sampleCount)
                                .put("pts_us", ptsUs)
                                .put("flags", extractor.sampleFlags)
                                .put(
                                    "sample_size_bytes",
                                    if (Build.VERSION.SDK_INT >= 28) {
                                        extractor.sampleSize
                                    } else {
                                        -1L
                                    },
                                )
                                .toString(),
                        )
                        writer.newLine()
                        sampleCount += 1L
                        if (!extractor.advance()) break
                    }
                }
                val durationUs = if (
                    firstPtsUs != null && lastPtsUs != null &&
                    lastPtsUs!! > firstPtsUs!!
                ) {
                    lastPtsUs!! - firstPtsUs!!
                } else {
                    null
                }
                PhoneEncoderPtsSummary(
                    path = outputFile.absolutePath,
                    sampleCount = sampleCount,
                    firstPtsUs = firstPtsUs,
                    lastPtsUs = lastPtsUs,
                    observedFps = if (
                        durationUs != null && sampleCount > 1L
                    ) {
                        (sampleCount - 1L).toDouble() * 1_000_000.0 /
                            durationUs.toDouble()
                    } else {
                        null
                    },
                    status = "OK",
                )
            } finally {
                extractor.release()
            }
        }.getOrElse { error ->
            outputFile.writeText(
                JSONObject()
                    .put("type", "error")
                    .put("schema_version", 1)
                    .put(
                        "message",
                        error.message ?: error.javaClass.simpleName,
                    )
                    .toString() + "\n",
            )
            PhoneEncoderPtsSummary(
                path = outputFile.absolutePath,
                sampleCount = 0L,
                firstPtsUs = null,
                lastPtsUs = null,
                observedFps = null,
                status = "ERROR",
            )
        }
    }

    private fun MediaFormat.stringOrNull(key: String): String? =
        if (containsKey(key)) getString(key) else null

    private fun MediaFormat.integerOrNull(key: String): Int? =
        if (containsKey(key)) getInteger(key) else null

    private fun JSONObject.putNullable(
        key: String,
        value: Any?,
    ): JSONObject = put(key, value ?: JSONObject.NULL)
}
