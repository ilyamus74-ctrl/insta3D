package com.maklertour.data.camera

import com.maklertour.domain.CameraProvider
import com.maklertour.domain.CameraStatus
import com.maklertour.domain.CapturePoint
import com.maklertour.domain.ScanVideo
import com.maklertour.domain.ScanVideoCaptureStatus
import com.maklertour.domain.CameraDeleteResult

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
    override suspend fun startVideoScan(scanName: String): ScanVideo = ScanVideo(sessionId = "", name = scanName, sequenceNumber = 0, captureStatus = ScanVideoCaptureStatus.FAILED, notes = "Insta360 provider is not integrated yet")
    override suspend fun stopVideoScan(): ScanVideo = ScanVideo(sessionId = "", name = "Scan", sequenceNumber = 0, captureStatus = ScanVideoCaptureStatus.FAILED, notes = "Insta360 provider is not integrated yet")
    override suspend fun deleteFiles(fileUrls: List<String>): CameraDeleteResult {
        return CameraDeleteResult(
            deleted = emptyList(),
            failed = fileUrls.associateWith { "Insta360 provider is not integrated yet" }
        )
    }
}
