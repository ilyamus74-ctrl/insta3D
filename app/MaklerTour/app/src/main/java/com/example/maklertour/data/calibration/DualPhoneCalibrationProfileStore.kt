package com.maklertour.data.calibration

import android.content.Context
import com.maklertour.data.dualphone.DualPhoneStereoSettingsStore
import com.maklertour.data.rig.CalibrationStatus
import com.maklertour.data.rig.StereoRigProfileStore
import com.maklertour.data.rig.StereoRigTopology
import org.json.JSONObject
import java.io.File

class DualPhoneCalibrationProfileStore(context: Context) {
    private val appContext = context.applicationContext
    private val profilesRoot = File(
        appContext.filesDir,
        "dual_phone_calibration_profiles",
    ).apply { mkdirs() }

    @Synchronized
    fun save(result: DualPhoneCalibrationProfileResult): File {
        val output = File(
            profilesRoot,
            "${safePathPart(result.profileId)}.json",
        )
        writeJsonAtomically(output, result.toJson())

        if (result.successful) {
            val settingsStore = DualPhoneStereoSettingsStore(appContext)
            val settings = settingsStore.load()
            settingsStore.save(
                settings.copy(activeCalibrationProfileId = result.profileId),
            )

            val rigStore = StereoRigProfileStore(appContext)
            val current = rigStore.loadActiveProfile()
            val runPath = File(
                appContext.filesDir,
                "dual_phone_calibration/${safePathPart(result.calibrationRunId)}",
            )
            rigStore.saveActiveProfile(
                current.copy(
                    rigId = result.rigId,
                    baselineMm = result.stereo.baselineMm,
                    calibrationStatus = CalibrationStatus.CALIBRATED,
                    lastCalibrationSessionPath = runPath.absolutePath,
                    calibrationResultPath = output.absolutePath,
                    calibrationResult = result.toJson(),
                    topology = StereoRigTopology.DUAL_PHONE,
                    cam0DeviceId = result.masterDeviceId,
                    cam1DeviceId = result.slaveDeviceId,
                    cam0CameraId = result.masterCameraId,
                    cam1CameraId = result.slaveCameraId,
                ),
            )
        }
        return output
    }

    fun load(profileId: String): DualPhoneCalibrationProfileResult? {
        val file = File(profilesRoot, "${safePathPart(profileId)}.json")
        if (!file.isFile) return null
        return runCatching {
            DualPhoneCalibrationProfileResult.fromJson(
                JSONObject(file.readText()),
            )
        }.getOrNull()
    }

    private fun writeJsonAtomically(destination: File, json: JSONObject) {
        destination.parentFile?.mkdirs()
        val temporary = File(destination.parentFile, "${destination.name}.tmp")
        temporary.writeText(json.toString(2))
        if (destination.exists() && !destination.delete()) {
            temporary.delete()
            error("Cannot replace ${destination.absolutePath}")
        }
        if (!temporary.renameTo(destination)) {
            temporary.copyTo(destination, overwrite = true)
            temporary.delete()
        }
    }

    private fun safePathPart(value: String): String =
        value.trim()
            .replace(Regex("[^A-Za-z0-9._-]+"), "_")
            .trim('_')
            .ifBlank { "unknown" }
            .take(120)
}
