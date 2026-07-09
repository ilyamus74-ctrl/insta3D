package com.maklertour.i18n

enum class AppLanguage(
    val code: String,
    val label: String,
) {
    SYSTEM("system", "System"),
    EN("en", "English"),
    RU("ru", "Русский"),
    UK("uk", "Українська"),
    DE("de", "Deutsch");

    companion object {
        fun fromCode(code: String?): AppLanguage {
            return entries.firstOrNull { it.code == code } ?: SYSTEM
        }
    }
}
