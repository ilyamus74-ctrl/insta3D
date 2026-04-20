package com.maklertour.data.camera

import com.maklertour.domain.CameraProvider
import com.maklertour.domain.CameraStatus
import com.maklertour.domain.CapturePoint

/**
 * Заглушка для реальной интеграции через Insta360 SDK / OSC bridge.
 */
class Insta360Provider : CameraProvider {
    override suspend fun connect(): CameraStatus {
        return CameraStatus(isConnected = false, lastError = "Insta360 provider is not integrated yet")
    }

    override suspend fun disconnect(): CameraStatus = CameraStatus(isConnected = false)

    override suspend fun getStatus(): CameraStatus {
        return CameraStatus(isConnected = false, lastError = "Insta360 provider is not integrated yet")
    }

    override suspend fun capture(pointName: String): CapturePoint {
        error("Insta360 provider is not integrated yet")
    }

    override suspend fun listFiles(): List<String> = emptyList()
}
