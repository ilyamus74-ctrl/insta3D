package com.maklertour.data.network

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
