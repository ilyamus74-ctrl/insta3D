package com.maklertour.data.network

import com.maklertour.BuildConfig
import kotlinx.coroutines.delay

interface UploadApi {
    suspend fun uploadChunk(uploadId: String, sessionId: String, attempt: Int): Boolean
    suspend fun pollProcessingStatus(uploadId: String): Boolean
}

class MockUploadApi : UploadApi {
    override suspend fun uploadChunk(uploadId: String, sessionId: String, attempt: Int): Boolean {
        delay(600)
        val failOnFirstAttempt = (sessionId.hashCode() and 1) == 0
        return !(failOnFirstAttempt && attempt == 1)
    }

    override suspend fun pollProcessingStatus(uploadId: String): Boolean {
        delay(400)
        return true
    }
}

/**
 * Заглушка под реальный backend-клиент.
 * Пока возвращает success после небольшой задержки, но уже получает baseUrl/env-конфиг.
 */
class BackendUploadApi(
    private val baseUrl: String,
    private val requestDelayMs: Long = 300,
) : UploadApi {
    override suspend fun uploadChunk(uploadId: String, sessionId: String, attempt: Int): Boolean {
        delay(requestDelayMs)
        return baseUrl.isNotBlank()
    }

    override suspend fun pollProcessingStatus(uploadId: String): Boolean {
        delay(requestDelayMs)
        return true
    }
}

data class NetworkConfig(
    val baseUrl: String,
    val useMockUploadApi: Boolean,
)

object NetworkConfigProvider {
    fun fromBuildConfig(): NetworkConfig = NetworkConfig(
        baseUrl = BuildConfig.API_BASE_URL,
        useMockUploadApi = BuildConfig.USE_MOCK_UPLOAD_API,
    )
}

object UploadApiFactory {
    fun create(config: NetworkConfig): UploadApi {
        return if (config.useMockUploadApi) {
            MockUploadApi()
        } else {
            BackendUploadApi(baseUrl = config.baseUrl)
        }
    }
}
