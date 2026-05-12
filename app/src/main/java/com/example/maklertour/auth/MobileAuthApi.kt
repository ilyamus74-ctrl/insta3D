package com.example.maklertour.auth

import android.content.Context
import android.os.Build
import android.provider.Settings
import com.example.maklertour.network.ApiConfig
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.FormBody
import okhttp3.OkHttpClient
import okhttp3.Request
import org.json.JSONObject

class MobileAuthApi(
    private val context: Context,
    private val client: OkHttpClient = OkHttpClient(),
) {

    suspend fun login(username: String, password: String): LoginResult = withContext(Dispatchers.IO) {
        val requestBody = FormBody.Builder()
            .add("username", username)
            .add("password", password)
            .add("device_name", "${Build.MANUFACTURER} ${Build.MODEL}")
            .add("device_fingerprint", getDeviceFingerprint())
            .build()

        val request = Request.Builder()
            .url("${ApiConfig.mobileApiUrl}?action=login")
            .post(requestBody)
            .build()

        executeLoginRequest(request)
    }

    suspend fun ping(token: String): Boolean = withContext(Dispatchers.IO) {
        val request = Request.Builder()
            .url("${ApiConfig.mobileApiUrl}?action=ping")
            .post(FormBody.Builder().build())
            .header("Authorization", "Bearer $token")
            .build()

        client.newCall(request).execute().use { response ->
            if (!response.isSuccessful) return@withContext false
            val rawBody = response.body?.string().orEmpty()
            val json = runCatching { JSONObject(rawBody) }.getOrNull() ?: return@withContext false
            json.optBoolean("ok", false)
        }
    }

    suspend fun logout(token: String) = withContext(Dispatchers.IO) {
        val request = Request.Builder()
            .url("${ApiConfig.mobileApiUrl}?action=logout")
            .post(FormBody.Builder().build())
            .header("Authorization", "Bearer $token")
            .build()

        runCatching {
            client.newCall(request).execute().close()
        }
    }

    private fun executeLoginRequest(request: Request): LoginResult {
        return runCatching {
            client.newCall(request).execute().use { response ->
                val rawBody = response.body?.string().orEmpty()
                val json = runCatching { JSONObject(rawBody) }.getOrNull()
                    ?: return@use LoginResult.Error("invalid response")

                if (!response.isSuccessful || !json.optBoolean("ok", false)) {
                    return@use LoginResult.Error(json.optString("error", "invalid credentials"))
                }

                val token = json.optString("token", "")
                if (token.isBlank()) {
                    return@use LoginResult.Error("missing token")
                }

                val userJson = json.optJSONObject("user")
                val user = MobileUser(
                    id = userJson?.optInt("id", 0) ?: 0,
                    username = userJson?.optString("username", "").orEmpty(),
                    email = userJson?.optString("email", "").orEmpty(),
                    fullName = userJson?.optString("full_name", "").orEmpty(),
                    role = userJson?.optString("role", "").orEmpty(),
                )
                LoginResult.Success(token, user)
            }
        }.getOrElse {
            LoginResult.Error("network error")
        }
    }

    private fun getDeviceFingerprint(): String {
        return runCatching {
            Settings.Secure.getString(context.contentResolver, Settings.Secure.ANDROID_ID)
        }.getOrNull().orEmpty().ifBlank { "unknown" }
    }
}

data class MobileUser(
    val id: Int,
    val username: String,
    val email: String,
    val fullName: String,
    val role: String,
)

sealed interface LoginResult {
    data class Success(val token: String, val user: MobileUser) : LoginResult
    data class Error(val message: String) : LoginResult
}
