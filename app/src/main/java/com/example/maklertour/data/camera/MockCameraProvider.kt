package com.maklertour.data.camera

import com.maklertour.domain.CameraProvider
import com.maklertour.domain.CameraStatus
import com.maklertour.domain.CapturePoint
import kotlinx.coroutines.delay

class MockCameraProvider : CameraProvider {
    private var connected = false

    override suspend fun connect(): CameraStatus {
        delay(250)
        connected = true
        return getStatus()
    }

    override suspend fun disconnect(): CameraStatus {
        connected = false
        return getStatus()
    }

    override suspend fun getStatus(): CameraStatus {
        return if (connected) {
            CameraStatus(
                isConnected = true,
                model = "Insta360 Mock X4",
                batteryPercent = 84,
                freeStorageMb = 128_000,
                lastError = null,
            )
        } else {
            CameraStatus(
                isConnected = false,
                model = null,
                batteryPercent = null,
                freeStorageMb = null,
                lastError = null,
            )
        }
    }

    override suspend fun capture(pointName: String): CapturePoint {
        delay(300)
        return CapturePoint(
            name = pointName,
            previewUri = "mock://preview/${System.currentTimeMillis()}",
        )
    }

    override suspend fun listFiles(): List<String> {
        return listOf("mock_001.insv", "mock_002.insv", "mock_003.insv")
    }
}
