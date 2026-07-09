package com.maklertour.i18n

import android.content.Context
import android.content.res.Configuration
import java.util.Locale

fun Context.withAppLanguage(language: AppLanguage): Context {
    if (language == AppLanguage.SYSTEM) {
        return this
    }

    val locale = Locale(language.code)
    Locale.setDefault(locale)

    val config = Configuration(resources.configuration)
    config.setLocale(locale)

    return createConfigurationContext(config)
}
