package com.maklertour.data.phonecamera

import android.media.MediaExtractor
import org.json.JSONObject
import java.io.File
import java.util.Locale
import kotlin.math.abs
import kotlin.math.max
import kotlin.math.roundToLong

/**
 * Local integrity and ordinal/timestamp mapping between Camera2 capture results and
 * finalized MP4 samples. This does not replace the later Master/Slave common timeline.
 */
data class DualPhoneLocalTimelineSummary(
    val frameEncoderMapPath: String,
    val localTimelineReportPath: String,
    val videoParseable: Boolean,
    val keyframePresent: Boolean,
    val captureResultFpsActual: Double?,
    val encoderFpsActual: Double?,
    val captureResultGapCount: Long,
    val encoderGapCount: Long,
    val mappedSamples: Long,
    val unmatchedEncodedSamples: Long,
    val unmatchedCaptureResults: Long,
    val mappingResidualP50Ns: Long?,
    val mappingResidualP95Ns: Long?,
    val mappingResidualMaxNs: Long?,
    val mappingStatus: String,
    val mappingQuality: String,
    val actualWidth: Int?,
    val actualHeight: Int?,
    val effectiveVideoModeActual: String?,
)

object DualPhoneLocalTimelineAnalyzer {
    fun analyze(
        framesFile: File,
        encoderPtsFile: File,
        frameEncoderMapFile: File,
        localTimelineReportFile: File,
        cameraXStartElapsedNs: Long?,
        fallbackWidth: Int?,
        fallbackHeight: Int?,
    ): DualPhoneLocalTimelineSummary {
        frameEncoderMapFile.parentFile?.mkdirs()
        localTimelineReportFile.parentFile?.mkdirs()
        frameEncoderMapFile.delete()
        localTimelineReportFile.delete()

        val frames = readFrames(framesFile)
        val encoderData = readEncoderSamples(encoderPtsFile)
        val frameStats = timelineStats(frames.map { it.sensorTimestampNs }, 1_000_000_000.0)
        val encoderStats = timelineStats(encoderData.samples.map { it.ptsUs }, 1_000_000.0)
        val mapping = mapTimelines(
            frames = frames,
            samples = encoderData.samples,
            cameraXStartElapsedNs = cameraXStartElapsedNs,
            medianFrameIntervalNs = frameStats.medianIntervalNs,
        )

        val mappedFrameIndexes = mapping.rows.mapNotNull { it.frame?.frameIndex }.toSet()
        val mappedSamples = mapping.rows.count { it.frame != null }.toLong()
        val unmatchedEncoded = encoderData.samples.size.toLong() - mappedSamples
        val unmatchedFrames = frames.size.toLong() - mappedFrameIndexes.size.toLong()
        val residuals = mapping.rows.mapNotNull { it.residualNs?.let(::abs) }.sorted()
        val residualP50 = percentile(residuals, 0.50)
        val residualP95 = percentile(residuals, 0.95)
        val residualMax = residuals.lastOrNull()
        val videoParseable = encoderData.status == "OK" && encoderData.samples.isNotEmpty()
        val keyframePresent = encoderData.samples.any {
            it.flags and MediaExtractor.SAMPLE_FLAG_SYNC != 0
        }
        val actualWidth = encoderData.width ?: fallbackWidth
        val actualHeight = encoderData.height ?: fallbackHeight
        val actualFps = encoderStats.observedFps
        val effectiveMode = if (
            actualWidth != null && actualHeight != null && actualFps != null
        ) {
            "${actualWidth}x${actualHeight}@${fpsLabel(actualFps)}"
        } else {
            null
        }
        val mappedRatio = if (encoderData.samples.isNotEmpty()) {
            mappedSamples.toDouble() / encoderData.samples.size.toDouble()
        } else {
            0.0
        }
        val goodResidualLimit = max(
            5_000_000L,
            (frameStats.medianIntervalNs ?: 20_000_000L) / 4L,
        )
        val fairResidualLimit = max(
            15_000_000L,
            (frameStats.medianIntervalNs ?: 20_000_000L) / 2L,
        )
        val quality = when {
            !videoParseable || !keyframePresent || mappedSamples == 0L -> "POOR"
            mappedRatio >= 0.95 && (residualP95 ?: Long.MAX_VALUE) <= goodResidualLimit -> "GOOD"
            mappedRatio >= 0.90 && (residualP95 ?: Long.MAX_VALUE) <= fairResidualLimit -> "FAIR"
            else -> "POOR"
        }
        val status = when {
            mappedSamples == 0L -> "UNAVAILABLE"
            cameraXStartElapsedNs != null -> "MAPPED_MONOTONIC_CAMERAX_START_ANCHORED"
            else -> "MAPPED_MONOTONIC_BOUNDARY_FALLBACK"
        }

        writeMapping(
            file = frameEncoderMapFile,
            framesFile = framesFile,
            encoderPtsFile = encoderPtsFile,
            cameraXStartElapsedNs = cameraXStartElapsedNs,
            mapping = mapping,
            status = status,
            quality = quality,
        )

        val summary = DualPhoneLocalTimelineSummary(
            frameEncoderMapPath = frameEncoderMapFile.absolutePath,
            localTimelineReportPath = localTimelineReportFile.absolutePath,
            videoParseable = videoParseable,
            keyframePresent = keyframePresent,
            captureResultFpsActual = frameStats.observedFps,
            encoderFpsActual = encoderStats.observedFps,
            captureResultGapCount = frameStats.gapCount,
            encoderGapCount = encoderStats.gapCount,
            mappedSamples = mappedSamples,
            unmatchedEncodedSamples = unmatchedEncoded,
            unmatchedCaptureResults = unmatchedFrames,
            mappingResidualP50Ns = residualP50,
            mappingResidualP95Ns = residualP95,
            mappingResidualMaxNs = residualMax,
            mappingStatus = status,
            mappingQuality = quality,
            actualWidth = actualWidth,
            actualHeight = actualHeight,
            effectiveVideoModeActual = effectiveMode,
        )
        writeReport(localTimelineReportFile, summary, frameStats, encoderStats, mapping)
        return summary
    }

    private fun readFrames(file: File): List<FrameRecord> {
        if (!file.isFile) return emptyList()
        val result = ArrayList<FrameRecord>()
        file.useLines { lines ->
            lines.forEach { line ->
                if (line.isBlank()) return@forEach
                val json = runCatching { JSONObject(line) }.getOrNull() ?: return@forEach
                if (json.optString("type") != "frame") return@forEach
                val sensorNs = json.optLong("sensor_timestamp_ns", Long.MIN_VALUE)
                if (sensorNs == Long.MIN_VALUE) return@forEach
                result += FrameRecord(
                    frameIndex = json.optLong("frame_index", result.size.toLong()),
                    cameraFrameNumber = json.optLong("camera_frame_number", -1L),
                    sensorTimestampNs = sensorNs,
                )
            }
        }
        return result.sortedBy { it.sensorTimestampNs }
    }

    private fun readEncoderSamples(file: File): EncoderData {
        if (!file.isFile) return EncoderData(status = "MISSING")
        var status = "OK"
        var width: Int? = null
        var height: Int? = null
        val samples = ArrayList<EncoderSample>()
        file.useLines { lines ->
            lines.forEach { line ->
                if (line.isBlank()) return@forEach
                val json = runCatching { JSONObject(line) }.getOrNull() ?: return@forEach
                when (json.optString("type")) {
                    "metadata" -> {
                        width = json.optNullableInt("width") ?: width
                        height = json.optNullableInt("height") ?: height
                    }
                    "sample" -> {
                        val ptsUs = json.optLong("pts_us", Long.MIN_VALUE)
                        if (ptsUs == Long.MIN_VALUE) return@forEach
                        samples += EncoderSample(
                            sampleIndex = json.optLong("sample_index", samples.size.toLong()),
                            ptsUs = ptsUs,
                            flags = json.optInt("flags", 0),
                        )
                    }
                    "error" -> status = "ERROR"
                }
            }
        }
        return EncoderData(
            status = if (status == "OK" && samples.isEmpty()) "EMPTY" else status,
            width = width,
            height = height,
            samples = samples.sortedBy { it.ptsUs },
        )
    }

    private fun timelineStats(
        timestamps: List<Long>,
        unitsPerSecond: Double,
    ): TimelineStats {
        if (timestamps.size < 2) return TimelineStats()
        val intervals = timestamps.zipWithNext { a, b -> b - a }
            .filter { it > 0L }
            .sorted()
        val median = percentile(intervals, 0.50)
        val gaps = if (median != null && median > 0L) {
            intervals.sumOf { delta ->
                if (delta <= median + median / 2L) {
                    0L
                } else {
                    ((delta.toDouble() / median.toDouble()).roundToLong() - 1L)
                        .coerceAtLeast(0L)
                }
            }
        } else {
            0L
        }
        val duration = timestamps.last() - timestamps.first()
        val fps = if (duration > 0L) {
            (timestamps.size - 1).toDouble() * unitsPerSecond / duration.toDouble()
        } else {
            null
        }
        return TimelineStats(
            observedFps = fps,
            medianIntervalNs = if (unitsPerSecond == 1_000_000_000.0) {
                median
            } else {
                median?.times(1_000L)
            },
            gapCount = gaps,
        )
    }

    private fun mapTimelines(
        frames: List<FrameRecord>,
        samples: List<EncoderSample>,
        cameraXStartElapsedNs: Long?,
        medianFrameIntervalNs: Long?,
    ): MappingResult {
        if (frames.isEmpty() || samples.isEmpty()) return MappingResult(emptyList(), null, null)
        val anchorFramePosition = if (cameraXStartElapsedNs != null) {
            frames.indices.minByOrNull { index ->
                abs(frames[index].sensorTimestampNs - cameraXStartElapsedNs)
            } ?: 0
        } else {
            ((frames.size - samples.size).coerceAtLeast(0) / 2)
                .coerceIn(0, frames.lastIndex)
        }
        var offsetNs = frames[anchorFramePosition].sensorTimestampNs - samples.first().ptsUs * 1_000L
        var rows = mapWithOffset(frames, samples, offsetNs, medianFrameIntervalNs)
        val mappedOffsets = rows.mapNotNull { row ->
            row.frame?.let { it.sensorTimestampNs - row.sample.ptsUs * 1_000L }
        }.sorted()
        percentile(mappedOffsets, 0.50)?.let { refined ->
            offsetNs = refined
            rows = mapWithOffset(frames, samples, offsetNs, medianFrameIntervalNs)
        }
        return MappingResult(
            rows = rows,
            offsetNs = offsetNs,
            anchorFrameIndex = frames[anchorFramePosition].frameIndex,
        )
    }

    private fun mapWithOffset(
        frames: List<FrameRecord>,
        samples: List<EncoderSample>,
        offsetNs: Long,
        medianFrameIntervalNs: Long?,
    ): List<MappingRow> {
        val thresholdNs = max(
            20_000_000L,
            ((medianFrameIntervalNs ?: 33_333_333L) * 3L) / 4L,
        )
        val rows = ArrayList<MappingRow>(samples.size)
        var minimumFramePosition = 0
        samples.forEach { sample ->
            if (minimumFramePosition >= frames.size) {
                rows += MappingRow(sample, null, null, "UNMATCHED_NO_FRAME")
                return@forEach
            }
            val targetNs = sample.ptsUs * 1_000L + offsetNs
            var best = minimumFramePosition
            while (best + 1 < frames.size) {
                val currentDistance = abs(frames[best].sensorTimestampNs - targetNs)
                val nextDistance = abs(frames[best + 1].sensorTimestampNs - targetNs)
                if (nextDistance > currentDistance) break
                best += 1
            }
            val residual = frames[best].sensorTimestampNs - targetNs
            if (abs(residual) <= thresholdNs) {
                rows += MappingRow(sample, frames[best], residual, "MATCHED")
                minimumFramePosition = best + 1
            } else {
                rows += MappingRow(sample, null, null, "UNMATCHED_RESIDUAL_HIGH")
            }
        }
        return rows
    }

    private fun writeMapping(
        file: File,
        framesFile: File,
        encoderPtsFile: File,
        cameraXStartElapsedNs: Long?,
        mapping: MappingResult,
        status: String,
        quality: String,
    ) {
        file.bufferedWriter().use { writer ->
            writer.write(
                JSONObject()
                    .put("type", "metadata")
                    .put("schema_version", 1)
                    .put("mapping_contract", "PROVISIONAL_LOCAL_CAMERA2_TO_ENCODER_PTS")
                    .put("mapping_status", status)
                    .put("mapping_quality", quality)
                    .put("frames_path", framesFile.absolutePath)
                    .put("encoder_pts_path", encoderPtsFile.absolutePath)
                    .putNullable("camerax_start_elapsed_ns", cameraXStartElapsedNs)
                    .putNullable("mapping_offset_ns", mapping.offsetNs)
                    .putNullable("anchor_camera_frame_index", mapping.anchorFrameIndex)
                    .toString(),
            )
            writer.newLine()
            mapping.rows.forEach { row ->
                writer.write(
                    JSONObject()
                        .put("type", "mapping")
                        .put("schema_version", 1)
                        .put("sample_index", row.sample.sampleIndex)
                        .put("encoder_pts_us", row.sample.ptsUs)
                        .put("encoder_flags", row.sample.flags)
                        .putNullable("camera_frame_index", row.frame?.frameIndex)
                        .putNullable("camera_frame_number", row.frame?.cameraFrameNumber)
                        .putNullable("sensor_timestamp_ns", row.frame?.sensorTimestampNs)
                        .putNullable("mapping_residual_ns", row.residualNs)
                        .put("mapping_status", row.status)
                        .toString(),
                )
                writer.newLine()
            }
        }
    }

    private fun writeReport(
        file: File,
        summary: DualPhoneLocalTimelineSummary,
        frameStats: TimelineStats,
        encoderStats: TimelineStats,
        mapping: MappingResult,
    ) {
        file.writeText(
            JSONObject()
                .put("schema_version", 1)
                .put("report_type", "dual_phone_local_timeline_integrity")
                .put("video_parseable", summary.videoParseable)
                .put("keyframe_present", summary.keyframePresent)
                .putNullable("capture_result_fps_actual", summary.captureResultFpsActual)
                .putNullable("encoder_fps_actual", summary.encoderFpsActual)
                .putNullable("capture_result_median_interval_ns", frameStats.medianIntervalNs)
                .putNullable("encoder_median_interval_ns", encoderStats.medianIntervalNs)
                .put("capture_result_count", mapping.rows.mapNotNull { it.frame }.size + summary.unmatchedCaptureResults)
                .put("encoded_sample_count", mapping.rows.size)
                .put("capture_result_gap_count", summary.captureResultGapCount)
                .put("encoder_gap_count", summary.encoderGapCount)
                .put("mapped_samples", summary.mappedSamples)
                .put("unmatched_encoded_samples", summary.unmatchedEncodedSamples)
                .put("unmatched_capture_results", summary.unmatchedCaptureResults)
                .putNullable("mapping_offset_ns", mapping.offsetNs)
                .putNullable("mapping_residual_p50_ns", summary.mappingResidualP50Ns)
                .putNullable("mapping_residual_p95_ns", summary.mappingResidualP95Ns)
                .putNullable("mapping_residual_max_ns", summary.mappingResidualMaxNs)
                .put("mapping_status", summary.mappingStatus)
                .put("mapping_quality", summary.mappingQuality)
                .putNullable("actual_width", summary.actualWidth)
                .putNullable("actual_height", summary.actualHeight)
                .putNullable("effective_video_mode_actual", summary.effectiveVideoModeActual)
                .put("server_common_timeline_required", true)
                .toString(2) + "\n",
            Charsets.UTF_8,
        )
    }

    private fun percentile(values: List<Long>, fraction: Double): Long? {
        if (values.isEmpty()) return null
        val position = ((values.size - 1).toDouble() * fraction)
            .roundToLong()
            .toInt()
            .coerceIn(0, values.lastIndex)
        return values[position]
    }

    private fun fpsLabel(fps: Double): String {
        val rounded = fps.roundToLong()
        return if (abs(fps - rounded.toDouble()) < 0.05) {
            rounded.toString()
        } else {
            String.format(Locale.US, "%.3f", fps)
        }
    }

    private fun JSONObject.optNullableInt(key: String): Int? =
        if (has(key) && !isNull(key)) optInt(key) else null

    private fun JSONObject.putNullable(key: String, value: Any?): JSONObject =
        put(key, value ?: JSONObject.NULL)

    private data class FrameRecord(
        val frameIndex: Long,
        val cameraFrameNumber: Long,
        val sensorTimestampNs: Long,
    )

    private data class EncoderSample(
        val sampleIndex: Long,
        val ptsUs: Long,
        val flags: Int,
    )

    private data class EncoderData(
        val status: String,
        val width: Int? = null,
        val height: Int? = null,
        val samples: List<EncoderSample> = emptyList(),
    )

    private data class TimelineStats(
        val observedFps: Double? = null,
        val medianIntervalNs: Long? = null,
        val gapCount: Long = 0L,
    )

    private data class MappingRow(
        val sample: EncoderSample,
        val frame: FrameRecord?,
        val residualNs: Long?,
        val status: String,
    )

    private data class MappingResult(
        val rows: List<MappingRow>,
        val offsetNs: Long?,
        val anchorFrameIndex: Long?,
    )
}
