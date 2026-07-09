package com.maklertour.domain

interface CameraProvider {
    suspend fun connect(): CameraStatus
    suspend fun disconnect(): CameraStatus
    suspend fun getStatus(): CameraStatus
    suspend fun capture(pointName: String): CapturePoint
    suspend fun listFiles(): List<String>
    suspend fun startVideoScan(scanName: String): ScanVideo
    suspend fun stopVideoScan(): ScanVideo
    suspend fun deleteFiles(fileUrls: List<String>): CameraDeleteResult
}

data class CameraDeleteResult(
    val deleted: List<String>,
    val failed: Map<String, String>,
)