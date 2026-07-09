package com.maklertour.i18n

import android.content.Context

object DebugPreferences {
    private const val PREFS_NAME = "app_settings"
    private const val KEY_DEBUG_MODE = "debug_mode"

    fun get(context: Context): Boolean =
        context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .getBoolean(KEY_DEBUG_MODE, false)

    fun set(context: Context, enabled: Boolean) {
        context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .edit()
            .putBoolean(KEY_DEBUG_MODE, enabled)
            .apply()
    }
}
