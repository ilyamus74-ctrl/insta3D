package com.example.maklertour.network

import com.maklertour.BuildConfig

object ApiConfig {
    const val MOBILE_API_PATH = "api/mobile.php"
    const val REGISTER_PATH = "register.php"

    val baseUrl: String
        get() = BuildConfig.API_BASE_URL

    val mobileApiUrl: String
        get() = baseUrl.trimEnd('/') + "/" + MOBILE_API_PATH

    val registerUrl: String
        get() = baseUrl.trimEnd('/') + "/" + REGISTER_PATH
}
