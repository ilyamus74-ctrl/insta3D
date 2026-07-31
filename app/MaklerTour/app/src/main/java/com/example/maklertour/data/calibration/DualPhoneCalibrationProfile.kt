package com.maklertour.data.calibration

import org.json.JSONArray
import org.json.JSONObject
import java.util.Locale
import kotlin.math.abs

data class DualPhoneStereoEstimate(
    val solved: Boolean,
    val pairsUsed: Int,
    val rms: Double? = null,
    val rotation: List<Double> = emptyList(),
    val translationMm: List<Double> = emptyList(),
    val baselineMm: Double? = null,
    val operatorBaselineMm: Double? = null,
    val baselineDeltaMm: Double? = null,
    val status: String,
) {
    val acceptable: Boolean
        get() = solved &&
            rms != null &&
            rms.isFinite() &&
            rms <= MAX_STEREO_RMS_PX &&
            rotation.size == 9 &&
            translationMm.size == 3 &&
            baselineMm != null &&
            baselineMm.isFinite() &&
            baselineMm > 0.0

    fun summary(): String = if (solved && rms != null && baselineMm != null) {
        buildString {
            append("STEREO RMS ")
            append(String.format(Locale.US, "%.3f", rms))
            append(" px · базис ")
            append(String.format(Locale.US, "%.1f", baselineMm))
            append(" мм")
            baselineDeltaMm?.let {
                append(" · Δ ")
                append(String.format(Locale.US, "%+.1f", it))
                append(" мм")
            }
        }
    } else {
        status
    }

    fun toJson(): JSONObject = JSONObject()
        .put("solved", solved)
        .put("pairs_used", pairsUsed)
        .put("rms", rms ?: JSONObject.NULL)
        .put("rotation", rotation.toJsonArray())
        .put("translation_mm", translationMm.toJsonArray())
        .put("baseline_mm", baselineMm ?: JSONObject.NULL)
        .put("operator_baseline_mm", operatorBaselineMm ?: JSONObject.NULL)
        .put("baseline_delta_mm", baselineDeltaMm ?: JSONObject.NULL)
        .put("status", status)

    companion object {
        const val MAX_STEREO_RMS_PX = 2.0

        fun fromJson(json: JSONObject): DualPhoneStereoEstimate =
            DualPhoneStereoEstimate(
                solved = json.optBoolean("solved", false),
                pairsUsed = json.optInt("pairs_used", 0),
                rms = json.optNullableDouble("rms"),
                rotation = json.optJSONArray("rotation").toDoubleList(),
                translationMm = json.optJSONArray("translation_mm").toDoubleList(),
                baselineMm = json.optNullableDouble("baseline_mm"),
                operatorBaselineMm = json.optNullableDouble("operator_baseline_mm"),
                baselineDeltaMm = json.optNullableDouble("baseline_delta_mm"),
                status = json.optString("status", "Stereo result unavailable"),
            )
    }
}

data class DualPhoneCalibrationProfileResult(
    val profileId: String,
    val calibrationRunId: String,
    val rigId: String,
    val rigMountRevision: String,
    val masterDeviceId: String,
    val slaveDeviceId: String,
    val masterCameraId: String?,
    val slaveCameraId: String?,
    val masterIntrinsics: DualPhoneLiveIntrinsicsEstimate,
    val slaveIntrinsics: DualPhoneLiveIntrinsicsEstimate,
    val stereo: DualPhoneStereoEstimate,
    val createdAtEpochMs: Long,
    val status: String,
    val error: String? = null,
) {
    val successful: Boolean
        get() = status == STATUS_SUCCESS &&
            masterIntrinsics.acceptable &&
            slaveIntrinsics.acceptable &&
            stereo.acceptable

    fun toJson(): JSONObject = JSONObject()
        .put("schema_version", 1)
        .put("profile_id", profileId)
        .put("calibration_run_id", calibrationRunId)
        .put("rig_id", rigId)
        .put("rig_mount_revision", rigMountRevision)
        .put("master_device_id", masterDeviceId)
        .put("slave_device_id", slaveDeviceId)
        .put("master_camera_id", masterCameraId ?: JSONObject.NULL)
        .put("slave_camera_id", slaveCameraId ?: JSONObject.NULL)
        .put("master_intrinsics", masterIntrinsics.toJson())
        .put("slave_intrinsics", slaveIntrinsics.toJson())
        .put("stereo", stereo.toJson())
        .put("created_at_epoch_ms", createdAtEpochMs)
        .put("status", status)
        .put("error", error ?: JSONObject.NULL)

    companion object {
        const val STATUS_SUCCESS = "success"
        const val STATUS_FAILED = "failed"

        fun build(
            calibrationRunId: String,
            rigId: String,
            rigMountRevision: String,
            masterDeviceId: String,
            slaveDeviceId: String,
            masterCameraId: String?,
            slaveCameraId: String?,
            masterIntrinsics: DualPhoneLiveIntrinsicsEstimate,
            slaveIntrinsics: DualPhoneLiveIntrinsicsEstimate,
            stereo: DualPhoneStereoEstimate,
        ): DualPhoneCalibrationProfileResult {
            val errors = buildList {
                if (!masterIntrinsics.acceptable) {
                    add("MASTER intrinsics are not acceptable")
                }
                if (!slaveIntrinsics.acceptable) {
                    add("SLAVE intrinsics are not acceptable")
                }
                if (!stereo.acceptable) {
                    add(stereo.status)
                }
            }
            return DualPhoneCalibrationProfileResult(
                profileId = "dual-${calibrationRunId.removePrefix("cal-")}",
                calibrationRunId = calibrationRunId,
                rigId = rigId,
                rigMountRevision = rigMountRevision,
                masterDeviceId = masterDeviceId,
                slaveDeviceId = slaveDeviceId,
                masterCameraId = masterCameraId,
                slaveCameraId = slaveCameraId,
                masterIntrinsics = masterIntrinsics,
                slaveIntrinsics = slaveIntrinsics,
                stereo = stereo,
                createdAtEpochMs = System.currentTimeMillis(),
                status = if (errors.isEmpty()) STATUS_SUCCESS else STATUS_FAILED,
                error = errors.takeIf { it.isNotEmpty() }?.joinToString("; "),
            )
        }

        fun fromJson(json: JSONObject): DualPhoneCalibrationProfileResult? {
            val profileId = json.optString("profile_id").trim()
            val runId = json.optString("calibration_run_id").trim()
            if (profileId.isBlank() || runId.isBlank()) return null
            val master = json.optJSONObject("master_intrinsics") ?: return null
            val slave = json.optJSONObject("slave_intrinsics") ?: return null
            val stereo = json.optJSONObject("stereo") ?: return null
            return DualPhoneCalibrationProfileResult(
                profileId = profileId,
                calibrationRunId = runId,
                rigId = json.optString("rig_id"),
                rigMountRevision = json.optString("rig_mount_revision"),
                masterDeviceId = json.optString("master_device_id"),
                slaveDeviceId = json.optString("slave_device_id"),
                masterCameraId = json.optNullableString("master_camera_id"),
                slaveCameraId = json.optNullableString("slave_camera_id"),
                masterIntrinsics = DualPhoneLiveIntrinsicsEstimate.fromJson(master),
                slaveIntrinsics = DualPhoneLiveIntrinsicsEstimate.fromJson(slave),
                stereo = DualPhoneStereoEstimate.fromJson(stereo),
                createdAtEpochMs = json.optLong("created_at_epoch_ms", 0L),
                status = json.optString("status", STATUS_FAILED),
                error = json.optNullableString("error"),
            )
        }
    }
}

private fun List<Double>.toJsonArray(): JSONArray =
    JSONArray().also { array -> forEach(array::put) }

private fun JSONArray?.toDoubleList(): List<Double> {
    if (this == null) return emptyList()
    return buildList {
        for (index in 0 until length()) {
            val value = optDouble(index, Double.NaN)
            if (value.isFinite()) add(value)
        }
    }
}

private fun JSONObject.optNullableDouble(name: String): Double? =
    if (!has(name) || isNull(name)) null else optDouble(name).takeIf { it.isFinite() }

private fun JSONObject.optNullableString(name: String): String? =
    if (!has(name) || isNull(name)) null else optString(name).takeIf { it.isNotBlank() }
