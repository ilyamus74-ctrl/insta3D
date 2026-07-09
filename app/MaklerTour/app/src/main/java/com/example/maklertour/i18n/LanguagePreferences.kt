package com.maklertour.i18n

import android.content.Context

object LanguagePreferences {
    private const val PREFS_NAME = "app_settings"
    private const val KEY_LANGUAGE = "app_language"

    fun get(context: Context): AppLanguage {
        val code = context
            .getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .getString(KEY_LANGUAGE, AppLanguage.SYSTEM.code)

        return AppLanguage.fromCode(code)
    }

    fun set(context: Context, language: AppLanguage) {
        context
            .getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .edit()
            .putString(KEY_LANGUAGE, language.code)
            .apply()
    }
}
