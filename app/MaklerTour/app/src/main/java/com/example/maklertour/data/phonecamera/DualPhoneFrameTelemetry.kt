package com.maklertour.data.phonecamera

import android.hardware.camera2.CaptureResult
import android.hardware.camera2.TotalCaptureResult
import android.media.MediaExtractor
import android.media.MediaFormat
import android.os.Build
import android.os.SystemClock
import org.json.JSONObject
import java.io.BufferedWriter
import java.io.File
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
)

data class PhoneEncoderPtsSummary(
    val path: String,
    val sampleCount: Long,
    val firstPtsUs: Long?,
    val lastPtsUs: Long?,
    val observedFps: Double?,
    val status: String,
)

class DualPhoneFrameTelemetryRecorder {
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

    fun start(
        baseDir: File,
        context: PhoneVideoTelemetryContext,
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
        writer = file.bufferedWriter().also { active ->
            active.write(
                JSONObject()
                    .put("type", "metadata")
                    .put("schema_version", 1)
                    .put("dual_capture_id", context.dualCaptureId)
                    .put("role", context.role)
                    .put("camera_id", cameraId)
                    .putNullable("video_mode_id", videoModeId)
                    .putNullable("width", width)
                    .putNullable("height", height)
                    .putNullable("fps_requested", fps)
                    .put("rotation_degrees", rotationDegrees)
                    .put(
                        "scheduled_start_elapsed_ns",
                        context.scheduledElapsedRealtimeNs,
                    )
                    .put("start_call_elapsed_ns", startCallElapsedNs)
                    .putNullable("clock_offset_ns", context.clockOffsetNs)
                    .putNullable(
                        "clock_uncertainty_ns",
                        context.clockUncertaintyNs,
                    )
                    .putNullable("clock_drift_ppm", context.clockDriftPpm)
                    .put(
                        "sensor_timestamp_source",
                        "CAMERA2_CAPTURE_RESULT_SENSOR_TIMESTAMP",
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
        synchronized(lock) {
            val active = writer ?: return
            val index = frameCount
            frameCount += 1L
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
                    .put("schema_version", 1)
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
        )
    }

    private fun JSONObject.putNullable(
        key: String,
        value: Any?,
    ): JSONObject = put(key, value ?: JSONObject.NULL)
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
