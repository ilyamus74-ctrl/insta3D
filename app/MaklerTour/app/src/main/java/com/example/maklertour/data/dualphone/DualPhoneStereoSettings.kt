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
    val transport: DualPhoneTransport = DualPhoneTransport.WIFI_LAN,
    val peerDeviceId: String? = null,
    val masterHost: String? = null,
    val masterControlsUpload: Boolean = true,
    val controlPort: Int = 48621,
    val clockSyncPort: Int = 48622,
    val preferredVideoModeId: String? = null,
) {
    fun toJson(): JSONObject = JSONObject()
        .put("schema_version", 2)
        .put("device_id", deviceId)
        .put("role", role.name)
        .put("transport", transport.name)
        .put("peer_device_id", peerDeviceId ?: JSONObject.NULL)
        .put("master_host", masterHost ?: JSONObject.NULL)
        .put("master_controls_upload", true)
        .put("control_port", controlPort)
        .put("clock_sync_port", clockSyncPort)
        .put("preferred_video_mode_id", preferredVideoModeId ?: JSONObject.NULL)
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

        return DualPhoneStereoSettings(
            deviceId = deviceId,
            role = enumValueOrDefault(
                prefs.getString(KEY_ROLE, null),
                DualPhoneRole.STANDALONE,
            ),
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
            preferredVideoModeId = prefs.getString(
                KEY_PREFERRED_VIDEO_MODE_ID,
                null,
            )?.takeIf { it.isNotBlank() },
        )
    }

    fun save(settings: DualPhoneStereoSettings) {
        prefs.edit()
            .putString(KEY_DEVICE_ID, settings.deviceId)
            .putString(KEY_ROLE, settings.role.name)
            .putString(KEY_TRANSPORT, DualPhoneTransport.WIFI_LAN.name)
            .putString(KEY_PEER_DEVICE_ID, settings.peerDeviceId)
            .putString(KEY_MASTER_HOST, settings.masterHost)
            .putBoolean(KEY_MASTER_CONTROLS_UPLOAD, true)
            .putInt(KEY_CONTROL_PORT, settings.controlPort)
            .putInt(KEY_CLOCK_SYNC_PORT, settings.clockSyncPort)
            .putString(
                KEY_PREFERRED_VIDEO_MODE_ID,
                settings.preferredVideoModeId,
            )
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
        private const val KEY_TRANSPORT = "transport"
        private const val KEY_PEER_DEVICE_ID = "peer_device_id"
        private const val KEY_MASTER_HOST = "master_host"
        private const val KEY_MASTER_CONTROLS_UPLOAD =
            "master_controls_upload"
        private const val KEY_CONTROL_PORT = "control_port"
        private const val KEY_CLOCK_SYNC_PORT = "clock_sync_port"
        private const val KEY_PREFERRED_VIDEO_MODE_ID =
            "preferred_video_mode_id"
    }
}
