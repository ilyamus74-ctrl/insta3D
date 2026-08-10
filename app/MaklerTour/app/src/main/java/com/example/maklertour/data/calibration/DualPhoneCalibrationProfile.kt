package com.maklertour.data.calibration

import org.json.JSONArray
import org.json.JSONObject
import java.util.Locale
import kotlin.math.abs

data class DualPhoneStereoEstimate(
    val solved: Boolean,
    val pairsUsed: Int,
    val rms: Double? = null,
    val imageWidth: Int = 1280,
    val imageHeight: Int = 720,
    val rotation: List<Double> = emptyList(),
    val translationMm: List<Double> = emptyList(),
    val baselineMm: Double? = null,
    val operatorBaselineMm: Double? = null,
    val baselineDeltaMm: Double? = null,
    val pairsRejected: Int = 0,
    val meanEpipolarErrorPx: Double? = null,
    val maxFrameDeltaMs: Double? = null,
    val coveragePercent: Int = 0,
    val status: String,
) {
    val normalizedRmsPx: Double?
        get() = rms?.let { normalizePixelError(it, imageWidth) }

    val normalizedMeanEpipolarErrorPx: Double?
        get() = meanEpipolarErrorPx?.let { normalizePixelError(it, imageWidth) }

    val acceptable: Boolean
        get() {
            val normalizedRms = normalizedRmsPx
            val normalizedEpi = normalizedMeanEpipolarErrorPx
            return solved &&
                normalizedRms != null &&
                normalizedRms.isFinite() &&
                normalizedRms <= MAX_STEREO_RMS_PX &&
                rotation.size == 9 &&
                translationMm.size == 3 &&
                baselineMm != null &&
                baselineMm.isFinite() &&
                baselineMm > 0.0 &&
                (normalizedEpi == null ||
                    normalizedEpi <= MAX_MEAN_EPIPOLAR_ERROR_PX)
        }

    fun rejectionMetricRu(): String? {
        val normalizedRms = normalizedRmsPx
        val normalizedEpi = normalizedMeanEpipolarErrorPx
        return when {
            !solved -> status
            normalizedRms != null && normalizedRms > MAX_STEREO_RMS_PX ->
                "RMS quality-equivalent ${String.format(Locale.US, "%.3f", normalizedRms)} px > ${String.format(Locale.US, "%.2f", MAX_STEREO_RMS_PX)} px"
            normalizedEpi != null && normalizedEpi > MAX_MEAN_EPIPOLAR_ERROR_PX ->
                "EPI quality-equivalent ${String.format(Locale.US, "%.2f", normalizedEpi)} px > ${String.format(Locale.US, "%.2f", MAX_MEAN_EPIPOLAR_ERROR_PX)} px"
            else -> null
        }
    }

    fun geometryAuditHintRu(): String? {
        val normalizedRms = normalizedRmsPx ?: return null
        val normalizedEpi = normalizedMeanEpipolarErrorPx ?: return null
        if (normalizedEpi <= MAX_MEAN_EPIPOLAR_ERROR_PX) return null
        val expectedBaseline = operatorBaselineMm
        val baselineClose = if (expectedBaseline != null && baselineDeltaMm != null) {
            abs(baselineDeltaMm) <= maxOf(15.0, expectedBaseline * 0.12)
        } else {
            false
        }
        val maxDelta = maxFrameDeltaMs
        return when {
            maxDelta != null && maxDelta > 30.0 ->
                "SYNC_SUSPECT: EPI высокая, max frame Δ=${String.format(Locale.US, "%.1f", maxDelta)} ms; сначала проверить временную синхронизацию stereo-пар."
            normalizedRms <= MAX_STEREO_RMS_PX && baselineClose ->
                "SYSTEMATIC_EPI: RMS и базис согласованы, но rectified EPI остаётся высокой; искать постоянную несогласованность stereo-пар/оптики/rectification, а не повышать порог."
            normalizedRms <= MAX_STEREO_RMS_PX ->
                "EPI_ONLY_FAILURE: общий stereo RMS проходит, но rectified соответствия не проходят EPI-критерий."
            else ->
                "STEREO_GEOMETRY_UNSTABLE: одновременно повышены RMS и EPI."
        }
    }

    fun summary(): String = if (solved && rms != null && baselineMm != null) {
        buildString {
            append("STEREO ${imageWidth}×${imageHeight}")
            append("\nRAW @${imageWidth}×${imageHeight}: RMS ")
            append(String.format(Locale.US, "%.3f", rms))
            append(" px")
            meanEpipolarErrorPx?.let {
                append(" · EPI mean ")
                append(String.format(Locale.US, "%.2f", it))
                append(" px")
            }
            append("\nQUALITY EQUIV @1280 (только шкала): RMS ")
            append(String.format(Locale.US, "%.3f", normalizedRmsPx ?: Double.NaN))
            append(" px")
            normalizedMeanEpipolarErrorPx?.let {
                append(" · EPI mean ")
                append(String.format(Locale.US, "%.2f", it))
                append(" px")
            }
            append("\nБазис ")
            append(String.format(Locale.US, "%.1f", baselineMm))
            append(" мм")
            baselineDeltaMm?.let {
                append(" · Δ ")
                append(String.format(Locale.US, "%+.1f", it))
                append(" мм")
            }
            if (pairsRejected > 0) append(" · отброшено пар: $pairsRejected")
            maxFrameDeltaMs?.let {
                append(" · max frame Δ ")
                append(String.format(Locale.US, "%.1f", it))
                append(" ms")
            }
            rejectionMetricRu()?.let {
                append("\nПричина отказа: ")
                append(it)
            }
            geometryAuditHintRu()?.let {
                append("\nAUDIT: ")
                append(it)
            }
        }
    } else {
        status
    }

    fun toJson(): JSONObject = JSONObject()
        .put("solved", solved)
        .put("pairs_used", pairsUsed)
        .put("rms", rms ?: JSONObject.NULL)
        .put("image_width", imageWidth)
        .put("image_height", imageHeight)
        .put("error_reference_width_px", ERROR_REFERENCE_WIDTH_PX)
        .put("normalized_rms_px", normalizedRmsPx ?: JSONObject.NULL)
        .put("normalized_mean_epipolar_error_px", normalizedMeanEpipolarErrorPx ?: JSONObject.NULL)
        .put("rotation", rotation.toJsonArray())
        .put("translation_mm", translationMm.toJsonArray())
        .put("baseline_mm", baselineMm ?: JSONObject.NULL)
        .put("operator_baseline_mm", operatorBaselineMm ?: JSONObject.NULL)
        .put("baseline_delta_mm", baselineDeltaMm ?: JSONObject.NULL)
        .put("pairs_rejected", pairsRejected)
        .put("mean_epipolar_error_px", meanEpipolarErrorPx ?: JSONObject.NULL)
        .put("max_frame_delta_ms", maxFrameDeltaMs ?: JSONObject.NULL)
        .put("coverage_percent", coveragePercent)
        .put("status", status)

    companion object {
        const val ERROR_REFERENCE_WIDTH_PX = 1280.0
        const val MAX_STEREO_RMS_PX = 2.0
        const val RECOMMENDED_MEAN_EPIPOLAR_ERROR_PX = 1.5
        const val MAX_MEAN_EPIPOLAR_ERROR_PX = 1.75

        fun normalizePixelError(valuePx: Double, imageWidth: Int): Double {
            if (!valuePx.isFinite() || imageWidth <= 0) return valuePx
            return valuePx * ERROR_REFERENCE_WIDTH_PX / imageWidth.toDouble()
        }

        fun fromJson(json: JSONObject): DualPhoneStereoEstimate =
            DualPhoneStereoEstimate(
                solved = json.optBoolean("solved", false),
                pairsUsed = json.optInt("pairs_used", 0),
                rms = json.optNullableDouble("rms"),
                imageWidth = json.optInt("image_width", ERROR_REFERENCE_WIDTH_PX.toInt()).coerceAtLeast(1),
                imageHeight = json.optInt("image_height", 720).coerceAtLeast(1),
                rotation = json.optJSONArray("rotation").toDoubleList(),
                translationMm = json.optJSONArray("translation_mm").toDoubleList(),
                baselineMm = json.optNullableDouble("baseline_mm"),
                operatorBaselineMm = json.optNullableDouble("operator_baseline_mm"),
                baselineDeltaMm = json.optNullableDouble("baseline_delta_mm"),
                pairsRejected = json.optInt("pairs_rejected", 0),
                meanEpipolarErrorPx =
                    json.optNullableDouble("mean_epipolar_error_px"),
                maxFrameDeltaMs = json.optNullableDouble("max_frame_delta_ms"),
                coveragePercent = json.optInt("coverage_percent", 0),
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
