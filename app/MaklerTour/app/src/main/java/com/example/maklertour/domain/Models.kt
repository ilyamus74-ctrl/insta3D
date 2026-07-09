package com.maklertour.domain

import java.time.Instant
import java.util.UUID

data class Session(
    val id: String = UUID.randomUUID().toString(),
    val name: String,
    val address: String,
    val comment: String,
    val createdAt: Instant = Instant.now(),
    val startPointId: String? = null,
    val points: List<CapturePoint> = emptyList(),
    val serverOrderId: Long? = null,
    val serverCaptureSessionId: Long? = null,
    val orderTitle: String? = null,
    val orderAddress: String? = null,

)

data class CapturePoint(
    val id: String = UUID.randomUUID().toString(),
    val name: String,
    val capturedAt: Instant = Instant.now(),
    val status: CaptureStatus = CaptureStatus.Draft,
    val previewUri: String? = null,
    val cameraFileUrl: String? = null,
    val cameraLocalPath: String? = null,
    val localPreviewPath: String? = null,
    val localOriginalPath: String? = null,
    val localOriginalState: FileLocalState = FileLocalState.NOT_DOWNLOADED,
    val serverUploadState: ServerUploadState = ServerUploadState.NOT_QUEUED,
    val cameraDeleteState: DeleteState = DeleteState.NOT_DELETED,
    val localDeleteState: DeleteState = DeleteState.NOT_DELETED,
    val fileSizeBytes: Long? = null,
    val checksumSha256: String? = null,
    val serverMediaId: String? = null,
    val serverConfirmedAtEpochMs: Long? = null,
    val roomId: String? = null,
)

data class RoomDraft(
    val id: String,
    val sessionId: String,
    val name: String,
    val type: String,
    val orderIndex: Int,
    val notes: String? = null,
    val lengthM: Double? = null,
    val widthM: Double? = null,
    val heightM: Double? = null,
)

data class TourDraftConnection(
    val id: String,
    val sessionId: String,
    val fromPointId: String,
    val toPointId: String,
    val connectionType: String = "manual",
)

enum class FileLocalState { NOT_DOWNLOADED, DOWNLOADING, DOWNLOADED, DOWNLOAD_ERROR }
enum class ServerUploadState { NOT_QUEUED, QUEUED, UPLOADING, CONFIRMED, ERROR }
enum class DeleteState { NOT_DELETED, DELETE_ALLOWED, DELETE_REQUESTED, DELETED, DELETE_ERROR }

enum class ScanSource { INSTA360, PHONE_CAMERA }

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
    Packaging,
    Queued,
    Uploading,
    Success,
    Error,
}

data class UploadItem(
    val id: String = UUID.randomUUID().toString(),
    val sessionId: String,
    val sessionTitle: String? = null,
    val orderId: Long? = null,
    val orderTitle: String? = null,
    val orderAddress: String? = null,
    val bindingId: String? = null,
    val uploadAppSessionUuid: String? = null,
    val serverCaptureSessionId: Long? = null,
    val status: UploadStatus = UploadStatus.Queued,
    val retryCount: Int = 0,
    val updatedAt: Instant = Instant.now(),
    val progressPercent: Int = 0,
    val bytesUploaded: Long = 0L,
    val bytesTotal: Long = 0L,
    val currentFileName: String? = null,
    val currentStep: String? = null,
    val uploadType: String = "MEDIA",
    val captureType: String? = null,
    val localFilePath: String? = null,
    val displayName: String? = null,
    val mimeType: String? = null,
)



data class ScanVideo(
    val id: String = UUID.randomUUID().toString(),
    val objectId: String? = null,
    val sessionId: String,
    val name: String,
    val sequenceNumber: Int,
    val cameraFileUrl: String? = null,
    val cameraLocalFileUrl: String? = null,
    val localPreviewPath: String? = null,
    val localVideoPath: String? = null,
    val durationSec: Long? = null,
    val fileSizeBytes: Long? = null,
    val markerExpected: Boolean = false,
    val markerDetected: Boolean = false,
    val captureStatus: ScanVideoCaptureStatus = ScanVideoCaptureStatus.DRAFT,
    val downloadState: ScanVideoDownloadState = ScanVideoDownloadState.CAMERA_ONLY,
    val uploadState: ScanVideoUploadState = ScanVideoUploadState.LOCAL_ONLY,
    val serverProcessingState: ScanVideoProcessingState = ScanVideoProcessingState.NOT_STARTED,
    val source: ScanSource = ScanSource.INSTA360,
    val role: ScanVideoRole? = null,
    val createdAt: Instant = Instant.now(),
    val updatedAt: Instant = Instant.now(),
    val notes: String? = null,
)

enum class ScanVideoCaptureStatus { DRAFT, RECORDING, CAPTURED, FAILED }
enum class ScanVideoRole { BACKBONE, MAIN_PASS, DETAIL }
enum class VideoScanUiState { IDLE, SWITCHING_MODE, RECORDING, STOPPING, CAPTURED, FAILED }
enum class ScanVideoDownloadState { CAMERA_ONLY, DOWNLOADING, DOWNLOADED, DOWNLOAD_ERROR }
enum class ScanVideoUploadState { LOCAL_ONLY, QUEUED, UPLOADING, UPLOADED, CONFIRMED, UPLOAD_ERROR }
enum class ScanVideoProcessingState { NOT_STARTED, QUEUED, PROCESSING, DONE, FAILED }
