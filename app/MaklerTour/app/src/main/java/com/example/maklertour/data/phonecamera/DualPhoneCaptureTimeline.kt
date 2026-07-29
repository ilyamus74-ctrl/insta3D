package com.maklertour.data.phonecamera

import com.maklertour.data.dualphone.DualPhoneClockSyncSnapshot
import com.maklertour.data.dualphone.captureSchedulingAllowed
import android.os.SystemClock
import org.json.JSONObject
import java.io.BufferedWriter
import java.io.File

internal class DualPhoneCaptureTimeline {
    private val lock = Any()
    private var eventWriter: BufferedWriter? = null
    private var clockWriter: BufferedWriter? = null
    private var eventFile: File? = null
    private var clockFile: File? = null
    private var sequence = 0L
    private var lastClockUpdatedAtNs: Long? = null

    fun open(
        baseDir: File,
        dualCaptureId: String,
        role: String,
        deviceId: String,
        armedAtElapsedNs: Long,
    ): Pair<File, File> = synchronized(lock) {
        closeLocked()
        baseDir.mkdirs()
        val events = File(baseDir, "capture_events.jsonl").apply { delete() }
        val clocks = File(baseDir, "clock_sync_history.jsonl").apply { delete() }
        eventFile = events
        clockFile = clocks
        sequence = 0L
        lastClockUpdatedAtNs = null
        eventWriter = events.bufferedWriter().also { writer ->
            writer.write(
                JSONObject()
                    .put("type", "metadata")
                    .put("schema_version", 1)
                    .put("timeline_mode", "ASYNC_PRE_ROLL_POST_ROLL")
                    .put("dual_capture_id", dualCaptureId)
                    .put("role", role)
                    .put("device_id", deviceId)
                    .put("clock_domain", "CLOCK_BOOTTIME")
                    .put("armed_at_elapsed_ns", armedAtElapsedNs)
                    .toString(),
            )
            writer.newLine()
            writer.flush()
        }
        clockWriter = clocks.bufferedWriter().also { writer ->
            writer.write(
                JSONObject()
                    .put("type", "metadata")
                    .put("schema_version", 1)
                    .put("dual_capture_id", dualCaptureId)
                    .put("role", role)
                    .put("clock_domain", "CLOCK_BOOTTIME")
                    .put("model", "PIECEWISE_LINEAR_OFFSET_AND_DRIFT")
                    .toString(),
            )
            writer.newLine()
            writer.flush()
        }
        events to clocks
    }

    fun event(
        name: String,
        localElapsedNs: Long,
        commandId: String? = null,
        commandCreatedMasterNs: Long? = null,
        scheduledLocalNs: Long? = null,
        details: JSONObject? = null,
    ) = synchronized(lock) {
        val writer = eventWriter ?: return
        val line = JSONObject()
            .put("type", "event")
            .put("schema_version", 1)
            .put("sequence", sequence++)
            .put("event", name)
            .put("local_elapsed_ns", localElapsedNs)
            .putNullable("command_id", commandId)
            .putNullable("command_created_master_ns", commandCreatedMasterNs)
            .putNullable("scheduled_local_ns", scheduledLocalNs)
            .putNullable("details", details)
        writer.write(line.toString())
        writer.newLine()
        writer.flush()
    }

    fun clock(snapshot: DualPhoneClockSyncSnapshot) = synchronized(lock) {
        val writer = clockWriter ?: return
        val updatedAt = snapshot.updatedAtElapsedNs ?: return
        if (lastClockUpdatedAtNs == updatedAt) return
        lastClockUpdatedAtNs = updatedAt
        writer.write(
            JSONObject()
                .put("type", "clock_model")
                .put("schema_version", 1)
                .put("updated_at_elapsed_ns", updatedAt)
                .put("recorded_local_elapsed_ns", SystemClock.elapsedRealtimeNanos())
                .putNullable("reference_master_ns", snapshot.referenceMasterNs)
                .put("quality", snapshot.quality.name)
                .put("capture_scheduling_allowed", snapshot.captureSchedulingAllowed)
                .putNullable("offset_ns", snapshot.offsetNs)
                .putNullable("median_rtt_ns", snapshot.medianRttNs)
                .putNullable("p95_rtt_ns", snapshot.p95RttNs)
                .putNullable("uncertainty_ns", snapshot.uncertaintyNs)
                .putNullable("drift_ppm", snapshot.driftPpm)
                .put("accepted_samples", snapshot.acceptedSamples)
                .put("total_samples", snapshot.totalSamples)
                .put("message", snapshot.message)
                .toString(),
        )
        writer.newLine()
        writer.flush()
    }

    fun paths(): Pair<String?, String?> = synchronized(lock) {
        eventFile?.absolutePath to clockFile?.absolutePath
    }

    fun close() = synchronized(lock) {
        closeLocked()
    }

    private fun closeLocked() {
        runCatching { eventWriter?.flush() }
        runCatching { eventWriter?.close() }
        runCatching { clockWriter?.flush() }
        runCatching { clockWriter?.close() }
        eventWriter = null
        clockWriter = null
    }

    private fun JSONObject.putNullable(key: String, value: Any?): JSONObject =
        put(key, value ?: JSONObject.NULL)

}
