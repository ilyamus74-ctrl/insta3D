package com.example.ocrscannertest  // свой пакет

import android.content.Context
import android.provider.Settings
import java.util.UUID

data class DeviceConfig(
    val serverUrl: String,
    val deviceUid: String,
    val deviceName: String,
    val deviceToken: String?,
    val enrolled: Boolean,
    val allowInsecureSsl: Boolean = false,
    val useRemoteOcr: Boolean = false,   // локальный/удалённый парсер
    val liveScanEnabled: Boolean = false, // live распознавание в превью
    val syncNameDict: Boolean = true,    // тянуть словарь из WebView
    val debugToasts: Boolean = false,    // показать debug toasts
    val cameraModeEnabled: Boolean = false // показывать пресеты зума на экране сканера
    //
)

class DeviceConfigRepository(private val context: Context) {

    private val prefs = context.getSharedPreferences("device_config", Context.MODE_PRIVATE)

    fun load(): DeviceConfig {
        val savedUid = prefs.getString("device_uid", null)
        val uid = savedUid ?: generateAndSaveUid()

        val serverUrl = prefs.getString("server_url", "") ?: ""
        val deviceName = prefs.getString("device_name", "") ?: ""
        val deviceToken = prefs.getString("device_token", null)
        val enrolled = prefs.getBoolean("enrolled", false)
        val allowInsecure = prefs.getBoolean("allow_insecure_ssl", false)
        val useRemoteOcr = prefs.getBoolean("use_remote_ocr", false)
        val liveScanEnabled = prefs.getBoolean("live_scan_enabled", false)
        val syncNameDict = prefs.getBoolean("sync_name_dict", true)
        val debugToasts = prefs.getBoolean("debug_toasts", false)
        val cameraModeEnabled = prefs.getBoolean("camera_mode_enabled", false)

        return DeviceConfig(
            serverUrl = serverUrl,
            deviceUid = uid,
            deviceName = deviceName,
            deviceToken = deviceToken,
            enrolled = enrolled,
            allowInsecureSsl = allowInsecure,
            liveScanEnabled = liveScanEnabled,
            useRemoteOcr = useRemoteOcr,
            syncNameDict = syncNameDict,
            debugToasts = debugToasts,
            cameraModeEnabled = cameraModeEnabled

        )
    }



    private fun generateAndSaveUid(): String {
        val androidId = Settings.Secure.getString(
            context.contentResolver,
            Settings.Secure.ANDROID_ID
        ) ?: ""
        val uid = "dev-" + UUID.randomUUID().toString() + "-" + androidId
        prefs.edit().putString("device_uid", uid).apply()
        return uid
    }

    fun save(cfg: DeviceConfig) {
        prefs.edit()
            .putString("server_url", cfg.serverUrl)
            .putString("device_name", cfg.deviceName)
            .putString("device_token", cfg.deviceToken)
            .putBoolean("enrolled", cfg.enrolled)
            .putBoolean("use_remote_ocr", cfg.useRemoteOcr)
            .putBoolean("live_scan_enabled", cfg.liveScanEnabled)
            .putBoolean("sync_name_dict", cfg.syncNameDict)
            .putBoolean("debug_toasts", cfg.debugToasts)
            .putBoolean("camera_mode_enabled", cfg.cameraModeEnabled)
            .apply()
    }

    fun clearEnroll() {
        prefs.edit()
            .putString("server_url", "")
            .putString("device_name", "")
            .remove("device_token")
            .putBoolean("enrolled", false)
            .apply()
    }
}
