package com.maklertour.data.phonecamera

import android.content.Context
import android.os.SystemClock
import com.maklertour.data.dualphone.DualPhoneStereoSettingsStore
import com.maklertour.data.tof.TofActiveClockSync
import com.maklertour.data.tof.TofCameraCalibrationStore
import com.maklertour.data.tof.TofFrameV1
import com.maklertour.data.tof.TofUsbRuntime
import java.io.BufferedWriter
import java.io.File
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.cancelAndJoin
import kotlinx.coroutines.flow.filterNotNull
import kotlinx.coroutines.launch
import org.json.JSONArray
import org.json.JSONObject

data class TofCaptureSidecarSummary(
    val path: String,
    val frameCount: Long,
    val firstSequence: Long?,
    val lastSequence: Long?,
)

class TofCaptureSidecarRecorder(context: Context) {
    private val appContext = context.applicationContext
    private val runtime = TofUsbRuntime.get(appContext)
    private val lock = Any()

    private var scope: CoroutineScope? = null
    private var collectJob: Job? = null
    private var writer: BufferedWriter? = null
    private var outputFile: File? = null
    private var captureStartElapsedNs = 0L
    private var frameCount = 0L
    private var firstSequence: Long? = null
    private var lastSequence: Long? = null
    private var lastFrameKey: String? = null

    fun start(baseDir: File): File {
        check(collectJob == null) { "ToF capture sidecar recorder is already active" }
        baseDir.mkdirs()
        val file = File(baseDir, "tof_frames.jsonl")
        file.delete()

        captureStartElapsedNs = SystemClock.elapsedRealtimeNanos()
        frameCount = 0L
        firstSequence = null
        lastSequence = null
        lastFrameKey = null
        outputFile = file

        writer = file.bufferedWriter().also { active ->
            active.write(
                JSONObject()
                    .put("type", "metadata")
                    .put("schema_version", 1)
                    .put("source", "VL53L8CX_RP2040_USB")
                    .put("capture_scope", "PHONE_VIDEO")
                    .put("capture_start_elapsed_realtime_ns", captureStartElapsedNs)
                    .put(
                        "event_time_mapping",
                        "RP2040_IRQ_TO_ANDROID_ELAPSED_REALTIME_ACTIVE_SYNC",
                    )
                    .put(
                        "distance_contract",
                        "AXIAL_PERPENDICULAR_Z_MM",
                    )
                    .toString(),
            )
            active.newLine()
            active.flush()
        }

        val newScope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
        scope = newScope
        collectJob = newScope.launch {
            runtime.latestFrame
                .filterNotNull()
                .collect { frame ->
                    if (frame.hostReceivedElapsedRealtimeNs < captureStartElapsedNs) {
                        return@collect
                    }
                    recordFrame(frame)
                }
        }
        return file
    }

    suspend fun stop(): TofCaptureSidecarSummary? {
        val job = collectJob
        collectJob = null
        job?.cancelAndJoin()
        scope?.cancel()
        scope = null

        var file: File? = null
        var count = 0L
        var first: Long? = null
        var last: Long? = null
        synchronized(lock) {
            writer?.flush()
            writer?.close()
            writer = null
            file = outputFile
            outputFile = null
            count = frameCount
            first = firstSequence
            last = lastSequence
        }

        val completedFile = file ?: return null
        if (count <= 0L) {
            completedFile.delete()
            return null
        }
        return TofCaptureSidecarSummary(
            path = completedFile.absolutePath,
            frameCount = count,
            firstSequence = first,
            lastSequence = last,
        )
    }

    private fun recordFrame(frame: TofFrameV1) {
        val key = "${frame.slot}:${frame.sequence}"
        synchronized(lock) {
            val active = writer ?: return
            if (lastFrameKey == key) return
            lastFrameKey = key

            val mappedElapsedNs =
                if (frame.irqTimestampValid) {
                    TofActiveClockSync.mapRp2040TimestampUsToHostElapsedNs(
                        frame.rp2040TimestampUs,
                    )
                } else {
                    null
                }

            active.write(
                JSONObject()
                    .put("type", "tof_frame")
                    .put("schema_version", 1)
                    .put("protocol_version", frame.protocolVersion)
                    .put("slot", frame.slot)
                    .put("width", frame.width)
                    .put("height", frame.height)
                    .put("frequency_hz", frame.frequencyHz)
                    .put("silicon_temperature_c", frame.siliconTemperatureC)
                    .put("sequence", frame.sequence)
                    .put("rp2040_timestamp_us", frame.rp2040TimestampUs)
                    .put("irq_timestamp_valid", frame.irqTimestampValid)
                    .put(
                        "mapped_elapsed_realtime_ns",
                        mappedElapsedNs ?: JSONObject.NULL,
                    )
                    .put(
                        "host_received_elapsed_realtime_ns",
                        frame.hostReceivedElapsedRealtimeNs,
                    )
                    .put("distance_mm", JSONArray(frame.distanceMm.toList()))
                    .put(
                        "sigma_mm",
                        JSONArray(frame.rangeSigmaMm.toList()),
                    )
                    .put(
                        "target_status",
                        JSONArray(frame.targetStatus.toList()),
                    )
                    .put(
                        "nb_target_detected",
                        JSONArray(frame.nbTargetDetected.toList()),
                    )
                    .toString(),
            )
            active.newLine()
            frameCount += 1L
            if (firstSequence == null) firstSequence = frame.sequence
            lastSequence = frame.sequence
            if (frameCount % 15L == 0L) active.flush()
        }
    }
}

fun writeActiveTofCalibrationSnapshot(
    context: Context,
    baseDir: File,
    selectedCameraId: String? = null,
): File? {
    val profiles = TofCameraCalibrationStore(context).loadActiveProfiles()
        .filter { it.solved }
    if (profiles.isEmpty()) return null

    val settings = DualPhoneStereoSettingsStore(context).load()
    val effectiveCameraId = selectedCameraId
        ?.trim()
        ?.takeIf { it.isNotEmpty() }
        ?: profiles
            .map { it.masterCameraId }
            .distinct()
            .singleOrNull()

    val captureIdentity = JSONObject()
        .put("device_id", settings.deviceId)
        .put("rig_id", settings.rigId)
        .put("rig_mount_revision", settings.rigMountRevision)
        .put(
            "selected_camera_id",
            effectiveCameraId ?: JSONObject.NULL,
        )
        .put(
            "active_calibration_profile_id",
            settings.activeCalibrationProfileId ?: JSONObject.NULL,
        )

    val file = File(baseDir, "tof_calibration.json")
    val jsonProfiles = JSONArray()
    profiles.forEach { profile -> jsonProfiles.put(profile.toJson()) }
    file.writeText(
        JSONObject()
            .put("schema_version", 2)
            .put("snapshot_type", "FROZEN_TOF_TO_CAMERA_EXTRINSICS")
            .put("captured_at_epoch_ms", System.currentTimeMillis())
            .put("capture_identity", captureIdentity)
            .put("profile_count", profiles.size)
            .put("profiles", jsonProfiles)
            .toString(2),
    )
    return file
}
