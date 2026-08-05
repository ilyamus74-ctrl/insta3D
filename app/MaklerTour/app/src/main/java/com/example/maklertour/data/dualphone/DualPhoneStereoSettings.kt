package com.maklertour.data.dualphone

import android.content.Context
import org.json.JSONObject
import java.util.UUID

enum class DualPhoneRole {
    STANDALONE,
    MASTER,
    SLAVE,
}

enum class DualPhoneTransport {
    WIFI_LAN,
}

data class DualPhoneStereoSettings(
    val deviceId: String,
    val role: DualPhoneRole = DualPhoneRole.STANDALONE,
    val applicationMode: ApplicationCaptureMode =
        ApplicationCaptureMode.migrateFromLegacyRole(role),
    val transport: DualPhoneTransport = DualPhoneTransport.WIFI_LAN,
    val peerDeviceId: String? = null,
    val masterHost: String? = null,
    val masterControlsUpload: Boolean = true,
    val controlPort: Int = 48621,
    val clockSyncPort: Int = 48622,
    val bundleTransferPort: Int = 48623,
    val preferredVideoModeId: String? = null,
    val rigId: String = "dual-phone-rig-001",
    val rigMountRevision: String = "rev-a",
    val operatorLensBaselineMm: Double? = null,
    val activeCalibrationProfileId: String? = null,
    val calibrationBoard: DualPhoneCalibrationBoardSettings =
        DualPhoneCalibrationBoardSettings(),
) {
    fun withApplicationMode(
        mode: ApplicationCaptureMode,
    ): DualPhoneStereoSettings = copy(
        applicationMode = mode,
        role = mode.compatibilityRole,
    )

    fun toJson(): JSONObject = JSONObject()
        .put("schema_version", 6)
        .put("device_id", deviceId)
        .put("role", role.name)
        .put("application_mode", applicationMode.name)
        .put("transport", transport.name)
        .put("peer_device_id", peerDeviceId ?: JSONObject.NULL)
        .put("master_host", masterHost ?: JSONObject.NULL)
        .put("master_controls_upload", true)
        .put("control_port", controlPort)
        .put("clock_sync_port", clockSyncPort)
        .put("bundle_transfer_port", bundleTransferPort)
        .put("preferred_video_mode_id", preferredVideoModeId ?: JSONObject.NULL)
        .put("rig_id", rigId)
        .put("rig_mount_revision", rigMountRevision)
        .put(
            "operator_lens_baseline_mm",
            operatorLensBaselineMm ?: JSONObject.NULL,
        )
        .put("active_calibration_profile_id", activeCalibrationProfileId ?: JSONObject.NULL)
        .put("calibration_board", calibrationBoard.toJson())
}

class DualPhoneStereoSettingsStore(context: Context) {
    private val prefs = context.applicationContext.getSharedPreferences(
        PREFS,
        Context.MODE_PRIVATE,
    )

    fun load(): DualPhoneStereoSettings {
        val deviceId = prefs.getString(KEY_DEVICE_ID, null)
            ?.takeIf { it.isNotBlank() }
            ?: UUID.randomUUID().toString().also {
                prefs.edit().putString(KEY_DEVICE_ID, it).apply()
            }

        val storedRole = enumValueOrDefault(
            prefs.getString(KEY_ROLE, null),
            DualPhoneRole.STANDALONE,
        )
        val rawApplicationMode = prefs.getString(
            KEY_APPLICATION_MODE,
            null,
        )
        val applicationMode = rawApplicationMode
            ?.let { raw ->
                runCatching {
                    enumValueOf<ApplicationCaptureMode>(raw)
                }.getOrNull()
            }
            ?: ApplicationCaptureMode.migrateFromLegacyRole(storedRole)
        val compatibilityRole = applicationMode.compatibilityRole
        if (
            rawApplicationMode != applicationMode.name ||
            prefs.getString(KEY_ROLE, null) != compatibilityRole.name
        ) {
            prefs.edit()
                .putString(KEY_APPLICATION_MODE, applicationMode.name)
                .putString(KEY_ROLE, compatibilityRole.name)
                .apply()
        }

        return DualPhoneStereoSettings(
            deviceId = deviceId,
            role = compatibilityRole,
            applicationMode = applicationMode,
            transport = enumValueOrDefault(
                prefs.getString(KEY_TRANSPORT, null),
                DualPhoneTransport.WIFI_LAN,
            ),
            peerDeviceId = prefs.getString(KEY_PEER_DEVICE_ID, null)
                ?.takeIf { it.isNotBlank() },
            masterHost = prefs.getString(KEY_MASTER_HOST, null)
                ?.takeIf { it.isNotBlank() },
            masterControlsUpload = true,
            controlPort = prefs.getInt(KEY_CONTROL_PORT, 48621)
                .coerceIn(1024, 65535),
            clockSyncPort = prefs.getInt(KEY_CLOCK_SYNC_PORT, 48622)
                .coerceIn(1024, 65535),
            bundleTransferPort = prefs.getInt(KEY_BUNDLE_TRANSFER_PORT, 48623)
                .coerceIn(1024, 65535),
            preferredVideoModeId = prefs.getString(
                KEY_PREFERRED_VIDEO_MODE_ID,
                null,
            )?.takeIf { it.isNotBlank() },
            rigId = prefs.getString(KEY_RIG_ID, null)
                ?.trim()
                ?.takeIf { it.isNotBlank() }
                ?: "dual-phone-rig-001",
            rigMountRevision = prefs.getString(KEY_RIG_MOUNT_REVISION, null)
                ?.trim()
                ?.takeIf { it.isNotBlank() }
                ?: "rev-a",
            operatorLensBaselineMm = prefs.getString(KEY_OPERATOR_LENS_BASELINE_MM, null)
                ?.toDoubleOrNull()
                ?.takeIf { it in 1.0..1_000.0 },
            activeCalibrationProfileId = prefs.getString(KEY_ACTIVE_CALIBRATION_PROFILE_ID, null)
                ?.takeIf { it.isNotBlank() },
            calibrationBoard = prefs.getString(KEY_CALIBRATION_BOARD_JSON, null)
                ?.let { raw ->
                    runCatching {
                        DualPhoneCalibrationBoardSettings.fromJson(JSONObject(raw))
                    }.getOrNull()
                }
                ?.takeIf { it.validationError() == null }
                ?: DualPhoneCalibrationBoardSettings(),
        )
    }

    fun save(settings: DualPhoneStereoSettings) {
        // Old callers still mutate only role. Preserve them until the role UI
        // is removed by deriving a matching top-level mode on mismatch.
        val applicationMode = if (
            settings.role == settings.applicationMode.compatibilityRole
        ) {
            settings.applicationMode
        } else {
            ApplicationCaptureMode.migrateFromLegacyRole(settings.role)
        }
        val compatibilityRole = applicationMode.compatibilityRole
        prefs.edit()
            .putString(KEY_DEVICE_ID, settings.deviceId)
            .putString(KEY_ROLE, compatibilityRole.name)
            .putString(KEY_APPLICATION_MODE, applicationMode.name)
            .putString(KEY_TRANSPORT, DualPhoneTransport.WIFI_LAN.name)
            .putString(KEY_PEER_DEVICE_ID, settings.peerDeviceId)
            .putString(KEY_MASTER_HOST, settings.masterHost)
            .putBoolean(KEY_MASTER_CONTROLS_UPLOAD, true)
            .putInt(KEY_CONTROL_PORT, settings.controlPort)
            .putInt(KEY_CLOCK_SYNC_PORT, settings.clockSyncPort)
            .putInt(KEY_BUNDLE_TRANSFER_PORT, settings.bundleTransferPort)
            .putString(
                KEY_PREFERRED_VIDEO_MODE_ID,
                settings.preferredVideoModeId,
            )
            .putString(KEY_RIG_ID, settings.rigId.trim())
            .putString(KEY_RIG_MOUNT_REVISION, settings.rigMountRevision.trim())
            .putString(
                KEY_OPERATOR_LENS_BASELINE_MM,
                settings.operatorLensBaselineMm?.toString(),
            )
            .putString(KEY_ACTIVE_CALIBRATION_PROFILE_ID, settings.activeCalibrationProfileId)
            .putString(KEY_CALIBRATION_BOARD_JSON, settings.calibrationBoard.toJson().toString())
            .apply()
    }

    private inline fun <reified T : Enum<T>> enumValueOrDefault(
        value: String?,
        fallback: T,
    ): T = runCatching {
        enumValueOf<T>(value.orEmpty())
    }.getOrDefault(fallback)

    companion object {
        private const val PREFS = "dual_phone_stereo"
        private const val KEY_DEVICE_ID = "device_id"
        private const val KEY_ROLE = "role"
        private const val KEY_APPLICATION_MODE = "application_capture_mode"
        private const val KEY_TRANSPORT = "transport"
        private const val KEY_PEER_DEVICE_ID = "peer_device_id"
        private const val KEY_MASTER_HOST = "master_host"
        private const val KEY_MASTER_CONTROLS_UPLOAD =
            "master_controls_upload"
        private const val KEY_CONTROL_PORT = "control_port"
        private const val KEY_CLOCK_SYNC_PORT = "clock_sync_port"
        private const val KEY_BUNDLE_TRANSFER_PORT = "bundle_transfer_port"
        private const val KEY_PREFERRED_VIDEO_MODE_ID =
            "preferred_video_mode_id"
        private const val KEY_RIG_ID = "rig_id"
        private const val KEY_RIG_MOUNT_REVISION = "rig_mount_revision"
        private const val KEY_OPERATOR_LENS_BASELINE_MM =
            "operator_lens_baseline_mm"
        private const val KEY_ACTIVE_CALIBRATION_PROFILE_ID =
            "active_calibration_profile_id"
        private const val KEY_CALIBRATION_BOARD_JSON =
            "calibration_board_json"
    }
}
