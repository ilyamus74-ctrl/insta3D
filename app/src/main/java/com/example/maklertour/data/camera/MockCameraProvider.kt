package com.maklertour.data.camera

import com.maklertour.domain.CameraProvider
import com.maklertour.domain.CameraDeleteResult
import com.maklertour.domain.CameraStatus
import com.maklertour.domain.CapturePoint
import com.maklertour.domain.ScanVideo
import com.maklertour.domain.ScanVideoCaptureStatus
import kotlinx.coroutines.delay

class MockCameraProvider : CameraProvider {
    private var connected = false
    private var currentRecordingScanName: String? = null
    private var currentRecordingStartMs: Long? = null

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

    override suspend fun startVideoScan(scanName: String): ScanVideo {
        currentRecordingScanName = scanName
        currentRecordingStartMs = System.currentTimeMillis()
        return ScanVideo(sessionId = "", name = scanName, sequenceNumber = 0, captureStatus = ScanVideoCaptureStatus.RECORDING)
    }

    override suspend fun stopVideoScan(): ScanVideo {
        val start = currentRecordingStartMs ?: System.currentTimeMillis()
        val name = currentRecordingScanName ?: "Scan"
        val durationSec = (System.currentTimeMillis()-start)/1000
        currentRecordingScanName = null
        currentRecordingStartMs = null
        return ScanVideo(sessionId = "", name = name, sequenceNumber = 0, captureStatus = ScanVideoCaptureStatus.CAPTURED, cameraFileUrl = "http://mock/cam/${name}.insv", cameraLocalFileUrl = "file:///mock/${name}.insv", durationSec=durationSec)
    }

    override suspend fun deleteFiles(fileUrls: List<String>): CameraDeleteResult {
        return CameraDeleteResult(deleted = fileUrls, failed = emptyMap())
    }
}
