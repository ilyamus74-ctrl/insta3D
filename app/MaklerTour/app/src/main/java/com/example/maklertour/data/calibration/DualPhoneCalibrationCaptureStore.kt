package com.maklertour.data.calibration

import android.content.Context
import com.maklertour.data.dualphone.DualPhoneCalibrationObservation
import com.maklertour.data.dualphone.DualPhoneCalibrationStage
import com.maklertour.data.phonecamera.CalibrationFrame
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.io.FileOutputStream

class DualPhoneCalibrationCaptureStore(context: Context) {
    private val root = File(
        context.applicationContext.filesDir,
        "dual_phone_calibration",
    )

    @Synchronized
    fun saveAcceptedFrame(
        calibrationRunId: String,
        deviceId: String,
        stage: DualPhoneCalibrationStage,
        acceptanceSerial: Long,
        poseIndex: Int,
        poseId: String,
        frame: CalibrationFrame,
        observation: DualPhoneCalibrationObservation?,
    ): File {
        require(stage != DualPhoneCalibrationStage.COMPLETE) {
            "Cannot save a frame for the COMPLETE calibration stage"
        }
        val safeRunId = safePathPart(calibrationRunId)
        val safeDeviceId = safePathPart(deviceId)
        val safePoseId = safePathPart(poseId)
        val roleRoot = File(root, "$safeRunId/$safeDeviceId").apply { mkdirs() }
        val framesRoot = File(roleRoot, "accepted_frames").apply { mkdirs() }
        val framesDir = File(framesRoot, stage.captureDirectory).apply { mkdirs() }
        val output = File(
            framesDir,
            "pose_${poseIndex.toString().padStart(2, '0')}_$safePoseId.jpg",
        )
        val temporary = File(output.parentFile, "${output.name}.tmp")
        FileOutputStream(temporary).use { stream ->
            check(frame.bitmap.compress(android.graphics.Bitmap.CompressFormat.JPEG, 95, stream)) {
                "Failed to encode accepted calibration frame"
            }
            stream.fd.sync()
        }
        replaceAtomically(temporary, output)

        val manifestFile = File(roleRoot, MANIFEST_FILE)
        val manifest = runCatching {
            if (manifestFile.exists()) JSONObject(manifestFile.readText()) else JSONObject()
        }.getOrDefault(JSONObject())
        val entries = manifest.optJSONArray("accepted_frames") ?: JSONArray().also {
            manifest.put("accepted_frames", it)
        }
        for (index in entries.length() - 1 downTo 0) {
            val existing = entries.optJSONObject(index) ?: continue
            if (
                existing.optString("stage") == stage.wireValue &&
                existing.optInt("pose_index", -1) == poseIndex
            ) {
                entries.remove(index)
            }
        }
        val relativeFile =
            "accepted_frames/${stage.captureDirectory}/${output.name}"
        val entry = JSONObject()
            .put("stage", stage.wireValue)
            .put("acceptance_serial", acceptanceSerial)
            .put("pose_index", poseIndex)
            .put("pose_id", poseId)
            .put("file", relativeFile)
            .put("frame_sequence", frame.sequence)
            .put("timestamp_ns", frame.timestampNs)
            .put("raw_width", frame.rawWidth ?: frame.bitmap.width)
            .put("raw_height", frame.rawHeight ?: frame.bitmap.height)
            .put("saved_width", frame.savedWidth)
            .put("saved_height", frame.savedHeight)
            .put("rotation_degrees_applied", frame.rotationDegreesApplied)
            .put("image_proxy_rotation_degrees", frame.imageProxyRotationDegrees)
            .put("display_rotation_at_capture", frame.displayRotationAtCapture ?: JSONObject.NULL)
            .put("app_orientation_at_capture", frame.appOrientationAtCapture ?: JSONObject.NULL)
            .put("quality_observation", observation?.toJson() ?: JSONObject.NULL)
        entries.put(entry)

        manifest
            .put("schema_version", 2)
            .put("calibration_run_id", calibrationRunId)
            .put("device_id", deviceId)
            .put("capture_type", "dual_phone_calibration_samples")
            .put("raw_frames_unrotated", true)
            .put("rotation_degrees_applied", 0)
            .put("accepted_pose_count", entries.length())
            .put(
                "master_intrinsics_count",
                countStage(entries, DualPhoneCalibrationStage.MASTER_INTRINSICS),
            )
            .put(
                "slave_intrinsics_count",
                countStage(entries, DualPhoneCalibrationStage.SLAVE_INTRINSICS),
            )
            .put(
                "stereo_extrinsics_count",
                countStage(entries, DualPhoneCalibrationStage.STEREO_EXTRINSICS),
            )
            .put(
                "tof_extrinsics_count",
                countStage(
                    entries,
                    DualPhoneCalibrationStage.MASTER_TOF_EXTRINSICS,
                ),
            )
        writeJsonAtomically(manifestFile, manifest)
        return output
    }

    private fun countStage(
        entries: JSONArray,
        stage: DualPhoneCalibrationStage,
    ): Int {
        var count = 0
        for (index in 0 until entries.length()) {
            if (entries.optJSONObject(index)?.optString("stage") == stage.wireValue) {
                count += 1
            }
        }
        return count
    }

    private fun writeJsonAtomically(destination: File, json: JSONObject) {
        destination.parentFile?.mkdirs()
        val temporary = File(destination.parentFile, "${destination.name}.tmp")
        temporary.writeText(json.toString(2))
        replaceAtomically(temporary, destination)
    }

    private fun replaceAtomically(source: File, destination: File) {
        if (destination.exists() && !destination.delete()) {
            source.delete()
            error("Cannot replace ${destination.absolutePath}")
        }
        if (!source.renameTo(destination)) {
            source.copyTo(destination, overwrite = true)
            source.delete()
        }
    }

    private fun safePathPart(value: String): String =
        value.trim()
            .replace(Regex("[^A-Za-z0-9._-]+"), "_")
            .trim('_')
            .ifBlank { "unknown" }
            .take(120)

    companion object {
        const val MANIFEST_FILE = "capture_manifest.json"
    }
}
