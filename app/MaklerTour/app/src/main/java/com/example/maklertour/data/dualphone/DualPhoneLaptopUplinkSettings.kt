package com.example.maklertour.data.dualphone

import android.content.Context

enum class DualPhoneLaptopCameraSlot {
    CAMERA_A,
    CAMERA_B,
}

data class DualPhoneLaptopUplinkConfig(
    val host: String,
    val port: Int = DEFAULT_PORT,
    val slot: DualPhoneLaptopCameraSlot = DualPhoneLaptopCameraSlot.CAMERA_A,
) {
    init {
        require(host.isNotBlank()) { "Laptop host is required" }
        require(port in 1..65535) { "Laptop port is invalid" }
    }

    companion object {
        const val DEFAULT_PORT = 48640
    }
}

class DualPhoneLaptopUplinkSettingsStore(context: Context) {
    private val prefs = context.applicationContext.getSharedPreferences(
        PREFS,
        Context.MODE_PRIVATE,
    )

    fun load(defaultHost: String? = null): DualPhoneLaptopUplinkConfig {
        val host = prefs.getString(KEY_HOST, null)
            ?.trim()
            ?.takeIf { it.isNotBlank() }
            ?: defaultHost?.trim()?.takeIf { it.isNotBlank() }
            ?: "192.168.2.1"
        return DualPhoneLaptopUplinkConfig(
            host = host,
            port = prefs.getInt(
                KEY_PORT,
                DualPhoneLaptopUplinkConfig.DEFAULT_PORT,
            ).coerceIn(1, 65535),
            slot = runCatching {
                DualPhoneLaptopCameraSlot.valueOf(
                    prefs.getString(KEY_SLOT, null).orEmpty(),
                )
            }.getOrDefault(DualPhoneLaptopCameraSlot.CAMERA_A),
        )
    }

    fun save(config: DualPhoneLaptopUplinkConfig) {
        prefs.edit()
            .putString(KEY_HOST, config.host.trim())
            .putInt(KEY_PORT, config.port)
            .putString(KEY_SLOT, config.slot.name)
            .apply()
    }

    companion object {
        private const val PREFS = "dual_phone_laptop_uplink"
        private const val KEY_HOST = "host"
        private const val KEY_PORT = "port"
        private const val KEY_SLOT = "slot"
    }
}
