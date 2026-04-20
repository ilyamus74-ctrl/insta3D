package com.maklertour.domain

import java.time.Instant
import java.util.UUID

data class Session(
    val id: String = UUID.randomUUID().toString(),
    val name: String,
    val address: String,
    val comment: String,
    val createdAt: Instant = Instant.now(),
    val points: List<CapturePoint> = emptyList(),
)

data class CapturePoint(
    val id: String = UUID.randomUUID().toString(),
    val name: String,
    val capturedAt: Instant = Instant.now(),
    val status: CaptureStatus = CaptureStatus.Draft,
    val previewUri: String? = null,
)

enum class CaptureStatus {
    Draft,
    Ready,
    Failed,
}

data class CameraStatus(
    val isConnected: Boolean = false,
    val model: String? = null,
    val batteryPercent: Int? = null,
    val freeStorageMb: Long? = null,
    val lastError: String? = null,
)

enum class UploadStatus {
    Queued,
    Uploading,
    Success,
    Error,
}

data class UploadItem(
    val id: String = UUID.randomUUID().toString(),
    val sessionId: String,
    val status: UploadStatus = UploadStatus.Queued,
    val retryCount: Int = 0,
    val updatedAt: Instant = Instant.now(),
)
