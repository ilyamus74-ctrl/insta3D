package com.maklertour.data.dualphone

import android.content.Context
import org.json.JSONObject
import java.io.File
import java.security.MessageDigest
import java.time.Instant
import java.util.UUID

enum class DualPhoneCalibrationProfileStatus {
    DRAFT,
    ACCEPTED,
    REJECTED,
}

data class DualPhoneCalibrationCameraIdentity(
    val deviceId: String,
    val physicalCameraId: String,
    val videoModeId: String,
    val width: Int,
    val height: Int,
    val zoomRatio: Double,
    val stabilizationMode: String,
    val orientationContract: String,
) {
    fun canonicalToken(): String = listOf(
        deviceId.trim(),
        physicalCameraId.trim(),
        videoModeId.trim(),
        width.toString(),
        height.toString(),
        canonicalDouble(zoomRatio),
        stabilizationMode.trim().uppercase(),
        orientationContract.trim().uppercase(),
    ).joinToString("|")

    fun toJson(): JSONObject = JSONObject()
        .put("device_id", deviceId)
        .put("physical_camera_id", physicalCameraId)
        .put("video_mode_id", videoModeId)
        .put("width", width)
        .put("height", height)
        .put("zoom_ratio", zoomRatio)
        .put("stabilization_mode", stabilizationMode)
        .put("orientation_contract", orientationContract)

    companion object {
        fun fromJson(json: JSONObject): DualPhoneCalibrationCameraIdentity =
            DualPhoneCalibrationCameraIdentity(
                deviceId = json.getString("device_id"),
                physicalCameraId = json.getString("physical_camera_id"),
                videoModeId = json.getString("video_mode_id"),
                width = json.getInt("width"),
                height = json.getInt("height"),
                zoomRatio = json.getDouble("zoom_ratio"),
                stabilizationMode = json.getString("stabilization_mode"),
                orientationContract = json.getString("orientation_contract"),
            )
    }
}

data class DualPhoneCalibrationIdentity(
    val rigId: String,
    val rigMountRevision: String,
    val operatorLensBaselineMm: Double,
    val cameraA: DualPhoneCalibrationCameraIdentity,
    val cameraB: DualPhoneCalibrationCameraIdentity,
) {
    private fun orderedCameras(): Pair<
        DualPhoneCalibrationCameraIdentity,
        DualPhoneCalibrationCameraIdentity,
        > = listOf(cameraA, cameraB)
        .sortedBy { it.canonicalToken() }
        .let { it[0] to it[1] }

    fun identityHash(): String {
        val (first, second) = orderedCameras()
        val canonical = listOf(
            "DUAL_PHONE_STEREO",
            rigId.trim(),
            rigMountRevision.trim(),
            canonicalDouble(operatorLensBaselineMm),
            first.canonicalToken(),
            second.canonicalToken(),
        ).joinToString("\n")
        return canonical.sha256()
    }

    fun containsDevice(deviceId: String): Boolean =
        cameraA.deviceId == deviceId || cameraB.deviceId == deviceId

    fun toJson(): JSONObject = JSONObject()
        .put("rig_topology", "DUAL_PHONE_STEREO")
        .put("rig_id", rigId)
        .put("rig_mount_revision", rigMountRevision)
        .put("operator_lens_baseline_mm", operatorLensBaselineMm)
        .put("camera_a", cameraA.toJson())
        .put("camera_b", cameraB.toJson())
        .put("calibration_identity_hash", identityHash())

    companion object {
        fun fromJson(json: JSONObject): DualPhoneCalibrationIdentity =
            DualPhoneCalibrationIdentity(
                rigId = json.getString("rig_id"),
                rigMountRevision = json.getString("rig_mount_revision"),
                operatorLensBaselineMm = json.getDouble("operator_lens_baseline_mm"),
                cameraA = DualPhoneCalibrationCameraIdentity.fromJson(
                    json.getJSONObject("camera_a"),
                ),
                cameraB = DualPhoneCalibrationCameraIdentity.fromJson(
                    json.getJSONObject("camera_b"),
                ),
            )
    }
}

data class DualPhoneCalibrationProfile(
    val profileId: String = UUID.randomUUID().toString(),
    val identity: DualPhoneCalibrationIdentity,
    val status: DualPhoneCalibrationProfileStatus =
        DualPhoneCalibrationProfileStatus.DRAFT,
    val calibrationOrderDeviceId0: String,
    val calibrationOrderDeviceId1: String,
    val measuredBaselineMm: Double? = null,
    val resultPath: String? = null,
    val createdAtUtc: String = Instant.now().toString(),
    val acceptedAtUtc: String? = null,
) {
    init {
        require(identity.containsDevice(calibrationOrderDeviceId0)) {
            "Calibration cam0 device is not part of the profile identity"
        }
        require(identity.containsDevice(calibrationOrderDeviceId1)) {
            "Calibration cam1 device is not part of the profile identity"
        }
        require(calibrationOrderDeviceId0 != calibrationOrderDeviceId1) {
            "Calibration camera order must contain two different devices"
        }
    }

    fun reusableFor(requestedIdentity: DualPhoneCalibrationIdentity): Boolean =
        status == DualPhoneCalibrationProfileStatus.ACCEPTED &&
            identity.identityHash() == requestedIdentity.identityHash()

    fun rolesReversed(masterDeviceId: String, slaveDeviceId: String): Boolean =
        calibrationOrderDeviceId0 == slaveDeviceId &&
            calibrationOrderDeviceId1 == masterDeviceId

    fun toJson(): JSONObject = JSONObject()
        .put("schema_version", 1)
        .put("profile_id", profileId)
        .put("status", status.name)
        .put("identity", identity.toJson())
        .put("calibration_order_device_id_0", calibrationOrderDeviceId0)
        .put("calibration_order_device_id_1", calibrationOrderDeviceId1)
        .put("measured_baseline_mm", measuredBaselineMm ?: JSONObject.NULL)
        .put("result_path", resultPath ?: JSONObject.NULL)
        .put("created_at_utc", createdAtUtc)
        .put("accepted_at_utc", acceptedAtUtc ?: JSONObject.NULL)

    companion object {
        fun fromJson(json: JSONObject): DualPhoneCalibrationProfile =
            DualPhoneCalibrationProfile(
                profileId = json.getString("profile_id"),
                identity = DualPhoneCalibrationIdentity.fromJson(
                    json.getJSONObject("identity"),
                ),
                status = runCatching {
                    DualPhoneCalibrationProfileStatus.valueOf(
                        json.getString("status"),
                    )
                }.getOrDefault(DualPhoneCalibrationProfileStatus.DRAFT),
                calibrationOrderDeviceId0 = json.getString(
                    "calibration_order_device_id_0",
                ),
                calibrationOrderDeviceId1 = json.getString(
                    "calibration_order_device_id_1",
                ),
                measuredBaselineMm = json.optNullableDouble("measured_baseline_mm"),
                resultPath = json.optNullableString("result_path"),
                createdAtUtc = json.getString("created_at_utc"),
                acceptedAtUtc = json.optNullableString("accepted_at_utc"),
            )
    }
}

class DualPhoneCalibrationProfileStore(context: Context) {
    private val root = File(
        context.applicationContext.filesDir,
        "dual_phone_calibration_profiles",
    ).apply { mkdirs() }

    fun save(profile: DualPhoneCalibrationProfile): File {
        val output = File(root, "${safeProfileId(profile.profileId)}.json")
        val temporary = File(root, "${output.name}.tmp")
        temporary.writeText(profile.toJson().toString(2) + "\n", Charsets.UTF_8)
        require(temporary.renameTo(output) || temporary.copyTo(output, overwrite = true).let {
            temporary.delete()
            true
        }) {
            "Failed to store calibration profile"
        }
        return output
    }

    fun load(profileId: String): DualPhoneCalibrationProfile? =
        File(root, "${safeProfileId(profileId)}.json")
            .takeIf { it.isFile }
            ?.let { file ->
                runCatching {
                    DualPhoneCalibrationProfile.fromJson(
                        JSONObject(file.readText(Charsets.UTF_8)),
                    )
                }.getOrNull()
            }

    fun list(): List<DualPhoneCalibrationProfile> = root
        .listFiles { file -> file.isFile && file.extension == "json" }
        .orEmpty()
        .mapNotNull { file ->
            runCatching {
                DualPhoneCalibrationProfile.fromJson(
                    JSONObject(file.readText(Charsets.UTF_8)),
                )
            }.getOrNull()
        }
        .sortedByDescending { it.acceptedAtUtc ?: it.createdAtUtc }

    fun findReusable(
        identity: DualPhoneCalibrationIdentity,
    ): DualPhoneCalibrationProfile? = list().firstOrNull {
        it.reusableFor(identity)
    }

    private fun safeProfileId(profileId: String): String {
        require(profileId.matches(Regex("[A-Za-z0-9._-]{8,160}"))) {
            "Unsafe calibration profile ID"
        }
        return profileId
    }
}

private fun JSONObject.optNullableString(key: String): String? =
    if (!has(key) || isNull(key)) null else optString(key).takeIf { it.isNotBlank() }

private fun JSONObject.optNullableDouble(key: String): Double? =
    if (!has(key) || isNull(key)) null else optDouble(key)

private fun canonicalDouble(value: Double): String =
    java.lang.String.format(java.util.Locale.US, "%.6f", value)

private fun String.sha256(): String = MessageDigest
    .getInstance("SHA-256")
    .digest(toByteArray(Charsets.UTF_8))
    .joinToString("") { byte -> "%02x".format(byte) }
