package com.maklertour.data.tof

import android.content.Context
import org.json.JSONObject
import java.io.File

/**
 * Persistent LM03.4 calibration artifacts.
 *
 * Layout:
 *   files/tof_camera_calibration/runs/<run>/samples/<pose>.json
 *   files/tof_camera_calibration/runs/<run>/solve_result.json
 *   files/tof_camera_calibration/runs/<run>/validation_samples/<pose>.json
 *   files/tof_camera_calibration/runs/<run>/validation_result.json
 *   files/tof_camera_calibration/profiles/<profile>.json
 *   files/tof_camera_calibration/active_profile.json
 */
class TofCameraCalibrationStore(context: Context) {
    private val root = File(
        context.applicationContext.filesDir,
        "tof_camera_calibration",
    )

    @Synchronized
    fun saveSample(
        calibrationRunId: String,
        poseIndex: Int,
        poseId: String,
        sample: TofCameraPlanarCalibrationSample,
    ): File {
        require(sample.structurallyValid) {
            "Cannot persist an invalid ToF planar calibration sample"
        }
        val runRoot = runRoot(calibrationRunId)
        val samplesDir = File(runRoot, "samples").apply { mkdirs() }
        val destination = File(
            samplesDir,
            "pose_${poseIndex.toString().padStart(2, '0')}_${safePathPart(poseId)}.json",
        )
        writeJsonAtomically(
            destination,
            JSONObject()
                .put("calibration_run_id", calibrationRunId)
                .put("pose_index", poseIndex)
                .put("pose_id", poseId)
                .put("sample", sample.toJson()),
        )
        return destination
    }

    @Synchronized
    fun loadSamples(
        calibrationRunId: String,
    ): List<TofCameraPlanarCalibrationSample> {
        val samplesDir = File(runRoot(calibrationRunId), "samples")
        if (!samplesDir.isDirectory) return emptyList()
        return samplesDir.listFiles()
            .orEmpty()
            .filter { it.isFile && it.extension.equals("json", ignoreCase = true) }
            .sortedBy { it.name }
            .mapNotNull { file ->
                runCatching {
                    JSONObject(file.readText())
                        .optJSONObject("sample")
                        ?.let(TofCameraPlanarCalibrationSample::fromJson)
                }.getOrNull()
            }
    }

    @Synchronized
    fun saveValidationSample(
        validationRunId: String,
        poseIndex: Int,
        poseId: String,
        sample: TofCameraPlanarCalibrationSample,
    ): File {
        require(sample.structurallyValid) {
            "Cannot persist an invalid ToF hold-out sample"
        }
        val samplesDir = File(
            runRoot(validationRunId),
            VALIDATION_SAMPLES_DIR,
        ).apply { mkdirs() }
        val destination = File(
            samplesDir,
            "pose_${poseIndex.toString().padStart(2, '0')}_${safePathPart(poseId)}.json",
        )
        writeJsonAtomically(
            destination,
            JSONObject()
                .put("validation_run_id", validationRunId)
                .put("pose_index", poseIndex)
                .put("pose_id", poseId)
                .put("sample", sample.toJson()),
        )
        return destination
    }

    @Synchronized
    fun loadValidationSamples(
        validationRunId: String,
    ): List<TofCameraPlanarCalibrationSample> {
        val samplesDir = File(runRoot(validationRunId), VALIDATION_SAMPLES_DIR)
        if (!samplesDir.isDirectory) return emptyList()
        return samplesDir.listFiles()
            .orEmpty()
            .filter { it.isFile && it.extension.equals("json", ignoreCase = true) }
            .sortedBy { it.name }
            .mapNotNull { file ->
                runCatching {
                    JSONObject(file.readText())
                        .optJSONObject("sample")
                        ?.let(TofCameraPlanarCalibrationSample::fromJson)
                }.getOrNull()
            }
    }

    @Synchronized
    fun saveValidationResult(
        validationRunId: String,
        result: TofCameraHoldoutValidationResult,
    ): File {
        val destination = File(
            runRoot(validationRunId),
            VALIDATION_RESULT_FILE,
        )
        writeJsonAtomically(
            destination,
            JSONObject()
                .put("validation_run_id", validationRunId)
                .put("result", result.toJson()),
        )
        return destination
    }

    @Synchronized
    fun loadValidationResult(
        validationRunId: String,
    ): TofCameraHoldoutValidationResult? {
        val file = File(runRoot(validationRunId), VALIDATION_RESULT_FILE)
        if (!file.isFile) return null
        return runCatching {
            JSONObject(file.readText())
                .optJSONObject("result")
                ?.let(TofCameraHoldoutValidationResult::fromJson)
        }.getOrNull()
    }

    @Synchronized
    fun saveSolveResult(
        calibrationRunId: String,
        result: TofCameraExtrinsicsSolveResult,
    ): File {
        val destination = File(
            runRoot(calibrationRunId),
            SOLVE_RESULT_FILE,
        )
        writeJsonAtomically(
            destination,
            JSONObject()
                .put("calibration_run_id", calibrationRunId)
                .put("result", result.toJson()),
        )
        return destination
    }

    @Synchronized
    fun loadSolveResult(
        calibrationRunId: String,
    ): TofCameraExtrinsicsSolveResult? {
        val file = File(runRoot(calibrationRunId), SOLVE_RESULT_FILE)
        if (!file.isFile) return null
        return runCatching {
            JSONObject(file.readText())
                .optJSONObject("result")
                ?.let(TofCameraExtrinsicsSolveResult::fromJson)
        }.getOrNull()
    }

    @Synchronized
    fun saveProfile(
        profile: TofCameraExtrinsicsProfile,
    ): File {
        require(profile.solved) {
            "Only solved ToF/CAMERA_A profiles can be activated"
        }
        val profilesDir = File(root, "profiles").apply { mkdirs() }
        val destination = File(
            profilesDir,
            buildString {
                append(safePathPart(profile.rigId))
                append("_")
                append(safePathPart(profile.rigMountRevision))
                append("_")
                append(profile.createdAtEpochMs)
                append(".json")
            },
        )
        writeJsonAtomically(destination, profile.toJson())
        writeJsonAtomically(
            File(root, ACTIVE_PROFILE_FILE),
            profile.toJson(),
        )
        return destination
    }

    @Synchronized
    fun loadActiveProfile(): TofCameraExtrinsicsProfile? {
        val file = File(root, ACTIVE_PROFILE_FILE)
        if (!file.isFile) return null
        return runCatching {
            TofCameraExtrinsicsProfile.fromJson(
                JSONObject(file.readText()),
            )
        }.getOrNull()
    }

    private fun runRoot(calibrationRunId: String): File =
        File(
            root,
            "runs/${safePathPart(calibrationRunId)}",
        ).apply { mkdirs() }

    private fun writeJsonAtomically(
        destination: File,
        json: JSONObject,
    ) {
        destination.parentFile?.mkdirs()
        val temporary = File(
            destination.parentFile,
            "${destination.name}.tmp",
        )
        temporary.writeText(json.toString(2))
        replaceAtomically(temporary, destination)
    }

    private fun replaceAtomically(
        source: File,
        destination: File,
    ) {
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
        private const val SOLVE_RESULT_FILE = "solve_result.json"
        private const val VALIDATION_SAMPLES_DIR = "validation_samples"
        private const val VALIDATION_RESULT_FILE = "validation_result.json"
        private const val ACTIVE_PROFILE_FILE = "active_profile.json"
    }
}
